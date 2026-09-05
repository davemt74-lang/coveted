<?php
declare(strict_types=1);

require_once __DIR__ . '/app/event_experience.php';

$user = coveted_require_user();
$error = '';
$notice = trim((string)($_SESSION['event_experience_notice'] ?? ''));
unset($_SESSION['event_experience_notice']);

$eventRef = trim((string)(
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? ($_POST['event_ref'] ?? '')
        : ($_GET['event'] ?? '')
));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        if ($eventRef === '' || strlen($eventRef) > 64) {
            throw new InvalidArgumentException('That event is not available.');
        }

        $action = trim((string)($_POST['action'] ?? ''));
        if ($action !== 'feedback') {
            throw new InvalidArgumentException('Unsupported event action.');
        }

        $changed = coveted_event_feedback_set(
            $user,
            $eventRef,
            (string)($_POST['response'] ?? '')
        );
        $_SESSION['event_experience_notice'] = $changed
            ? 'Your private answer was saved.'
            : 'Your answer is already saved.';
        coveted_redirect('/event.php?event=' . rawurlencode($eventRef));
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted event experience update error: ' . $e->getMessage());
        $error = 'Unable to update that event right now.';
    }
}

$experience = null;
if ($eventRef !== '') {
    try {
        $experience = coveted_event_experience_for_user($user, $eventRef);
    } catch (InvalidArgumentException $e) {
        $error = $error !== '' ? $error : $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted event experience load error: ' . $e->getMessage());
        $error = $error !== '' ? $error : 'Unable to load that event right now.';
    }
} elseif ($error === '') {
    $error = 'That event is not available.';
}

$pageTitle = $experience ? (string)$experience['event']['title'] : 'Event';
coveted_page_start($pageTitle, 'Events');

if (!$experience):
?>
<section class="cv-page-heading">
    <span class="cv-eyebrow">EVENT</span>
    <h1>That gathering isn’t available.</h1>
    <p>Event details are shown only when your membership, invitation, attendance or host authority allows access.</p>
</section>
<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<a class="cv-button cv-button-soft" href="/events.php">Back to Events</a>
<?php
    coveted_page_end();
    exit;
endif;

$event = $experience['event'];
$phase = (string)$experience['phase'];
$canManage = (bool)$experience['can_manage'];
$memberState = (array)$experience['member_state'];
$location = $experience['location'];
$reveals = (array)$experience['reveals'];
$artists = (array)$experience['artists'];
$benefits = (array)$experience['benefits'];
$feedback = $experience['feedback'];
$feedbackSummary = $experience['feedback_summary'];

$formatLocation = static function (?array $row): string {
    if (!$row) {
        return '';
    }

    $name = trim((string)($row['location_name'] ?? $row['private_location_label'] ?? ''));
    $cityParts = array_values(array_filter([
        trim((string)($row['city'] ?? '')),
        trim((string)($row['region'] ?? '')),
    ], static fn(string $value): bool => $value !== ''));
    $city = implode(', ', $cityParts);

    return implode(' · ', array_values(array_filter([$name, $city], static fn(string $value): bool => $value !== '')));
};

$formatAddress = static function (?array $row): string {
    if (!$row || empty($row['location_id'])) {
        return '';
    }

    $street = implode(' ', array_values(array_filter([
        trim((string)($row['address1'] ?? '')),
        trim((string)($row['address2'] ?? '')),
    ], static fn(string $value): bool => $value !== '')));
    $locality = implode(', ', array_values(array_filter([
        trim((string)($row['city'] ?? '')),
        trim((string)($row['region'] ?? '')),
        trim((string)($row['postal_code'] ?? '')),
    ], static fn(string $value): bool => $value !== '')));

    return implode(' · ', array_values(array_filter([$street, $locality], static fn(string $value): bool => $value !== '')));
};

if ($phase === 'arrived'):
?>
<section class="cv-page-heading">
    <span class="cv-eyebrow">YOU’RE HERE</span>
    <h1>Enjoy the evening.</h1>
    <p>We’ll see you tomorrow.</p>
