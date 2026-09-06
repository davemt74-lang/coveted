<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/system_sample_data.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
$view = strtolower(trim((string)($_GET['view'] ?? 'dashboard')));
$businessRef = trim((string)($_GET['business'] ?? ''));
$artistRef = trim((string)($_GET['artist'] ?? ''));

$liveRoutes = [
    'dashboard' => '/admin/?view=dashboard',
    'people' => '/admin/?view=users',
    'requests' => '/admin/?view=requests',
    'crm' => '/admin/crm.php',
    'cities' => '/admin/cities.php',
    'businesses' => '/admin/?view=businesses',
    'partner' => '/venue-relationships.php',
    'groups' => '/admin/?view=groups',
    'events' => '/admin/?view=events',
    'artists' => '/admin/?view=artists',
    'loyalty' => '/admin/loyalty.php',
    'benefits' => '/admin/?view=benefits',
];

if (!isset($liveRoutes[$view])) {
    $view = 'dashboard';
}

if (!coveted_system_sample_mode($admin, $pdo)) {
    $target = $liveRoutes[$view];
    if ($view === 'partner' && $businessRef !== '') {
        $target .= '?business=' . rawurlencode($businessRef);
    }
    coveted_redirect($target);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Full System Sample Mode is read-only.');
}

$sample = coveted_system_sample_data();
$counts = coveted_system_sample_admin_counts($sample);
$inventory = coveted_system_sample_inventory($sample);

$active = match ($view) {
    'people' => 'users',
    'requests' => 'requests',
    'crm' => 'crm',
    'cities' => 'cities',
    'businesses', 'partner' => 'businesses',
    'groups' => 'groups',
    'events' => 'events',
    'artists' => 'artists',
    'loyalty' => 'loyalty',
    'benefits' => 'benefits',
    default => 'dashboard',
};

$pageTitle = match ($view) {
    'people' => 'People & Access',
    'requests' => 'Role Requests',
    'crm' => 'Invite CRM',
    'cities' => 'Cities',
    'businesses' => 'Businesses',
    'partner' => 'Partner Profile',
    'groups' => 'Groups',
    'events' => 'Events',
    'artists' => 'Artists',
    'loyalty' => 'Group Loyalty',
    'benefits' => 'Benefits',
    default => 'Dashboard',
};

$formatTime = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }
    try {
        return coveted_utc_datetime($value)->setTimezone(new DateTimeZone('America/Phoenix'))->format('M j, Y · g:i A');
    } catch (Throwable) {
        return $value;
    }
};

$filterBy = static function (array $rows, string $key, string $value): array {
    return array_values(array_filter($rows, static fn(array $row): bool => (string)($row[$key] ?? '') === $value));
};

$findBy = static function (array $rows, string $key, string $value): ?array {
    foreach ($rows as $row) {
        if ((string)($row[$key] ?? '') === $value) {
            return $row;
        }
    }
    return null;
};

$crmStatusCounts = ['new'=>0,'contacted'=>0,'qualified'=>0,'converted'=>0,'declined'=>0];
foreach ((array)$sample['invite_crm'] as $row) {
    $status = (string)($row['status'] ?? '');
    if (array_key_exists($status, $crmStatusCounts)) {
        $crmStatusCounts[$status]++;
    }
}

$selectedBusiness = null;
if ($view === 'partner') {
    $selectedBusiness = $findBy((array)$sample['businesses'], 'public_id', $businessRef);
    if ($selectedBusiness === null) {
        $selectedBusiness = (array)($sample['businesses'][0] ?? []);
        $businessRef = (string)($selectedBusiness['public_id'] ?? '');
    }
}

coveted_page_start($pageTitle, '', true);
coveted_admin_ui_start($admin, $active, $pageTitle, $counts);
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">SYNTHETIC SYSTEM VIEW</span>
        <h1><?= coveted_e($pageTitle) ?></h1>
        <p>Read-only sample data from the canonical Coveted Full System Demo. Turn Sample Mode off to return to live production records.</p>
    </div>
    <a class="cv-button cv-button-soft" href="/admin/sample-data.php">Sample Data Control</a>
