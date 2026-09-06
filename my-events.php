<?php
declare(strict_types=1);

require_once __DIR__ . '/app/attendee_event_workspace.php';

$user = coveted_require_user();
$pdo = coveted_db();
$sampleMode = coveted_member_sample_mode($user, $pdo);
$error = '';
$notice = trim((string)($_SESSION['attendee_event_notice'] ?? ''));
unset($_SESSION['attendee_event_notice']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    coveted_require_csrf();

    try {
        if ($sampleMode) {
            throw new InvalidArgumentException('Sample events are preview-only. Turn Sample Data off to manage a live RSVP.');
        }

        $action = trim((string)($_POST['action'] ?? ''));
        if ($action !== 'rsvp') {
            throw new InvalidArgumentException('Unsupported My Events action.');
        }

        $eventRef = trim((string)($_POST['event_ref'] ?? ''));
        $decision = trim((string)($_POST['decision'] ?? ''));
        $guestCount = (int)($_POST['guest_count'] ?? 0);
        $response = coveted_attendee_event_set_rsvp($user, $eventRef, $decision, $guestCount);

        $_SESSION['attendee_event_notice'] = match ($response) {
            'attending' => 'You’re in. Your Event Pass is ready.',
            'waitlist' => 'You’re on the waitlist. Coveted will keep the event in your calendar.',
            default => 'Your RSVP was updated.',
        };
        coveted_redirect('/my-events.php');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted My Events RSVP error: ' . $e->getMessage());
        $error = 'Unable to update that RSVP right now.';
    }
}

$workspace = coveted_attendee_event_workspace($user, $pdo);
$upcomingEvents = (array)$workspace['upcoming'];
$historyEvents = (array)$workspace['history'];
$waitingInvitations = (array)$workspace['waiting_invitations'];
$nextEvent = $workspace['next_event'];
$view = strtolower(trim((string)($_GET['view'] ?? 'upcoming')));
if (!in_array($view, ['upcoming', 'mystery', 'history'], true)) {
    $view = 'upcoming';
}

$visibleEvents = match ($view) {
    'mystery' => array_values(array_filter(
        $upcomingEvents,
        static fn(array $event): bool => (string)$event['event_type'] === 'mystery'
    )),
    'history' => $historyEvents,
    default => $upcomingEvents,
};

$nextPerks = [];
$nextPassId = null;
$nextLocationVisible = false;
if (is_array($nextEvent)) {
    $nextLocationVisible = coveted_attendee_event_location_visible($nextEvent);
    if (!$sampleMode) {
        try {
            $nextPerks = coveted_attendee_event_active_perks($nextEvent, 4, $pdo);
        } catch (Throwable $e) {
            error_log('Coveted My Events perk preview error: ' . $e->getMessage());
        }
    }
    if ((string)($nextEvent['response'] ?? '') === 'attending') {
        $nextPassId = coveted_attendee_event_pass_id($user, $nextEvent);
    }
}

$eventStatus = static function (array $event): string {
    $attendance = (string)($event['attendance_status'] ?? '');
    if (in_array($attendance, ['checked_in', 'attended', 'left_early'], true)) {
        return 'Attended';
    }
    if ($attendance === 'no_show') {
        return 'No-show';
    }
    if ((string)($event['status'] ?? '') === 'cancelled') {
        return 'Cancelled';
    }
    $response = (string)($event['response'] ?? '');
    if ($response === 'attending') {
        return 'Attending';
    }
    if ($response === 'waitlist') {
        return 'Waitlist';
    }
    if ($response === 'declined') {
        return 'Passed';
    }
    if ((string)($event['invitation_status'] ?? '') === 'pending') {
        return 'Needs response';
    }
    return 'Available';
};

