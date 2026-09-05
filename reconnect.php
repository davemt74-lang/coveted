<?php
declare(strict_types=1);

require_once __DIR__ . '/app/member_people_v2.php';

$user = coveted_require_user();
$pdo = coveted_db();
$sampleMode = coveted_member_sample_mode($user, $pdo);
$error = '';
$notice = trim((string)($_SESSION['reconnect_notice'] ?? ''));
unset($_SESSION['reconnect_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        if ($sampleMode) {
            throw new InvalidArgumentException('Sample reconnect choices are preview-only. Turn Sample Data off to manage live reconnects.');
        }

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
                : 'Your choice stays private unless they choose you too.';
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

$events = coveted_member_v2_reconnect_events($user, $pdo);
$matches = coveted_member_v2_reconnect_matches($user, $pdo);
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
        $attendees = coveted_member_v2_reconnect_attendees($user, (string)$selectedEvent['public_id'], $pdo);
    } catch (InvalidArgumentException $e) {
        $error = $error !== '' ? $error : $e->getMessage();
        $selectedEvent = null;
    } catch (Throwable $e) {
        error_log('Coveted reconnect attendee load error: ' . $e->getMessage());
        $error = $error !== '' ? $error : 'Unable to load reconnect choices right now.';
        $selectedEvent = null;
    }
}

