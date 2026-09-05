<?php
declare(strict_types=1);

require_once __DIR__ . '/app/events.php';

$user = coveted_require_user();
$events = coveted_events_for_user($user, 100);
$isApprovedHost = coveted_event_actor_has_host_approval($user);
$isSystemAdmin = coveted_is_system_admin($user);
foreach ($events as &$event) {
    $event['assigned_host_role'] = $isSystemAdmin
        ? 'system_admin'
        : (coveted_event_assigned_host_role((int)$event['id'], (int)$user['id']) ?? '');
}
unset($event);
$now = time();

$upcomingEvents = array_values(array_filter(
    $events,
    static fn(array $event): bool => coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() >= $now
        && !in_array((string)$event['status'], ['completed', 'cancelled'], true)
));
usort(
    $upcomingEvents,
    static fn(array $a, array $b): int => strcmp((string)$a['starts_at'], (string)$b['starts_at'])
);

$historyEvents = array_values(array_filter(
    $events,
    static fn(array $event): bool => coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() < $now
        || in_array((string)$event['status'], ['completed', 'cancelled'], true)
));
usort(
    $historyEvents,
    static fn(array $a, array $b): int => strcmp((string)$b['starts_at'], (string)$a['starts_at'])
);
$reconnectHistory = array_values(array_filter(
    $historyEvents,
    static fn(array $event): bool => $event['status'] === 'completed'
        && in_array((string)($event['attendance_status'] ?? ''), ['checked_in', 'attended', 'left_early'], true)
));

$attendingCount = count(array_filter(
    $upcomingEvents,
    static fn(array $event): bool => $event['response'] === 'attending'
));
$mysteryCount = count(array_filter(
    $upcomingEvents,
    static fn(array $event): bool => $event['event_type'] === 'mystery'
));

$hostUpcoming = array_values(array_filter(
    $upcomingEvents,
    static fn(array $event): bool => !empty($event['assigned_host_role'])
));
$hostHistory = array_values(array_filter(
    $historyEvents,
    static fn(array $event): bool => !empty($event['assigned_host_role'])
));
$hostingEvents = array_merge($hostUpcoming, $hostHistory);
$hostingCount = count($hostingEvents);

$view = strtolower(trim((string)($_GET['view'] ?? 'upcoming')));
if ($view === 'past') {
    $view = 'history';
}
if (!in_array($view, ['upcoming', 'history', 'mystery', 'hosting'], true)) {
    $view = 'upcoming';
}

$visibleEvents = match ($view) {
    'history' => $historyEvents,
    'mystery' => array_values(array_filter(
        $upcomingEvents,
        static fn(array $event): bool => $event['event_type'] === 'mystery'
    )),
    'hosting' => $hostingEvents,
    default => $upcomingEvents,
};

$featuredEvent = null;
foreach ($upcomingEvents as $event) {
    if ($event['response'] === 'attending') {
        $featuredEvent = $event;
        break;
    }
    $featuredEvent ??= $event;
}

coveted_page_start('Events', 'Events');
?>
<section class="cv-page-heading">
    <span class="cv-eyebrow">EVENTS</span>
    <h1>Show up for something real.</h1>
    <p>Your calendar is intentionally small: the gatherings you’re attending, invited to, hosting or have already shared with people.</p>
</section>

<section class="cv-stat-grid cv-home-stats" aria-label="Event summary">
    <a class="cv-card cv-stat" href="/events.php?view=upcoming">
        <strong><?= count($upcomingEvents) ?></strong>
        <span>Upcoming</span>
    </a>
    <a class="cv-card cv-stat" href="/events.php?view=upcoming">
        <strong><?= $attendingCount ?></strong>
        <span>Attending</span>
    </a>
    <a class="cv-card cv-stat" href="/events.php?view=mystery">
        <strong><?= $mysteryCount ?></strong>
        <span>Mystery</span>
    </a>
    <a class="cv-card cv-stat" href="<?= $isApprovedHost ? '/events.php?view=hosting' : '/events.php?view=history' ?>">
        <strong><?= $isApprovedHost ? $hostingCount : count($historyEvents) ?></strong>
        <span><?= $isApprovedHost ? 'Hosting' : 'History' ?></span>
    </a>
</section>