</section>
<a class="cv-button cv-button-soft" href="/events.php">Back to Events</a>
<?php
    coveted_page_end();
    exit;
endif;
?>

<section class="cv-page-heading">
    <span class="cv-eyebrow"><?= $phase === 'memory' ? 'POST-EVENT MEMORY' : ($phase === 'completed' ? 'EVENT COMPLETE' : 'EVENT') ?></span>
    <h1><?= $phase === 'memory' ? 'You were there.' : coveted_e($event['title']) ?></h1>
    <p><?= $phase === 'memory'
        ? 'The useful parts of the gathering stay here: where you went, who appeared, what you unlocked and whether you want another experience like it.'
        : coveted_e($event['group_name'] . ' · ' . coveted_event_format($event, 'l, F j · g:i A')) ?></p>
</section>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<?php if ($phase === 'cancelled'): ?>
    <article class="cv-card cv-empty">
        <span class="cv-kicker">CANCELLED</span>
        <h2><?= coveted_e($event['title']) ?></h2>
        <p>This gathering was cancelled. It remains in Event History so your calendar stays understandable.</p>
        <a class="cv-button cv-button-soft" href="/events.php?view=history">Event History</a>
    </article>
<?php elseif ($phase === 'memory'): ?>
    <section class="cv-two-column">
        <div class="cv-stack">
            <article class="cv-card cv-feature-card cv-copy-card">
                <span class="cv-kicker"><?= coveted_e($event['group_name']) ?></span>
                <h2><?= coveted_e($event['title']) ?></h2>
                <p><?= coveted_e(coveted_event_format($event, 'l, F j, Y · g:i A')) ?></p>
                <?php if ($location): ?>
                    <p><strong><?= coveted_e($formatLocation($location)) ?></strong></p>
                    <?php if ($formatAddress($location) !== ''): ?><p><?= coveted_e($formatAddress($location)) ?></p><?php endif; ?>
                <?php elseif ($event['location_visibility'] === 'host_only'): ?>
                    <p>The location remains private to event hosts.</p>
                <?php endif; ?>
                <div class="cv-tag-row">
                    <span class="cv-pill is-selected">You were there</span>
                    <span class="cv-pill"><?= coveted_e(str_replace('_', ' ', (string)$event['event_type'])) ?></span>
                    <?php if ($artists): ?><span class="cv-pill"><?= count($artists) ?> artist<?= count($artists) === 1 ? '' : 's' ?></span><?php endif; ?>
                    <?php if ($benefits): ?><span class="cv-pill"><?= count($benefits) ?> benefit<?= count($benefits) === 1 ? '' : 's' ?></span><?php endif; ?>
                </div>
            </article>

            <?php if ($artists): ?>
                <div class="cv-section-head">
                    <div>
                        <span class="cv-eyebrow">ARTISTS</span>
                        <h2>Part of this gathering</h2>
                    </div>
                </div>
                <section class="cv-list" aria-label="Event artists">
                    <?php foreach ($artists as $artist): ?>
                        <article class="cv-card cv-member-row">
                            <div>
                                <strong><?= coveted_e($artist['artist_name']) ?></strong>
                                <span><?= coveted_e(ucfirst(str_replace('_', ' ', (string)$artist['appearance_type']))) ?></span>
                            </div>
                            <span class="cv-pill">Artist Partner</span>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <div class="cv-section-head">
                <div>
                    <span class="cv-eyebrow">UNLOCKED HERE</span>
                    <h2>Benefits from this event</h2>
                </div>
                <a class="cv-button cv-button-soft" href="/benefits.php">All Benefits</a>
            </div>

            <?php if (!$benefits): ?>
                <div class="cv-card cv-empty">
                    <h2>No event benefit was unlocked.</h2>
                    <p>If a venue, artist, group or Coveted campaign distributes something from this gathering later, it will appear in Benefits.</p>
                </div>
            <?php endif; ?>

            <section class="cv-benefit-grid" aria-label="Benefits unlocked at this event">
                <?php foreach ($benefits as $benefit): ?>
                    <?php
                    $cover = coveted_safe_url($benefit['cover_url'] ?? null, false);
                    $expired = !empty($benefit['expires_at']) && strtotime((string)$benefit['expires_at']) <= time();
                    $status = $expired ? 'expired' : (string)$benefit['status'];
                    $provenance = trim((string)($benefit['artist_name'] ?? ''));
                    if ($provenance === '') {
                        $provenance = trim((string)($benefit['business_name'] ?? ''));
                    }
                    ?>
                    <article class="cv-card cv-benefit-card">
                        <?php if ($cover !== null): ?><img class="cv-benefit-cover" src="<?= coveted_e($cover) ?>" alt=""><?php endif; ?>
                        <div class="cv-benefit-body">
                            <div class="cv-tag-row">
                                <span class="cv-kicker"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$benefit['reward_type']))) ?></span>
                                <span class="cv-status"><?= coveted_e(ucfirst(str_replace('_', ' ', $status))) ?></span>
                            </div>
                            <h2><?= coveted_e($benefit['title']) ?></h2>
                            <?php if (!empty($benefit['description'])): ?><p><?= coveted_e(mb_strimwidth((string)$benefit['description'], 0, 280, '…')) ?></p><?php endif; ?>
                            <?php if (!empty($benefit['value_text'])): ?>
                                <p><strong><?= coveted_e($benefit['value_text']) ?></strong></p>
                            <?php elseif ($benefit['value_amount'] !== null): ?>
                                <p><strong>$<?= coveted_e(number_format((float)$benefit['value_amount'], 2)) ?></strong></p>
                            <?php endif; ?>
                            <div class="cv-meta-row">
                                <span>Unlocked at <?= coveted_e($event['title']) ?></span>
                                <?php if ($provenance !== ''): ?><span><?= coveted_e($provenance) ?></span><?php endif; ?>
                            </div>

                            <?php if (!$expired && !in_array($status, ['cancelled', 'expired'], true)): ?>
                                <div class="cv-media-list">
                                    <?php foreach ((array)$benefit['media'] as $item): ?>
                                        <?php
                                        $mediaUrl = coveted_safe_url($item['media_url'] ?? null, false);
                                        if ($mediaUrl === null) {
                                            continue;
                                        }
                                        ?>
                                        <?php if ($item['media_type'] === 'audio'): ?>
                                            <button
                                                type="button"
                                                class="cv-media-action"
                                                data-play-audio
                                                data-src="<?= coveted_e($mediaUrl) ?>"
                                                data-title="<?= coveted_e($item['title'] ?: $benefit['title']) ?>"
                                                data-artist="<?= coveted_e($benefit['artist_name'] ?: 'Coveted') ?>"
                                                data-artwork="<?= coveted_e($cover ?? '') ?>"
                                            >▶ <?= coveted_e($item['title'] ?: 'Play audio') ?></button>
                                        <?php elseif ($item['media_type'] === 'video'): ?>
                                            <form method="post" action="/media.php">
                                                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                                <input type="hidden" name="action" value="open">
                                                <input type="hidden" name="issuance" value="<?= coveted_e($benefit['public_id']) ?>">
                                                <input type="hidden" name="media" value="<?= (int)$item['sort_order'] ?>">
                                                <button class="cv-media-action" type="submit">Watch · <?= coveted_e($item['title'] ?: 'Video') ?></button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        </div>

        <aside class="cv-stack">
            <section class="cv-card cv-copy-card">
                <span class="cv-kicker">MUTUAL RECONNECT</span>
                <h2>Someone you’d like to see again?</h2>
                <p>Your choice stays private unless they independently choose you too.</p>
                <a class="cv-button" href="/reconnect.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">Choose privately</a>
            </section>

            <section class="cv-card cv-copy-card">
                <span class="cv-kicker">PRIVATE FEEDBACK</span>
                <h2>Would you do this again?</h2>
                <p>Your answer stays private in Coveted. Event managers see only combined Yes, Maybe and No totals, never who chose what.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="action" value="feedback">
                    <input type="hidden" name="event_ref" value="<?= coveted_e($event['public_id']) ?>">
                    <div class="cv-action-row">
                        <button class="cv-button <?= $feedback === 'yes' ? '' : 'cv-button-soft' ?>" type="submit" name="response" value="yes">Yes</button>
                        <button class="cv-button <?= $feedback === 'maybe' ? '' : 'cv-button-soft' ?>" type="submit" name="response" value="maybe">Maybe</button>
                        <button class="cv-button <?= $feedback === 'no' ? '' : 'cv-button-soft' ?>" type="submit" name="response" value="no">No</button>
                    </div>
                </form>
                <?php if ($feedback !== null): ?><p class="cv-form-help">Saved: <?= coveted_e(ucfirst((string)$feedback)) ?></p><?php endif; ?>
            </section>

            <?php if ($feedbackSummary !== null): ?>
                <section>
                    <div class="cv-section-head">
                        <div>
                            <span class="cv-eyebrow">HOST VIEW</span>
                            <h2>Private feedback totals</h2>
                        </div>
                    </div>
                    <div class="cv-stat-grid" aria-label="Private event feedback summary">
                        <div class="cv-card cv-stat"><strong><?= (int)$feedbackSummary['yes'] ?></strong><span>Yes</span></div>
                        <div class="cv-card cv-stat"><strong><?= (int)$feedbackSummary['maybe'] ?></strong><span>Maybe</span></div>
                        <div class="cv-card cv-stat"><strong><?= (int)$feedbackSummary['no'] ?></strong><span>No</span></div>
                    </div>
                    <p class="cv-form-help"><?= (int)$feedbackSummary['total'] ?> verified attendee response<?= (int)$feedbackSummary['total'] === 1 ? '' : 's' ?>. Individual answers are never shown.</p>
                </section>
            <?php endif; ?>
        </aside>
    </section>
