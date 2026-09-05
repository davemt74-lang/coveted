<?php
declare(strict_types=1);

require_once __DIR__ . '/app/member_pages_v2.php';

$user = coveted_require_user();
$pdo = coveted_db();
$sampleMode = coveted_member_sample_mode($user, $pdo);
$events = coveted_member_v2_events($user, $pdo);
$now = time();

$upcomingEvents = array_values(array_filter(
    $events,
    static fn(array $event): bool => coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() >= $now
        && !in_array((string)$event['status'], ['completed', 'cancelled'], true)
));
usort($upcomingEvents, static fn(array $a, array $b): int => strcmp((string)$a['starts_at'], (string)$b['starts_at']));

$historyEvents = array_values(array_filter(
    $events,
    static fn(array $event): bool => coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() < $now
        || in_array((string)$event['status'], ['completed', 'cancelled'], true)
));
usort($historyEvents, static fn(array $a, array $b): int => strcmp((string)$b['starts_at'], (string)$a['starts_at']));

$hostUpcoming = array_values(array_filter($upcomingEvents, static fn(array $event): bool => !empty($event['assigned_host_role'])));
$hostHistory = array_values(array_filter($historyEvents, static fn(array $event): bool => !empty($event['assigned_host_role'])));
$hostingEvents = array_merge($hostUpcoming, $hostHistory);
$hostingCount = count($hostingEvents);
$isApprovedHost = !$sampleMode && coveted_event_actor_has_host_approval($user);
$hasHostWorkspaceAccess = !$sampleMode && ($isApprovedHost || $hostingCount > 0);

$view = strtolower(trim((string)($_GET['view'] ?? 'upcoming')));
if ($view === 'past') {
    $view = 'history';
}
if (!in_array($view, ['upcoming', 'history', 'mystery', 'hosting'], true)) {
    $view = 'upcoming';
}
if ($view === 'hosting' && !$hasHostWorkspaceAccess) {
    $view = 'upcoming';
}

$visibleEvents = match ($view) {
    'history' => $historyEvents,
    'mystery' => array_values(array_filter($upcomingEvents, static fn(array $event): bool => $event['event_type'] === 'mystery')),
    'hosting' => $hostingEvents,
    default => $upcomingEvents,
};

$featuredEvent = null;
foreach ($upcomingEvents as $event) {
    if (($event['response'] ?? null) === 'attending') {
        $featuredEvent = $event;
        break;
    }
    $featuredEvent ??= $event;
}