<?php if ($featuredEvent): ?>
    <?php
    $featuredCanManage = (bool)$featuredEvent['can_manage'];
    $featuredIsHost = $featuredCanManage || !empty($featuredEvent['assigned_host_role']);
    $featuredShowLocation = $featuredIsHost
        || $featuredEvent['location_visibility'] === 'immediate'
        || (
            $featuredEvent['location_visibility'] === 'scheduled_reveal'
            && (bool)$featuredEvent['location_revealed']
        );
    $featuredLabel = $featuredEvent['response'] === 'attending'
        ? 'NEXT GATHERING'
        : ($featuredIsHost
            ? 'NEXT EVENT YOU HOST'
            : ($featuredEvent['invitation_status'] === 'pending' ? 'INVITATION WAITING' : 'UPCOMING'));
    ?>
    <article class="cv-card cv-feature-card cv-copy-card">
        <span class="cv-kicker"><?= coveted_e($featuredLabel) ?></span>
        <h2><?= coveted_e($featuredEvent['title']) ?></h2>
        <p>
            <?= coveted_e($featuredEvent['group_name']) ?> · <?= coveted_e(coveted_event_format($featuredEvent, 'l, F j · g:i A')) ?>
            <?php if ($featuredShowLocation && $featuredEvent['location_name']): ?>
                · <?= coveted_e($featuredEvent['location_name']) ?>
            <?php elseif (!$featuredShowLocation): ?>
                · Location revealed later
            <?php endif; ?>
        </p>
        <div class="cv-tag-row">
            <?php if ($featuredEvent['response'] === 'attending'): ?><span class="cv-pill">Attending</span><?php endif; ?>
            <?php if ($featuredEvent['response'] === 'waitlist'): ?><span class="cv-pill">Waitlist</span><?php endif; ?>
            <?php if ($featuredEvent['event_type'] === 'mystery'): ?><span class="cv-pill">Mystery gathering</span><?php endif; ?>
            <?php if ($featuredEvent['audience'] === 'invitation_only'): ?><span class="cv-pill">Invitation only</span><?php endif; ?>
            <?php if ($featuredIsHost): ?><span class="cv-pill">Host</span><?php endif; ?>
        </div>
        <div class="cv-action-row">
            <a class="cv-button" href="/event.php?event=<?= coveted_e(rawurlencode((string)$featuredEvent['public_id'])) ?>">View Event</a>
            <?php if ($featuredIsHost): ?>
                <a class="cv-button cv-button-soft" href="/host.php?event=<?= coveted_e($featuredEvent['public_id']) ?>">Host Workspace</a>
            <?php elseif ($featuredEvent['invitation_status'] === 'pending'): ?>
                <a class="cv-button cv-button-soft" href="/invitations.php?view=pending">Respond</a>
            <?php endif; ?>
        </div>
    </article>
<?php endif; ?>

<div class="cv-section-head">
    <div>
        <span class="cv-eyebrow">YOUR CALENDAR</span>
        <h2><?= match ($view) {
            'history' => 'Event history',
            'mystery' => 'Mystery gatherings',
            'hosting' => 'Your host assignments',
            default => 'Upcoming gatherings',
        } ?></h2>
    </div>
    <div class="cv-member-actions">
        <?php if ($view === 'history' && $reconnectHistory): ?>
            <a class="cv-button" href="/reconnect.php">Mutual Reconnect</a>
        <?php endif; ?>
        <?php if ($isApprovedHost): ?>
            <a class="cv-button cv-button-soft" href="/host.php">Host Workspace</a>
        <?php endif; ?>
    </div>
</div>

<nav class="cv-tab-row" aria-label="Event views">
    <a class="cv-tab <?= $view === 'upcoming' ? 'is-active' : '' ?>" href="/events.php?view=upcoming">Upcoming</a>
    <a class="cv-tab <?= $view === 'history' ? 'is-active' : '' ?>" href="/events.php?view=history">History</a>
    <a class="cv-tab <?= $view === 'mystery' ? 'is-active' : '' ?>" href="/events.php?view=mystery">Mystery</a>
    <?php if ($isApprovedHost): ?>
        <a class="cv-tab <?= $view === 'hosting' ? 'is-active' : '' ?>" href="/events.php?view=hosting">Hosting</a>
    <?php endif; ?>
</nav>