coveted_page_start('Reconnect', 'Reconnect');
?>
<div class="cv-member-page-v2 cv-reconnect-v2">
    <section class="cv-member-page-intro">
        <div>
            <span class="cv-eyebrow">RECONNECT</span>
            <h1>Good company lasts longer.</h1>
            <p>Choose the people you’d be glad to see again. Nothing is revealed unless the choice is mutual.</p>
        </div>
        <?php if ($sampleMode): ?>
            <a class="cv-member-preview-pill" href="/admin/sample-data.php">Sample data · ON</a>
        <?php endif; ?>
    </section>

    <?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

    <?php if ($events): ?>
        <nav class="cv-member-segmented-tabs cv-reconnect-event-tabs" aria-label="Shared events">
            <?php foreach ($events as $event): ?>
                <?php $active = $selectedEvent && (string)$selectedEvent['public_id'] === (string)$event['public_id']; ?>
                <a class="<?= $active ? 'is-active' : '' ?>" href="/reconnect.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">
                    <span><?= coveted_e((string)$event['title']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if ($selectedEvent): ?>
        <?php
        $eventImage = trim((string)($selectedEvent['image'] ?? ''));
        $locationParts = array_values(array_filter([
            trim((string)($selectedEvent['location_name'] ?? '')),
            trim((string)($selectedEvent['location_city'] ?? '')),
        ], static fn(string $value): bool => $value !== ''));
        $locationLabel = implode(' · ', $locationParts);
        ?>
        <section class="cv-reconnect-context-card">
            <div class="cv-reconnect-context-media <?= $eventImage === '' ? 'is-empty' : '' ?>">
                <?php if ($eventImage !== ''): ?><img src="<?= coveted_e($eventImage) ?>" alt="" loading="eager" decoding="async"><?php endif; ?>
            </div>
            <div class="cv-reconnect-context-copy">
                <span class="cv-member-overline">PEOPLE YOU MET</span>
                <h2><?= coveted_e((string)$selectedEvent['title']) ?></h2>
                <p><?= coveted_e((string)$selectedEvent['group_name']) ?> · <?= coveted_e(coveted_event_format($selectedEvent, 'F j, Y')) ?></p>
                <?php if ($locationLabel !== ''): ?><p><?= coveted_e($locationLabel) ?></p><?php endif; ?>
                <div class="cv-reconnect-private-note">
                    <strong>Private until mutual.</strong>
                    <span>Incoming one-sided interest is never shown. A connection appears only after both people independently choose each other.</span>
                </div>
            </div>
        </section>

        <section class="cv-member-section-head">
            <div>
                <span class="cv-member-overline">FROM THIS GATHERING</span>
                <h2>Who would you like to see again?</h2>
            </div>
            <a class="cv-member-text-link" href="/events.php?view=history">Event history →</a>
        </section>

        <?php if (!$attendees): ?>
            <div class="cv-member-empty-v2">
                <span>Nothing to choose yet</span>
                <h2>No fellow attendees are available here.</h2>
                <p>Reconnect only includes active members whose attendance at this completed gathering was verified.</p>
            </div>
        <?php else: ?>
            <section class="cv-reconnect-person-grid" aria-label="Verified fellow attendees">
                <?php foreach ($attendees as $attendee): ?>
                    <?php
                    $status = (string)($attendee['my_request_status'] ?? '');
                    $avatar = coveted_safe_url((string)($attendee['avatar_url'] ?? ''), true);
                    if ($sampleMode && !empty($attendee['avatar_url'])) {
                        $avatar = (string)$attendee['avatar_url'];
                    }
                    ?>
                    <article class="cv-reconnect-person-card">
                        <div class="cv-reconnect-person-photo <?= $avatar === null ? 'is-empty' : '' ?>">
                            <?php if ($avatar !== null): ?><img src="<?= coveted_e($avatar) ?>" alt="<?= coveted_e((string)$attendee['display_name']) ?>" loading="lazy" decoding="async"><?php endif; ?>
                        </div>
                        <div class="cv-reconnect-person-copy">
                            <span class="cv-member-overline"><?= coveted_e((string)($attendee['context'] ?? $selectedEvent['group_name'])) ?></span>
                            <h3><?= coveted_e((string)$attendee['display_name']) ?></h3>
                            <p><?= match ($status) {
                                'mutual' => 'You both chose to reconnect.',
                                'pending' => 'Your choice is saved privately.',
                                default => 'No selection made.',
                            } ?></p>

                            <div class="cv-reconnect-person-action">
                                <?php if ($sampleMode): ?>
                                    <span class="cv-member-status-chip <?= $status === 'mutual' ? 'is-mutual' : '' ?>"><?= coveted_e(match ($status) {
                                        'mutual' => 'Mutual reconnect',
                                        'pending' => 'Private choice',
                                        default => 'Preview',
                                    }) ?></span>
                                <?php elseif ($status === 'mutual'): ?>
                                    <span class="cv-member-status-chip is-mutual">Mutual reconnect</span>
                                <?php elseif ($status === 'pending'): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="event_ref" value="<?= coveted_e((string)$selectedEvent['public_id']) ?>">
                                        <input type="hidden" name="target_user_id" value="<?= (int)$attendee['user_id'] ?>">
                                        <button class="cv-button cv-button-soft" type="submit">Undo private choice</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                        <input type="hidden" name="action" value="request">
                                        <input type="hidden" name="event_ref" value="<?= coveted_e((string)$selectedEvent['public_id']) ?>">
                                        <input type="hidden" name="target_user_id" value="<?= (int)$attendee['user_id'] ?>">
                                        <button class="cv-button cv-button-primary" type="submit">Reconnect if mutual</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    <?php else: ?>
        <div class="cv-member-empty-v2">
            <span>POST-EVENT</span>
            <h2>Your first reconnect starts after a shared gathering.</h2>
            <p>Mutual Reconnect opens only after an event is completed and your attendance has been verified.</p>
            <a class="cv-button cv-button-soft" href="/events.php">View Events</a>
        </div>
    <?php endif; ?>

    <section class="cv-member-section-head">
        <div>
            <span class="cv-member-overline">MUTUAL</span>
            <h2>Recent reconnects.</h2>
        </div>
    </section>

    <?php if (!$matches): ?>
        <div class="cv-member-empty-v2 cv-reconnect-empty-small">
            <span>NO MATCHES YET</span>
            <h2>One-sided choices stay invisible.</h2>
            <p>When someone you selected independently selects you too, the reconnect appears here.</p>
        </div>
    <?php else: ?>
        <section class="cv-reconnect-match-row" aria-label="Mutual reconnects">
            <?php foreach ($matches as $match): ?>
                <?php
                $matchAvatar = coveted_safe_url((string)($match['matched_avatar_url'] ?? ''), true);
                if ($sampleMode && !empty($match['matched_avatar_url'])) {
                    $matchAvatar = (string)$match['matched_avatar_url'];
                }
                ?>
                <article class="cv-reconnect-match-card">
                    <div class="cv-reconnect-match-avatar">
                        <?php if ($matchAvatar !== null): ?><img src="<?= coveted_e($matchAvatar) ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                    </div>
                    <div>
                        <strong><?= coveted_e((string)$match['matched_display_name']) ?></strong>
                        <span><?= coveted_e((string)$match['event_title']) ?></span>
                    </div>
                    <span class="cv-member-status-chip is-mutual">Mutual</span>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</div>
<?php coveted_page_end(); ?>
