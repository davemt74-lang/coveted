<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/event_management.php';
require_once dirname(__DIR__) . '/app/campaigns.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();

function coveted_admin_event_local_to_utc(string $value, string $timezone): string
{
    $value = trim($value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
        throw new InvalidArgumentException('Enter a valid event date and time.');
    }

    $zone = coveted_require_timezone($timezone);
    $local = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $zone);
    if (!$local || $local->format('Y-m-d\TH:i') !== $value) {
        throw new InvalidArgumentException('Enter a valid event date and time.');
    }

    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function coveted_admin_event_user_id_from_email(string $email): int
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        throw new InvalidArgumentException('Enter a valid Coveted member email.');
    }

    $stmt = coveted_db()->prepare("SELECT id FROM users WHERE email = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$email]);
    $userId = (int)$stmt->fetchColumn();
    if ($userId < 1) {
        throw new InvalidArgumentException('No active Coveted account was found for that email.');
    }

    return $userId;
}

function coveted_admin_event_remove_host(array $admin, string $eventRef, int $userId): void
{
    coveted_event_require_system_admin($admin);
    if ($userId < 1) {
        throw new InvalidArgumentException('Choose a valid host assignment.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $event = coveted_event_manage_locked($pdo, $admin, $eventRef);
        if (in_array((string)$event['status'], ['completed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Host assignments are locked for completed or cancelled events.');
        }

        $host = $pdo->prepare('SELECT host_role FROM event_hosts WHERE event_id = ? AND user_id = ? LIMIT 1 FOR UPDATE');
        $host->execute([(int)$event['id'], $userId]);
        $role = $host->fetchColumn();
        if ($role === false) {
            throw new InvalidArgumentException('That user is not assigned to this event.');
        }

        $pdo->prepare('DELETE FROM event_hosts WHERE event_id = ? AND user_id = ?')
            ->execute([(int)$event['id'], $userId]);

        coveted_audit(
            'event.host_removed',
            'event',
            (string)$event['public_id'],
            ['user_id' => $userId, 'host_role' => (string)$role],
            (int)$admin['id']
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_admin_event_unlink_campaign(array $admin, string $eventRef, int $campaignId): void
{
    coveted_event_require_system_admin($admin);
    if ($campaignId < 1) {
        throw new InvalidArgumentException('Choose a valid campaign.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $event = coveted_event_manage_locked($pdo, $admin, $eventRef);
        if (in_array((string)$event['status'], ['completed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Campaign links are locked for completed or cancelled events.');
        }

        $campaign = $pdo->prepare(
            "SELECT c.public_id, c.title
             FROM campaign_event_links cel
             JOIN campaigns c ON c.id = cel.campaign_id
             WHERE cel.event_id = ? AND c.id = ?
             LIMIT 1 FOR UPDATE"
        );
        $campaign->execute([(int)$event['id'], $campaignId]);
        $row = $campaign->fetch();
        if (!$row) {
            throw new InvalidArgumentException('That campaign is not linked to this event.');
        }

        $pdo->prepare('DELETE FROM campaign_event_links WHERE event_id = ? AND campaign_id = ?')
            ->execute([(int)$event['id'], $campaignId]);

        coveted_audit(
            'campaign.event_unlinked',
            'event',
            (string)$event['public_id'],
            ['campaign_id' => $campaignId, 'campaign_public_id' => (string)$row['public_id']],
            (int)$admin['id']
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_admin_event_remove_reveal(array $admin, string $eventRef, int $revealId): void
{
    coveted_event_require_system_admin($admin);
    if ($revealId < 1) {
        throw new InvalidArgumentException('Choose a valid reveal.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $event = coveted_event_manage_locked($pdo, $admin, $eventRef);
        if (in_array((string)$event['status'], ['completed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Mystery reveals are locked for completed or cancelled events.');
        }

        $reveal = $pdo->prepare(
            'SELECT reveal_type, reveal_at FROM event_mystery_reveals WHERE id = ? AND event_id = ? LIMIT 1 FOR UPDATE'
        );
        $reveal->execute([$revealId, (int)$event['id']]);
        $row = $reveal->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Mystery reveal not found.');
        }
        if (coveted_utc_datetime((string)$row['reveal_at'])->getTimestamp() <= time()) {
            throw new InvalidArgumentException('A reveal that has already become visible cannot be removed.');
        }

        $pdo->prepare('DELETE FROM event_mystery_reveals WHERE id = ? AND event_id = ?')
            ->execute([$revealId, (int)$event['id']]);

        coveted_audit(
            'event.reveal_removed',
            'event',
            (string)$event['public_id'],
            ['reveal_id' => $revealId, 'reveal_type' => (string)$row['reveal_type']],
            (int)$admin['id']
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

$eventRef = trim((string)($_GET['event'] ?? $_POST['event_ref'] ?? ''));
$tab = strtolower(trim((string)($_GET['tab'] ?? $_POST['tab'] ?? 'overview')));
$tabs = [
    'overview' => 'Overview',
    'guests' => 'Guests',
    'hosts' => 'Hosts',
    'location' => 'Location',
    'artist' => 'Artist',
    'benefits' => 'Benefits',
    'mystery' => 'Mystery',
    'attendance' => 'Attendance',
    'activity' => 'Activity',
];
if (!isset($tabs[$tab])) {
    $tab = 'overview';
}

$error = '';
$saved = trim((string)($_GET['saved'] ?? ''));
$notice = match ($saved) {
    'event' => 'Event details updated.',
    'status' => 'Event status updated.',
    'invite' => 'Invitation sent.',
    'host' => 'Host assignment updated.',
    'host-removed' => 'Host assignment removed.',
    'location' => 'Event location updated.',
    'artist' => 'Artist lineup updated.',
    'campaign' => 'Benefit campaign linked.',
    'campaign-removed' => 'Benefit campaign unlinked.',
    'reveal' => 'Mystery reveal added.',
    'reveal-removed' => 'Mystery reveal removed.',
    'attendance' => 'Attendance updated.',
    default => '',
};

$event = $eventRef !== '' ? coveted_event_by_ref($eventRef) : null;
if (!$event) {
    http_response_code(404);
    coveted_page_start('Event Workspace', '', true);
    coveted_admin_ui_start($admin, 'events', 'Event Workspace');
    ?>
    <div class="cv-admin-page-head">
        <div><span class="cv-eyebrow">EVENT WORKSPACE</span><h1>Event not found.</h1><p>Choose an event from Admin Events to open its canonical configuration workspace.</p></div>
        <a class="cv-button cv-button-soft" href="/admin/?view=events">← Back to Events</a>
    </div>
    <?php coveted_admin_ui_end(); coveted_page_end(); exit; ?>
    <?php
}

$eventRef = (string)$event['public_id'];
$eventId = (int)$event['id'];
$isFinal = in_array((string)$event['status'], ['completed', 'cancelled'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        $action = trim((string)($_POST['action'] ?? ''));

        switch ($action) {
            case 'update_event':
                $timezone = trim((string)($_POST['timezone'] ?? 'America/Phoenix'));
                $startsAt = coveted_admin_event_local_to_utc((string)($_POST['starts_at'] ?? ''), $timezone);
                $endsRaw = trim((string)($_POST['ends_at'] ?? ''));
                coveted_event_update($admin, $eventRef, [
                    'title' => (string)($_POST['title'] ?? ''),
                    'description' => (string)($_POST['description'] ?? ''),
                    'event_type' => (string)($_POST['event_type'] ?? 'regular'),
                    'audience' => (string)($_POST['audience'] ?? 'group'),
                    'timezone' => $timezone,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsRaw !== '' ? coveted_admin_event_local_to_utc($endsRaw, $timezone) : '',
                    'capacity' => (string)($_POST['capacity'] ?? ''),
                    'plus_one_allowed' => (string)($_POST['plus_one_allowed'] ?? '0') === '1' ? 1 : 0,
                    'location_visibility' => (string)($_POST['location_visibility'] ?? 'immediate'),
                ]);
                coveted_redirect('/admin/event.php?event=' . rawurlencode($eventRef) . '&tab=overview&saved=event');

            case 'set_status':
                coveted_event_set_status($admin, $eventRef, (string)($_POST['status'] ?? ''));
                coveted_redirect('/admin/event.php?event=' . rawurlencode($eventRef) . '&tab=overview&saved=status');

            case 'invite_user':
                $targetId = coveted_admin_event_user_id_from_email((string)($_POST['email'] ?? ''));
                coveted_event_invite_user($admin, $eventRef, $targetId, (string)($_POST['invite_type'] ?? 'member'));
                coveted_redirect('/admin/event.php?event=' . rawurlencode($eventRef) . '&tab=guests&saved=invite');

            case 'assign_host':
                $targetId = coveted_admin_event_user_id_from_email((string)($_POST['email'] ?? ''));
                coveted_event_assign_host($admin, $eventRef, $targetId, (string)($_POST['host_role'] ?? 'checkin'));
                coveted_redirect('/admin/event.php?event=' . rawurlencode($eventRef) . '&tab=hosts&saved=host');

            case 'remove_host':
                coveted_admin_event_remove_host($admin, $eventRef, (int)($_POST['user_id'] ?? 0));
                coveted_redirect('/admin/event.php?event=' . rawurlencode($eventRef) . '&tab=hosts&saved=host-removed');

            case 'set_location':
                $locationId = (int)($_POST['location_id'] ?? 0);
                coveted_event_set_location(
                    $admin,
                    $eventRef,
                    $locationId > 0 ? $locationId : null,
                    (string)($_POST['private_location_label'] ?? ''),
                    (string)($_POST['reveal_notes'] ?? '')
                );
                coveted_redirect('/admin/event.php?event=' . rawurlencode($eventRef) . '&tab=location&saved=location');

            case 'set_artist':
                coveted_event_set_artist(
                    $admin,
                    $eventRef,
                    (int)($_POST['artist_id'] ?? 0),
                    (string)($_POST['appearance_type'] ?? 'featured')
                );
                coveted_redirect('/admin/event.php?event=' . rawurlencode($eventRef) . '&tab=artist&saved=artist');

            case 'remove_artist':
                coveted_event_remove_artist($admin, $eventRef, (int)($_POST['artist_id'] ?? 0));
                coveted_redirect('/admin/event.php?event=' . rawurlencode($eventRef) . '&tab=artist&saved=artist');

            case 'link_campaign':
                coveted_campaign_link_event($admin, (string)($_POST['campaign_ref'] ?? ''), $eventId);
                coveted_redirect('/admin/event.php?event=' . rawurlencode($eventRef) . '&tab=benefits&saved=campaign');

            case 'unlink_campaign':
                coveted_admin_event_unlink_campaign($admin, $eventRef, (int)($_POST['campaign_id'] ?? 0));
                coveted_redirect('/admin/event.php?event=' . rawurlencode($eventRef) . '&tab=benefits&saved=campaign-removed');

            case 'add_reveal':
                $revealAt = coveted_admin_event_local_to_utc(
                    (string)($_POST['reveal_at'] ?? ''),
                    (string)$event['timezone']
                );
                coveted_event_add_mystery_reveal(
                    $admin,
                    $eventRef,
                    $revealAt,
                    (string)($_POST['reveal_type'] ?? 'custom'),
                    (string)($_POST['reveal_title'] ?? ''),
                    (string)($_POST['reveal_content'] ?? '')
                );
                coveted_redirect('/admin/event.php?event=' . rawurlencode($eventRef) . '&tab=mystery&saved=reveal');

            case 'remove_reveal':
                coveted_admin_event_remove_reveal($admin, $eventRef, (int)($_POST['reveal_id'] ?? 0));
                coveted_redirect('/admin/event.php?event=' . rawurlencode($eventRef) . '&tab=mystery&saved=reveal-removed');

            case 'record_attendance':
                $targetId = coveted_admin_event_user_id_from_email((string)($_POST['email'] ?? ''));
                coveted_event_record_attendance(
                    $admin,
                    $eventRef,
                    $targetId,
                    (string)($_POST['attendance_status'] ?? 'checked_in')
                );
                coveted_redirect('/admin/event.php?event=' . rawurlencode($eventRef) . '&tab=attendance&saved=attendance');

            default:
                throw new InvalidArgumentException('Unsupported Event Workspace action.');
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted Admin Event Workspace error: ' . $e->getMessage());
        $error = 'Unable to update the event right now.';
    }

    $event = coveted_event_by_ref($eventRef) ?: $event;
    $isFinal = in_array((string)$event['status'], ['completed', 'cancelled'], true);
}

$statsStmt = $pdo->prepare(
    "SELECT
        (SELECT COUNT(*) FROM event_invitations WHERE event_id = ? AND status <> 'revoked') AS invited,
        (SELECT COUNT(*) FROM event_rsvps WHERE event_id = ? AND response = 'attending') AS attending,
        (SELECT COUNT(*) FROM event_rsvps WHERE event_id = ? AND response = 'waitlist') AS waitlist,
        (SELECT COUNT(*) FROM event_attendance WHERE event_id = ? AND status IN ('checked_in','attended','left_early')) AS checked_in,
        (SELECT COUNT(*) FROM event_hosts WHERE event_id = ?) AS hosts,
        (SELECT COUNT(*) FROM event_artists WHERE event_id = ?) AS artists,
        (SELECT COUNT(*) FROM campaign_event_links WHERE event_id = ?) AS campaigns"
);
$statsStmt->execute([$eventId, $eventId, $eventId, $eventId, $eventId, $eventId, $eventId]);
$stats = $statsStmt->fetch() ?: ['invited' => 0, 'attending' => 0, 'waitlist' => 0, 'checked_in' => 0, 'hosts' => 0, 'artists' => 0, 'campaigns' => 0];

$locationStmt = $pdo->prepare(
    "SELECT el.*, l.name AS location_name, l.address1, l.address2, l.city, l.region, l.postal_code,
            l.timezone AS location_timezone, b.id AS business_id, b.name AS business_name
     FROM event_locations el
     LEFT JOIN locations l ON l.id = el.location_id
     LEFT JOIN businesses b ON b.id = l.business_id
     WHERE el.event_id = ? LIMIT 1"
);
$locationStmt->execute([$eventId]);
$eventLocation = $locationStmt->fetch() ?: null;

$peopleStmt = $pdo->prepare(
    "SELECT DISTINCT
        u.id, u.display_name, u.email,
        ei.public_id AS invitation_public_id, ei.invite_type, ei.status AS invitation_status, ei.created_at AS invited_at,
        er.response, er.guest_count, er.responded_at,
        ea.status AS attendance_status, ea.checked_in_at
     FROM users u
     LEFT JOIN event_invitations ei ON ei.user_id = u.id AND ei.event_id = ?
     LEFT JOIN event_rsvps er ON er.user_id = u.id AND er.event_id = ?
     LEFT JOIN event_attendance ea ON ea.user_id = u.id AND ea.event_id = ?
     WHERE ei.id IS NOT NULL OR er.id IS NOT NULL OR ea.id IS NOT NULL
     ORDER BY u.display_name, u.id"
);
$peopleStmt->execute([$eventId, $eventId, $eventId]);
$eventPeople = $peopleStmt->fetchAll();

$hostStmt = $pdo->prepare(
    "SELECT eh.user_id, eh.host_role, u.display_name, u.email
     FROM event_hosts eh
     JOIN users u ON u.id = eh.user_id
     WHERE eh.event_id = ?
     ORDER BY FIELD(eh.host_role, 'lead','cohost','checkin'), u.display_name"
);
$hostStmt->execute([$eventId]);
$eventHosts = $hostStmt->fetchAll();

$artistStmt = $pdo->prepare(
    "SELECT ea.artist_id, ea.appearance_type, ap.artist_name, ap.public_id
     FROM event_artists ea
     JOIN artist_profiles ap ON ap.id = ea.artist_id
     WHERE ea.event_id = ?
     ORDER BY ap.artist_name"
);
$artistStmt->execute([$eventId]);
$eventArtists = $artistStmt->fetchAll();

$revealStmt = $pdo->prepare(
    "SELECT id, reveal_at, reveal_type, title, content, notified_at
     FROM event_mystery_reveals
     WHERE event_id = ?
     ORDER BY reveal_at, id"
);
$revealStmt->execute([$eventId]);
$eventReveals = $revealStmt->fetchAll();

$campaignStmt = $pdo->prepare(
    "SELECT c.id, c.public_id, c.title, c.owner_type, c.status, c.trigger_key,
            rt.title AS reward_title,
            COALESCE(sg.name, b.name, ap.artist_name, 'Coveted') AS owner_name
     FROM campaign_event_links cel
     JOIN campaigns c ON c.id = cel.campaign_id
     JOIN reward_templates rt ON rt.id = c.reward_template_id
     LEFT JOIN social_groups sg ON sg.id = c.group_id
     LEFT JOIN businesses b ON b.id = c.business_id
     LEFT JOIN artist_profiles ap ON ap.id = c.artist_id
     WHERE cel.event_id = ?
     ORDER BY c.status = 'active' DESC, c.title"
);
$campaignStmt->execute([$eventId]);
$linkedCampaigns = $campaignStmt->fetchAll();

$locationOptions = $pdo->query(
    "SELECT l.id, l.name, l.city, l.region, b.name AS business_name
     FROM locations l
     JOIN businesses b ON b.id = l.business_id
     WHERE l.status = 'active' AND b.status = 'active'
     ORDER BY b.name, l.name"
)->fetchAll();

$artistOptions = $pdo->query(
    "SELECT id, public_id, artist_name
     FROM artist_profiles
     WHERE status = 'active'
     ORDER BY artist_name, id"
)->fetchAll();

$eventArtistIds = array_map(static fn(array $row): int => (int)$row['artist_id'], $eventArtists);
$businessId = (int)($eventLocation['business_id'] ?? 0);
$availableCampaigns = [];
$availableSql = "SELECT c.id, c.public_id, c.title, c.owner_type, c.status, c.trigger_key,
                        rt.title AS reward_title,
                        COALESCE(sg.name, b.name, ap.artist_name, 'Coveted') AS owner_name
                 FROM campaigns c
                 JOIN reward_templates rt ON rt.id = c.reward_template_id
                 LEFT JOIN social_groups sg ON sg.id = c.group_id
                 LEFT JOIN businesses b ON b.id = c.business_id
                 LEFT JOIN artist_profiles ap ON ap.id = c.artist_id
                 WHERE c.status <> 'archived'
                   AND NOT EXISTS (
                       SELECT 1 FROM campaign_event_links cel
                       WHERE cel.event_id = ? AND cel.campaign_id = c.id
                   )
                   AND (
                       c.owner_type = 'platform'
                       OR (c.owner_type = 'group' AND c.group_id = ?)";
$availableParams = [$eventId, (int)$event['group_id']];
if ($businessId > 0) {
    $availableSql .= " OR (c.owner_type = 'business' AND c.business_id = ? AND (c.location_id IS NULL OR c.location_id = ?))";
    $availableParams[] = $businessId;
    $availableParams[] = (int)($eventLocation['location_id'] ?? 0);
}
if ($eventArtistIds) {
    $placeholders = implode(',', array_fill(0, count($eventArtistIds), '?'));
    $availableSql .= " OR (c.owner_type = 'artist' AND c.artist_id IN ({$placeholders}))";
    array_push($availableParams, ...$eventArtistIds);
}
$availableSql .= ') ORDER BY c.status = \'active\' DESC, c.owner_type, c.title LIMIT 250';
$availableStmt = $pdo->prepare($availableSql);
$availableStmt->execute($availableParams);
$availableCampaigns = $availableStmt->fetchAll();

$auditStmt = $pdo->prepare(
    "SELECT ae.id, ae.event_type, ae.entity_type, ae.entity_id, ae.metadata_json, ae.created_at,
            u.display_name AS actor_name, u.email AS actor_email
     FROM audit_events ae
     LEFT JOIN users u ON u.id = ae.actor_user_id
     WHERE ae.entity_type = 'event' AND ae.entity_id = ?
     ORDER BY ae.created_at DESC, ae.id DESC
     LIMIT 100"
);
$auditStmt->execute([$eventRef]);
$auditEvents = $auditStmt->fetchAll();

$timezoneOptions = [
    'America/Phoenix' => 'Arizona',
    'America/Los_Angeles' => 'Pacific',
    'America/Denver' => 'Mountain',
    'America/Chicago' => 'Central',
    'America/New_York' => 'Eastern',
];
$currentTimezone = (string)$event['timezone'];
if (!isset($timezoneOptions[$currentTimezone])) {
    $timezoneOptions = [$currentTimezone => $currentTimezone] + $timezoneOptions;
}
$startLocal = coveted_event_local_datetime($event)->format('Y-m-d\TH:i');
$endLocal = !empty($event['ends_at']) ? coveted_event_local_datetime($event, 'ends_at')->format('Y-m-d\TH:i') : '';
$statusTransitions = [
    'draft' => ['published' => 'Publish', 'cancelled' => 'Cancel Event'],
    'published' => ['closed' => 'Close RSVPs', 'completed' => 'Complete Event', 'cancelled' => 'Cancel Event'],
    'closed' => ['published' => 'Reopen RSVPs', 'completed' => 'Complete Event', 'cancelled' => 'Cancel Event'],
    'completed' => [],
    'cancelled' => [],
];
$formatTime = static function (?string $value, ?string $timezone = null): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }
    return coveted_utc_datetime($value)->setTimezone(coveted_timezone($timezone ?? ''))->format('M j, Y · g:i A');
};

coveted_page_start('Event Workspace', '', true);
coveted_admin_ui_start($admin, 'events', 'Event Workspace');
?>
<div class="cv-admin-event-workspace" data-admin-event-workspace>
    <div class="cv-admin-event-topline">
        <a class="cv-text-link" href="/admin/?view=events">← All Events</a>
        <div class="cv-admin-event-top-actions">
            <a class="cv-button cv-button-soft" href="/events.php">Member View</a>
            <a class="cv-button cv-button-soft" href="/host.php?event=<?= coveted_e(rawurlencode($eventRef)) ?>">Host / Check-in View</a>
        </div>
    </div>

    <header class="cv-admin-event-hero">
        <div>
            <div class="cv-tag-row">
                <span class="cv-status cv-admin-event-status is-<?= coveted_e((string)$event['status']) ?>"><?= coveted_e(ucfirst((string)$event['status'])) ?></span>
                <span class="cv-pill"><?= coveted_e(ucwords(str_replace('_', ' ', (string)$event['event_type']))) ?></span>
                <span class="cv-pill"><?= coveted_e(ucwords(str_replace('_', ' ', (string)$event['audience']))) ?></span>
            </div>
            <h1><?= coveted_e($event['title']) ?></h1>
            <p><?= coveted_e($event['group_name']) ?> · <?= coveted_e(coveted_event_format($event, 'D, M j · g:i A T')) ?></p>
        </div>
        <div class="cv-admin-event-hero-meta">
            <span>Event ID</span><strong><?= coveted_e($eventRef) ?></strong>
        </div>
    </header>

    <?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

    <section class="cv-admin-event-metrics" aria-label="Event summary">
        <div><strong><?= (int)$stats['invited'] ?></strong><span>Invited</span></div>
        <div><strong><?= (int)$stats['attending'] ?></strong><span>Attending</span></div>
        <div><strong><?= (int)$stats['waitlist'] ?></strong><span>Waitlist</span></div>
        <div><strong><?= (int)$stats['checked_in'] ?></strong><span>Checked in</span></div>
        <div><strong><?= (int)$stats['hosts'] ?></strong><span>Hosts</span></div>
        <div><strong><?= (int)$stats['campaigns'] ?></strong><span>Benefits</span></div>
    </section>

    <nav class="cv-admin-event-tabs" aria-label="Event workspace sections">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="<?= $tab === $key ? 'is-active' : '' ?>" href="/admin/event.php?event=<?= coveted_e(rawurlencode($eventRef)) ?>&amp;tab=<?= coveted_e($key) ?>"><?= coveted_e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($tab === 'overview'): ?>
        <div class="cv-admin-event-grid cv-admin-event-grid-main">
            <section class="cv-admin-panel">
                <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">EVENT CONFIGURATION</span><h2>Overview</h2></div></div>
                <?php if ($isFinal): ?>
                    <div class="cv-admin-empty"><strong>Configuration locked.</strong><span>Completed and cancelled events remain available for reporting and audit history.</span></div>
                <?php else: ?>
                    <form method="post" class="cv-form-grid">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_event">
                        <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                        <input type="hidden" name="tab" value="overview">
                        <label class="cv-form-span">Event title<input name="title" maxlength="190" value="<?= coveted_e($event['title']) ?>" required></label>
                        <label>Event type
                            <select name="event_type">
                                <?php foreach (['regular' => 'Regular gathering','mystery' => 'Mystery gathering','private_table' => 'Private table','member_plus_one' => 'Member +1','session' => 'Session'] as $value => $label): ?>
                                    <option value="<?= coveted_e($value) ?>" <?= $event['event_type'] === $value ? 'selected' : '' ?>><?= coveted_e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <?php if ($event['status'] === 'draft'): ?>
                            <label>Audience
                                <select name="audience">
                                    <option value="group" <?= $event['audience'] === 'group' ? 'selected' : '' ?>>Group</option>
                                    <option value="invitation_only" <?= $event['audience'] === 'invitation_only' ? 'selected' : '' ?>>Invitation only</option>
                                </select>
                            </label>
                        <?php else: ?>
                            <input type="hidden" name="audience" value="<?= coveted_e($event['audience']) ?>">
                            <label>Audience<input value="<?= coveted_e(ucwords(str_replace('_', ' ', (string)$event['audience']))) ?>" disabled></label>
                        <?php endif; ?>
                        <label>Starts<input type="datetime-local" name="starts_at" value="<?= coveted_e($startLocal) ?>" required></label>
                        <label>Ends<input type="datetime-local" name="ends_at" value="<?= coveted_e($endLocal) ?>"></label>
                        <label>Timezone
                            <select name="timezone">
                                <?php foreach ($timezoneOptions as $zone => $label): ?>
                                    <option value="<?= coveted_e($zone) ?>" <?= $zone === $currentTimezone ? 'selected' : '' ?>><?= coveted_e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Capacity<input type="number" name="capacity" min="1" step="1" value="<?= $event['capacity'] !== null ? (int)$event['capacity'] : '' ?>" placeholder="Unlimited"></label>
                        <label>Location visibility
                            <select name="location_visibility">
                                <?php foreach (['immediate' => 'Immediate','scheduled_reveal' => 'Scheduled reveal','host_only' => 'Host only'] as $value => $label): ?>
                                    <option value="<?= coveted_e($value) ?>" <?= $event['location_visibility'] === $value ? 'selected' : '' ?>><?= coveted_e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Allow +1
                            <select name="plus_one_allowed">
                                <option value="0" <?= empty($event['plus_one_allowed']) ? 'selected' : '' ?>>No</option>
                                <option value="1" <?= !empty($event['plus_one_allowed']) ? 'selected' : '' ?>>Yes · one guest</option>
                            </select>
                        </label>
                        <label class="cv-form-span">Description<textarea name="description" rows="5" maxlength="5000"><?= coveted_e((string)$event['description']) ?></textarea></label>
                        <div class="cv-form-span"><button class="cv-button cv-button-primary" type="submit">Save Event</button></div>
                    </form>
                <?php endif; ?>
            </section>

            <div class="cv-stack">
                <section class="cv-admin-panel">
                    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">STATUS</span><h2><?= coveted_e(ucfirst((string)$event['status'])) ?></h2></div></div>
                    <p><?= coveted_e(coveted_event_format($event, 'D, M j · g:i A T')) ?></p>
                    <?php if (!$isFinal && ($statusTransitions[(string)$event['status']] ?? [])): ?>
                        <div class="cv-action-row">
                            <?php foreach ($statusTransitions[(string)$event['status']] as $value => $label): ?>
                                <form method="post" data-confirm="<?= coveted_e($value === 'cancelled' ? 'Cancel this event? This cannot be undone.' : ($value === 'completed' ? 'Mark this event completed?' : 'Apply this event status change?')) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="set_status">
                                    <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                                    <input type="hidden" name="status" value="<?= coveted_e($value) ?>">
                                    <button class="cv-button <?= $value === 'cancelled' ? 'cv-button-soft' : 'cv-button-primary' ?>" type="submit"><?= coveted_e($label) ?></button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="cv-admin-panel">
                    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">AT A GLANCE</span><h2>Event setup</h2></div></div>
                    <dl class="cv-admin-event-definition-list">
                        <div><dt>Group</dt><dd><?= coveted_e($event['group_name']) ?></dd></div>
                        <div><dt>Location</dt><dd><?= coveted_e((string)($eventLocation['location_name'] ?? $eventLocation['private_location_label'] ?? 'Not assigned')) ?></dd></div>
                        <div><dt>Artists</dt><dd><?= (int)$stats['artists'] ?></dd></div>
                        <div><dt>Benefits</dt><dd><?= (int)$stats['campaigns'] ?> linked campaign<?= (int)$stats['campaigns'] === 1 ? '' : 's' ?></dd></div>
                        <div><dt>Created</dt><dd><?= coveted_e($formatTime((string)$event['created_at'])) ?></dd></div>
                        <div><dt>Updated</dt><dd><?= coveted_e($formatTime((string)$event['updated_at'])) ?></dd></div>
                    </dl>
                </section>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'guests'): ?>
        <div class="cv-admin-event-grid">
            <section class="cv-admin-panel">
                <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">INVITE</span><h2>Add a guest or member</h2></div></div>
                <?php if ($event['status'] === 'published' && coveted_event_is_future($event)): ?>
                    <form method="post" class="cv-form-grid">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="invite_user">
                        <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                        <label class="cv-form-span">Member email<input type="email" name="email" maxlength="255" required placeholder="member@example.com"></label>
                        <label>Invitation type
                            <select name="invite_type"><option value="member">Member</option><option value="guest">Guest</option><option value="plus_one">Plus one</option><option value="standby">Standby</option></select>
                        </label>
                        <div><button class="cv-button cv-button-primary" type="submit">Send Invitation</button></div>
                    </form>
                <?php else: ?>
                    <div class="cv-admin-empty"><strong>Invitations are closed.</strong><span>Publish a future event before sending invitations.</span></div>
                <?php endif; ?>
            </section>
            <section class="cv-admin-panel cv-admin-event-callout">
                <span class="cv-eyebrow">GUEST PIPELINE</span>
                <h2><?= count($eventPeople) ?> people connected to this event</h2>
                <p>Invitation, RSVP and attendance states stay separate so Admin can see exactly where every guest is in the event lifecycle.</p>
            </section>
        </div>

        <div class="cv-admin-table-card cv-admin-event-table-card">
            <div class="cv-admin-table-head"><strong>Guest list</strong><span>Invitation → RSVP → attendance</span></div>
            <div class="cv-admin-table-scroll">
                <table class="cv-admin-table">
                    <thead><tr><th>Person</th><th>Invite</th><th>RSVP</th><th>Guest</th><th>Attendance</th></tr></thead>
                    <tbody>
                    <?php if (!$eventPeople): ?><tr><td colspan="5"><div class="cv-admin-empty"><strong>No guests yet.</strong><span>Send the first invitation above.</span></div></td></tr><?php endif; ?>
                    <?php foreach ($eventPeople as $person): ?>
                        <tr>
                            <td><strong><?= coveted_e($person['display_name']) ?></strong><small><?= coveted_e($person['email']) ?></small></td>
                            <td><?= $person['invitation_status'] ? '<span class="cv-status">' . coveted_e(ucfirst((string)$person['invitation_status'])) . '</span>' : '—' ?></td>
                            <td><?= coveted_e($person['response'] ? ucfirst((string)$person['response']) : '—') ?></td>
                            <td><?= (int)($person['guest_count'] ?? 0) ?></td>
                            <td><?= coveted_e($person['attendance_status'] ? ucwords(str_replace('_', ' ', (string)$person['attendance_status'])) : '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'hosts'): ?>
        <div class="cv-admin-event-grid">
            <section class="cv-admin-panel">
                <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">ASSIGN HOST</span><h2>Event-day team</h2></div></div>
                <?php if (!$isFinal): ?>
                    <form method="post" class="cv-form-grid">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="assign_host">
                        <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                        <label class="cv-form-span">Member email<input type="email" name="email" maxlength="255" required placeholder="host@example.com"></label>
                        <label>Role
                            <select name="host_role"><option value="lead">Lead host</option><option value="cohost">Cohost</option><option value="checkin">Check-in only</option></select>
                        </label>
                        <div><button class="cv-button cv-button-primary" type="submit">Assign Host</button></div>
                    </form>
                    <p class="cv-form-help">Lead and cohost roles require Attendee Host approval. Check-in access can be assigned to an active account without giving event configuration authority.</p>
                <?php else: ?>
                    <div class="cv-admin-empty"><strong>Host assignments locked.</strong><span>This event is final.</span></div>
                <?php endif; ?>
            </section>
            <section class="cv-admin-panel cv-admin-event-callout">
                <span class="cv-eyebrow">AUTHORITY</span><h2>Admin configures. Hosts operate.</h2><p>Hosts can support invitations and attendance through the Host / Check-in view. Event details, location, artists, benefits and mystery configuration remain System Admin responsibilities.</p>
            </section>
        </div>

        <div class="cv-stack cv-admin-event-list">
            <?php if (!$eventHosts): ?><div class="cv-admin-panel cv-admin-empty"><strong>No hosts assigned.</strong><span>Assign an event-day lead, cohost or check-in user above.</span></div><?php endif; ?>
            <?php foreach ($eventHosts as $host): ?>
                <article class="cv-admin-panel cv-admin-event-row">
                    <div><span class="cv-status"><?= coveted_e(ucwords(str_replace('_', ' ', (string)$host['host_role']))) ?></span><h3><?= coveted_e($host['display_name']) ?></h3><p><?= coveted_e($host['email']) ?></p></div>
                    <?php if (!$isFinal): ?>
                        <form method="post" data-confirm="Remove this host assignment?">
                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                            <input type="hidden" name="action" value="remove_host">
                            <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                            <input type="hidden" name="user_id" value="<?= (int)$host['user_id'] ?>">
                            <button class="cv-button cv-button-soft" type="submit">Remove</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'location'): ?>
        <div class="cv-admin-event-grid cv-admin-event-grid-main">
            <section class="cv-admin-panel">
                <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">LOCATION</span><h2>Venue & reveal details</h2></div></div>
                <?php if (!$isFinal): ?>
                    <form method="post" class="cv-form-grid">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="set_location">
                        <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                        <label class="cv-form-span">Coveted business location
                            <select name="location_id">
                                <option value="0">Use a private location label instead</option>
                                <?php foreach ($locationOptions as $option): ?>
                                    <option value="<?= (int)$option['id'] ?>" <?= (int)($eventLocation['location_id'] ?? 0) === (int)$option['id'] ? 'selected' : '' ?>><?= coveted_e($option['business_name']) ?> · <?= coveted_e($option['name']) ?><?= $option['city'] ? ' · ' . coveted_e($option['city']) : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="cv-form-span">Private location label<input name="private_location_label" maxlength="255" value="<?= coveted_e((string)($eventLocation['private_location_label'] ?? '')) ?>" placeholder="Private residence, secret venue, meetup point"></label>
                        <label class="cv-form-span">Internal / reveal notes<textarea name="reveal_notes" rows="4" maxlength="5000"><?= coveted_e((string)($eventLocation['reveal_notes'] ?? '')) ?></textarea></label>
                        <div class="cv-form-span"><button class="cv-button cv-button-primary" type="submit">Save Location</button></div>
                    </form>
                <?php else: ?>
                    <div class="cv-admin-empty"><strong>Location locked.</strong><span>This event is final.</span></div>
                <?php endif; ?>
            </section>
            <section class="cv-admin-panel">
                <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">CURRENT LOCATION</span><h2><?= coveted_e((string)($eventLocation['location_name'] ?? $eventLocation['private_location_label'] ?? 'Not assigned')) ?></h2></div></div>
                <?php if (!empty($eventLocation['location_name'])): ?>
                    <p><strong><?= coveted_e((string)$eventLocation['business_name']) ?></strong></p>
                    <p><?= coveted_e(trim(implode(', ', array_filter([(string)($eventLocation['address1'] ?? ''), (string)($eventLocation['city'] ?? ''), (string)($eventLocation['region'] ?? ''), (string)($eventLocation['postal_code'] ?? '')])))) ?></p>
                <?php elseif (!empty($eventLocation['private_location_label'])): ?>
                    <p>Private location record. Public visibility follows the event's <?= coveted_e(str_replace('_', ' ', (string)$event['location_visibility'])) ?> setting.</p>
                <?php else: ?>
                    <p>No location has been assigned.</p>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'artist'): ?>
        <div class="cv-admin-event-grid">
            <section class="cv-admin-panel">
                <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">ARTIST</span><h2>Add to the lineup</h2></div></div>
                <?php if (!$isFinal && $artistOptions): ?>
                    <form method="post" class="cv-form-grid">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="set_artist">
                        <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                        <label class="cv-form-span">Artist
                            <select name="artist_id" required><?php foreach ($artistOptions as $option): ?><option value="<?= (int)$option['id'] ?>"><?= coveted_e($option['artist_name']) ?></option><?php endforeach; ?></select>
                        </label>
                        <label>Appearance
                            <select name="appearance_type"><option value="featured">Featured</option><option value="support">Support</option><option value="dj">DJ</option><option value="session">Session</option><option value="mystery">Mystery</option></select>
                        </label>
                        <div><button class="cv-button cv-button-primary" type="submit">Add Artist</button></div>
                    </form>
                <?php elseif (!$artistOptions): ?>
                    <div class="cv-admin-empty"><strong>No active artists.</strong><span>Create or reactivate an artist profile first.</span></div>
                <?php else: ?>
                    <div class="cv-admin-empty"><strong>Lineup locked.</strong><span>This event is final.</span></div>
                <?php endif; ?>
            </section>
            <section class="cv-admin-panel cv-admin-event-callout"><span class="cv-eyebrow">MEDIA + REWARDS</span><h2>Artist relationships travel with the event.</h2><p>Once an artist is attached, eligible artist-owned campaigns can be linked from Benefits.</p></section>
        </div>

        <div class="cv-stack cv-admin-event-list">
            <?php if (!$eventArtists): ?><div class="cv-admin-panel cv-admin-empty"><strong>No artist lineup yet.</strong></div><?php endif; ?>
            <?php foreach ($eventArtists as $artist): ?>
                <article class="cv-admin-panel cv-admin-event-row">
                    <div><span class="cv-status"><?= coveted_e(ucfirst((string)$artist['appearance_type'])) ?></span><h3><?= coveted_e($artist['artist_name']) ?></h3><a class="cv-text-link" href="/artist.php?artist=<?= coveted_e(rawurlencode((string)$artist['public_id'])) ?>">Open Artist →</a></div>
                    <?php if (!$isFinal): ?>
                        <form method="post" data-confirm="Remove this artist from the event?">
                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="remove_artist"><input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>"><input type="hidden" name="artist_id" value="<?= (int)$artist['artist_id'] ?>"><button class="cv-button cv-button-soft" type="submit">Remove</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'benefits'): ?>
        <div class="cv-admin-event-grid">
            <section class="cv-admin-panel">
                <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">LINK BENEFIT</span><h2>Campaigns for this event</h2></div></div>
                <?php if (!$isFinal && $availableCampaigns): ?>
                    <form method="post" class="cv-form-grid">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="link_campaign"><input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                        <label class="cv-form-span">Eligible campaign
                            <select name="campaign_ref" required><?php foreach ($availableCampaigns as $campaign): ?><option value="<?= coveted_e($campaign['public_id']) ?>"><?= coveted_e($campaign['owner_name']) ?> · <?= coveted_e($campaign['title']) ?> → <?= coveted_e($campaign['reward_title']) ?> · <?= coveted_e($campaign['status']) ?></option><?php endforeach; ?></select>
                        </label>
                        <div><button class="cv-button cv-button-primary" type="submit">Link Campaign</button></div>
                    </form>
                <?php elseif (!$availableCampaigns): ?>
                    <div class="cv-admin-empty"><strong>No additional eligible campaigns.</strong><span>Create campaigns under this event's group, location business or attached artists.</span></div>
                <?php else: ?>
                    <div class="cv-admin-empty"><strong>Benefits locked.</strong><span>This event is final.</span></div>
                <?php endif; ?>
            </section>
            <section class="cv-admin-panel cv-admin-event-callout"><span class="cv-eyebrow">DISTRIBUTION</span><h2>Link here. Send from Distribution.</h2><p>Campaign configuration remains with its owner. The event workspace controls which eligible campaigns belong to this gathering.</p><a class="cv-button cv-button-soft" href="/admin/?view=distribution&amp;event_id=<?= $eventId ?>">Open Distribution</a></section>
        </div>

        <div class="cv-stack cv-admin-event-list">
            <?php if (!$linkedCampaigns): ?><div class="cv-admin-panel cv-admin-empty"><strong>No benefits linked.</strong><span>Link a reward campaign above.</span></div><?php endif; ?>
            <?php foreach ($linkedCampaigns as $campaign): ?>
                <article class="cv-admin-panel cv-admin-event-row">
                    <div><div class="cv-tag-row"><span class="cv-status"><?= coveted_e(ucfirst((string)$campaign['status'])) ?></span><span class="cv-pill"><?= coveted_e(ucwords(str_replace('_', ' ', (string)$campaign['owner_type']))) ?></span></div><h3><?= coveted_e($campaign['title']) ?></h3><p><?= coveted_e($campaign['owner_name']) ?> · <?= coveted_e($campaign['reward_title']) ?> · <?= coveted_e(str_replace('_', ' ', (string)$campaign['trigger_key'])) ?></p></div>
                    <?php if (!$isFinal): ?>
                        <form method="post" data-confirm="Unlink this benefit campaign from the event?">
                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="unlink_campaign"><input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>"><input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>"><button class="cv-button cv-button-soft" type="submit">Unlink</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'mystery'): ?>
        <div class="cv-admin-event-grid">
            <section class="cv-admin-panel">
                <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">MYSTERY REVEAL</span><h2>Schedule information</h2></div></div>
                <?php if (!$isFinal): ?>
                    <form method="post" class="cv-form-grid">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="add_reveal"><input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                        <label>Reveal at<input type="datetime-local" name="reveal_at" max="<?= coveted_e($startLocal) ?>" required></label>
                        <label>Type<select name="reveal_type"><option value="area">Area</option><option value="experience">Experience</option><option value="instructions">Instructions</option><option value="location">Location</option><option value="artist">Artist</option><option value="custom">Custom</option></select></label>
                        <label class="cv-form-span">Title<input name="reveal_title" maxlength="180"></label>
                        <label class="cv-form-span">Reveal content<textarea name="reveal_content" rows="5" maxlength="10000" required></textarea></label>
                        <div class="cv-form-span"><button class="cv-button cv-button-primary" type="submit">Add Reveal</button></div>
                    </form>
                <?php else: ?>
                    <div class="cv-admin-empty"><strong>Reveals locked.</strong><span>This event is final.</span></div>
                <?php endif; ?>
            </section>
            <section class="cv-admin-panel cv-admin-event-callout"><span class="cv-eyebrow">VISIBILITY</span><h2><?= coveted_e(ucwords(str_replace('_', ' ', (string)$event['location_visibility']))) ?></h2><p>Reveals may occur before or at event start. Already-visible reveals are preserved as part of the event history.</p></section>
        </div>

        <div class="cv-stack cv-admin-event-list">
            <?php if (!$eventReveals): ?><div class="cv-admin-panel cv-admin-empty"><strong>No mystery reveals scheduled.</strong></div><?php endif; ?>
            <?php foreach ($eventReveals as $reveal): ?>
                <?php $isPastReveal = coveted_utc_datetime((string)$reveal['reveal_at'])->getTimestamp() <= time(); ?>
                <article class="cv-admin-panel cv-admin-event-row cv-admin-event-reveal-row">
                    <div><div class="cv-tag-row"><span class="cv-status"><?= coveted_e(ucwords(str_replace('_', ' ', (string)$reveal['reveal_type']))) ?></span><span class="cv-pill"><?= $isPastReveal ? 'Visible' : 'Scheduled' ?></span></div><h3><?= coveted_e((string)($reveal['title'] ?: 'Untitled reveal')) ?></h3><p><?= coveted_e($formatTime((string)$reveal['reveal_at'], (string)$event['timezone'])) ?></p><p><?= nl2br(coveted_e((string)$reveal['content'])) ?></p></div>
                    <?php if (!$isFinal && !$isPastReveal): ?>
                        <form method="post" data-confirm="Remove this scheduled reveal?">
                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="remove_reveal"><input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>"><input type="hidden" name="reveal_id" value="<?= (int)$reveal['id'] ?>"><button class="cv-button cv-button-soft" type="submit">Remove</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'attendance'): ?>
        <div class="cv-admin-event-grid">
            <section class="cv-admin-panel">
                <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">ATTENDANCE</span><h2>Record event-day status</h2></div></div>
                <?php if (!in_array((string)$event['status'], ['draft', 'cancelled'], true)): ?>
                    <form method="post" class="cv-form-grid">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="record_attendance"><input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                        <label class="cv-form-span">Member email<input type="email" name="email" maxlength="255" required></label>
                        <label>Status<select name="attendance_status"><option value="checked_in">Checked in</option><option value="attended">Attended</option><option value="left_early">Left early</option><option value="no_show">No show</option></select></label>
                        <div><button class="cv-button cv-button-primary" type="submit">Update Attendance</button></div>
                    </form>
                <?php else: ?>
                    <div class="cv-admin-empty"><strong>Attendance unavailable.</strong><span>Draft and cancelled events cannot record attendance.</span></div>
                <?php endif; ?>
            </section>
            <section class="cv-admin-panel cv-admin-event-callout"><span class="cv-eyebrow">HOST VIEW</span><h2>Use the event-day interface when doors open.</h2><p>Hosts can check in eligible participants without receiving event configuration permissions.</p><a class="cv-button cv-button-soft" href="/host.php?event=<?= coveted_e(rawurlencode($eventRef)) ?>&amp;tab=people">Open Host / Check-in View</a></section>
        </div>

        <div class="cv-admin-table-card cv-admin-event-table-card">
            <div class="cv-admin-table-head"><strong>Attendance roster</strong><span><?= (int)$stats['checked_in'] ?> checked in / attended</span></div>
            <div class="cv-admin-table-scroll"><table class="cv-admin-table"><thead><tr><th>Person</th><th>RSVP</th><th>Attendance</th><th>Checked in</th></tr></thead><tbody>
                <?php if (!$eventPeople): ?><tr><td colspan="4"><div class="cv-admin-empty"><strong>No participants yet.</strong></div></td></tr><?php endif; ?>
                <?php foreach ($eventPeople as $person): ?><tr><td><strong><?= coveted_e($person['display_name']) ?></strong><small><?= coveted_e($person['email']) ?></small></td><td><?= coveted_e($person['response'] ? ucfirst((string)$person['response']) : '—') ?></td><td><?= coveted_e($person['attendance_status'] ? ucwords(str_replace('_', ' ', (string)$person['attendance_status'])) : '—') ?></td><td><?= coveted_e($person['checked_in_at'] ? $formatTime((string)$person['checked_in_at'], (string)$event['timezone']) : '—') ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'activity'): ?>
        <section class="cv-admin-panel">
            <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">AUDIT TRAIL</span><h2>Event activity</h2></div><span class="cv-pill"><?= count($auditEvents) ?> shown</span></div>
            <div class="cv-admin-event-timeline">
                <?php if (!$auditEvents): ?><div class="cv-admin-empty"><strong>No audit events yet.</strong></div><?php endif; ?>
                <?php foreach ($auditEvents as $audit): ?>
                    <?php
                    $meta = json_decode((string)($audit['metadata_json'] ?? ''), true);
                    $metaText = is_array($meta) && $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
                    if (is_string($metaText) && mb_strlen($metaText) > 300) {
                        $metaText = mb_substr($metaText, 0, 297) . '…';
                    }
                    ?>
                    <article>
                        <span class="cv-admin-event-timeline-dot" aria-hidden="true"></span>
                        <div><span class="cv-eyebrow"><?= coveted_e(strtoupper(str_replace(['.', '_'], ' ', (string)$audit['event_type']))) ?></span><h3><?= coveted_e($audit['actor_name'] ?: 'System') ?></h3><p><?= coveted_e($formatTime((string)$audit['created_at'])) ?></p><?php if ($metaText): ?><code><?= coveted_e($metaText) ?></code><?php endif; ?></div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
