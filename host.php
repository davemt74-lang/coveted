<?php
declare(strict_types=1);

require_once __DIR__ . '/app/event_management.php';

$user = coveted_require_user();
$isSystemAdmin = coveted_is_system_admin($user);
$hasHostApproval = coveted_event_actor_has_host_approval($user);
$assignmentStmt = coveted_db()->prepare('SELECT 1 FROM event_hosts WHERE user_id = ? LIMIT 1');
$assignmentStmt->execute([(int)$user['id']]);
$hasEventAssignment = (bool)$assignmentStmt->fetchColumn();

if (!$hasHostApproval && !$hasEventAssignment) {
    http_response_code(403);
    coveted_page_start('Host Workspace', 'Events');
    ?>
    <section class="cv-page-heading">
        <span class="cv-eyebrow">HOST WORKSPACE</span>
        <h1>Host approval required.</h1>
        <p>Coveted Attendee Host approval is required before you can manage assigned gatherings.</p>
    </section>
    <div class="cv-card cv-empty">
        <h2>Want to host?</h2>
        <p>Request Attendee Host access from your Groups workspace.</p>
        <a class="cv-button" href="/groups.php">Open Groups</a>
    </div>
    <?php
    coveted_page_end();
    exit;
}

function coveted_host_local_to_utc(string $value, string $timezone): string
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

function coveted_host_event_input(array $source): array
{
    $timezone = trim((string)($source['timezone'] ?? 'America/Phoenix'));
    $startsAt = coveted_host_local_to_utc((string)($source['starts_at'] ?? ''), $timezone);
    $endsRaw = trim((string)($source['ends_at'] ?? ''));

    return [
        'title' => (string)($source['title'] ?? ''),
        'description' => (string)($source['description'] ?? ''),
        'event_type' => (string)($source['event_type'] ?? 'regular'),
        'audience' => (string)($source['audience'] ?? 'group'),
        'timezone' => $timezone,
        'starts_at' => $startsAt,
        'ends_at' => $endsRaw !== '' ? coveted_host_local_to_utc($endsRaw, $timezone) : '',
        'capacity' => (string)($source['capacity'] ?? ''),
        'plus_one_allowed' => !empty($source['plus_one_allowed']) ? 1 : 0,
        'location_visibility' => (string)($source['location_visibility'] ?? 'immediate'),
    ];
}

function coveted_host_user_id_from_email(string $email): int
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

