<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/distribution.php';
require_once dirname(__DIR__) . '/app/businesses.php';
require_once dirname(__DIR__) . '/app/groups.php';
require_once dirname(__DIR__) . '/app/events.php';
require_once dirname(__DIR__) . '/app/artists.php';

// PWA/Web Push is an optional admin tool. Do not load Composer/Web Push for
// every Admin request; that would make the entire control center depend on
// the optional push transport and its PHP/runtime requirements.
$requestedView = strtolower(trim((string)($_GET['view'] ?? 'dashboard')));
$requestedAction = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? strtolower(trim((string)($_POST['action'] ?? '')))
    : '';
$needsPwaRuntime = $requestedView === 'pwa' || str_starts_with($requestedAction, 'pwa_');
if ($needsPwaRuntime) {
    require_once dirname(__DIR__) . '/app/pwa.php';
}

$admin = coveted_require_system_admin();
$pdo = coveted_db();

if (!isset($_GET['view']) && coveted_admin_should_show_onboarding($admin)) {
    coveted_redirect('/admin/onboarding.php');
}

$view = $requestedView;
$allowedViews = ['dashboard', 'requests', 'users', 'businesses', 'groups', 'events', 'artists', 'benefits', 'distribution', 'settings', 'pwa'];

if (!in_array($view, $allowedViews, true)) {
    $view = 'dashboard';
}

$error = '';
$notice = '';
$distributionEventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$distributionCampaignRef = trim((string)($_GET['campaign'] ?? $_POST['campaign_ref'] ?? ''));

$formatAdminTime = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    return coveted_utc_datetime($value)->setTimezone(coveted_timezone())->format('M j, Y · g:i A');
};

$adminLocalToUtc = static function (string $value, string $timezone): string {
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
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        $action = (string)($_POST['action'] ?? '');

        switch ($action) {
            case 'review_request':
                coveted_admin_review_role_request(
                    $admin,
                    (int)($_POST['request_id'] ?? 0),
                    (string)($_POST['decision'] ?? ''),
                    (string)($_POST['review_note'] ?? '')
                );
                $notice = 'Role request reviewed.';
                break;

            case 'create_user':
                $createdUser = coveted_admin_create_user(
                    $admin,
                    (string)($_POST['name'] ?? ''),
                    (string)($_POST['email'] ?? ''),
                    (string)($_POST['password'] ?? ''),
                    (string)($_POST['password_confirm'] ?? ''),
                    (array)($_POST['roles'] ?? [])
                );
                $notice = 'User created: ' . (string)$createdUser['email'];
                $view = 'users';
                break;

            case 'create_business':
                $initialAdminUserId = (int)($_POST['initial_admin_user_id'] ?? 0);
                $createdBusiness = coveted_business_create(
                    $admin,
                    (string)($_POST['name'] ?? ''),
                    (string)($_POST['description'] ?? ''),
                    $initialAdminUserId > 0 ? $initialAdminUserId : null
                );
                $notice = 'Business created.';
                $view = 'businesses';
                break;

            case 'create_group':
                coveted_create_group(
                    $admin,
                    (string)($_POST['name'] ?? ''),
                    (string)($_POST['description'] ?? ''),
                    (string)($_POST['city'] ?? ''),
                    (string)($_POST['visibility'] ?? 'invite_only')
                );
                $notice = 'Group created.';
                $view = 'groups';
                break;

            case 'create_event':
                $eventTimezone = trim((string)($_POST['timezone'] ?? ''));
                $eventStart = $adminLocalToUtc((string)($_POST['starts_at'] ?? ''), $eventTimezone);
                $eventEndRaw = trim((string)($_POST['ends_at'] ?? ''));
                $eventData = [
                    'title' => (string)($_POST['title'] ?? ''),
                    'description' => (string)($_POST['description'] ?? ''),
                    'event_type' => (string)($_POST['event_type'] ?? 'regular'),
                    'audience' => (string)($_POST['audience'] ?? 'group'),
                    'timezone' => $eventTimezone,
                    'starts_at' => $eventStart,
                    'ends_at' => $eventEndRaw !== '' ? $adminLocalToUtc($eventEndRaw, $eventTimezone) : '',
                    'capacity' => (string)($_POST['capacity'] ?? ''),
                    'plus_one_allowed' => !empty($_POST['plus_one_allowed']) ? 1 : 0,
                    'location_visibility' => (string)($_POST['location_visibility'] ?? 'immediate'),
                    'status' => (string)($_POST['status'] ?? 'draft'),
                ];
                coveted_event_create($admin, (int)($_POST['group_id'] ?? 0), $eventData);
                $notice = 'Event created.';
                $view = 'events';
                break;

            case 'create_artist':
                coveted_artist_create(
                    $admin,
                    (string)($_POST['artist_name'] ?? ''),
                    (string)($_POST['bio'] ?? '')
                );
                $notice = 'Artist created.';
                $view = 'artists';
                break;

            case 'user_password':
                coveted_admin_set_user_password(
                    $admin,
                    (int)($_POST['user_id'] ?? 0),
                    (string)($_POST['password'] ?? ''),
                    (string)($_POST['password_confirm'] ?? '')
                );
                $notice = 'User password updated.';
                $view = 'users';
                break;

            case 'user_role':
                coveted_admin_set_user_role(
                    $admin,
                    (int)($_POST['user_id'] ?? 0),
                    (string)($_POST['role_key'] ?? ''),
                    (string)($_POST['mode'] ?? '')
                );
                $notice = 'User role updated.';
                break;

            case 'user_status':
                coveted_admin_set_user_status(
                    $admin,
                    (int)($_POST['user_id'] ?? 0),
                    (string)($_POST['status'] ?? '')
                );
                $notice = 'Account status updated.';
                break;

            case 'group_status':
                coveted_admin_set_group_status(
                    $admin,
                    (int)($_POST['group_id'] ?? 0),
                    (string)($_POST['status'] ?? '')
                );
                $notice = 'Group status updated.';
                break;

            case 'distribution_event':
                $summary = coveted_distribution_run_event_campaign(
                    $admin,
                    (string)($_POST['campaign_ref'] ?? ''),
                    (int)($_POST['event_id'] ?? 0),
                    (string)($_POST['note'] ?? '')
                );
                $notice = sprintf(
                    'Distribution complete: %d issued, %d already issued, %d skipped.',
                    (int)$summary['issued_count'],
                    (int)$summary['already_issued_count'],
                    (int)$summary['skipped_count']
                );
                $view = 'distribution';
                break;

            case 'distribution_manual':
                $summary = coveted_distribution_run_manual_campaign(
                    $admin,
                    (string)($_POST['campaign_ref'] ?? ''),
                    (int)($_POST['user_id'] ?? 0),
                    (string)($_POST['request_key'] ?? ''),
                    (string)($_POST['note'] ?? '')
                );
                $notice = !empty($summary['already_issued'])
                    ? 'That exact manual distribution was already completed; no duplicate gift was created.'
                    : 'Gift distributed to the selected member.';
                $view = 'distribution';
                break;

            case 'pwa_upload':
                coveted_pwa_store_uploaded_asset(
                    $admin,
                    (string)($_POST['asset_key'] ?? ''),
                    (array)($_FILES['asset'] ?? [])
                );
                $notice = 'PWA artwork uploaded and activated.';
                $view = 'pwa';
                break;

            case 'pwa_delete':
                coveted_pwa_delete_asset($admin, (string)($_POST['asset_key'] ?? ''));
                $notice = 'PWA artwork removed.';
                $view = 'pwa';
                break;

            case 'pwa_test_notification':
                $recipientId = (int)($_POST['user_id'] ?? 0);
                coveted_notification_create(
                    $recipientId,
                    'pwa.test',
                    trim((string)($_POST['title'] ?? 'Coveted notification test')) ?: 'Coveted notification test',
                    trim((string)($_POST['body'] ?? 'Your Coveted notification system is working.')),
                    '/',
                    ['source' => 'system_admin_pwa_test'],
                    'normal',
                    'pwa-test:' . $recipientId . ':' . date('YmdHi'),
                    (int)$admin['id']
                );
                $notice = 'Test notification created in the canonical notification queue.';
                $view = 'pwa';
                break;

            default:
                throw new InvalidArgumentException('Unsupported Admin action.');
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted admin error: ' . $e->getMessage());
        $error = 'Unable to complete that Admin action.';
    }
}