coveted_page_start('Events', 'Events');
?>
<div class="cv-member-page-v2 cv-events-v2">
    <section class="cv-member-page-intro">
        <div>
            <span class="cv-eyebrow">EVENTS</span>
            <h1>Your calendar, without the noise.</h1>
            <p>Upcoming gatherings, mystery nights, host assignments and the shared experiences worth remembering.</p>
        </div>
        <?php if ($sampleMode): ?>
            <a class="cv-member-preview-pill" href="/admin/sample-data.php">Sample data · ON</a>
        <?php endif; ?>
    </section>

    <nav class="cv-member-segmented-tabs" aria-label="Event views">
        <a class="<?= $view === 'upcoming' ? 'is-active' : '' ?>" href="/events.php?view=upcoming"><span>Upcoming</span><small><?= count($upcomingEvents) ?></small></a>
        <a class="<?= $view === 'mystery' ? 'is-active' : '' ?>" href="/events.php?view=mystery"><span>Mystery</span></a>
        <a class="<?= $view === 'history' ? 'is-active' : '' ?>" href="/events.php?view=history"><span>History</span><?php if ($historyEvents): ?><small><?= count($historyEvents) ?></small><?php endif; ?></a>
        <?php if ($hasHostWorkspaceAccess): ?>
            <a class="<?= $view === 'hosting' ? 'is-active' : '' ?>" href="/events.php?view=hosting"><span>Hosting</span><?php if ($hostingCount): ?><small><?= $hostingCount ?></small><?php endif; ?></a>
        <?php endif; ?>
    </nav>

    <?php if ($featuredEvent && $view === 'upcoming'): ?>
        <?php
        $featuredImage = trim((string)($featuredEvent['image'] ?? ''));
        $featuredIsHost = !empty($featuredEvent['assigned_host_role']) || (bool)($featuredEvent['can_manage'] ?? false);
        $featuredShowLocation = $featuredIsHost
            || $featuredEvent['location_visibility'] === 'immediate'
            || ($featuredEvent['location_visibility'] === 'scheduled_reveal' && (bool)$featuredEvent['location_revealed']);
        ?>
        <section class="cv-event-feature <?= $featuredImage === '' ? 'is-image-empty' : '' ?>" aria-label="Next gathering">
            <div class="cv-event-feature-media">
                <?php if ($featuredImage !== ''): ?><img src="<?= coveted_e($featuredImage) ?>" alt="" loading="eager" decoding="async"><?php endif; ?>
                <div class="cv-event-feature-shade" aria-hidden="true"></div>
                <div class="cv-event-feature-date">
                    <strong><?= coveted_e(coveted_event_format($featuredEvent, 'M')) ?></strong>
                    <span><?= coveted_e(coveted_event_format($featuredEvent, 'j')) ?></span>
                </div>
            </div>
            <div class="cv-event-feature-copy">
                <span class="cv-member-overline"><?= ($featuredEvent['response'] ?? null) === 'attending' ? 'YOUR NEXT GATHERING' : (($featuredEvent['invitation_status'] ?? null) === 'pending' ? 'INVITATION WAITING' : 'UPCOMING') ?></span>
                <h2><?= coveted_e((string)$featuredEvent['title']) ?></h2>
                <p><?= coveted_e((string)($featuredEvent['description'] ?? '')) ?></p>

                <dl class="cv-member-detail-list">
                    <div><dt>When</dt><dd><?= coveted_e(coveted_event_format($featuredEvent, 'l, F j · g:i A')) ?></dd></div>
                    <div><dt>Group</dt><dd><?= coveted_e((string)$featuredEvent['group_name']) ?></dd></div>
                    <div><dt>Place</dt><dd><?= $featuredShowLocation && !empty($featuredEvent['location_name']) ? coveted_e((string)$featuredEvent['location_name']) : 'Revealed later' ?></dd></div>
                </dl>

                <?php if (!empty($featuredEvent['attendee_images'])): ?>
                    <div class="cv-event-attendee-row" aria-label="People going">
                        <div class="cv-event-avatar-stack">
                            <?php foreach ((array)$featuredEvent['attendee_images'] as $avatar): ?>
                                <img src="<?= coveted_e((string)$avatar) ?>" alt="" loading="lazy" decoding="async">
                            <?php endforeach; ?>
                        </div>
                        <span><?= max(0, (int)($featuredEvent['guest_count'] ?? 0)) ?> people expected</span>
                    </div>
                <?php endif; ?>

                <div class="cv-event-feature-actions">
                    <?php if ($sampleMode): ?>
                        <span class="cv-member-preview-chip">Preview event</span>
                    <?php else: ?>
                        <a class="cv-button cv-button-primary" href="/event.php?event=<?= coveted_e(rawurlencode((string)$featuredEvent['public_id'])) ?>">View gathering</a>
                        <?php if ($featuredIsHost): ?>
                            <a class="cv-button cv-button-soft" href="/host.php?event=<?= coveted_e((string)$featuredEvent['public_id']) ?>">Host Workspace</a>
                        <?php elseif (($featuredEvent['invitation_status'] ?? null) === 'pending'): ?>
                            <a class="cv-button cv-button-soft" href="/invitations.php?view=waiting">Respond</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="cv-member-section-head">
        <div>
            <span class="cv-member-overline">YOUR EVENTS</span>
            <h2><?= match ($view) {
                'history' => 'Recently shared.',
                'mystery' => 'A little less information. On purpose.',
                'hosting' => 'Where you’re helping the room come together.',
                default => 'What’s coming up.',
            } ?></h2>
        </div>
        <?php if ($view === 'history' && $historyEvents): ?><a class="cv-member-text-link" href="/reconnect.php">Open Reconnect →</a><?php endif; ?>
        <?php if ($view === 'hosting' && $hasHostWorkspaceAccess): ?><a class="cv-member-text-link" href="/host.php">Host Workspace →</a><?php endif; ?>
    </section>

    <?php if (!$visibleEvents): ?>
        <div class="cv-member-empty-v2">
            <span><?= $view === 'mystery' ? 'No mystery tonight' : 'Nothing scheduled' ?></span>
            <h2><?= match ($view) {
                'history' => 'Your event history will build as you show up.',
                'mystery' => 'No mystery gatherings are waiting.',
                'hosting' => 'No host assignments right now.',
                default => 'Your next gathering hasn’t landed yet.',
            } ?></h2>
            <p><?= match ($view) {
                'hosting' => 'Coveted Admin will assign you when a gathering needs host support.',
                'mystery' => 'When one appears, Coveted will reveal only what you need, when you need it.',
                default => 'Join a group or accept an invitation and it will appear here.',
            } ?></p>
        </div>
    <?php else: ?>
        <div class="cv-event-card-grid">
            <?php foreach ($visibleEvents as $event): ?>
                <?php
                $image = trim((string)($event['image'] ?? ''));
                $future = coveted_event_is_future($event);
                $isHostAssignment = !empty($event['assigned_host_role']) || (bool)($event['can_manage'] ?? false);
                $verifiedAttendance = in_array((string)($event['attendance_status'] ?? ''), ['checked_in', 'attended', 'left_early'], true);
                $showLocation = $isHostAssignment
                    || $event['location_visibility'] === 'immediate'
                    || ($event['location_visibility'] === 'scheduled_reveal' && ((bool)$event['location_revealed'] || ($event['status'] === 'completed' && $verifiedAttendance)));
                $canReconnect = !$sampleMode && $event['status'] === 'completed' && $verifiedAttendance;
                ?>
                <article class="cv-event-card-v2">
                    <div class="cv-event-card-media <?= $image === '' ? 'is-empty' : '' ?>">
                        <?php if ($image !== ''): ?><img src="<?= coveted_e($image) ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                        <div class="cv-event-card-date">
                            <strong><?= coveted_e(coveted_event_format($event, 'M')) ?></strong>
                            <span><?= coveted_e(coveted_event_format($event, 'j')) ?></span>
                        </div>
                    </div>
                    <div class="cv-event-card-copy">
                        <span class="cv-member-overline"><?= coveted_e((string)$event['group_name']) ?></span>
                        <h3><?= coveted_e((string)$event['title']) ?></h3>
                        <p><?= coveted_e(coveted_event_format($event, 'D, M j · g:i A')) ?></p>

                        <div class="cv-member-card-meta">
                            <span><?= $showLocation && !empty($event['location_name']) ? coveted_e((string)$event['location_name']) : 'Location revealed later' ?></span>
                            <?php if (($event['response'] ?? null) === 'attending'): ?><span>Attending</span><?php endif; ?>
                            <?php if (($event['invitation_status'] ?? null) === 'pending'): ?><span>Needs response</span><?php endif; ?>
                            <?php if ($event['event_type'] === 'mystery'): ?><span>Mystery</span><?php endif; ?>
                            <?php if ($isHostAssignment): ?><span>Host</span><?php endif; ?>
                        </div>

                        <?php if (!empty($event['attendee_images'])): ?>
                            <div class="cv-event-attendee-row compact">
                                <div class="cv-event-avatar-stack">
                                    <?php foreach ((array)$event['attendee_images'] as $avatar): ?><img src="<?= coveted_e((string)$avatar) ?>" alt="" loading="lazy" decoding="async"><?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="cv-event-card-actions">
                            <?php if ($sampleMode): ?>
                                <span class="cv-member-preview-chip">Preview</span>
                            <?php else: ?>
                                <a class="cv-member-text-link" href="/event.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>"><?= $canReconnect ? 'View memory' : 'View event' ?> →</a>
                                <?php if ($canReconnect): ?><a class="cv-member-text-link" href="/reconnect.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">Reconnect →</a><?php endif; ?>
                                <?php if ($isHostAssignment): ?><a class="cv-member-text-link" href="/host.php?event=<?= coveted_e((string)$event['public_id']) ?>">Host →</a><?php endif; ?>
                                <?php if (($event['invitation_status'] ?? null) === 'pending' && $future): ?><a class="cv-member-text-link" href="/invitations.php?view=waiting">Respond →</a><?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php coveted_page_end(); ?>