$pdo = coveted_db();
$error = '';
$noticeMap = [
    'created' => 'Event created.',
    'updated' => 'Event details updated.',
    'status' => 'Event status updated.',
    'location' => 'Event location updated.',
    'artist' => 'Artist lineup updated.',
    'invite' => 'Invitation sent.',
    'reveal' => 'Mystery reveal added.',
    'host' => 'Event host assignment updated.',
    'attendance' => 'Attendance updated.',
];
$noticeKey = trim((string)($_GET['saved'] ?? ''));
$notice = $noticeMap[$noticeKey] ?? '';
$eventRef = trim((string)($_GET['event'] ?? $_POST['event_ref'] ?? ''));
$tab = strtolower(trim((string)($_GET['tab'] ?? $_POST['tab'] ?? 'overview')));
if (!in_array($tab, ['overview', 'people', 'experience', 'mystery'], true)) {
    $tab = 'overview';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        $action = trim((string)($_POST['action'] ?? ''));
        $adminOnlyActions = [
            'update_event', 'set_status', 'set_location', 'set_artist',
            'remove_artist', 'assign_host', 'add_reveal',
        ];
        if (!$isSystemAdmin && in_array($action, $adminOnlyActions, true)) {
            throw new InvalidArgumentException('Event configuration is controlled by Coveted System Admin.');
        }

        if ($eventRef === '') {
            throw new InvalidArgumentException('Choose an event first.');
        }

        if ($action === 'update_event') {
            coveted_event_update($user, $eventRef, coveted_host_event_input($_POST));
            coveted_redirect('/host.php?event=' . rawurlencode($eventRef) . '&tab=overview&saved=updated');
        }

        if ($action === 'set_status') {
            coveted_event_set_status($user, $eventRef, (string)($_POST['status'] ?? ''));
            coveted_redirect('/host.php?event=' . rawurlencode($eventRef) . '&tab=overview&saved=status');
        }

        if ($action === 'set_location') {
            $locationId = (int)($_POST['location_id'] ?? 0);
            coveted_event_set_location(
                $user,
                $eventRef,
                $locationId > 0 ? $locationId : null,
                (string)($_POST['private_location_label'] ?? ''),
                (string)($_POST['reveal_notes'] ?? '')
            );
            coveted_redirect('/host.php?event=' . rawurlencode($eventRef) . '&tab=overview&saved=location');
        }

        if ($action === 'set_artist') {
            coveted_event_set_artist(
                $user,
                $eventRef,
                (int)($_POST['artist_id'] ?? 0),
                (string)($_POST['appearance_type'] ?? 'featured')
            );
            coveted_redirect('/host.php?event=' . rawurlencode($eventRef) . '&tab=experience&saved=artist');
        }

        if ($action === 'remove_artist') {
            coveted_event_remove_artist($user, $eventRef, (int)($_POST['artist_id'] ?? 0));
            coveted_redirect('/host.php?event=' . rawurlencode($eventRef) . '&tab=experience&saved=artist');
        }

        if ($action === 'invite_user') {
            $targetId = coveted_host_user_id_from_email((string)($_POST['email'] ?? ''));
            coveted_event_invite_user($user, $eventRef, $targetId, (string)($_POST['invite_type'] ?? 'member'));
            coveted_redirect('/host.php?event=' . rawurlencode($eventRef) . '&tab=people&saved=invite');
        }

        if ($action === 'assign_host') {
            $targetId = coveted_host_user_id_from_email((string)($_POST['email'] ?? ''));
            coveted_event_assign_host($user, $eventRef, $targetId, (string)($_POST['host_role'] ?? 'checkin'));
            coveted_redirect('/host.php?event=' . rawurlencode($eventRef) . '&tab=people&saved=host');
        }

        if ($action === 'record_attendance') {
            $targetId = coveted_host_user_id_from_email((string)($_POST['email'] ?? ''));
            coveted_event_record_attendance($user, $eventRef, $targetId, (string)($_POST['attendance_status'] ?? 'checked_in'));
            coveted_redirect('/host.php?event=' . rawurlencode($eventRef) . '&tab=people&saved=attendance');
        }

        if ($action === 'add_reveal') {
            $eventForReveal = coveted_event_by_ref($eventRef);
            if (!$eventForReveal || !coveted_event_can_manage($eventForReveal, $user)) {
                throw new InvalidArgumentException('You cannot manage this event.');
            }

            $revealAt = coveted_host_local_to_utc(
                (string)($_POST['reveal_at'] ?? ''),
                (string)$eventForReveal['timezone']
            );
            coveted_event_add_mystery_reveal(
                $user,
                $eventRef,
                $revealAt,
                (string)($_POST['reveal_type'] ?? 'custom'),
                (string)($_POST['reveal_title'] ?? ''),
                (string)($_POST['reveal_content'] ?? '')
            );
            coveted_redirect('/host.php?event=' . rawurlencode($eventRef) . '&tab=mystery&saved=reveal');
        }

        throw new InvalidArgumentException('Unsupported host action.');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted Host Workspace error: ' . $e->getMessage());
        $error = 'Unable to update the event right now.';
    }
}

$hostWorkspaceEvents = coveted_events_for_user($user, 250);
$manageableEvents = array_values(array_filter(
    $hostWorkspaceEvents,
    static function (array $event) use ($user, $isSystemAdmin): bool {
        return $isSystemAdmin
            || coveted_event_assigned_host_role((int)$event['id'], (int)$user['id']) !== null;
    }
));

$selectedEvent = null;
$eventLocation = null;
$eventArtists = [];
$eventReveals = [];
$eventHosts = [];
$eventPeople = [];
$stats = ['attending' => 0, 'waitlist' => 0, 'attendance' => 0];

if ($eventRef !== '') {
    $selectedEvent = coveted_event_by_ref($eventRef);
    $assignedHostRole = $selectedEvent && !$isSystemAdmin
        ? coveted_event_assigned_host_role((int)$selectedEvent['id'], (int)$user['id'])
        : ($isSystemAdmin ? 'system_admin' : null);
    if (!$selectedEvent || (!$isSystemAdmin && $assignedHostRole === null)) {
        http_response_code(404);
        $error = 'Event not found or you are no longer assigned to this gathering.';
        $selectedEvent = null;
        $eventRef = '';
    }
}