$counts = coveted_admin_ui_counts($pdo);

$requests = [];
$users = [];
$businesses = [];
$groups = [];
$adminEvents = [];
$artists = [];
$benefitCampaigns = [];
$benefitSummary = [
    'reward_templates' => 0,
    'active_campaigns' => 0,
    'issued' => 0,
    'claims' => 0,
];
$recentUsers = [];
$upcomingEvents = [];
$activeUserOptions = [];
$activeGroupOptions = [];
$onboarding = coveted_admin_onboarding_state($admin);
$pwaDefinitions = [];
$pwaAssets = [];
$pwaStatus = [];
$notificationStats = [];
$recentNotifications = [];
$pwaUsers = [];
$distributionEvents = [];
$distributionCampaigns = [];
$distributionPreview = null;
$manualCampaigns = [];
$distributionUsers = [];
$distributionRuns = [];

try {
if ($view === 'requests' || $view === 'dashboard') {
    $requests = $pdo->query(
        "SELECT rr.*, u.display_name, u.email
         FROM role_requests rr
         JOIN users u ON u.id = rr.user_id
         WHERE rr.status = 'pending'
         ORDER BY rr.created_at ASC
         LIMIT 50"
    )->fetchAll();
}

if ($view === 'users') {
    $users = $pdo->query(
        "SELECT
            u.id,
            u.public_id,
            u.display_name,
            u.email,
            u.status,
            u.created_at,
            (SELECT GROUP_CONCAT(ur.role_key ORDER BY ur.role_key SEPARATOR ',')
             FROM user_roles ur
             WHERE ur.user_id = u.id) AS roles
         FROM users u
         ORDER BY u.created_at DESC
         LIMIT 100"
    )->fetchAll();
}

if ($view === 'dashboard') {
    $recentUsers = $pdo->query(
        "SELECT id, public_id, display_name, email, status, created_at
         FROM users
         WHERE status <> 'deleted'
         ORDER BY created_at DESC
         LIMIT 6"
    )->fetchAll();

    $upcomingEvents = $pdo->query(
        "SELECT e.public_id, e.title, e.status, e.starts_at, e.timezone, g.name AS group_name,
                (SELECT COUNT(*) FROM event_invitations ei WHERE ei.event_id = e.id AND ei.status IN ('pending','accepted')) AS invite_count,
                (SELECT COUNT(*) FROM event_rsvps er WHERE er.event_id = e.id AND er.response = 'attending') AS attending_count
         FROM events e
         JOIN social_groups g ON g.id = e.group_id
         WHERE e.status IN ('draft','published','closed')
           AND e.starts_at >= UTC_TIMESTAMP()
         ORDER BY e.starts_at ASC
         LIMIT 6"
    )->fetchAll();
}

if ($view === 'businesses') {
    $businesses = $pdo->query(
        "SELECT b.*, u.display_name AS creator_name,
                (SELECT COUNT(*) FROM locations l WHERE l.business_id = b.id AND l.status <> 'archived') AS location_count,
                (SELECT COUNT(*) FROM business_admins ba WHERE ba.business_id = b.id) AS admin_count,
                (SELECT COUNT(*) FROM reward_templates rt WHERE rt.business_id = b.id AND rt.status <> 'archived') AS reward_count,
                (SELECT COUNT(*) FROM campaigns c WHERE c.business_id = b.id AND c.status <> 'archived') AS campaign_count
         FROM businesses b
         JOIN users u ON u.id = b.created_by
         ORDER BY b.updated_at DESC
         LIMIT 100"
    )->fetchAll();
}

if ($view === 'businesses') {
    $activeUserOptions = $pdo->query(
        "SELECT id, display_name, email FROM users WHERE status = 'active' ORDER BY display_name, id LIMIT 1000"
    )->fetchAll();
}

if ($view === 'groups') {
    $groups = $pdo->query(
        "SELECT
            g.*,
            u.display_name AS creator_name,
            (SELECT COUNT(*)
             FROM group_memberships gm
             WHERE gm.group_id = g.id AND gm.membership_status = 'active') AS member_count,
            (SELECT COUNT(*) FROM events e WHERE e.group_id = g.id) AS event_count
         FROM social_groups g
         JOIN users u ON u.id = g.created_by
         ORDER BY g.updated_at DESC
         LIMIT 100"
    )->fetchAll();
}

if ($view === 'events') {
    $adminEvents = $pdo->query(
        "SELECT e.*, g.name AS group_name, u.display_name AS creator_name,
                (SELECT COUNT(*) FROM event_invitations ei WHERE ei.event_id = e.id AND ei.status <> 'revoked') AS invite_count,
                (SELECT COUNT(*) FROM event_rsvps er WHERE er.event_id = e.id AND er.response = 'attending') AS attending_count,
                (SELECT COUNT(*) FROM event_attendance ea WHERE ea.event_id = e.id AND ea.status IN ('checked_in','attended','left_early')) AS attendance_count
         FROM events e
         JOIN social_groups g ON g.id = e.group_id
         JOIN users u ON u.id = e.created_by
         ORDER BY e.starts_at DESC
         LIMIT 120"
    )->fetchAll();
}

if ($view === 'events') {
    $activeGroupOptions = $pdo->query(
        "SELECT id, name, city FROM social_groups WHERE status = 'active' ORDER BY name, id LIMIT 500"
    )->fetchAll();
}

if ($view === 'artists') {
    $artists = $pdo->query(
        "SELECT ap.*, u.display_name AS owner_name,
                (SELECT COUNT(*) FROM artist_members am WHERE am.artist_id = ap.id) AS team_count,
                (SELECT COUNT(*) FROM event_artists ea WHERE ea.artist_id = ap.id) AS appearance_count,
                (SELECT COUNT(*) FROM reward_templates rt WHERE rt.artist_id = ap.id AND rt.status <> 'archived') AS reward_count
         FROM artist_profiles ap
         JOIN users u ON u.id = ap.owner_user_id
         ORDER BY ap.updated_at DESC
         LIMIT 100"
    )->fetchAll();
}

if ($view === 'benefits') {
    $benefitSummary = $pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM reward_templates WHERE status <> 'archived') AS reward_templates,
            (SELECT COUNT(*) FROM campaigns WHERE status = 'active') AS active_campaigns,
            (SELECT COUNT(*) FROM reward_issuances WHERE status IN ('issued','viewed','claimed')) AS issued,
            (SELECT COUNT(*) FROM reward_claims WHERE status = 'claimed') AS claims"
    )->fetch() ?: $benefitSummary;

    $benefitCampaigns = $pdo->query(
        "SELECT c.public_id, c.title, c.owner_type, c.status, c.trigger_key, c.updated_at,
                rt.title AS reward_title,
                COALESCE(b.name, g.name, ap.artist_name, 'Coveted') AS owner_name,
                (SELECT COUNT(*) FROM reward_issuances ri WHERE ri.campaign_id = c.id) AS issued_count
         FROM campaigns c
         JOIN reward_templates rt ON rt.id = c.reward_template_id
         LEFT JOIN businesses b ON b.id = c.business_id
         LEFT JOIN social_groups g ON g.id = c.group_id
         LEFT JOIN artist_profiles ap ON ap.id = c.artist_id
         WHERE c.status <> 'archived'
         ORDER BY c.updated_at DESC
         LIMIT 40"
    )->fetchAll();
}