<section class="cv-list">
    <?php if (!$visibleEvents): ?>
        <div class="cv-card cv-empty">
            <h2><?= match ($view) {
                'history' => 'No shared history yet.',
                'mystery' => 'No mystery gatherings are waiting.',
                'hosting' => 'Nothing to manage yet.',
                default => 'No upcoming gatherings yet.',
            } ?></h2>
            <p><?= match ($view) {
                'history' => 'Past and cancelled events will remain here so your calendar stays understandable.',
                'mystery' => 'Mystery events will appear here when Coveted Admin schedules them for your groups.',
                'hosting' => 'Coveted Admin will assign you when a gathering needs host support.',
                default => 'Join a group or accept an invitation and the gathering will appear here.',
            } ?></p>
            <?php if ($view === 'hosting' && $isApprovedHost): ?><a class="cv-button" href="/host.php">Open Host Workspace</a><?php endif; ?>
        </div>
    <?php endif; ?>

    <?php foreach ($visibleEvents as $event): ?>
        <?php
        $future = coveted_event_is_future($event);
        $canManage = (bool)$event['can_manage'];
        $isHostAssignment = $canManage || !empty($event['assigned_host_role']);
        $verifiedAttendance = in_array(
            (string)($event['attendance_status'] ?? ''),
            ['checked_in', 'attended', 'left_early'],
            true
        );
        $showLocation = $isHostAssignment
            || $event['location_visibility'] === 'immediate'
            || (
                $event['location_visibility'] === 'scheduled_reveal'
                && (
                    (bool)$event['location_revealed']
                    || ($event['status'] === 'completed' && $verifiedAttendance)
                )
            );
        $canReconnect = $event['status'] === 'completed' && $verifiedAttendance;
        ?>
        <article class="cv-card cv-event-row">
            <div class="cv-event-date">
                <strong><?= coveted_e(coveted_event_format($event, 'M')) ?></strong>
                <span><?= coveted_e(coveted_event_format($event, 'j')) ?></span>
            </div>

            <div class="cv-event-copy">
                <span class="cv-kicker">
                    <?= coveted_e($event['group_name']) ?> · <?= coveted_e(str_replace('_', ' ', (string)$event['event_type'])) ?>
                </span>
                <h2><?= coveted_e($event['title']) ?></h2>
                <p>
                    <?= coveted_e(coveted_event_format($event, 'l · g:i A')) ?>
                    <?php if ($showLocation && $event['location_name']): ?>
                        · <?= coveted_e($event['location_name']) ?>
                    <?php elseif (!$showLocation): ?>
                        · Location revealed later
                    <?php endif; ?>
                </p>

                <div class="cv-tag-row">
                    <?php if ($event['status'] === 'draft'): ?>
                        <span class="cv-pill">Draft</span>
                    <?php elseif ($event['status'] === 'cancelled'): ?>
                        <span class="cv-pill">Cancelled</span>
                    <?php elseif ($verifiedAttendance): ?>
                        <span class="cv-pill">You were there</span>
                    <?php elseif ($event['response'] === 'attending'): ?>
                        <span class="cv-pill">Attending</span>
                    <?php elseif ($event['response'] === 'waitlist'): ?>
                        <span class="cv-pill">Waitlist</span>
                    <?php elseif ($event['invitation_status'] === 'pending'): ?>
                        <span class="cv-pill">Needs response</span>
                    <?php elseif ($event['audience'] === 'invitation_only'): ?>
                        <span class="cv-pill">Invitation only</span>
                    <?php endif; ?>
                    <?php if ($event['event_type'] === 'mystery'): ?><span class="cv-pill">Mystery</span><?php endif; ?>
                    <?php if ($isHostAssignment): ?><span class="cv-pill">Host</span><?php endif; ?>
                </div>

                <div class="cv-action-row">
                    <a class="cv-button" href="/event.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>"><?= $canReconnect ? 'View Memory' : 'View Event' ?></a>
                    <?php if ($canReconnect): ?>
                        <a class="cv-button cv-button-soft" href="/reconnect.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">Reconnect</a>
                    <?php endif; ?>
                    <?php if ($isHostAssignment): ?>
                        <a class="cv-button cv-button-soft" href="/host.php?event=<?= coveted_e($event['public_id']) ?>">Host Workspace</a>
                    <?php elseif ($event['invitation_status'] === 'pending' && $future): ?>
                        <a class="cv-button cv-button-soft" href="/invitations.php?view=pending">Respond</a>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</section>
<?php coveted_page_end(); ?>