$isFinalEvent = $selectedEvent && in_array((string)$selectedEvent['status'], ['completed', 'cancelled'], true);
$canInvite = $selectedEvent
    && coveted_event_can_manage($selectedEvent, $user)
    && $selectedEvent['status'] === 'published'
    && coveted_event_is_future($selectedEvent);
$canRecordAttendance = $selectedEvent
    && coveted_event_can_checkin($selectedEvent, $user)
    && !in_array((string)$selectedEvent['status'], ['draft', 'cancelled'], true);
$canConfigure = $selectedEvent && $isSystemAdmin && !$isFinalEvent;

if ($selectedEvent) {
    $eventId = (int)$selectedEvent['id'];

    $statStmt = $pdo->prepare(
        "SELECT
            (SELECT COUNT(*) FROM event_rsvps WHERE event_id = ? AND response = 'attending') AS attending,
            (SELECT COUNT(*) FROM event_rsvps WHERE event_id = ? AND response = 'waitlist') AS waitlist,
            (SELECT COUNT(*) FROM event_attendance WHERE event_id = ? AND status IN ('checked_in','attended','left_early')) AS attendance"
    );
    $statStmt->execute([$eventId, $eventId, $eventId]);
    $stats = $statStmt->fetch() ?: $stats;

    if ($tab === 'overview') {
        $locationStmt = $pdo->prepare(
            "SELECT el.*, l.name AS location_name, l.city AS location_city, b.name AS business_name
             FROM event_locations el
             LEFT JOIN locations l ON l.id = el.location_id
             LEFT JOIN businesses b ON b.id = l.business_id
             WHERE el.event_id = ? LIMIT 1"
        );
        $locationStmt->execute([$eventId]);
        $eventLocation = $locationStmt->fetch() ?: null;
    }

    if ($tab === 'people') {
        $hostStmt = $pdo->prepare(
            "SELECT eh.user_id, eh.host_role, u.display_name, u.email
             FROM event_hosts eh
             JOIN users u ON u.id = eh.user_id
             WHERE eh.event_id = ?
             ORDER BY FIELD(eh.host_role, 'lead','cohost','checkin'), u.display_name"
        );
        $hostStmt->execute([$eventId]);
        $eventHosts = $hostStmt->fetchAll();

        $peopleStmt = $pdo->prepare(
            "SELECT DISTINCT
                u.id, u.display_name, u.email,
                er.response, er.guest_count,
                ei.status AS invitation_status,
                ea.status AS attendance_status,
                ea.checked_in_at
             FROM users u
             LEFT JOIN event_rsvps er ON er.user_id = u.id AND er.event_id = ?
             LEFT JOIN event_invitations ei ON ei.user_id = u.id AND ei.event_id = ?
             LEFT JOIN event_attendance ea ON ea.user_id = u.id AND ea.event_id = ?
             WHERE er.id IS NOT NULL OR ei.id IS NOT NULL OR ea.id IS NOT NULL
             ORDER BY u.display_name, u.id"
        );
        $peopleStmt->execute([$eventId, $eventId, $eventId]);
        $eventPeople = $peopleStmt->fetchAll();
    }

    if ($tab === 'experience') {
        $artistStmt = $pdo->prepare(
            "SELECT ea.artist_id, ea.appearance_type, ap.artist_name, ap.public_id
             FROM event_artists ea
             JOIN artist_profiles ap ON ap.id = ea.artist_id
             WHERE ea.event_id = ?
             ORDER BY ap.artist_name"
        );
        $artistStmt->execute([$eventId]);
        $eventArtists = $artistStmt->fetchAll();
    }

    if ($tab === 'mystery') {
        $revealStmt = $pdo->prepare(
            "SELECT id, reveal_at, reveal_type, title, content, notified_at
             FROM event_mystery_reveals
             WHERE event_id = ?
             ORDER BY reveal_at, id"
        );
        $revealStmt->execute([$eventId]);
        $eventReveals = $revealStmt->fetchAll();
    }
}

$locationOptions = [];
if ($selectedEvent && $tab === 'overview' && $canConfigure) {
    $locationOptions = $pdo->query(
        "SELECT l.id, l.name, l.city, b.name AS business_name
         FROM locations l
         JOIN businesses b ON b.id = l.business_id
         WHERE l.status = 'active' AND b.status = 'active'
         ORDER BY b.name, l.name"
    )->fetchAll();
}

$artistOptions = [];
if ($selectedEvent && $tab === 'experience' && $canConfigure) {
    $artistOptions = $pdo->query(
        "SELECT id, public_id, artist_name
         FROM artist_profiles
         WHERE status = 'active'
         ORDER BY artist_name, id"
    )->fetchAll();
}