<?php elseif ($phase === 'completed'): ?>
    <section class="cv-two-column">
        <article class="cv-card cv-copy-card">
            <span class="cv-kicker"><?= coveted_e($event['group_name']) ?></span>
            <h2><?= coveted_e($event['title']) ?></h2>
            <p><?= coveted_e(coveted_event_format($event, 'l, F j, Y · g:i A')) ?></p>
            <?php if ($location): ?><p><strong><?= coveted_e($formatLocation($location)) ?></strong></p><?php endif; ?>
            <p>This event is complete. Attendee memories and private feedback remain scoped to people whose attendance was verified.</p>
            <?php if ($canManage): ?><a class="cv-button cv-button-soft" href="/host.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">Host Workspace</a><?php endif; ?>
        </article>

        <?php if ($feedbackSummary !== null): ?>
            <aside class="cv-stack">
                <section class="cv-card cv-copy-card">
                    <span class="cv-kicker">PRIVATE FEEDBACK</span>
                    <h2><?= (int)$feedbackSummary['total'] ?> responses</h2>
                    <p>Only aggregate totals are available to event managers.</p>
                </section>
                <div class="cv-stat-grid" aria-label="Private event feedback summary">
                    <div class="cv-card cv-stat"><strong><?= (int)$feedbackSummary['yes'] ?></strong><span>Yes</span></div>
                    <div class="cv-card cv-stat"><strong><?= (int)$feedbackSummary['maybe'] ?></strong><span>Maybe</span></div>
                    <div class="cv-card cv-stat"><strong><?= (int)$feedbackSummary['no'] ?></strong><span>No</span></div>
                </div>
            </aside>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="cv-two-column">
        <div class="cv-stack">
            <article class="cv-card cv-feature-card cv-copy-card">
                <span class="cv-kicker"><?= coveted_e($event['group_name']) ?></span>
                <h2><?= coveted_e($event['title']) ?></h2>
                <p><?= coveted_e(coveted_event_format($event, 'l, F j, Y · g:i A')) ?></p>
                <?php if (!empty($event['ends_at'])): ?><p>Ends <?= coveted_e(coveted_event_format($event, 'g:i A', 'ends_at')) ?></p><?php endif; ?>
                <?php if (!empty($event['description'])): ?><p><?= nl2br(coveted_e((string)$event['description'])) ?></p><?php endif; ?>
                <div class="cv-tag-row">
                    <span class="cv-pill"><?= coveted_e(ucfirst(str_replace('_', ' ', (string)$event['event_type']))) ?></span>
                    <?php if (!empty($memberState['rsvp_response'])): ?><span class="cv-pill is-selected"><?= coveted_e(ucfirst((string)$memberState['rsvp_response'])) ?></span><?php endif; ?>
                    <?php if ((int)($memberState['guest_count'] ?? 0) > 0): ?><span class="cv-pill">+1</span><?php endif; ?>
                    <?php if ($canManage): ?><span class="cv-pill">Host</span><?php endif; ?>
                </div>
                <div class="cv-action-row">
                    <?php if (($memberState['invitation_status'] ?? '') === 'pending'): ?><a class="cv-button" href="/invitations.php?view=pending">Respond to Invitation</a><?php endif; ?>
                    <?php if ($canManage): ?><a class="cv-button cv-button-soft" href="/host.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">Manage Event</a><?php endif; ?>
                </div>
            </article>

            <?php if ($reveals): ?>
                <div class="cv-section-head">
                    <div>
                        <span class="cv-eyebrow">REVEALED</span>
                        <h2>What you know so far</h2>
                    </div>
                </div>
                <section class="cv-list" aria-label="Mystery reveals">
                    <?php foreach ($reveals as $reveal): ?>
                        <article class="cv-card cv-copy-card">
                            <span class="cv-kicker"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$reveal['reveal_type']))) ?></span>
                            <h2><?= coveted_e($reveal['title'] ?: ucfirst((string)$reveal['reveal_type'])) ?></h2>
                            <p><?= nl2br(coveted_e((string)$reveal['content'])) ?></p>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        </div>

        <aside class="cv-stack">
            <section class="cv-card cv-copy-card">
                <span class="cv-kicker">WHERE</span>
                <?php if ($location): ?>
                    <h2><?= coveted_e($formatLocation($location)) ?></h2>
                    <?php if ($formatAddress($location) !== ''): ?><p><?= coveted_e($formatAddress($location)) ?></p><?php endif; ?>
                    <?php if (!empty($location['business_name'])): ?><p><?= coveted_e($location['business_name']) ?></p><?php endif; ?>
                <?php elseif ($event['location_visibility'] === 'host_only'): ?>
                    <h2>Host-only location</h2>
                    <p>The event host is keeping the location private.</p>
                <?php elseif ($event['location_visibility'] === 'scheduled_reveal'): ?>
                    <h2>Location revealed later</h2>
                    <p>Coveted will show the location here when the scheduled reveal opens.</p>
                <?php else: ?>
                    <h2>Location not set</h2>
                    <p>The host has not published a location yet.</p>
                <?php endif; ?>
            </section>

            <?php if ($artists): ?>
                <section class="cv-card cv-copy-card">
                    <span class="cv-kicker">ARTIST PARTNERS</span>
                    <h2><?= count($artists) === 1 ? coveted_e($artists[0]['artist_name']) : count($artists) . ' artists appearing' ?></h2>
                    <?php foreach ($artists as $artist): ?>
                        <p><?= coveted_e($artist['artist_name']) ?> · <?= coveted_e(ucfirst(str_replace('_', ' ', (string)$artist['appearance_type']))) ?></p>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <?php if ($phase === 'in_progress'): ?>
                <section class="cv-card cv-copy-card">
                    <span class="cv-kicker">UNDERWAY</span>
                    <h2>The gathering has started.</h2>
                    <p>If you arrive and a host verifies your attendance, Coveted becomes intentionally quiet until the post-event experience opens.</p>
                </section>
            <?php endif; ?>
        </aside>
    </section>
<?php endif; ?>

<div class="cv-action-row">
    <a class="cv-button cv-button-soft" href="/events.php<?= $phase === 'memory' || $phase === 'completed' || $phase === 'cancelled' ? '?view=history' : '' ?>">Back to Events</a>
</div>
<?php coveted_page_end(); ?>