if ($view === 'distribution') {
    $distributionEvents = coveted_distribution_events();
    if ($distributionEventId < 1 && $distributionEvents) {
        $distributionEventId = (int)$distributionEvents[0]['id'];
    }

    if ($distributionEventId > 0) {
        $distributionCampaigns = coveted_distribution_event_campaigns($distributionEventId);
        if ($distributionCampaignRef === '' && $distributionCampaigns) {
            $distributionCampaignRef = (string)$distributionCampaigns[0]['public_id'];
        }

        if ($distributionCampaignRef !== '') {
            try {
                $distributionPreview = coveted_distribution_preview(
                    $admin,
                    $distributionCampaignRef,
                    $distributionEventId
                );
            } catch (InvalidArgumentException $e) {
                if ($error === '') {
                    $error = $e->getMessage();
                }
            }
        }
    }

    $manualCampaigns = coveted_distribution_manual_campaigns();
    $distributionUsers = $pdo->query(
        "SELECT id, display_name, email
         FROM users
         WHERE status = 'active'
         ORDER BY display_name, id
         LIMIT 1000"
    )->fetchAll();
    $distributionRuns = coveted_distribution_recent_runs(50);
}

if ($view === 'pwa') {
    $pwaDefinitions = coveted_pwa_asset_definitions();
    $pwaAssets = coveted_pwa_assets();
    $pwaStatus = coveted_pwa_status();
    $notificationStats = coveted_notification_admin_stats();
    $recentNotifications = coveted_notification_recent_admin(20);
    $pwaUsers = $pdo->query(
        "SELECT id, display_name, email
         FROM users
         WHERE status = 'active'
         ORDER BY display_name, id
         LIMIT 500"
    )->fetchAll();
}

} catch (Throwable $e) {
    $adminLoadMessage = trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? $e->getMessage());
    error_log('Coveted Admin recovery mode: ' . $adminLoadMessage);
    if ($error === '') {
        $error = 'Admin opened in recovery mode because some data could not load: ' . $adminLoadMessage;
    }
}

