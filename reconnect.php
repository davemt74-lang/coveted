<?php
declare(strict_types=1);

require_once __DIR__ . '/app/reconnect.php';

$user = coveted_require_user();
$error = '';
$notice = trim((string)($_SESSION['reconnect_notice'] ?? ''));
unset($_SESSION['reconnect_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        $action = trim((string)($_POST['action'] ?? ''));
        $eventRef = trim((string)($_POST['event_ref'] ?? ''));
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);

        if ($eventRef === '' || strlen($eventRef) > 64) {
            throw new InvalidArgumentException('That completed event is not available for reconnect.');
        }

        if ($action === 'request') {
            $result = coveted_reconnect_request($user, $eventRef, $targetUserId);
            $_SESSION['reconnect_notice'] = $result['mutual']
                ? 'It’s mutual. You both chose to reconnect.'
                : 'Your choice is private. It will only become visible if they choose you too.';
            coveted_redirect('/reconnect.php?event=' . rawurlencode((string)$result['event_id']));
        }

        if ($action === 'cancel') {
            $cancelled = coveted_reconnect_cancel($user, $eventRef, $targetUserId);
            $_SESSION['reconnect_notice'] = $cancelled
                ? 'Your private reconnect choice was removed.'
                : 'Nothing changed.';
            coveted_redirect('/reconnect.php?event=' . rawurlencode($eventRef));
        }

        throw new InvalidArgumentException('Unsupported reconnect action.');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted reconnect error: ' . $e->getMessage());
        $error = 'Unable to update reconnect choices right now.';
    }
}

$events = coveted_reconnect_events_for_user($user, 100);
$matches = coveted_reconnect_matches_for_user($user, 100);
$requestedEventRef = trim((string)(
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? ($_POST['event_ref'] ?? '')
        : ($_GET['event'] ?? '')
));
$selectedEvent = null;

if ($requestedEventRef !== '') {
    foreach ($events as $event) {
        if (
            hash_equals((string)$event['public_id'], $requestedEventRef)
            || hash_equals((string)$event['id'], $requestedEventRef)
        ) {
            $selectedEvent = $event;
            break;
        }
    }

    if ($selectedEvent === null && $error === '') {
        $error = 'That completed event is not available for reconnect.';
    }
} elseif ($events) {
    $selectedEvent = $events[0];
}

$attendees = [];
if ($selectedEvent !== null) {
    try {
        $attendees = coveted_reconnect_attendees_for_event($user, (string)$selectedEvent['public_id']);
    } catch (InvalidArgumentException $e) {
        $error = $error !== '' ? $error : $e->getMessage();
        $selectedEvent = null;
    } catch (Throwable $e) {
        error_log('Coveted reconnect attendee load error: ' . $e->getMessage());
        $error = $error !== '' ? $error : 'Unable to load reconnect choices right now.';
        $selectedEvent = null;
    }
}

coveted_page_start('Mutual Reconnect', 'Events');
?>
<section class="cv-page-heading">
    <span class="cv-eyebrow">POST-EVENT</span>
    <h1>Reconnect only when it’s mutual.</h1>
    <p>Choose people you’d be happy to see again. Your choice stays private unless they independently choose you too.</p>
</section>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<section class="cv-stat-grid cv-home-stats" aria-label="Reconnect summary">
    <div class="cv-card cv-stat">
        <strong><?= count($events) ?></strong>
        <span>Shared events</span>
    </div>
    <div class="cv-card cv-stat">
        <strong><?= count($matches) ?></strong>
        <span>Mutual reconnects</span>
    </div>
    <div class="cv-card cv-stat">
        <strong>Private</strong>
        <span>Until it’s mutual</span>
    </div>
</section>