coveted_page_start('My Events', 'Events');
?>
<div class="cv-attendee-events">
    <section class="cv-attendee-events-hero">
        <div>
            <span class="cv-eyebrow">MY EVENTS</span>
            <h1>Show up. Put the phone away. Keep the value.</h1>
            <p>Invitations, RSVP, your Event Pass, mystery reveals, event-day essentials and the benefits that remain after the gathering.</p>
        </div>
        <div class="cv-attendee-events-hero-actions">
            <a class="cv-button cv-button-soft" href="/invitations.php">Invitations<?= count($waitingInvitations) > 0 ? ' · ' . count($waitingInvitations) : '' ?></a>
            <a class="cv-button cv-button-soft" href="/notifications.php">Updates<?= (int)$workspace['unread_notifications'] > 0 ? ' · ' . (int)$workspace['unread_notifications'] : '' ?></a>
            <?php if (!empty($workspace['has_host_workspace_access'])): ?>
                <a class="cv-button cv-button-soft" href="/events.php?view=hosting">Hosting<?= (int)$workspace['host_assignment_count'] > 0 ? ' · ' . (int)$workspace['host_assignment_count'] : '' ?></a>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($notice !== ''): ?><div class="cv-alert" role="status"><?= coveted_e($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="cv-alert cv-alert-error" role="alert"><?= coveted_e($error) ?></div><?php endif; ?>
    <?php if ($sampleMode): ?><div class="cv-member-preview-note">Sample Data is on. The attendee experience is preview-only and live RSVP mutations are disabled.</div><?php endif; ?>

    <section class="cv-attendee-event-stats" aria-label="My Events summary">
        <article><span>Upcoming</span><strong><?= count($upcomingEvents) ?></strong></article>
        <article><span>Attending</span><strong><?= (int)$workspace['attending_count'] ?></strong></article>
        <article><span>Invites waiting</span><strong><?= count($waitingInvitations) ?></strong></article>
        <article><span>Active benefits</span><strong><?= (int)$workspace['benefit_count'] ?></strong></article>
    </section>

    <?php if (is_array($nextEvent)): ?>
        <?php
        $nextResponse = (string)($nextEvent['response'] ?? '');
        $nextCanRsvp = !$sampleMode && coveted_attendee_event_can_rsvp($nextEvent);
        $nextLocation = $nextLocationVisible && !empty($nextEvent['location_name'])
            ? (string)$nextEvent['location_name']
            : 'Revealed later';
        ?>
        <section class="cv-attendee-next-event">
            <div class="cv-attendee-next-copy">
                <span class="cv-member-overline"><?= $nextResponse === 'attending' ? 'YOUR NEXT GATHERING' : ((string)($nextEvent['invitation_status'] ?? '') === 'pending' ? 'INVITATION WAITING' : 'NEXT UP') ?></span>
                <h2><?= coveted_e((string)$nextEvent['title']) ?></h2>
                <p><?= coveted_e((string)($nextEvent['description'] ?? '')) ?></p>

                <dl class="cv-attendee-event-details">
                    <div><dt>When</dt><dd><?= coveted_e(coveted_event_format($nextEvent, 'l, F j · g:i A')) ?></dd></div>
                    <div><dt>Community</dt><dd><?= coveted_e((string)$nextEvent['group_name']) ?></dd></div>
                    <div><dt>Place</dt><dd><?= coveted_e($nextLocation) ?></dd></div>
                    <div><dt>Status</dt><dd><?= coveted_e($eventStatus($nextEvent)) ?></dd></div>
                </dl>

                <div class="cv-attendee-event-actions">
                    <a class="cv-button cv-button-primary" href="/event.php?event=<?= coveted_e(rawurlencode((string)$nextEvent['public_id'])) ?>">Open Event</a>
                    <?php if ((string)($nextEvent['invitation_status'] ?? '') === 'pending'): ?><a class="cv-button cv-button-soft" href="/invitations.php?view=waiting">Invitation</a><?php endif; ?>
                </div>

                <?php if ($nextCanRsvp): ?>
                    <form class="cv-attendee-rsvp" method="post" action="/my-events.php">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="rsvp">
                        <input type="hidden" name="event_ref" value="<?= coveted_e((string)$nextEvent['public_id']) ?>">
                        <?php if ((bool)$nextEvent['plus_one_allowed']): ?>
                            <label>
                                <span>Who’s coming?</span>
                                <select name="guest_count">
                                    <option value="0" <?= (int)($nextEvent['guest_count'] ?? 0) === 0 ? 'selected' : '' ?>>Just me</option>
                                    <option value="1" <?= (int)($nextEvent['guest_count'] ?? 0) === 1 ? 'selected' : '' ?>>Me + one guest</option>
                                </select>
                            </label>
                        <?php else: ?>
                            <input type="hidden" name="guest_count" value="0">
                        <?php endif; ?>
                        <div>
                            <button class="cv-button cv-button-primary" type="submit" name="decision" value="attending">I’m in</button>
                            <button class="cv-button cv-button-soft" type="submit" name="decision" value="declined">Not this time</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <aside class="cv-attendee-pass-column">
                <?php if ($nextPassId !== null): ?>
                    <article class="cv-attendee-event-pass" aria-label="Event Pass">
                        <div class="cv-attendee-event-pass-head">
                            <span>EVENT PASS</span>
                            <strong><?= coveted_e(coveted_event_format($nextEvent, 'M j')) ?></strong>
                        </div>
                        <div class="cv-attendee-event-pass-member">
                            <small>MEMBER</small>
                            <strong><?= coveted_e((string)$user['display_name']) ?></strong>
                        </div>
                        <div class="cv-attendee-event-pass-code">
                            <small>PASS ID</small>
                            <strong><?= coveted_e($nextPassId) ?></strong>
                        </div>
                        <div class="cv-attendee-event-pass-meta">
                            <span><?= coveted_e((string)$nextEvent['title']) ?></span>
                            <span><?= (int)($nextEvent['guest_count'] ?? 0) > 0 ? '+1 included' : 'Member admission' ?></span>
                        </div>
                        <p>Show this screen to the host at check-in. The Pass ID is an identity aid only; Coveted’s host-side attendance permissions remain authoritative.</p>
                    </article>
                <?php else: ?>
                    <article class="cv-attendee-event-pass is-pending">
                        <span class="cv-member-overline">EVENT PASS</span>
                        <h3><?= $nextResponse === 'waitlist' ? 'Waitlist active.' : 'Your pass opens after you RSVP yes.' ?></h3>
                        <p><?= $nextResponse === 'waitlist' ? 'If space opens and Coveted promotes your RSVP, the Event Pass will appear here.' : 'RSVP establishes the member attendance state before event day.' ?></p>
                    </article>
                <?php endif; ?>
            </aside>
        </section>

        <section class="cv-attendee-event-support-grid">
            <article class="cv-attendee-event-support-card">
                <span class="cv-member-overline">PHONE-FREE EVENT DAY</span>
                <h3>Once you’re checked in, Coveted gets quiet.</h3>
                <p>The live event screen intentionally collapses to the essentials so the technology stays out of the gathering. Your post-event memory and rewards open afterward.</p>
            </article>

            <article class="cv-attendee-event-support-card">
                <span class="cv-member-overline">MYSTERY REVEALS</span>
                <h3><?= (string)$nextEvent['location_visibility'] === 'scheduled_reveal' && !$nextLocationVisible ? 'Location still locked.' : 'You know what you need.' ?></h3>
                <p><?= (string)$nextEvent['location_visibility'] === 'scheduled_reveal' && !$nextLocationVisible ? 'Coveted will reveal the location only when the Admin-defined reveal becomes active.' : 'Any live location or artist reveal is available from the Event page.' ?></p>
            </article>
        </section>

        <section class="cv-attendee-event-perks">
            <div class="cv-member-section-head">
                <div>
                    <span class="cv-member-overline">EVENT VALUE</span>
                    <h2>Perks attached to your next gathering.</h2>
                </div>
                <a class="cv-member-text-link" href="/benefits.php">My Benefits →</a>
            </div>
            <?php if (!$nextPerks): ?>
                <div class="cv-member-empty-v2">
                    <span>Nothing promised yet</span>
                    <h2>No active event perk is published.</h2>
                    <p>Rewards can still be issued after attendance. Coveted only shows active event-linked value here.</p>
                </div>
            <?php else: ?>
                <div class="cv-attendee-perk-grid">
                    <?php foreach ($nextPerks as $perk): ?>
                        <?php $provenance = trim((string)($perk['artist_name'] ?? '')) ?: trim((string)($perk['business_name'] ?? '')); ?>
                        <article>
                            <span><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$perk['reward_type']))) ?></span>
                            <h3><?= coveted_e((string)$perk['title']) ?></h3>
                            <?php if (!empty($perk['description'])): ?><p><?= coveted_e(mb_strimwidth((string)$perk['description'], 0, 180, '…')) ?></p><?php endif; ?>
                            <?php if (!empty($perk['value_text'])): ?><strong><?= coveted_e((string)$perk['value_text']) ?></strong><?php elseif ($perk['value_amount'] !== null): ?><strong>$<?= coveted_e(number_format((float)$perk['value_amount'], 2)) ?></strong><?php endif; ?>
                            <?php if ($provenance !== ''): ?><small><?= coveted_e($provenance) ?></small><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <nav class="cv-member-segmented-tabs" aria-label="My Event views">
        <a class="<?= $view === 'upcoming' ? 'is-active' : '' ?>" href="/my-events.php?view=upcoming"><span>Upcoming</span><small><?= count($upcomingEvents) ?></small></a>
        <a class="<?= $view === 'mystery' ? 'is-active' : '' ?>" href="/my-events.php?view=mystery"><span>Mystery</span></a>
        <a class="<?= $view === 'history' ? 'is-active' : '' ?>" href="/my-events.php?view=history"><span>History</span><?php if ($historyEvents): ?><small><?= count($historyEvents) ?></small><?php endif; ?></a>
    </nav>

    <section class="cv-member-section-head">
        <div>
            <span class="cv-member-overline"><?= $view === 'history' ? 'EVENT HISTORY' : ($view === 'mystery' ? 'MYSTERY EVENTS' : 'YOUR CALENDAR') ?></span>
            <h2><?= $view === 'history' ? 'The experiences you keep.' : ($view === 'mystery' ? 'Only what you need, when you need it.' : 'What’s coming up.') ?></h2>
        </div>
        <?php if ($view === 'history'): ?><a class="cv-member-text-link" href="/reconnect.php">Reconnect →</a><?php endif; ?>
    </section>

    <?php if (!$visibleEvents): ?>
        <div class="cv-member-empty-v2">
            <span><?= $view === 'mystery' ? 'No mystery tonight' : 'Nothing scheduled' ?></span>
            <h2><?= $view === 'history' ? 'Your event history will build as you show up.' : 'Your next gathering hasn’t landed yet.' ?></h2>
            <p>Accept an invitation or join an eligible community event and it will appear here.</p>
        </div>
    <?php else: ?>
        <div class="cv-attendee-event-grid">
            <?php foreach ($visibleEvents as $event): ?>
                <?php
                $locationVisible = coveted_attendee_event_location_visible($event);
                $attended = in_array((string)($event['attendance_status'] ?? ''), ['checked_in', 'attended', 'left_early'], true);
                $history = $view === 'history';
                ?>
                <article class="cv-attendee-event-card">
                    <div class="cv-attendee-event-card-date">
                        <strong><?= coveted_e(coveted_event_format($event, 'M')) ?></strong>
                        <span><?= coveted_e(coveted_event_format($event, 'j')) ?></span>
                    </div>
                    <div class="cv-attendee-event-card-copy">
                        <span class="cv-member-overline"><?= coveted_e((string)$event['group_name']) ?></span>
                        <h3><?= coveted_e((string)$event['title']) ?></h3>
                        <p><?= coveted_e(coveted_event_format($event, 'D, M j · g:i A')) ?></p>
                        <div class="cv-member-card-meta">
                            <span><?= $locationVisible && !empty($event['location_name']) ? coveted_e((string)$event['location_name']) : 'Location revealed later' ?></span>
                            <span><?= coveted_e($eventStatus($event)) ?></span>
                            <?php if ((int)($event['guest_count'] ?? 0) > 0 && (string)($event['response'] ?? '') === 'attending'): ?><span>+1</span><?php endif; ?>
                            <?php if ((string)$event['event_type'] === 'mystery'): ?><span>Mystery</span><?php endif; ?>
                        </div>
                    </div>
                    <div class="cv-attendee-event-card-actions">
                        <a class="cv-member-text-link" href="/event.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>"><?= $history && $attended ? 'View memory' : 'View event' ?> →</a>
                        <?php if ($history && $attended): ?><a class="cv-member-text-link" href="/reconnect.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">Reconnect →</a><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="cv-attendee-after-event">
        <article>
            <span class="cv-member-overline">AFTER THE EVENT</span>
            <h2>The social feed is not the product.</h2>
            <p>Verified attendance can unlock event benefits, artist media and return-visit value. Reconnect stays mutual and private.</p>
            <div class="cv-attendee-event-actions">
                <a class="cv-button cv-button-soft" href="/benefits.php">Benefits</a>
                <a class="cv-button cv-button-soft" href="/reconnect.php">Reconnect</a>
                <a class="cv-button cv-button-soft" href="/notifications.php">Event Updates</a>
            </div>
        </article>
    </section>
</div>
<?php coveted_page_end(); ?>