</div>

<nav class="cv-action-row" aria-label="Sample system views">
    <?php foreach ([
        'dashboard'=>'Dashboard','people'=>'People','crm'=>'CRM','businesses'=>'Businesses','groups'=>'Groups',
        'events'=>'Events','artists'=>'Artists','loyalty'=>'Loyalty','benefits'=>'Benefits'
    ] as $key => $label): ?>
        <a class="cv-button <?= $view === $key ? 'cv-button-primary' : 'cv-button-soft' ?>" href="/admin/system-preview.php?view=<?= coveted_e($key) ?>"><?= coveted_e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($view === 'dashboard'): ?>
    <div class="cv-admin-metric-grid cv-admin-section-gap">
        <a href="/admin/system-preview.php?view=people"><span>Users</span><strong><?= (int)$counts['users'] ?></strong><small>Accounts & access</small></a>
        <a href="/admin/system-preview.php?view=businesses"><span>Businesses</span><strong><?= (int)$counts['businesses'] ?></strong><small>Partner network</small></a>
        <a href="/admin/system-preview.php?view=groups"><span>Groups</span><strong><?= (int)$counts['groups'] ?></strong><small>Private communities</small></a>
        <a href="/admin/system-preview.php?view=events"><span>Events</span><strong><?= (int)$counts['events'] ?></strong><small>Full lifecycle mix</small></a>
        <a href="/admin/system-preview.php?view=artists"><span>Artists</span><strong><?= (int)$counts['artists'] ?></strong><small>Partner identities</small></a>
    </div>

    <div class="cv-admin-dashboard-grid cv-admin-section-gap">
        <section class="cv-admin-panel">
            <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">NEXT MOVES</span><h2>Agent opportunities</h2></div><a href="/admin/agent.php">Open Agent →</a></div>
            <div class="cv-admin-list">
                <?php foreach (array_slice((array)$sample['agent']['opportunities'], 0, 6) as $opportunity): ?>
                    <div class="cv-admin-list-row">
                        <span class="cv-admin-list-copy"><strong><?= coveted_e((string)$opportunity['title']) ?></strong><small><?= coveted_e((string)$opportunity['category']) ?> · <?= coveted_e((string)$opportunity['evidence']) ?></small></span>
                        <span>P<?= (int)$opportunity['priority'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="cv-admin-panel">
            <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">OPERATING LOOP</span><h2>System coverage</h2></div></div>
            <div class="cv-admin-definition-list">
                <div><dt>CRM prospects</dt><dd><?= (int)$inventory['invite_crm'] ?></dd></div>
                <div><dt>Daily Events</dt><dd><?= (int)$inventory['daily_events'] ?></dd></div>
                <div><dt>Partner relationships</dt><dd><?= (int)$inventory['partner_relationships'] ?></dd></div>
                <div><dt>Benefit Programs</dt><dd><?= (int)$inventory['benefit_programs'] ?></dd></div>
                <div><dt>Claims</dt><dd><?= (int)$inventory['claims'] ?></dd></div>
                <div><dt>Agent tasks</dt><dd><?= (int)$inventory['agent_tasks'] ?></dd></div>
            </div>
        </section>
    </div>

    <section class="cv-admin-panel cv-admin-section-gap">
        <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">UPCOMING</span><h2>Events</h2></div><a href="/admin/system-preview.php?view=events">View all →</a></div>
        <div class="cv-admin-list">
            <?php foreach (array_slice(array_values(array_filter((array)$sample['events'], static fn(array $row): bool => in_array((string)$row['status'], ['draft','published'], true))), 0, 5) as $event): ?>
                <div class="cv-admin-list-row">
                    <span class="cv-admin-list-copy"><strong><?= coveted_e((string)$event['title']) ?></strong><small><?= coveted_e((string)$event['group_name']) ?> · <?= coveted_e((string)$event['location_name']) ?> · <?= coveted_e($formatTime((string)$event['starts_at'])) ?></small></span>
                    <span class="cv-status"><?= coveted_e(ucfirst((string)$event['status'])) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($view === 'people'): ?>
    <div class="cv-admin-metric-grid cv-admin-metric-grid-four">
        <div><span>People</span><strong><?= count((array)$sample['people']) ?></strong><small>Synthetic accounts</small></div>
        <div><span>Active</span><strong><?= count(array_filter((array)$sample['people'], static fn(array $row): bool => (string)$row['status'] === 'active')) ?></strong><small>Usable members</small></div>
        <div><span>Host capable</span><strong><?= count(array_filter((array)$sample['people'], static fn(array $row): bool => in_array('attendee_host', (array)$row['roles'], true))) ?></strong><small>Attendee Hosts</small></div>
        <div><span>Pending roles</span><strong><?= (int)$counts['pending_requests'] ?></strong><small><a href="/admin/system-preview.php?view=requests">Review sample queue</a></small></div>
    </div>
    <section class="cv-admin-panel cv-admin-section-gap">
        <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PEOPLE</span><h2>Accounts & access</h2></div></div>
        <div class="cv-admin-list">
            <?php foreach ((array)$sample['people'] as $person): ?>
                <div class="cv-admin-list-row">
                    <span class="cv-admin-list-avatar"><?= coveted_e(coveted_shell_initials((string)$person['display_name'])) ?></span>
                    <span class="cv-admin-list-copy"><strong><?= coveted_e((string)$person['display_name']) ?></strong><small><?= coveted_e((string)$person['email']) ?> · <?= coveted_e((string)$person['city']) ?> · <?= coveted_e(implode(', ', array_map(static fn(string $role): string => ucwords(str_replace('_',' ',$role)), (array)$person['roles']))) ?></small></span>
                    <span class="cv-status"><?= coveted_e(ucfirst((string)$person['status'])) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($view === 'requests'): ?>
    <section class="cv-admin-panel">
        <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">ROLE REQUESTS</span><h2>Access approvals</h2></div><span class="cv-pill">Read only</span></div>
        <div class="cv-admin-list">
            <?php foreach ((array)$sample['role_requests'] as $request): ?>
                <div class="cv-admin-list-row">
                    <span class="cv-admin-list-copy"><strong><?= coveted_e((string)$request['display_name']) ?> · <?= coveted_e(ucwords(str_replace('_',' ',(string)$request['role_key']))) ?></strong><small><?= coveted_e((string)$request['email']) ?> · <?= coveted_e((string)$request['request_note']) ?> · <?= coveted_e($formatTime((string)$request['created_at'])) ?></small></span>
                    <span class="cv-status"><?= coveted_e(ucfirst((string)$request['status'])) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($view === 'crm'): ?>
    <div class="cv-crm-metrics">
        <?php foreach ($crmStatusCounts as $status => $count): ?><div><span><?= coveted_e(ucfirst($status)) ?></span><strong><?= (int)$count ?></strong></div><?php endforeach; ?>
    </div>
    <section class="cv-admin-panel cv-admin-section-gap">
        <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PIPELINE</span><h2>Invite CRM prospects</h2></div><span class="cv-pill"><?= count((array)$sample['invite_crm']) ?> records</span></div>
        <div class="cv-admin-list">
            <?php foreach ((array)$sample['invite_crm'] as $lead): ?>
                <article class="cv-admin-list-row">
                    <span class="cv-admin-list-copy">
                        <strong><?= coveted_e((string)$lead['full_name']) ?> · <?= coveted_e((string)$lead['company']) ?></strong>
                        <small><?= coveted_e((string)$lead['email']) ?> · <?= coveted_e((string)$lead['city']) ?> · <?= coveted_e((string)$lead['source']) ?></small>
                        <small><?= coveted_e((string)$lead['admin_note']) ?> Next: <?= coveted_e((string)$lead['next_action']) ?></small>
                    </span>
                    <span><strong><?= (int)$lead['score'] ?></strong><br><span class="cv-status"><?= coveted_e(ucfirst((string)$lead['status'])) ?></span></span>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($view === 'cities'): ?>
    <section class="cv-admin-panel">
        <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">MARKETS</span><h2>Sample city network</h2></div></div>
        <div class="cv-admin-table-scroll"><table class="cv-admin-table"><thead><tr><th>City</th><th>Members</th><th>Events</th><th>Partners</th><th>Status</th></tr></thead><tbody>
        <?php foreach ((array)$sample['cities'] as $city): ?><tr><td><strong><?= coveted_e((string)$city['name']) ?></strong><small><?= coveted_e((string)$city['region']) ?></small></td><td><?= number_format((int)$city['members']) ?></td><td><?= number_format((int)$city['events']) ?></td><td><?= number_format((int)$city['partners']) ?></td><td><span class="cv-status"><?= coveted_e(ucfirst((string)$city['status'])) ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
<?php endif; ?>

<?php if ($view === 'businesses'): ?>
    <div class="cv-admin-card-grid">
        <?php foreach ((array)$sample['businesses'] as $business): ?>
            <?php
            $relationships = $filterBy((array)$sample['partner_relationships'], 'business_ref', (string)$business['public_id']);
            $locations = $filterBy((array)$sample['locations'], 'business_ref', (string)$business['public_id']);
            $verified = array_sum(array_map(static fn(array $row): int => (int)($row['verified_visits'] ?? 0), $relationships));
            ?>
            <article class="cv-admin-entity-card">
                <div class="cv-admin-entity-avatar"><?php if (!empty($business['logo_url'])): ?><img src="<?= coveted_e((string)$business['logo_url']) ?>" alt=""><?php else: ?><span><?= coveted_e(coveted_shell_initials((string)$business['name'])) ?></span><?php endif; ?></div>
                <div><span class="cv-status"><?= coveted_e(ucfirst((string)$business['status'])) ?></span><h3><?= coveted_e((string)$business['name']) ?></h3><p><?= coveted_e((string)$business['category_label']) ?> · <?= count($locations) ?> location<?= count($locations) === 1 ? '' : 's' ?></p></div>
                <div class="cv-admin-mini-stats"><span><strong><?= (int)$business['reward_count'] ?></strong>rewards</span><span><strong><?= (int)$business['campaign_count'] ?></strong>campaigns</span><span><strong><?= $verified ?></strong>visits</span></div>
                <a class="cv-text-link" href="/admin/system-preview.php?view=partner&amp;business=<?= coveted_e((string)$business['public_id']) ?>">Open Partner Profile →</a>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($view === 'partner' && $selectedBusiness): ?>
    <?php
    $relationships = $filterBy((array)$sample['partner_relationships'], 'business_ref', $businessRef);
    $locations = $filterBy((array)$sample['locations'], 'business_ref', $businessRef);
    $contacts = $filterBy((array)$sample['partner_contacts'], 'business_ref', $businessRef);
    $interactions = $filterBy((array)$sample['partner_interactions'], 'business_ref', $businessRef);
    $notes = $filterBy((array)$sample['partner_notes'], 'business_ref', $businessRef);
    $followups = $filterBy((array)$sample['partner_followups'], 'business_ref', $businessRef);
    $perks = $filterBy((array)$sample['partner_perks'], 'business_ref', $businessRef);
    $dailyEvents = $filterBy((array)$sample['daily_events'], 'business_ref', $businessRef);
    ?>
    <div class="cv-admin-page-head"><div><span class="cv-eyebrow">PARTNER PROFILE CRM</span><h1><?= coveted_e((string)$selectedBusiness['name']) ?></h1><p><?= coveted_e((string)$selectedBusiness['description']) ?></p></div><a class="cv-button cv-button-soft" href="/admin/system-preview.php?view=businesses">← Businesses</a></div>
    <div class="cv-admin-metric-grid cv-admin-metric-grid-four">
        <div><span>Locations</span><strong><?= count($locations) ?></strong><small>Canonical venue examples</small></div>
        <div><span>Relationships</span><strong><?= count($relationships) ?></strong><small>Group × location</small></div>
        <div><span>Contacts</span><strong><?= count($contacts) ?></strong><small>Partner people</small></div>
        <div><span>Open follow-ups</span><strong><?= count(array_filter($followups, static fn(array $row): bool => (string)$row['status'] === 'open')) ?></strong><small>Next actions</small></div>
    </div>
    <div class="cv-admin-dashboard-grid cv-admin-section-gap">
        <section class="cv-admin-panel"><div class="cv-admin-panel-head"><div><span class="cv-eyebrow">RELATIONSHIPS</span><h2>Group performance</h2></div></div><div class="cv-admin-list">
            <?php foreach ($relationships as $relationship): ?><div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e((string)$relationship['group_name']) ?> × <?= coveted_e((string)$relationship['location_name']) ?></strong><small><?= (int)$relationship['completed_events'] ?> completed · <?= (int)$relationship['verified_visits'] ?> verified visits · <?= (int)$relationship['return_claims'] ?> return claims</small></span><span class="cv-status"><?= coveted_e(ucwords(str_replace('_',' ',(string)$relationship['relationship_status']))) ?></span></div><?php endforeach; ?>
        </div></section>
        <section class="cv-admin-panel"><div class="cv-admin-panel-head"><div><span class="cv-eyebrow">CONTACTS</span><h2>Partner people</h2></div></div><div class="cv-admin-list">
            <?php foreach ($contacts as $contact): ?><div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e((string)$contact['full_name']) ?></strong><small><?= coveted_e((string)$contact['role_title']) ?> · <?= coveted_e((string)$contact['preferred_contact']) ?></small></span><?= !empty($contact['is_primary']) ? '<span class="cv-pill">Primary</span>' : '' ?></div><?php endforeach; ?>
        </div></section>
    </div>
    <section class="cv-admin-panel cv-admin-section-gap"><div class="cv-admin-panel-head"><div><span class="cv-eyebrow">FOLLOW-UPS</span><h2>Next actions</h2></div></div><div class="cv-admin-list">
        <?php foreach ($followups as $followup): ?><div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e((string)$followup['title']) ?></strong><small><?= coveted_e((string)$followup['contact_name']) ?> · <?= coveted_e((string)$followup['detail']) ?> · Due <?= coveted_e($formatTime((string)$followup['due_at'])) ?></small></span><span class="cv-status"><?= coveted_e(ucfirst((string)$followup['status'])) ?></span></div><?php endforeach; ?>
    </div></section>
    <div class="cv-admin-dashboard-grid cv-admin-section-gap">
        <section class="cv-admin-panel"><div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PARTNER PERKS</span><h2>Ongoing member value</h2></div></div><div class="cv-admin-list">
            <?php foreach ($perks as $perk): ?><div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e((string)$perk['title']) ?></strong><small><?= coveted_e((string)$perk['description']) ?> · <?= (int)$perk['claimed_count'] ?>/<?= (int)$perk['issued_count'] ?> claimed</small></span><span class="cv-status"><?= coveted_e(ucfirst((string)$perk['status'])) ?></span></div><?php endforeach; ?>
        </div></section>
        <section class="cv-admin-panel"><div class="cv-admin-panel-head"><div><span class="cv-eyebrow">DAILY EVENTS</span><h2>Partnered event activity</h2></div></div><div class="cv-admin-list">
            <?php foreach ($dailyEvents as $daily): ?><div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e((string)$daily['title']) ?></strong><small><?= (int)$daily['verified_attendance'] ?>/<?= (int)$daily['attendance_threshold'] ?> verified · <?= (int)$daily['loyalty_points'] ?> Loyalty pts · <?= coveted_e((string)$daily['reward_title']) ?></small></span><span class="cv-status"><?= !empty($daily['reward_unlocked_at']) ? 'Unlocked' : 'Pending' ?></span></div><?php endforeach; ?>
        </div></section>
    </div>
    <section class="cv-admin-panel cv-admin-section-gap"><div class="cv-admin-panel-head"><div><span class="cv-eyebrow">RELATIONSHIP HISTORY</span><h2>Notes & conversations</h2></div></div><div class="cv-admin-list">
        <?php foreach ($interactions as $interaction): ?><div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e((string)$interaction['subject']) ?></strong><small><?= coveted_e(ucfirst((string)$interaction['interaction_type'])) ?> with <?= coveted_e((string)$interaction['contact_name']) ?> · <?= coveted_e((string)$interaction['summary']) ?></small></span><span><?= coveted_e($formatTime((string)$interaction['occurred_at'])) ?></span></div><?php endforeach; ?>
        <?php foreach ($notes as $note): ?><div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e(ucfirst((string)$note['note_type'])) ?> note</strong><small><?= coveted_e((string)$note['body']) ?></small></span><span><?= coveted_e($formatTime((string)$note['created_at'])) ?></span></div><?php endforeach; ?>
    </div></section>
<?php endif; ?>

<?php if ($view === 'groups'): ?>
    <div class="cv-admin-card-grid">
        <?php foreach ((array)$sample['groups'] as $group): ?>
            <article class="cv-admin-entity-card"><div class="cv-admin-entity-avatar"><img src="<?= coveted_e((string)$group['image']) ?>" alt=""></div><div><span class="cv-status"><?= coveted_e(ucfirst((string)$group['status'])) ?></span><h3><?= coveted_e((string)$group['name']) ?></h3><p><?= coveted_e((string)$group['city']) ?> · <?= coveted_e((string)$group['description']) ?></p></div><div class="cv-admin-mini-stats"><span><strong><?= (int)$group['member_count'] ?></strong>members</span><span><strong><?= (int)$group['event_count'] ?></strong>events</span><span><strong><?= coveted_e((string)$group['next']) ?></strong>next</span></div></article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($view === 'events'): ?>
    <div class="cv-admin-metric-grid cv-admin-metric-grid-four">
        <div><span>Events</span><strong><?= count((array)$sample['events']) ?></strong><small>Draft + future + completed</small></div>
        <div><span>Published future</span><strong><?= count(array_filter((array)$sample['events'], static fn(array $row): bool => (string)$row['status'] === 'published')) ?></strong><small>Scheduled gatherings</small></div>
        <div><span>Completed</span><strong><?= count(array_filter((array)$sample['events'], static fn(array $row): bool => (string)$row['status'] === 'completed')) ?></strong><small>Historical proof</small></div>
        <div><span>Daily Events</span><strong><?= count((array)$sample['daily_events']) ?></strong><small>Partnered opportunities</small></div>
    </div>
    <section class="cv-admin-panel cv-admin-section-gap"><div class="cv-admin-panel-head"><div><span class="cv-eyebrow">EVENT LIFECYCLE</span><h2>Gatherings & sessions</h2></div></div><div class="cv-admin-list">
        <?php foreach ((array)$sample['events'] as $event): ?>
            <?php $daily = $findBy((array)$sample['daily_events'], 'event_ref', (string)$event['public_id']); ?>
            <div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e((string)$event['title']) ?></strong><small><?= coveted_e((string)$event['group_name']) ?> · <?= coveted_e((string)$event['location_name']) ?> · <?= coveted_e($formatTime((string)$event['starts_at'])) ?> · <?= (int)$event['attending_count'] ?> attending<?php if ($daily): ?> · Daily Event <?= (int)$daily['verified_attendance'] ?>/<?= (int)$daily['attendance_threshold'] ?> verified · <?= (int)$daily['loyalty_points'] ?> pts<?php endif; ?></small></span><span class="cv-status"><?= coveted_e(ucfirst((string)$event['status'])) ?></span></div>
        <?php endforeach; ?>
    </div></section>
<?php endif; ?>

<?php if ($view === 'artists'): ?>
    <?php foreach ((array)$sample['artists'] as $artist): ?>
        <?php
        if ($artistRef !== '' && (string)$artist['public_id'] !== $artistRef) continue;
        $media = $filterBy((array)$sample['artist_media'], 'artist_ref', (string)$artist['public_id']);
        $appearances = $filterBy((array)$sample['artist_appearances'], 'artist_ref', (string)$artist['public_id']);
        ?>
        <section class="cv-admin-panel cv-admin-section-gap">
            <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">ARTIST PARTNER</span><h2><?= coveted_e((string)$artist['artist_name']) ?></h2><p><?= coveted_e((string)$artist['bio']) ?></p></div><span class="cv-status"><?= coveted_e(ucfirst((string)$artist['status'])) ?></span></div>
            <div class="cv-admin-metric-grid cv-admin-metric-grid-four"><div><span>Team</span><strong><?= (int)$artist['team_count'] ?></strong></div><div><span>Appearances</span><strong><?= (int)$artist['appearance_count'] ?></strong></div><div><span>Rewards</span><strong><?= (int)$artist['reward_count'] ?></strong></div><div><span>Media</span><strong><?= count($media) ?></strong></div></div>
            <div class="cv-admin-dashboard-grid cv-admin-section-gap"><div><span class="cv-eyebrow">MEDIA</span><div class="cv-admin-list"><?php foreach ($media as $item): ?><div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e((string)$item['title']) ?></strong><small><?= coveted_e(ucfirst((string)$item['media_type'])) ?> · <?= coveted_e((string)$item['duration']) ?> · Reward <?= !empty($item['reward_enabled']) ? 'enabled' : 'off' ?></small></span><span class="cv-status"><?= coveted_e(ucfirst((string)$item['status'])) ?></span></div><?php endforeach; ?></div></div><div><span class="cv-eyebrow">APPEARANCES</span><div class="cv-admin-list"><?php foreach ($appearances as $appearance): ?><div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e((string)$appearance['event']) ?></strong><small><?= coveted_e(ucfirst((string)$appearance['role'])) ?> appearance</small></span><span class="cv-status"><?= coveted_e(ucfirst((string)$appearance['status'])) ?></span></div><?php endforeach; ?></div></div></div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($view === 'loyalty'): ?>
    <div class="cv-admin-card-grid">
        <?php foreach ((array)$sample['loyalty'] as $loyalty): ?>
            <article class="cv-admin-entity-card"><div><span class="cv-status"><?= coveted_e((string)$loyalty['top_tier']) ?></span><h3><?= coveted_e((string)$loyalty['group_name']) ?></h3><p><?= (int)$loyalty['active_members'] ?>/<?= (int)$loyalty['members'] ?> active members · <?= (int)$loyalty['streak_members'] ?> streak members</p></div><div class="cv-admin-mini-stats"><span><strong><?= number_format((int)$loyalty['points_issued']) ?></strong>issued</span><span><strong><?= number_format((int)$loyalty['points_redeemed']) ?></strong>redeemed</span><span><strong><?= (int)$loyalty['avg_points'] ?></strong>avg pts</span></div></article>
        <?php endforeach; ?>
    </div>
    <section class="cv-admin-panel cv-admin-section-gap"><div class="cv-admin-panel-head"><div><span class="cv-eyebrow">WALLET VALUE</span><h2>Member benefits</h2></div></div><div class="cv-admin-list"><?php foreach ((array)$sample['wallet'] as $wallet): ?><div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e((string)$wallet['title']) ?></strong><small><?= coveted_e((string)$wallet['partner']) ?> · <?= coveted_e((string)$wallet['value']) ?> · <?= coveted_e((string)$wallet['description']) ?></small></span><span class="cv-status"><?= coveted_e((string)$wallet['status']) ?></span></div><?php endforeach; ?></div></section>
<?php endif; ?>

<?php if ($view === 'benefits'): ?>
    <div class="cv-admin-metric-grid cv-admin-metric-grid-four">
        <div><span>Rewards</span><strong><?= count((array)$sample['rewards']) ?></strong><small>Templates</small></div>
        <div><span>Campaigns</span><strong><?= count((array)$sample['campaigns']) ?></strong><small>Active trigger logic</small></div>
        <div><span>Benefit Programs</span><strong><?= count((array)$sample['benefit_programs']) ?></strong><small>Pooled value</small></div>
        <div><span>Claims</span><strong><?= count((array)$sample['claims']) ?></strong><small>Observed redemption</small></div>
    </div>
    <section class="cv-admin-panel cv-admin-section-gap"><div class="cv-admin-panel-head"><div><span class="cv-eyebrow">BENEFIT PROGRAMS</span><h2>Program performance</h2></div></div><div class="cv-admin-list"><?php foreach ((array)$sample['benefit_programs'] as $program): ?><div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e((string)$program['title']) ?></strong><small><?= coveted_e((string)$program['owner']) ?> · <?= coveted_e((string)$program['trigger']) ?> → <?= coveted_e((string)$program['reward']) ?> · <?= (int)$program['issued'] ?>/<?= (int)$program['pool'] ?> issued</small></span><span><strong><?= number_format((float)$program['claim_rate'], 1) ?>%</strong><br><span class="cv-status"><?= coveted_e(ucfirst((string)$program['status'])) ?></span></span></div><?php endforeach; ?></div></section>
    <div class="cv-admin-dashboard-grid cv-admin-section-gap">
        <section class="cv-admin-panel"><div class="cv-admin-panel-head"><div><span class="cv-eyebrow">SPONSORSHIPS</span><h2>Merchant proposals</h2></div></div><div class="cv-admin-list"><?php foreach ((array)$sample['sponsorships'] as $sponsor): ?><div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e((string)$sponsor['program']) ?></strong><small><?= coveted_e((string)$sponsor['business']) ?> · <?= (int)$sponsor['quantity_limit'] ?> rewards · <?= coveted_e((string)$sponsor['estimated_value']) ?></small></span><span class="cv-status"><?= coveted_e(ucfirst((string)$sponsor['status'])) ?></span></div><?php endforeach; ?></div></section>
        <section class="cv-admin-panel"><div class="cv-admin-panel-head"><div><span class="cv-eyebrow">DISTRIBUTION</span><h2>Recent runs</h2></div></div><div class="cv-admin-list"><?php foreach ((array)$sample['distribution'] as $run): ?><div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e((string)$run['campaign']) ?></strong><small><?= coveted_e((string)$run['event']) ?> · <?= (int)$run['issued_count'] ?> issued · <?= (int)$run['skipped_count'] ?> skipped</small></span><span><?= coveted_e($formatTime((string)$run['created_at'])) ?></span></div><?php endforeach; ?></div></section>
    </div>
    <section class="cv-admin-panel cv-admin-section-gap"><div class="cv-admin-panel-head"><div><span class="cv-eyebrow">CLAIMS</span><h2>Observed value</h2></div></div><div class="cv-admin-list"><?php foreach ((array)$sample['claims'] as $claim): ?><div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e((string)$claim['reward']) ?> · <?= coveted_e((string)$claim['member']) ?></strong><small><?= coveted_e((string)$claim['business']) ?> · <?= coveted_e((string)$claim['location']) ?> · <?= coveted_e((string)$claim['value']) ?></small></span><span class="cv-status"><?= coveted_e(ucfirst((string)$claim['status'])) ?></span></div><?php endforeach; ?></div></section>
<?php endif; ?>

<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