<section class="cv-two-column">
    <div>
        <div class="cv-section-head">
            <div>
                <span class="cv-eyebrow">PEOPLE YOU MET</span>
                <h2><?= $selectedEvent ? coveted_e($selectedEvent['title']) : 'Choose a shared event' ?></h2>
            </div>
            <a class="cv-button cv-button-soft" href="/events.php?view=history">Event History</a>
        </div>

        <?php if ($selectedEvent): ?>
            <article class="cv-card cv-copy-card">
                <span class="cv-kicker"><?= coveted_e($selectedEvent['group_name']) ?></span>
                <h2><?= coveted_e($selectedEvent['title']) ?></h2>
                <p><?= coveted_e(coveted_event_format($selectedEvent, 'l, F j · g:i A')) ?></p>
                <p>Your selections are never shown as incoming interest. A person appears as a match only after both of you choose each other.</p>
            </article>

            <section class="cv-list" aria-label="Verified fellow attendees">
                <?php if (!$attendees): ?>
                    <div class="cv-card cv-empty">
                        <h2>No fellow attendees are available here.</h2>
                        <p>Reconnect only includes active members whose attendance at this completed gathering was verified.</p>
                    </div>
                <?php endif; ?>

                <?php foreach ($attendees as $attendee): ?>
                    <?php $status = (string)($attendee['my_request_status'] ?? ''); ?>
                    <article class="cv-card cv-member-row">
                        <div>
                            <strong><?= coveted_e($attendee['display_name']) ?></strong>
                            <span><?= match ($status) {
                                'mutual' => 'You both chose to reconnect',
                                'pending' => 'Selected privately',
                                default => 'No selection made',
                            } ?></span>
                        </div>

                        <div class="cv-member-actions">
                            <?php if ($status === 'mutual'): ?>
                                <span class="cv-pill is-selected">Mutual</span>
                            <?php elseif ($status === 'pending'): ?>
                                <span class="cv-pill">Private choice</span>
                                <form class="cv-inline-form" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="event_ref" value="<?= coveted_e($selectedEvent['public_id']) ?>">
                                    <input type="hidden" name="target_user_id" value="<?= (int)$attendee['user_id'] ?>">
                                    <button class="cv-button cv-button-soft" type="submit">Undo</button>
                                </form>
                            <?php else: ?>
                                <form class="cv-inline-form" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="request">
                                    <input type="hidden" name="event_ref" value="<?= coveted_e($selectedEvent['public_id']) ?>">
                                    <input type="hidden" name="target_user_id" value="<?= (int)$attendee['user_id'] ?>">
                                    <button class="cv-button" type="submit">Reconnect if mutual</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php else: ?>
            <div class="cv-card cv-empty">
                <h2>No completed shared events yet.</h2>
                <p>Mutual Reconnect opens only after a gathering is completed and your attendance has been verified.</p>
                <a class="cv-button cv-button-soft" href="/events.php">View Events</a>
            </div>
        <?php endif; ?>
    </div>

    <aside class="cv-stack">
        <section class="cv-card cv-copy-card">
            <span class="cv-kicker">SHARED EVENTS</span>
            <h2>Pick a gathering</h2>
            <p>Only completed events you actually attended are eligible.</p>
        </section>

        <?php foreach ($events as $event): ?>
            <a class="cv-card cv-group-row" href="/reconnect.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">
                <div>
                    <strong><?= coveted_e($event['title']) ?></strong>
                    <span><?= coveted_e($event['group_name']) ?> · <?= coveted_e(coveted_event_format($event, 'M j, Y')) ?></span>
                </div>
                <?php if ($selectedEvent && (int)$selectedEvent['id'] === (int)$event['id']): ?>
                    <span class="cv-pill is-selected">Viewing</span>
                <?php else: ?>
                    <span class="cv-pill">Open</span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>

        <div class="cv-section-head">
            <div>
                <span class="cv-eyebrow">MUTUAL</span>
                <h2>Your reconnects</h2>
            </div>
        </div>

        <?php if (!$matches): ?>
            <div class="cv-card cv-empty">
                <h2>No mutual reconnects yet.</h2>
                <p>One-sided choices never appear here.</p>
            </div>
        <?php endif; ?>

        <?php foreach ($matches as $match): ?>
            <article class="cv-card cv-member-row">
                <div>
                    <strong><?= coveted_e($match['matched_display_name']) ?></strong>
                    <span><?= coveted_e($match['event_title']) ?></span>
                </div>
                <span class="cv-pill is-selected">Mutual</span>
            </article>
        <?php endforeach; ?>
    </aside>
</section>
<?php coveted_page_end(); ?>