coveted_page_start('Admin', '', true);
$adminPageTitle = match ($view) {
    'requests' => 'Role Requests',
    'users' => 'Users',
    'businesses' => 'Businesses',
    'groups' => 'Groups',
    'events' => 'Events',
    'artists' => 'Artists',
    'benefits' => 'Benefits',
    'distribution' => 'Distribution',
    'settings' => 'Settings',
    'pwa' => 'PWA & Notifications',
    default => 'Dashboard',
};
$adminActive = $view === 'pwa' ? 'settings' : $view;
coveted_admin_ui_start($admin, $adminActive, $adminPageTitle, $counts);
?>
        <?php if ($error): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
        <?php if ($notice): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

        <?php if ($view === 'dashboard'): ?>
            <div class="cv-admin-page-head">
                <div>
                    <span class="cv-eyebrow">SYSTEM ADMIN</span>
                    <h1>Control Center</h1>
                    <p>Everything needed to run Coveted from one place.</p>
                </div>
                <a class="cv-button cv-button-soft" href="/">View Member App</a>
            </div>

            <?php if (!$onboarding['is_complete']): ?>
                <a class="cv-admin-setup-banner" href="/admin/onboarding.php">
                    <span class="cv-admin-setup-ring" style="--progress: <?= (int)$onboarding['percent'] ?>%"><strong><?= (int)$onboarding['completed'] ?>/<?= (int)$onboarding['total'] ?></strong></span>
                    <span><strong>Finish setting up Coveted</strong><small>Create the first operating loop so you can test the product end-to-end.</small></span>
                    <span>Continue setup →</span>
                </a>
            <?php endif; ?>

            <div class="cv-admin-metric-grid">
                <a href="/admin/?view=users"><span>Users</span><strong><?= (int)$counts['users'] ?></strong><small>Accounts & access</small></a>
                <a href="/admin/?view=businesses"><span>Businesses</span><strong><?= (int)$counts['businesses'] ?></strong><small>Venues & partners</small></a>
                <a href="/admin/?view=groups"><span>Groups</span><strong><?= (int)$counts['groups'] ?></strong><small>Private communities</small></a>
                <a href="/admin/?view=events"><span>Events</span><strong><?= (int)$counts['events'] ?></strong><small>Gatherings & sessions</small></a>
                <a href="/admin/?view=requests"><span>Role Requests</span><strong><?= (int)$counts['pending_requests'] ?></strong><small>Needs review</small></a>
            </div>

            <section class="cv-admin-panel cv-admin-quick-create">
                <div class="cv-admin-panel-head">
                    <div><span class="cv-eyebrow">QUICK CREATE</span><h2>Start something</h2></div>
                </div>
                <div class="cv-admin-action-grid">
                    <a href="/admin/?view=users#create-user"><span>01</span><strong>User</strong><small>Add a member, host, artist partner or admin.</small></a>
                    <a href="/admin/?view=businesses#create-business"><span>02</span><strong>Business</strong><small>Add a venue or partner business.</small></a>
                    <a href="/admin/?view=groups#create-group"><span>03</span><strong>Group</strong><small>Create a private community.</small></a>
                    <a href="/admin/?view=events#create-event"><span>04</span><strong>Event</strong><small>Plan a new gathering or session.</small></a>
                    <a href="/admin/?view=artists#create-artist"><span>05</span><strong>Artist</strong><small>Add an artist partner identity.</small></a>
                    <a href="/admin/?view=benefits"><span>06</span><strong>Benefit</strong><small>Open rewards and campaign tools.</small></a>
                </div>
            </section>

            <div class="cv-admin-dashboard-grid">
                <section class="cv-admin-panel">
                    <div class="cv-admin-panel-head">
                        <div><span class="cv-eyebrow">UPCOMING</span><h2>Events</h2></div>
                        <a href="/admin/?view=events">View all →</a>
                    </div>
                    <div class="cv-admin-list">
                        <?php if (!$upcomingEvents): ?>
                            <div class="cv-admin-empty"><strong>No upcoming events.</strong><span>Create an event when the first group is ready.</span></div>
                        <?php endif; ?>
                        <?php foreach ($upcomingEvents as $event): ?>
                            <a class="cv-admin-list-row" href="/host.php?event=<?= coveted_e($event['public_id']) ?>">
                                <span class="cv-admin-list-date"><strong><?= coveted_e(coveted_utc_datetime((string)$event['starts_at'])->setTimezone(new DateTimeZone((string)$event['timezone']))->format('d')) ?></strong><small><?= coveted_e(strtoupper(coveted_utc_datetime((string)$event['starts_at'])->setTimezone(new DateTimeZone((string)$event['timezone']))->format('M'))) ?></small></span>
                                <span class="cv-admin-list-copy"><strong><?= coveted_e($event['title']) ?></strong><small><?= coveted_e($event['group_name']) ?> · <?= (int)$event['attending_count'] ?> attending · <?= coveted_e(ucfirst((string)$event['status'])) ?></small></span>
                                <span>→</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="cv-admin-panel">
                    <div class="cv-admin-panel-head">
                        <div><span class="cv-eyebrow">PEOPLE</span><h2>Newest users</h2></div>
                        <a href="/admin/?view=users">Manage →</a>
                    </div>
                    <div class="cv-admin-list">
                        <?php foreach ($recentUsers as $recentUser): ?>
                            <div class="cv-admin-list-row">
                                <span class="cv-admin-list-avatar"><?= coveted_e(coveted_shell_initials((string)$recentUser['display_name'])) ?></span>
                                <span class="cv-admin-list-copy"><strong><?= coveted_e($recentUser['display_name']) ?></strong><small><?= coveted_e($recentUser['email']) ?> · <?= coveted_e(ucfirst((string)$recentUser['status'])) ?></small></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>

            <?php if ($requests): ?>
                <section class="cv-admin-panel">
                    <div class="cv-admin-panel-head">
                        <div><span class="cv-eyebrow">ATTENTION</span><h2><?= count($requests) ?> role request<?= count($requests) === 1 ? '' : 's' ?> waiting</h2></div>
                        <a href="/admin/?view=requests">Review →</a>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($view === 'requests'): ?>
            <div class="cv-admin-page-head">
                <div><span class="cv-eyebrow">ROLE REQUESTS</span><h1>Access approvals</h1><p>Approve Attendee Host and Artist Partner platform roles. Business Admin remains resource-scoped to each business.</p></div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'requests'): ?>
            <div class="cv-stack">
                <?php if (!$requests): ?><div class="cv-card cv-empty"><h3>No pending requests.</h3><p>The elevated-role review queue is clear.</p></div><?php endif; ?>
                <?php foreach ($requests as $request): ?>
                    <div class="cv-card cv-admin-row">
                        <div>
                            <div class="cv-tag-row">
                                <span class="cv-kicker"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$request['role_key']))) ?></span>
                                <span class="cv-status">Pending</span>
                            </div>
                            <h3><?= coveted_e($request['display_name']) ?></h3>
                            <p><?= coveted_e($request['email']) ?> · Requested <?= coveted_e($formatAdminTime((string)$request['created_at'])) ?></p>
                            <?php if ($request['request_note']): ?><p><?= coveted_e($request['request_note']) ?></p><?php endif; ?>
                        </div>
                        <form method="post" class="cv-admin-actions">
                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                            <input type="hidden" name="action" value="review_request">
                            <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                            <input name="review_note" placeholder="Optional review note" maxlength="500">
                            <button class="cv-button cv-button-primary" name="decision" value="approved">Approve</button>
                            <button class="cv-button cv-button-soft" name="decision" value="declined">Decline</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($view === 'users'): ?>
            <div class="cv-admin-page-head">
                <div><span class="cv-eyebrow">USERS</span><h1>People & access</h1><p>Create accounts, set passwords, manage status and control platform-wide roles.</p></div>
                <a class="cv-button cv-button-primary" href="#create-user">＋ Add User</a>
            </div>

            <div class="cv-section-head">
                <div><span class="cv-eyebrow">CREATE USER</span><h2>Add a new account</h2></div>
            </div>
            <form id="create-user" method="post" class="cv-card cv-form-grid cv-anchor-target" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                <input type="hidden" name="action" value="create_user">

                <label>
                    Name
                    <input name="name" maxlength="180" required autocomplete="name">
                </label>

                <label>
                    Email
                    <input type="email" name="email" maxlength="255" required autocomplete="email">
                </label>

                <label>
                    Password
                    <input type="password" name="password" minlength="10" maxlength="4096" required autocomplete="new-password">
                </label>

                <label>
                    Confirm password
                    <input type="password" name="password_confirm" minlength="10" maxlength="4096" required autocomplete="new-password">
                </label>

                <fieldset class="cv-card">
                    <legend>Initial access</legend>
                    <p class="cv-muted">Every account receives Attendee access automatically.</p>
                    <label><input type="checkbox" name="roles[]" value="attendee_host"> Attendee Host</label>
                    <label><input type="checkbox" name="roles[]" value="artist_partner"> Artist Partner</label>
                    <label><input type="checkbox" name="roles[]" value="system_admin"> System Admin</label>
                </fieldset>

                <div>
                    <button class="cv-button cv-button-primary" type="submit">Create User</button>
                </div>
            </form>

            <div class="cv-section-head"><div><span class="cv-eyebrow">RECENT ACCOUNTS</span><h2>Up to 100 newest users</h2></div></div>
            <div class="cv-stack">
                <?php if (!$users): ?><div class="cv-card cv-empty"><h3>No user accounts.</h3></div><?php endif; ?>
                <?php foreach ($users as $listedUser): ?>
                    <?php $roles = array_filter(explode(',', (string)$listedUser['roles'])); ?>
                    <div class="cv-card cv-admin-row">
                        <div>
                            <div class="cv-tag-row">
                                <?php foreach ($roles as $role): ?><span class="cv-pill"><?= coveted_e(ucwords(str_replace('_', ' ', $role))) ?></span><?php endforeach; ?>
                                <span class="cv-status"><?= coveted_e(ucfirst((string)$listedUser['status'])) ?></span>
                            </div>
                            <h3><?= coveted_e($listedUser['display_name']) ?></h3>
                            <p><?= coveted_e($listedUser['email']) ?> · Joined <?= coveted_e($formatAdminTime((string)$listedUser['created_at'])) ?></p>
                        </div>

                        <div class="cv-admin-actions">
                            <?php if ($listedUser['status'] !== 'deleted'): ?>
                                <form method="post" class="cv-inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="user_role">
                                    <input type="hidden" name="user_id" value="<?= (int)$listedUser['id'] ?>">
                                    <select name="role_key" aria-label="Role for <?= coveted_e($listedUser['display_name']) ?>">
                                        <?php foreach (['attendee_host' => 'Attendee Host', 'artist_partner' => 'Artist Partner', 'system_admin' => 'System Admin'] as $key => $label): ?>
                                            <option value="<?= coveted_e($key) ?>"><?= coveted_e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="cv-button cv-button-soft" name="mode" value="grant">Grant</button>
                                    <button class="cv-button cv-button-soft" name="mode" value="revoke">Revoke</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($listedUser['status'] !== 'deleted'): ?>
                                <details>
                                    <summary class="cv-button cv-button-soft">Set Password</summary>
                                    <form method="post" class="cv-stack">
                                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                        <input type="hidden" name="action" value="user_password">
                                        <input type="hidden" name="user_id" value="<?= (int)$listedUser['id'] ?>">
                                        <label>New password<input type="password" name="password" minlength="10" maxlength="4096" required autocomplete="new-password"></label>
                                        <label>Confirm password<input type="password" name="password_confirm" minlength="10" maxlength="4096" required autocomplete="new-password"></label>
                                        <button class="cv-button cv-button-primary" type="submit">Update Password</button>
                                    </form>
                                </details>
                            <?php endif; ?>

                            <?php if (in_array($listedUser['status'], ['active', 'suspended'], true)): ?>
                                <?php $nextStatus = $listedUser['status'] === 'active' ? 'suspended' : 'active'; ?>
                                <form method="post" class="cv-inline-form" data-confirm="<?= coveted_e($nextStatus === 'suspended' ? 'Suspend this account?' : 'Reactivate this account?') ?>">
                                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="user_status">
                                    <input type="hidden" name="user_id" value="<?= (int)$listedUser['id'] ?>">
                                    <button class="cv-button cv-button-soft" name="status" value="<?= coveted_e($nextStatus) ?>"><?= $nextStatus === 'suspended' ? 'Suspend' : 'Reactivate' ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($view === 'businesses'): ?>
            <div class="cv-admin-page-head">
                <div><span class="cv-eyebrow">BUSINESSES</span><h1>Venues & partners</h1><p>See every partner business and jump into the scoped business workspace.</p></div>
                <a class="cv-button cv-button-primary" href="/admin/?view=businesses#create-business">＋ Add Business</a>
            </div>

            <section id="create-business" class="cv-admin-panel cv-anchor-target cv-admin-create-form">
                <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">ADD BUSINESS</span><h2>Create a venue or partner</h2></div></div>
                <form method="post" class="cv-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_business">
                    <label>Business name<input name="name" maxlength="180" required></label>
                    <label>Initial Business Admin
                        <select name="initial_admin_user_id">
                            <option value="0">Assign later</option>
                            <?php foreach ($activeUserOptions as $optionUser): ?>
                                <option value="<?= (int)$optionUser['id'] ?>"><?= coveted_e($optionUser['display_name']) ?> · <?= coveted_e($optionUser['email']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="cv-form-span">Description<textarea name="description" rows="3" maxlength="4000" placeholder="What this business or venue contributes to Coveted"></textarea></label>
                    <div class="cv-form-span"><button class="cv-button cv-button-primary" type="submit">Create Business</button></div>
                </form>
            </section>

            <div class="cv-admin-table-card">
                <div class="cv-admin-table-head"><strong><?= count($businesses) ?> businesses</strong><span>Locations, rewards and campaigns stay scoped to each business.</span></div>
                <div class="cv-admin-table-scroll">
                    <table class="cv-admin-table">
                        <thead><tr><th>Business</th><th>Status</th><th>Locations</th><th>Admins</th><th>Rewards</th><th>Campaigns</th><th></th></tr></thead>
                        <tbody>
                        <?php if (!$businesses): ?><tr><td colspan="7"><div class="cv-admin-empty"><strong>No businesses yet.</strong><span>Add the first venue or partner business.</span></div></td></tr><?php endif; ?>
                        <?php foreach ($businesses as $business): ?>
                            <tr>
                                <td><strong><?= coveted_e($business['name']) ?></strong><small>Created by <?= coveted_e($business['creator_name']) ?></small></td>
                                <td><span class="cv-status"><?= coveted_e(ucfirst((string)$business['status'])) ?></span></td>
                                <td><?= (int)$business['location_count'] ?></td>
                                <td><?= (int)$business['admin_count'] ?></td>
                                <td><?= (int)$business['reward_count'] ?></td>
                                <td><?= (int)$business['campaign_count'] ?></td>
                                <td><a class="cv-text-link" href="/business.php?business=<?= coveted_e($business['public_id']) ?>">Open →</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'groups'): ?>
            <div class="cv-admin-page-head">
                <div><span class="cv-eyebrow">GROUPS</span><h1>Private communities</h1><p>See every group, its members and event activity, then open the scoped Group workspace when you need to manage it.</p></div>
                <a class="cv-button cv-button-primary" href="/admin/?view=groups#create-group">＋ Add Group</a>
            </div>
            <section id="create-group" class="cv-admin-panel cv-anchor-target cv-admin-create-form">
                <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">ADD GROUP</span><h2>Create a private community</h2></div></div>
                <form method="post" class="cv-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_group">
                    <label>Group name<input name="name" maxlength="180" required></label>
                    <label>City<input name="city" maxlength="160" placeholder="Phoenix"></label>
                    <label>Visibility
                        <select name="visibility"><option value="invite_only">Invite only</option><option value="private">Private</option><option value="unlisted">Unlisted</option></select>
                    </label>
                    <label class="cv-form-span">Description<textarea name="description" rows="3" maxlength="2000"></textarea></label>
                    <div class="cv-form-span"><button class="cv-button cv-button-primary" type="submit">Create Group</button></div>
                </form>
            </section>
            <div class="cv-stack">
                <?php if (!$groups): ?><div class="cv-card cv-empty"><h3>No groups yet.</h3></div><?php endif; ?>
                <?php foreach ($groups as $listedGroup): ?>
                    <div class="cv-card cv-admin-row">
                        <div>
                            <div class="cv-tag-row"><span class="cv-kicker"><?= coveted_e(strtoupper((string)$listedGroup['status'])) ?></span><span class="cv-status"><?= (int)$listedGroup['member_count'] ?> members</span></div>
                            <h3><a href="/group.php?id=<?= coveted_e($listedGroup['public_id']) ?>"><?= coveted_e($listedGroup['name']) ?></a></h3>
                            <p><?= coveted_e($listedGroup['creator_name']) ?> · <?= (int)$listedGroup['event_count'] ?> events · Updated <?= coveted_e($formatAdminTime((string)$listedGroup['updated_at'])) ?></p>
                        </div>
                        <form method="post" class="cv-inline-form">
                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                            <input type="hidden" name="action" value="group_status">
                            <input type="hidden" name="group_id" value="<?= (int)$listedGroup['id'] ?>">
                            <select name="status" aria-label="Status for <?= coveted_e($listedGroup['name']) ?>">
                                <option value="active" <?= $listedGroup['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="paused" <?= $listedGroup['status'] === 'paused' ? 'selected' : '' ?>>Paused</option>
                                <option value="archived" <?= $listedGroup['status'] === 'archived' ? 'selected' : '' ?>>Archived</option>
                            </select>
                            <button class="cv-button cv-button-soft" type="submit">Update Status</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($view === 'events'): ?>
            <div class="cv-admin-page-head">
                <div><span class="cv-eyebrow">EVENTS</span><h1>Gatherings & sessions</h1><p>Platform-wide visibility into every event while event operations stay in the Host workspace.</p></div>
                <a class="cv-button cv-button-primary" href="/admin/?view=events#create-event">＋ Add Event</a>
            </div>
            <section id="create-event" class="cv-admin-panel cv-anchor-target cv-admin-create-form">
                <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">ADD EVENT</span><h2>Create a gathering</h2></div></div>
                <?php if (!$activeGroupOptions): ?>
                    <div class="cv-admin-empty"><strong>Create a group first.</strong><span>Every event belongs to a private Coveted group.</span><a class="cv-text-link" href="/admin/?view=groups#create-group">Add Group →</a></div>
                <?php else: ?>
                    <form method="post" class="cv-form-grid">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="create_event">
                        <label>Group<select name="group_id" required><?php foreach ($activeGroupOptions as $groupOption): ?><option value="<?= (int)$groupOption['id'] ?>"><?= coveted_e($groupOption['name']) ?><?= $groupOption['city'] ? ' · ' . coveted_e($groupOption['city']) : '' ?></option><?php endforeach; ?></select></label>
                        <label>Event title<input name="title" maxlength="190" required></label>
                        <label>Starts<input type="datetime-local" name="starts_at" required></label>
                        <label>Ends<input type="datetime-local" name="ends_at"></label>
                        <label>Timezone<select name="timezone"><option value="America/Phoenix">Arizona</option><option value="America/Los_Angeles">Pacific</option><option value="America/Denver">Mountain</option><option value="America/Chicago">Central</option><option value="America/New_York">Eastern</option></select></label>
                        <label>Type<select name="event_type"><option value="regular">Regular gathering</option><option value="mystery">Mystery gathering</option><option value="private_table">Private table</option><option value="member_plus_one">Member +1</option><option value="session">Session</option></select></label>
                        <label>Audience<select name="audience"><option value="group">Group</option><option value="invitation_only">Invitation only</option></select></label>
                        <label>Status<select name="status"><option value="draft">Draft</option><option value="published">Published</option></select></label>
                        <label>Capacity<input type="number" name="capacity" min="1" step="1" placeholder="Optional"></label>
                        <label>Location visibility<select name="location_visibility"><option value="immediate">Immediate</option><option value="scheduled_reveal">Scheduled reveal</option><option value="host_only">Host only</option></select></label>
                        <label class="cv-checkbox-line"><input type="checkbox" name="plus_one_allowed" value="1"> Allow +1</label>
                        <label class="cv-form-span">Description<textarea name="description" rows="3" maxlength="5000"></textarea></label>
                        <div class="cv-form-span"><button class="cv-button cv-button-primary" type="submit">Create Event</button></div>
                    </form>
                <?php endif; ?>
            </section>
            <div class="cv-admin-table-card">
                <div class="cv-admin-table-head"><strong><?= count($adminEvents) ?> events</strong><span>Newest and upcoming activity across all groups.</span></div>
                <div class="cv-admin-table-scroll">
                    <table class="cv-admin-table">
                        <thead><tr><th>Event</th><th>Group</th><th>Status</th><th>Starts</th><th>Invited</th><th>Attending</th><th></th></tr></thead>
                        <tbody>
                        <?php if (!$adminEvents): ?><tr><td colspan="7"><div class="cv-admin-empty"><strong>No events yet.</strong><span>Use Add Event above to create the first gathering.</span></div></td></tr><?php endif; ?>
                        <?php foreach ($adminEvents as $event): ?>
                            <tr>
                                <td><strong><?= coveted_e($event['title']) ?></strong><small><?= coveted_e(ucwords(str_replace('_', ' ', (string)$event['event_type']))) ?></small></td>
                                <td><?= coveted_e($event['group_name']) ?></td>
                                <td><span class="cv-status"><?= coveted_e(ucfirst((string)$event['status'])) ?></span></td>
                                <td><?= coveted_e($formatAdminTime((string)$event['starts_at'])) ?></td>
                                <td><?= (int)$event['invite_count'] ?></td>
                                <td><?= (int)$event['attending_count'] ?></td>
                                <td><a class="cv-text-link" href="/host.php?event=<?= coveted_e($event['public_id']) ?>">Manage →</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'artists'): ?>
            <div class="cv-admin-page-head">
                <div><span class="cv-eyebrow">ARTISTS</span><h1>Artist partners</h1><p>Artist identities, teams, appearances and rewards across Coveted.</p></div>
                <a class="cv-button cv-button-primary" href="/admin/?view=artists#create-artist">＋ Add Artist</a>
            </div>
            <section id="create-artist" class="cv-admin-panel cv-anchor-target cv-admin-create-form">
                <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">ADD ARTIST</span><h2>Create an artist partner</h2></div></div>
                <form method="post" class="cv-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_artist">
                    <label>Artist name<input name="artist_name" maxlength="180" required></label>
                    <label class="cv-form-span">Bio<textarea name="bio" rows="4" maxlength="5000"></textarea></label>
                    <div class="cv-form-span"><button class="cv-button cv-button-primary" type="submit">Create Artist</button></div>
                </form>
            </section>
            <div class="cv-admin-card-grid">
                <?php if (!$artists): ?><div class="cv-admin-panel cv-admin-empty"><strong>No artists yet.</strong><span>Add the first artist partner.</span></div><?php endif; ?>
                <?php foreach ($artists as $artist): ?>
                    <a class="cv-admin-entity-card" href="/artist.php?artist=<?= coveted_e($artist['public_id']) ?>">
                        <div class="cv-admin-entity-avatar">
                            <?php $artistAvatar = coveted_safe_url((string)($artist['avatar_url'] ?? ''), true); ?>
                            <?php if ($artistAvatar): ?><img src="<?= coveted_e($artistAvatar) ?>" alt=""><?php else: ?><span><?= coveted_e(coveted_shell_initials((string)$artist['artist_name'])) ?></span><?php endif; ?>
                        </div>
                        <div><span class="cv-status"><?= coveted_e(ucfirst((string)$artist['status'])) ?></span><h3><?= coveted_e($artist['artist_name']) ?></h3><p>Owner: <?= coveted_e($artist['owner_name']) ?></p></div>
                        <div class="cv-admin-mini-stats"><span><strong><?= (int)$artist['team_count'] ?></strong>team</span><span><strong><?= (int)$artist['appearance_count'] ?></strong>events</span><span><strong><?= (int)$artist['reward_count'] ?></strong>rewards</span></div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($view === 'benefits'): ?>
            <div class="cv-admin-page-head">
                <div><span class="cv-eyebrow">BENEFITS</span><h1>Rewards & campaigns</h1><p>See the value layer across businesses, groups and artists, then open the scoped workspace that owns it.</p></div>
                <a class="cv-button cv-button-primary" href="/admin/?view=distribution">Open Distribution</a>
            </div>
            <div class="cv-admin-metric-grid cv-admin-metric-grid-four">
                <div><span>Reward templates</span><strong><?= (int)$benefitSummary['reward_templates'] ?></strong><small>All non-archived</small></div>
                <div><span>Active campaigns</span><strong><?= (int)$benefitSummary['active_campaigns'] ?></strong><small>Ready to trigger</small></div>
                <div><span>Issued</span><strong><?= (int)$benefitSummary['issued'] ?></strong><small>Member entitlements</small></div>
                <div><span>Active claims</span><strong><?= (int)$benefitSummary['claims'] ?></strong><small>Redeemed value</small></div>
            </div>
            <section class="cv-admin-panel">
                <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">RECENT CAMPAIGNS</span><h2>Campaign activity</h2></div></div>
                <div class="cv-admin-list">
                    <?php if (!$benefitCampaigns): ?><div class="cv-admin-empty"><strong>No campaigns yet.</strong><span>Create rewards and campaigns inside a Business or Artist workspace.</span></div><?php endif; ?>
                    <?php foreach ($benefitCampaigns as $campaign): ?>
                        <div class="cv-admin-list-row">
                            <span class="cv-admin-list-copy"><strong><?= coveted_e($campaign['title']) ?></strong><small><?= coveted_e($campaign['owner_name']) ?> · <?= coveted_e($campaign['reward_title']) ?> · <?= coveted_e(ucfirst((string)$campaign['status'])) ?></small></span>
                            <span><?= (int)$campaign['issued_count'] ?> issued</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <div class="cv-admin-action-grid cv-admin-benefit-actions">
                <a href="/admin/?view=businesses"><span>01</span><strong>Business rewards</strong><small>Open a business, then create rewards and campaigns.</small></a>
                <a href="/admin/?view=artists"><span>02</span><strong>Artist rewards</strong><small>Open an artist workspace for media and reward tools.</small></a>
                <a href="/admin/?view=distribution"><span>03</span><strong>Distribution</strong><small>Preview eligibility and send campaign value.</small></a>
            </div>
        <?php endif; ?>

        <?php if ($view === 'settings'): ?>
            <?php $appConfig = coveted_config('app'); ?>
            <div class="cv-admin-page-head">
                <div><span class="cv-eyebrow">SETTINGS</span><h1>Platform settings</h1><p>Installation identity, PWA assets and administrator account settings.</p></div>
            </div>
            <div class="cv-admin-settings-grid">
                <section class="cv-admin-panel">
                    <span class="cv-eyebrow">INSTALLATION</span><h2><?= coveted_e((string)($appConfig['name'] ?? 'Coveted')) ?></h2>
                    <dl class="cv-admin-definition-list">
                        <div><dt>Base URL</dt><dd><?= coveted_e((string)($appConfig['base_url'] ?? '')) ?></dd></div>
                        <div><dt>Environment</dt><dd><?= coveted_e((string)($appConfig['environment'] ?? 'production')) ?></dd></div>
                        <div><dt>Timezone</dt><dd><?= coveted_e((string)($appConfig['default_timezone'] ?? 'UTC')) ?></dd></div>
                    </dl>
                    <p class="cv-form-help">Sensitive database and internal security values are intentionally not exposed here.</p>
                </section>
                <section class="cv-admin-panel">
                    <span class="cv-eyebrow">ADMIN ACCOUNT</span><h2>Profile & identity</h2><p>Your profile photo is used by the upper-right account menu throughout Coveted.</p>
                    <a class="cv-button cv-button-soft" href="/profile.php">Edit Profile</a>
                </section>
                <section class="cv-admin-panel">
                    <span class="cv-eyebrow">PWA & NOTIFICATIONS</span><h2>Installed app</h2><p>Manage install artwork, browser devices and push notification health.</p>
                    <a class="cv-button cv-button-soft" href="/admin/?view=pwa">Open PWA Settings</a>
                </section>
                <section class="cv-admin-panel">
                    <span class="cv-eyebrow">FIRST-RUN SETUP</span><h2><?= (int)$onboarding['completed'] ?>/<?= (int)$onboarding['total'] ?> steps complete</h2><p>Reopen the guided setup checklist at any time.</p>
                    <a class="cv-button cv-button-soft" href="/admin/onboarding.php">Open Admin Setup</a>
                </section>
            </div>
        <?php endif; ?>

        <?php if ($view === 'distribution'): ?>
            <div class="cv-page-heading">
                <span class="cv-eyebrow">DISTRIBUTION</span>
                <h1>Send benefits when the moment is right.</h1>
                <p>Campaigns can be prepared before an event. System Admin controls when eligible rewards are actually distributed.</p>
            </div>

            <div class="cv-two-column">
                <section class="cv-card cv-form">
                    <span class="cv-eyebrow">EVENT DISTRIBUTION</span>
                    <h2>Preview before sending</h2>
                    <form method="get" class="cv-form">
                        <input type="hidden" name="view" value="distribution">
                        <label>
                            Event
                            <select name="event_id" data-submit-on-change>
                                <?php foreach ($distributionEvents as $event): ?>
                                    <option value="<?= (int)$event['id'] ?>" <?= (int)$event['id'] === $distributionEventId ? 'selected' : '' ?>>
                                        <?= coveted_e($event['title']) ?> · <?= coveted_e($event['group_name']) ?> · <?= coveted_e($event['status']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <?php if ($distributionCampaigns): ?>
                            <label>
                                Campaign / reward
                                <select name="campaign" data-submit-on-change>
                                    <?php foreach ($distributionCampaigns as $campaign): ?>
                                        <option value="<?= coveted_e($campaign['public_id']) ?>" <?= $campaign['public_id'] === $distributionCampaignRef ? 'selected' : '' ?>>
                                            <?= coveted_e($campaign['title']) ?> → <?= coveted_e($campaign['reward_title']) ?> · <?= coveted_e($campaign['trigger_key']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php endif; ?>
                    </form>

                    <?php if (!$distributionEvents): ?>
                        <p>No events have linked campaigns yet.</p>
                    <?php elseif (!$distributionCampaigns): ?>
                        <p>The selected event has no linked campaigns.</p>
                    <?php elseif ($distributionPreview): ?>
                        <div class="cv-stat-grid cv-stat-grid-compact">
                            <div class="cv-card cv-stat"><strong><?= (int)$distributionPreview['eligible_count'] ?></strong><span>Eligible</span></div>
                            <div class="cv-card cv-stat"><strong><?= (int)$distributionPreview['already_issued_count'] ?></strong><span>Already sent</span></div>
                            <div class="cv-card cv-stat"><strong><?= (int)$distributionPreview['remaining_count'] ?></strong><span>Ready</span></div>
                        </div>
                        <p><strong><?= coveted_e($distributionPreview['context']['reward_title']) ?></strong> · <?= coveted_e($distributionPreview['campaign']['trigger_key']) ?> · campaign <?= coveted_e($distributionPreview['campaign']['status']) ?></p>

                        <form method="post" class="cv-form" data-confirm="Distribute this campaign to all currently eligible members?">
                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                            <input type="hidden" name="action" value="distribution_event">
                            <input type="hidden" name="event_id" value="<?= $distributionEventId ?>">
                            <input type="hidden" name="campaign_ref" value="<?= coveted_e($distributionCampaignRef) ?>">
                            <label>
                                Internal note
                                <textarea name="note" maxlength="1000" rows="3" placeholder="Optional reason or context for this send"></textarea>
                            </label>
                            <button class="cv-button cv-button-primary" type="submit" <?= (int)$distributionPreview['remaining_count'] < 1 ? 'disabled' : '' ?>>
                                Send <?= (int)$distributionPreview['remaining_count'] ?> gift<?= (int)$distributionPreview['remaining_count'] === 1 ? '' : 's' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </section>

                <section class="cv-card cv-form">
                    <span class="cv-eyebrow">MANUAL DISTRIBUTION</span>
                    <h2>Send to one member</h2>
                    <p>Use an active campaign configured for manual distribution. Campaign quantity and per-member limits still apply.</p>
                    <?php if (!$manualCampaigns): ?>
                        <p>No active manual campaigns are available.</p>
                    <?php else: ?>
                        <form method="post" class="cv-form" data-confirm="Send this gift to the selected member?">
                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                            <input type="hidden" name="action" value="distribution_manual">
                            <input type="hidden" name="request_key" value="<?= coveted_e(coveted_uuid('distreq')) ?>">
                            <label>
                                Campaign / reward
                                <select name="campaign_ref" required>
                                    <?php foreach ($manualCampaigns as $campaign): ?>
                                        <option value="<?= coveted_e($campaign['public_id']) ?>"><?= coveted_e($campaign['title']) ?> → <?= coveted_e($campaign['reward_title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                Member
                                <select name="user_id" required>
                                    <?php foreach ($distributionUsers as $member): ?>
                                        <option value="<?= (int)$member['id'] ?>"><?= coveted_e($member['display_name']) ?> · <?= coveted_e($member['email']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                Internal note
                                <textarea name="note" maxlength="1000" rows="3" placeholder="Optional reason or context for this send"></textarea>
                            </label>
                            <button class="cv-button cv-button-primary" type="submit">Send Gift</button>
                        </form>
                    <?php endif; ?>
                </section>
            </div>

            <div class="cv-section-head">
                <div><span class="cv-eyebrow">AUDIT HISTORY</span><h2>Recent distribution runs</h2></div>
            </div>
            <div class="cv-table-wrap">
                <table class="cv-table">
                    <thead><tr><th>When</th><th>System Admin</th><th>Type</th><th>Campaign</th><th>Results</th></tr></thead>
                    <tbody>
                    <?php if (!$distributionRuns): ?>
                        <tr><td colspan="5">No distributions have been triggered yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($distributionRuns as $run): ?>
                        <?php $runMeta = json_decode((string)($run['metadata_json'] ?? ''), true) ?: []; ?>
                        <tr>
                            <td><?= coveted_e($formatAdminTime((string)$run['created_at'])) ?></td>
                            <td><?= coveted_e((string)($run['actor_name'] ?? 'System')) ?></td>
                            <td><?= $run['event_type'] === 'campaign.system_manual_distributed' ? 'Manual' : 'Event' ?></td>
                            <td><?= coveted_e((string)$run['entity_id']) ?></td>
                            <td>
                                <?php if ($run['event_type'] === 'campaign.system_manual_distributed'): ?>
                                    User <?= (int)($runMeta['user_id'] ?? 0) ?> · <?= !empty($runMeta['already_issued']) ? 'already issued' : 'issued' ?>
                                <?php else: ?>
                                    <?= (int)($runMeta['issued_count'] ?? 0) ?> issued · <?= (int)($runMeta['already_issued_count'] ?? 0) ?> existing · <?= (int)($runMeta['skipped_count'] ?? 0) ?> skipped
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($view === 'pwa'): ?>
            <?php
            $pushTransport = (string)($pwaStatus['push_transport'] ?? 'disabled');
            $pushLabel = match ($pushTransport) {
                'configured' => 'Ready',
                'incomplete' => 'Needs config',
                default => 'Disabled',
            };
            ?>
            <div class="cv-page-heading">
                <span class="cv-eyebrow">PWA & PUSH</span>
                <h1>Installed app infrastructure.</h1>
                <p>Manage install artwork and the canonical notification pipeline used by the PWA today and reserved native clients later.</p>
            </div>

            <div class="cv-stat-grid">
                <div class="cv-card cv-stat"><strong><?= (int)$pwaStatus['assets_ready'] ?>/<?= (int)$pwaStatus['assets_total'] ?></strong><span>Artwork slots</span></div>
                <div class="cv-card cv-stat"><strong><?= $pwaStatus['manifest_ready'] ? 'Ready' : 'Needs icons' ?></strong><span>Manifest</span></div>
                <div class="cv-card cv-stat"><strong><?= coveted_e($pushLabel) ?></strong><span>Web Push</span></div>
                <div class="cv-card cv-stat"><strong><?= (int)$notificationStats['devices_active'] ?></strong><span>Active devices</span></div>
                <div class="cv-card cv-stat"><strong><?= (int)$notificationStats['deliveries_pending'] ?></strong><span>Pending delivery</span></div>
                <div class="cv-card cv-stat"><strong><?= (int)$notificationStats['deliveries_sent'] ?></strong><span>Push sent</span></div>
            </div>

            <?php if ($pushTransport !== 'configured'): ?>
                <div class="cv-alert">Web Push code is installed, but server transport is <?= coveted_e($pushTransport) ?>. Configure valid VAPID settings and enable Push before expecting browser delivery.</div>
            <?php endif; ?>

            <div class="cv-section-head">
                <div>
                    <span class="cv-eyebrow">INSTALL ARTWORK</span>
                    <h2>Icons & splash screens</h2>
                    <p>PNG only. Re-uploading a slot replaces its active file; no versioned copies are kept.</p>
                </div>
            </div>

            <div class="cv-pwa-asset-grid">
                <?php foreach ($pwaDefinitions as $assetKey => $definition): ?>
                    <?php $asset = $pwaAssets[$assetKey] ?? null; ?>
                    <article class="cv-card cv-pwa-asset-card">
                        <div class="cv-pwa-preview <?= str_starts_with($assetKey, 'splash_') ? 'is-splash' : '' ?>">
                            <?php if ($asset): ?>
                                <img src="<?= coveted_e($asset['public_path']) ?>?v=<?= coveted_e(substr((string)$asset['checksum_sha256'], 0, 12)) ?>" alt="<?= coveted_e($definition['label']) ?> preview">
                            <?php else: ?>
                                <span>No artwork</span>
                            <?php endif; ?>
                        </div>

                        <div>
                            <span class="cv-kicker"><?= coveted_e(strtoupper(str_replace('_', ' ', $assetKey))) ?></span>
                            <h3><?= coveted_e($definition['label']) ?></h3>
                            <?php if ($asset): ?>
                                <p><?= (int)$asset['width'] ?>×<?= (int)$asset['height'] ?> · <?= coveted_e(number_format(((int)$asset['file_bytes']) / 1024, 0)) ?> KB</p>
                                <p class="cv-code-link"><?= coveted_e(substr((string)$asset['checksum_sha256'], 0, 16)) ?>…</p>
                            <?php else: ?>
                                <p>This slot has not been uploaded yet.</p>
                            <?php endif; ?>
                        </div>

                        <form method="post" enctype="multipart/form-data" class="cv-form cv-compact-form">
                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                            <input type="hidden" name="action" value="pwa_upload">
                            <input type="hidden" name="asset_key" value="<?= coveted_e($assetKey) ?>">
                            <label>
                                PNG file
                                <input type="file" name="asset" accept="image/png,.png" required>
                            </label>
                            <button class="cv-button cv-button-primary" type="submit"><?= $asset ? 'Replace' : 'Upload' ?></button>
                        </form>

                        <?php if ($asset): ?>
                            <form method="post" data-confirm="Remove this active PWA artwork?">
                                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                <input type="hidden" name="action" value="pwa_delete">
                                <input type="hidden" name="asset_key" value="<?= coveted_e($assetKey) ?>">
                                <button class="cv-button cv-button-soft" type="submit">Remove</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="cv-two-column cv-admin-section-gap">
                <section class="cv-card cv-form">
                    <span class="cv-eyebrow">NOTIFICATION TEST</span>
                    <h2>Create a test notification</h2>
                    <p>The canonical notification record is created first. Active PWA devices receive Web Push when transport configuration is ready.</p>
                    <?php if (!$pwaUsers): ?>
                        <p>No active members are available for a test.</p>
                    <?php else: ?>
                        <form method="post" class="cv-form">
                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                            <input type="hidden" name="action" value="pwa_test_notification">
                            <label>
                                Member
                                <select name="user_id" required>
                                    <?php foreach ($pwaUsers as $pwaUser): ?>
                                        <option value="<?= (int)$pwaUser['id'] ?>"><?= coveted_e($pwaUser['display_name']) ?> · <?= coveted_e($pwaUser['email']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                Title
                                <input name="title" maxlength="190" value="Coveted notification test">
                            </label>
                            <label>
                                Message
                                <textarea name="body" maxlength="2000" rows="3">Your Coveted notification system is working.</textarea>
                            </label>
                            <button class="cv-button cv-button-primary" type="submit">Create Test Notification</button>
                        </form>
                    <?php endif; ?>
                </section>

                <section class="cv-card">
                    <span class="cv-eyebrow">DELIVERY PIPELINE</span>
                    <h2>One record, multiple clients.</h2>
                    <div class="cv-stack cv-stack-tight">
                        <div class="cv-mini-row"><strong>In-app</strong><span>Active</span></div>
                        <div class="cv-mini-row"><strong>Web Push</strong><span><?= coveted_e($pushLabel) ?></span></div>
                        <div class="cv-mini-row"><strong>Native iOS</strong><span>Transport reserved</span></div>
                        <div class="cv-mini-row"><strong>Native Android</strong><span>Transport reserved</span></div>
                    </div>
                    <div class="cv-meta-row">
                        <span><?= (int)$notificationStats['notifications_total'] ?> notifications</span>
                        <span><?= (int)$notificationStats['notifications_unread'] ?> unread</span>
                        <span><?= (int)$notificationStats['deliveries_failed'] ?> failed delivery</span>
                    </div>
                </section>
            </div>

            <div class="cv-section-head">
                <div><span class="cv-eyebrow">RECENT</span><h2>Notification history</h2></div>
            </div>
            <div class="cv-table-wrap">
                <table class="cv-table">
                    <thead><tr><th>Member</th><th>Type</th><th>Notification</th><th>Status</th><th>Created</th></tr></thead>
                    <tbody>
                    <?php if (!$recentNotifications): ?>
                        <tr><td colspan="5">No notifications yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($recentNotifications as $notification): ?>
                        <tr>
                            <td><strong><?= coveted_e($notification['display_name']) ?></strong><br><span><?= coveted_e($notification['email']) ?></span></td>
                            <td><?= coveted_e($notification['notification_type']) ?></td>
                            <td><strong><?= coveted_e($notification['title']) ?></strong><?php if ($notification['body']): ?><br><span><?= coveted_e($notification['body']) ?></span><?php endif; ?></td>
                            <td><span class="cv-status"><?= $notification['read_at'] ? 'Read' : 'Unread' ?></span></td>
                            <td><?= coveted_e($formatAdminTime((string)$notification['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>