$timezoneOptions = [
    'America/Phoenix' => 'Arizona',
    'America/Los_Angeles' => 'Pacific',
    'America/Denver' => 'Mountain',
    'America/Chicago' => 'Central',
    'America/New_York' => 'Eastern',
];

coveted_page_start('Host Workspace', 'Events');
?>
<section class="cv-page-heading">
    <span class="cv-eyebrow">HOST WORKSPACE</span>
    <h1><?= $selectedEvent ? coveted_e($selectedEvent['title']) : 'Assigned gatherings.' ?></h1>
    <p><?= $selectedEvent
        ? ($isSystemAdmin ? 'Configure the gathering and coordinate its assigned host team.' : 'Support the guest list and event-day experience. Coveted Admin controls the event setup.')
        : ($isSystemAdmin ? 'Choose an event to configure, or create one from Admin Events.' : 'Your assigned gatherings appear here when Coveted Admin adds you to the host team.') ?></p>
</section>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<div class="cv-admin-shell">
    <aside class="cv-admin-nav" aria-label="Host events">
        <span class="cv-eyebrow">HOST</span>
        <h2>Assignments</h2>
        <?php foreach ($manageableEvents as $managed): ?>
            <a class="<?= $selectedEvent && (int)$selectedEvent['id'] === (int)$managed['id'] ? 'is-active' : '' ?>" href="/host.php?event=<?= coveted_e($managed['public_id']) ?>">
                <?= coveted_e($managed['title']) ?>
                <span><?= coveted_e(coveted_event_format($managed, 'M j')) ?></span>
            </a>
        <?php endforeach; ?>
    </aside>

    <div class="cv-admin-content">
        <?php if (!$selectedEvent): ?>
            <div class="cv-card cv-empty">
                <?php if ($isSystemAdmin): ?>
                    <h2>Choose an event to manage.</h2>
                    <p>System Admin creates events and assigns the host team from the Admin Events workflow.</p>
                    <a class="cv-button" href="/admin/?view=events">Open Admin Events</a>
                <?php else: ?>
                    <h2>No assigned events yet.</h2>
                    <p>Coveted Admin creates each gathering and assigns Attendee Hosts when event-day support is needed.</p>
                    <a class="cv-button" href="/events.php">Open My Events</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php
            $startLocal = coveted_event_local_datetime($selectedEvent)->format('Y-m-d\TH:i');
            $endLocal = !empty($selectedEvent['ends_at'])
                ? coveted_event_local_datetime($selectedEvent, 'ends_at')->format('Y-m-d\TH:i')
                : '';
            $currentTimezone = (string)$selectedEvent['timezone'];
            if (!isset($timezoneOptions[$currentTimezone])) {
                $timezoneOptions = [$currentTimezone => $currentTimezone] + $timezoneOptions;
            }
            $statusTransitions = [
                'draft' => ['published' => 'Publish', 'cancelled' => 'Cancel event'],
                'published' => ['closed' => 'Close RSVPs', 'completed' => 'Complete event', 'cancelled' => 'Cancel event'],
                'closed' => ['published' => 'Reopen RSVPs', 'completed' => 'Complete event', 'cancelled' => 'Cancel event'],
                'completed' => [],
                'cancelled' => [],
            ];
            ?>

            <section class="cv-stat-grid">
                <div class="cv-card cv-stat"><strong><?= (int)$stats['attending'] ?></strong><span>Attending</span></div>
                <div class="cv-card cv-stat"><strong><?= (int)$stats['waitlist'] ?></strong><span>Waitlist</span></div>
                <div class="cv-card cv-stat"><strong><?= (int)$stats['attendance'] ?></strong><span>Checked in</span></div>
            </section>

            <nav class="cv-tab-row" aria-label="Event host sections">
                <?php foreach (['overview' => 'Overview', 'people' => 'People', 'experience' => 'Experience', 'mystery' => 'Mystery'] as $key => $label): ?>
                    <a class="cv-tab <?= $tab === $key ? 'is-active' : '' ?>" href="/host.php?event=<?= coveted_e($eventRef) ?>&amp;tab=<?= coveted_e($key) ?>"><?= coveted_e($label) ?></a>
                <?php endforeach; ?>
                <a class="cv-tab" href="/events.php">Member view</a>
            </nav>

            <?php if ($tab === 'overview'): ?>
                <div class="cv-workspace-grid">
                    <?php if ($canConfigure): ?>
                        <form class="cv-card cv-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                            <input type="hidden" name="action" value="update_event">
                            <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                            <span class="cv-eyebrow">DETAILS</span>
                            <h2>Event setup</h2>

                            <label>Title<input name="title" maxlength="190" value="<?= coveted_e($selectedEvent['title']) ?>" required></label>
                            <label>Description<textarea name="description" rows="4" maxlength="5000"><?= coveted_e((string)$selectedEvent['description']) ?></textarea></label>

                            <div class="cv-form-row">
                                <label>Event type
                                    <select name="event_type">
                                        <?php foreach (['regular' => 'Regular gathering','mystery' => 'Mystery gathering','private_table' => 'Private table','member_plus_one' => 'Member +1','session' => 'Coveted Session'] as $value => $label): ?>
                                            <option value="<?= coveted_e($value) ?>" <?= $selectedEvent['event_type'] === $value ? 'selected' : '' ?>><?= coveted_e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <?php if ($selectedEvent['status'] === 'draft'): ?>
                                    <label>Audience
                                        <select name="audience">
                                            <option value="group" <?= $selectedEvent['audience'] === 'group' ? 'selected' : '' ?>>Group</option>
                                            <option value="invitation_only" <?= $selectedEvent['audience'] === 'invitation_only' ? 'selected' : '' ?>>Invitation only</option>
                                        </select>
                                    </label>
                                <?php else: ?>
                                    <input type="hidden" name="audience" value="<?= coveted_e($selectedEvent['audience']) ?>">
                                    <label>Audience<input value="<?= coveted_e(ucwords(str_replace('_', ' ', (string)$selectedEvent['audience']))) ?>" disabled></label>
                                <?php endif; ?>
                            </div>

                            <div class="cv-form-row">
                                <label>Start<input type="datetime-local" name="starts_at" value="<?= coveted_e($startLocal) ?>" required></label>
                                <label>End<input type="datetime-local" name="ends_at" value="<?= coveted_e($endLocal) ?>"></label>
                            </div>

                            <div class="cv-form-row">
                                <label>Timezone
                                    <select name="timezone">
                                        <?php foreach ($timezoneOptions as $zone => $label): ?>
                                            <option value="<?= coveted_e($zone) ?>" <?= $currentTimezone === $zone ? 'selected' : '' ?>><?= coveted_e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>Capacity<input type="number" name="capacity" min="1" step="1" value="<?= $selectedEvent['capacity'] !== null ? (int)$selectedEvent['capacity'] : '' ?>" placeholder="Unlimited"></label>
                            </div>

                            <div class="cv-form-row">
                                <label>Location visibility
                                    <select name="location_visibility">
                                        <?php foreach (['immediate' => 'Show immediately','scheduled_reveal' => 'Reveal later','host_only' => 'Host only'] as $value => $label): ?>
                                            <option value="<?= coveted_e($value) ?>" <?= $selectedEvent['location_visibility'] === $value ? 'selected' : '' ?>><?= coveted_e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>Allow +1
                                    <select name="plus_one_allowed">
                                        <option value="0" <?= empty($selectedEvent['plus_one_allowed']) ? 'selected' : '' ?>>No</option>
                                        <option value="1" <?= !empty($selectedEvent['plus_one_allowed']) ? 'selected' : '' ?>>Yes · one guest per RSVP</option>
                                    </select>
                                </label>
                            </div>
                            <button class="cv-button" type="submit">Save Details</button>
                        </form>
                    <?php else: ?>
                        <section class="cv-card cv-form">
                            <span class="cv-eyebrow">DETAILS</span>
                            <h2>Event record</h2>
                            <p>Event setup is controlled by Coveted System Admin.</p>
                            <div class="cv-mini-row"><div><strong><?= coveted_e($selectedEvent['title']) ?></strong><span><?= coveted_e(ucwords(str_replace('_', ' ', (string)$selectedEvent['event_type']))) ?></span></div></div>
                            <div class="cv-mini-row"><div><strong><?= coveted_e(coveted_event_format($selectedEvent, 'D, M j · g:i A T')) ?></strong><span><?= coveted_e(ucwords(str_replace('_', ' ', (string)$selectedEvent['audience']))) ?></span></div></div>
                            <div class="cv-mini-row"><div><strong><?= $selectedEvent['capacity'] !== null ? (int)$selectedEvent['capacity'] : 'Unlimited' ?></strong><span>Capacity · <?= !empty($selectedEvent['plus_one_allowed']) ? '+1 allowed' : 'No +1' ?></span></div></div>
                        </section>
                    <?php endif; ?>

                    <div class="cv-stack">
                        <section class="cv-card cv-form">
                            <span class="cv-eyebrow">STATUS</span>
                            <h2><?= coveted_e(ucfirst((string)$selectedEvent['status'])) ?></h2>
                            <p><?= coveted_e(coveted_event_format($selectedEvent, 'D, M j · g:i A T')) ?></p>
                            <div class="cv-action-row">
                                <?php if ($canConfigure): ?>
                                    <?php foreach ($statusTransitions[(string)$selectedEvent['status']] ?? [] as $value => $label): ?>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="set_status">
                                            <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                                            <input type="hidden" name="status" value="<?= coveted_e($value) ?>">
                                            <button class="cv-button <?= $value === 'cancelled' ? 'cv-button-soft' : '' ?>" type="submit"><?= coveted_e($label) ?></button>
                                        </form>
                                    <?php endforeach; ?>
                                <?php elseif (!$isSystemAdmin): ?>
                                    <span class="cv-status">Admin controlled</span>
                                <?php endif; ?>
                            </div>
                        </section>

                        <?php if ($canConfigure): ?>
                            <form class="cv-card cv-form" method="post">
                                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                <input type="hidden" name="action" value="set_location">
                                <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                                <span class="cv-eyebrow">LOCATION</span>
                                <h2><?= coveted_e($eventLocation['location_name'] ?? $eventLocation['private_location_label'] ?? 'Not set') ?></h2>
                                <label>Coveted partner location
                                    <select name="location_id">
                                        <option value="0">Private / other location</option>
                                        <?php foreach ($locationOptions as $location): ?>
                                            <option value="<?= (int)$location['id'] ?>" <?= $eventLocation && (int)$eventLocation['location_id'] === (int)$location['id'] ? 'selected' : '' ?>><?= coveted_e($location['business_name'] . ' · ' . $location['name'] . ($location['city'] ? ' · ' . $location['city'] : '')) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>Private location label<input name="private_location_label" maxlength="255" value="<?= coveted_e((string)($eventLocation['private_location_label'] ?? '')) ?>" placeholder="Private residence · Arcadia"></label>
                                <label>Reveal / arrival notes<textarea name="reveal_notes" rows="3" maxlength="5000"><?= coveted_e((string)($eventLocation['reveal_notes'] ?? '')) ?></textarea></label>
                                <button class="cv-button" type="submit">Save Location</button>
                            </form>
                        <?php else: ?>
                            <section class="cv-card cv-form">
                                <span class="cv-eyebrow">LOCATION</span>
                                <h2><?= coveted_e($eventLocation['location_name'] ?? $eventLocation['private_location_label'] ?? 'Not set') ?></h2>
                                <p><?= coveted_e((string)($eventLocation['reveal_notes'] ?? 'Location is controlled by Coveted System Admin.')) ?></p>
                            </section>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($tab === 'people'): ?>
                <div class="cv-workspace-grid">
                    <?php if ($canInvite): ?>
                        <form class="cv-card cv-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                            <input type="hidden" name="action" value="invite_user">
                            <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                            <span class="cv-eyebrow">INVITATIONS</span>
                            <h2>Invite a member</h2>
                            <p>Use the member's Coveted account email. No global member directory is exposed.</p>
                            <label>Member email<input type="email" name="email" maxlength="255" required></label>
                            <label>Invitation type
                                <select name="invite_type">
                                    <option value="member">Member</option>
                                    <option value="guest">Guest</option>
                                    <option value="plus_one">+1</option>
                                    <option value="standby">Standby</option>
                                </select>
                            </label>
                            <button class="cv-button" type="submit">Send Invitation</button>
                        </form>
                    <?php else: ?>
                        <section class="cv-card cv-form">
                            <span class="cv-eyebrow">INVITATIONS</span>
                            <h2>Invitations are closed.</h2>
                            <p>Invitations can be sent only while a future event is published and accepting responses.</p>
                        </section>
                    <?php endif; ?>

                    <?php if ($canConfigure): ?>
                        <form class="cv-card cv-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                            <input type="hidden" name="action" value="assign_host">
                            <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                            <span class="cv-eyebrow">HOST TEAM</span>
                            <h2>Add event support</h2>
                            <p>Lead and cohost assignments require current Attendee Host approval. Check-in assignments do not.</p>
                            <label>Member email<input type="email" name="email" maxlength="255" required></label>
                            <label>Role
                                <select name="host_role">
                                    <option value="checkin">Check-in</option>
                                    <option value="cohost">Cohost</option>
                                    <option value="lead">Lead</option>
                                </select>
                            </label>
                            <button class="cv-button" type="submit">Assign Host</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canRecordAttendance): ?>
                        <form class="cv-card cv-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                            <input type="hidden" name="action" value="record_attendance">
                            <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                            <span class="cv-eyebrow">CHECK-IN</span>
                            <h2>Record attendance</h2>
                            <p>Use this for an eligible member who arrived without a prior RSVP record.</p>
                            <label>Member email<input type="email" name="email" maxlength="255" required></label>
                            <label>Attendance
                                <select name="attendance_status">
                                    <option value="checked_in">Checked in</option>
                                    <option value="attended">Attended</option>
                                    <option value="left_early">Left early</option>
                                    <option value="no_show">No show</option>
                                </select>
                            </label>
                            <button class="cv-button" type="submit">Save Attendance</button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if ($eventHosts): ?>
                    <section class="cv-card cv-table-card">
                        <div class="cv-section-heading"><div><span class="cv-eyebrow">HOSTS</span><h2>Event team</h2></div></div>
                        <div class="cv-table-wrap"><table class="cv-table"><thead><tr><th>Name</th><th>Role</th><th>Email</th></tr></thead><tbody>
                            <?php foreach ($eventHosts as $host): ?>
                                <tr><td><?= coveted_e($host['display_name']) ?></td><td><?= coveted_e(ucfirst((string)$host['host_role'])) ?></td><td><?= coveted_e($host['email']) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody></table></div>
                    </section>
                <?php endif; ?>

                <section class="cv-card cv-table-card">
                    <div class="cv-section-heading"><div><span class="cv-eyebrow">PEOPLE</span><h2>Invitations, RSVPs &amp; attendance</h2></div></div>
                    <?php if (!$eventPeople): ?>
                        <div class="cv-empty"><p>No invitations, RSVPs or attendance records yet.</p></div>
                    <?php else: ?>
                        <div class="cv-table-wrap"><table class="cv-table"><thead><tr><th>Member</th><th>Invitation</th><th>RSVP</th><th>Attendance</th></tr></thead><tbody>
                            <?php foreach ($eventPeople as $person): ?>
                                <tr>
                                    <td><strong><?= coveted_e($person['display_name']) ?></strong><br><small><?= coveted_e($person['email']) ?></small></td>
                                    <td><?= coveted_e($person['invitation_status'] ?: '—') ?></td>
                                    <td><?= coveted_e($person['response'] ?: '—') ?><?= (int)$person['guest_count'] > 0 ? ' +1' : '' ?></td>
                                    <td>
                                        <?php if ($canRecordAttendance): ?>
                                            <form class="cv-inline-form" method="post">
                                                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                                <input type="hidden" name="action" value="record_attendance">
                                                <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                                                <input type="hidden" name="email" value="<?= coveted_e($person['email']) ?>">
                                                <select name="attendance_status" aria-label="Attendance for <?= coveted_e($person['display_name']) ?>">
                                                    <?php foreach (['checked_in' => 'Checked in','attended' => 'Attended','left_early' => 'Left early','no_show' => 'No show'] as $value => $label): ?>
                                                        <option value="<?= coveted_e($value) ?>" <?= $person['attendance_status'] === $value ? 'selected' : '' ?>><?= coveted_e($label) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button class="cv-button cv-button-soft" type="submit">Save</button>
                                            </form>
                                        <?php else: ?>
                                            <?= coveted_e($person['attendance_status'] ?: '—') ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                </section>
            <?php elseif ($tab === 'experience'): ?>
                <div class="cv-workspace-grid">
                    <?php if ($canConfigure): ?>
                        <form class="cv-card cv-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                            <input type="hidden" name="action" value="set_artist">
                            <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                            <span class="cv-eyebrow">ARTIST PARTNERS</span>
                            <h2>Build the experience</h2>
                            <p>Add an active Coveted artist to this gathering.</p>
                            <label>Artist
                                <select name="artist_id" required>
                                    <?php foreach ($artistOptions as $artist): ?>
                                        <option value="<?= (int)$artist['id'] ?>"><?= coveted_e($artist['artist_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Appearance
                                <select name="appearance_type">
                                    <option value="featured">Featured</option>
                                    <option value="support">Support</option>
                                    <option value="dj">DJ</option>
                                    <option value="session">Session</option>
                                    <option value="mystery">Mystery</option>
                                </select>
                            </label>
                            <button class="cv-button" type="submit" <?= !$artistOptions ? 'disabled' : '' ?>>Add Artist</button>
                        </form>
                    <?php else: ?>
                        <section class="cv-card cv-form">
                            <span class="cv-eyebrow">ARTIST PARTNERS</span>
                            <h2>Lineup locked.</h2>
                            <p>Artist lineup is managed by Coveted System Admin.</p>
                        </section>
                    <?php endif; ?>

                    <section class="cv-card cv-form">
                        <span class="cv-eyebrow">LINEUP</span>
                        <h2><?= count($eventArtists) ?> artist<?= count($eventArtists) === 1 ? '' : 's' ?></h2>
                        <?php if (!$eventArtists): ?><p>No artist partners assigned yet.</p><?php endif; ?>
                        <?php foreach ($eventArtists as $artist): ?>
                            <div class="cv-mini-row">
                                <div><strong><?= coveted_e($artist['artist_name']) ?></strong><span><?= coveted_e(ucfirst((string)$artist['appearance_type'])) ?></span></div>
                                <?php if ($canConfigure): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                        <input type="hidden" name="action" value="remove_artist">
                                        <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                                        <input type="hidden" name="artist_id" value="<?= (int)$artist['artist_id'] ?>">
                                        <button class="cv-button cv-button-soft" type="submit">Remove</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </section>
                </div>
            <?php else: ?>
                <div class="cv-workspace-grid">
                    <?php if ($canConfigure): ?>
                        <form class="cv-card cv-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                            <input type="hidden" name="action" value="add_reveal">
                            <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                            <span class="cv-eyebrow">MYSTERY REVEAL</span>
                            <h2>Reveal the night in stages.</h2>
                            <p>Each reveal becomes visible at its scheduled time and can generate the member notification flow.</p>
                            <label>Reveal time<input type="datetime-local" name="reveal_at" required></label>
                            <label>Reveal type
                                <select name="reveal_type">
                                    <option value="area">Area / neighborhood</option>
                                    <option value="experience">Experience</option>
                                    <option value="instructions">Instructions</option>
                                    <option value="location">Exact location</option>
                                    <option value="artist">Artist</option>
                                    <option value="custom">Custom</option>
                                </select>
                            </label>
                            <label>Title<input name="reveal_title" maxlength="180"></label>
                            <label>Content<textarea name="reveal_content" rows="5" maxlength="10000" required></textarea></label>
                            <button class="cv-button" type="submit">Add Reveal</button>
                        </form>
                    <?php else: ?>
                        <section class="cv-card cv-form">
                            <span class="cv-eyebrow">MYSTERY REVEAL</span>
                            <h2>Admin-managed reveal timeline.</h2>
                            <p>Coveted System Admin controls mystery reveal timing and content.</p>
                        </section>
                    <?php endif; ?>

                    <section class="cv-card cv-form">
                        <span class="cv-eyebrow">REVEAL TIMELINE</span>
                        <h2><?= count($eventReveals) ?> scheduled</h2>
                        <?php if (!$eventReveals): ?><p>No mystery reveals scheduled yet.</p><?php endif; ?>
                        <?php foreach ($eventReveals as $reveal): ?>
                            <?php $revealTime = coveted_utc_datetime((string)$reveal['reveal_at'])->setTimezone(coveted_event_timezone($selectedEvent)); ?>
                            <div class="cv-mini-row">
                                <div>
                                    <strong><?= coveted_e($reveal['title'] ?: ucwords((string)$reveal['reveal_type'])) ?></strong>
                                    <span><?= coveted_e($revealTime->format('M j · g:i A')) ?> · <?= coveted_e(str_replace('_', ' ', (string)$reveal['reveal_type'])) ?></span>
                                </div>
                                <span class="cv-status"><?= $reveal['notified_at'] ? 'Sent' : ($revealTime->getTimestamp() <= time() ? 'Available' : 'Scheduled') ?></span>
                            </div>
                        <?php endforeach; ?>
                    </section>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php coveted_page_end(); ?>
