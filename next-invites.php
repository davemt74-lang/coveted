<?php
declare(strict_types=1);

require_once __DIR__ . '/app/invitation_eligibility.php';

$user = coveted_require_user();
if (!coveted_event_actor_has_host_approval($user)) {
    http_response_code(403);
    coveted_page_start('Next Invites', 'Events');
    ?>
    <section class="cv-page-heading">
        <span class="cv-eyebrow">NEXT INVITES</span>
        <h1>Host approval required.</h1>
        <p>Invitation planning is available only to approved Attendee Hosts and System Admins.</p>
    </section>
    <div class="cv-card cv-empty">
        <a class="cv-button" href="/profile.php">Back to Profile</a>
    </div>
    <?php
    coveted_page_end();
    exit;
}

$hostEvents = coveted_invitation_host_events($user, 150);
$eventRef = trim((string)($_GET['event'] ?? $_POST['event_ref'] ?? ''));
if ($eventRef === '' && $hostEvents) {
    $eventRef = (string)$hostEvents[0]['public_id'];
}

$filter = strtolower(trim((string)($_GET['band'] ?? 'all')));
if (!in_array($filter, ['all', 'recommended', 'eligible', 'new'], true)) {
    $filter = 'all';
}

$error = '';
$notice = trim((string)($_GET['saved'] ?? '')) === 'invite'
    ? 'Invitation sent through Coveted’s existing event invitation system.'
    : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action !== 'invite_candidate') {
            throw new InvalidArgumentException('Unsupported invitation action.');
        }

        coveted_invitation_invite_candidate(
            $user,
            $eventRef,
            (int)($_POST['user_id'] ?? 0)
        );

        coveted_redirect('/next-invites.php?event=' . rawurlencode($eventRef) . '&saved=invite');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted Next Invites error: ' . $e->getMessage());
        $error = 'Unable to send that invitation right now.';
    }
}

$target = null;
$candidates = [];
$signals = null;
$visibleCandidates = [];
$counts = [
    'recommended' => 0,
    'eligible' => 0,
    'new' => 0,
    'already' => 0,
];
$occupiedSeats = 0;
$remainingSeats = null;

if ($eventRef !== '') {
    try {
        $target = coveted_invitation_target_context($user, $eventRef);
        $candidates = coveted_invitation_candidates($user, $eventRef, 250);
        $signals = coveted_invitation_experience_signals($user, $eventRef);

        foreach ($candidates as $candidate) {
            if (!empty($candidate['already_invited'])) {
                $counts['already']++;
            } elseif (isset($counts[(string)$candidate['band']])) {
                $counts[(string)$candidate['band']]++;
            }
        }

        $visibleCandidates = array_values(array_filter(
            $candidates,
            static fn(array $candidate): bool => $filter === 'all' || (string)$candidate['band'] === $filter
        ));

        $occupiedSeats = coveted_event_occupied_seats_locked(coveted_db(), (int)$target['id']);
        if ($target['capacity'] !== null) {
            $remainingSeats = max(0, (int)$target['capacity'] - $occupiedSeats);
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
        $target = null;
        $candidates = [];
        $visibleCandidates = [];
        $signals = null;
    }
}

$feedback = $signals['feedback'] ?? ['total' => 0, 'yes' => 0, 'maybe' => 0, 'no' => 0];
$positiveFeedback = (int)($feedback['yes'] ?? 0) + (int)($feedback['maybe'] ?? 0);
$relationshipLabel = '';
if (!empty($signals['venue_relationship_status'])) {
    $relationshipLabel = ucwords(str_replace('_', ' ', (string)$signals['venue_relationship_status']));
}

coveted_page_start('Next Invites', 'Events');
?>
<section class="cv-page-heading">
    <span class="cv-eyebrow">NEXT INVITES</span>
    <h1>Build the room with intention.</h1>
    <p>Coveted uses explainable participation history—not a popularity score—to help hosts decide who to invite next.</p>
</section>

<?php if ($notice !== ''): ?>
    <div class="cv-alert"><?= coveted_e($notice) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div>
<?php endif; ?>

<?php if (!$hostEvents): ?>
    <div class="cv-card cv-empty">
        <h2>No future event is ready for planning.</h2>
        <p>Create a future gathering in the Host Workspace first.</p>
        <a class="cv-button" href="/host.php">Open Host Workspace</a>
    </div>
<?php else: ?>
    <form class="cv-card cv-form" method="get">
        <span class="cv-eyebrow">TARGET EVENT</span>
        <h2>Choose the gathering you are building.</h2>
        <label>Future event
            <select name="event">
                <?php foreach ($hostEvents as $event): ?>
                    <option value="<?= coveted_e((string)$event['public_id']) ?>" <?= $eventRef === (string)$event['public_id'] ? 'selected' : '' ?>>
                        <?= coveted_e((string)$event['group_name'] . ' · ' . (string)$event['title'] . ' · ' . coveted_event_format($event, 'M j')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="cv-button cv-button-soft" type="submit">Load Event</button>
    </form>

    <?php if ($target): ?>
        <article class="cv-card cv-feature-card cv-copy-card">
            <span class="cv-kicker"><?= coveted_e((string)$target['group_name']) ?> · <?= coveted_e(str_replace('_', ' ', (string)$target['event_type'])) ?></span>
            <h2><?= coveted_e((string)$target['title']) ?></h2>
            <p><?= coveted_e(coveted_event_format($target, 'l, F j · g:i A T')) ?></p>
            <div class="cv-tag-row">
                <span class="cv-pill"><?= coveted_e(ucfirst((string)$target['status'])) ?></span>
                <?php if ((bool)$target['is_mystery']): ?><span class="cv-pill">Mystery</span><?php endif; ?>
                <?php if (!empty($target['location_name'])): ?><span class="cv-pill"><?= coveted_e((string)$target['location_name']) ?></span><?php endif; ?>
                <?php if ($relationshipLabel !== ''): ?><span class="cv-pill"><?= coveted_e($relationshipLabel) ?> venue</span><?php endif; ?>
                <?php if ($remainingSeats !== null): ?><span class="cv-pill"><?= (int)$remainingSeats ?> seats open</span><?php endif; ?>
            </div>
            <div class="cv-action-row">
                <a class="cv-button cv-button-soft" href="/host.php?event=<?= coveted_e((string)$target['public_id']) ?>&amp;tab=people">Host People</a>
                <a class="cv-button cv-button-soft" href="/event.php?event=<?= coveted_e((string)$target['public_id']) ?>">Event View</a>
            </div>
        </article>

        <section class="cv-stat-grid" aria-label="Invitation recommendation summary">
            <div class="cv-card cv-stat"><strong><?= (int)$counts['recommended'] ?></strong><span>Recommended</span></div>
            <div class="cv-card cv-stat"><strong><?= (int)$counts['eligible'] ?></strong><span>Eligible</span></div>
            <div class="cv-card cv-stat"><strong><?= (int)$counts['new'] ?></strong><span>New history</span></div>
            <div class="cv-card cv-stat"><strong><?= (int)$counts['already'] ?></strong><span>Already covered</span></div>
        </section>

        <div class="cv-workspace-grid">
            <section class="cv-card">
                <span class="cv-eyebrow">EXPERIENCE SIGNALS</span>
                <h2>What worked for this group.</h2>
                <p>These are group-level results only. They help shape the next gathering without exposing individual private answers.</p>
                <div class="cv-mini-row"><div><strong><?= (int)($signals['completed_events'] ?? 0) ?></strong><span>Completed gatherings</span></div></div>
                <div class="cv-mini-row"><div><strong><?= (int)($signals['verified_visits'] ?? 0) ?></strong><span>Verified attendance visits</span></div></div>
                <div class="cv-mini-row"><div><strong><?= (int)($signals['repeat_attendees'] ?? 0) ?></strong><span>Repeat attendees</span></div></div>
                <div class="cv-mini-row"><div><strong><?= $positiveFeedback ?> / <?= (int)($feedback['total'] ?? 0) ?></strong><span>Private Yes + Maybe responses, aggregate only</span></div></div>
                <div class="cv-mini-row"><div><strong><?= (int)($signals['mutual_reconnects'] ?? 0) ?></strong><span>Mutual reconnects, aggregate only</span></div></div>
                <div class="cv-mini-row"><div><strong><?= (int)($signals['mystery_visits'] ?? 0) ?></strong><span>Verified mystery-event visits</span></div></div>
            </section>

            <section class="cv-card">
                <span class="cv-eyebrow">PRIVATE BY DESIGN</span>
                <h2>No hidden social score.</h2>
                <p>Recommended means the member has observable history that fits this gathering: verified attendance, repeat participation, the same event type, or prior attendance at this venue.</p>
                <p>Individual “Would you do this again?” answers and Mutual Reconnect choices are never shown here and never appear as candidate-level reasons.</p>
                <?php if (!$target['can_send_invitations']): ?>
                    <div class="cv-alert">
                        <?= $target['status'] === 'draft'
                            ? 'Planning is available now. Publish the event before sending invitations.'
                            : 'This event is not currently accepting new invitations.' ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <nav class="cv-tab-row" aria-label="Candidate filters">
            <?php foreach (['all' => 'All', 'recommended' => 'Recommended', 'eligible' => 'Eligible', 'new' => 'New history'] as $key => $label): ?>
                <a class="cv-tab <?= $filter === $key ? 'is-active' : '' ?>" href="/next-invites.php?event=<?= coveted_e((string)$target['public_id']) ?>&amp;band=<?= coveted_e($key) ?>"><?= coveted_e($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <section class="cv-list">
            <?php if (!$visibleCandidates): ?>
                <div class="cv-card cv-empty">
                    <h2>No members in this view.</h2>
                    <p>Try another recommendation filter or manage the group roster first.</p>
                </div>
            <?php endif; ?>

            <?php foreach ($visibleCandidates as $candidate): ?>
                <article class="cv-card cv-event-row">
                    <div class="cv-event-copy">
                        <span class="cv-kicker"><?= coveted_e(ucwords(str_replace('_', ' ', (string)$candidate['group_role']))) ?> · <?= coveted_e((string)$candidate['band_label']) ?></span>
                        <h2><?= coveted_e((string)$candidate['display_name']) ?></h2>
                        <?php if (!empty($candidate['city'])): ?><p><?= coveted_e((string)$candidate['city']) ?></p><?php endif; ?>

                        <div class="cv-tag-row">
                            <?php foreach ((array)$candidate['reasons'] as $reason): ?>
                                <span class="cv-pill"><?= coveted_e((string)$reason) ?></span>
                            <?php endforeach; ?>
                            <?php foreach ((array)$candidate['cautions'] as $caution): ?>
                                <span class="cv-pill"><?= coveted_e((string)$caution) ?></span>
                            <?php endforeach; ?>
                        </div>

                        <div class="cv-action-row">
                            <?php if (!empty($candidate['already_invited'])): ?>
                                <span class="cv-status">
                                    <?= coveted_e(ucfirst((string)($candidate['target_rsvp_response'] ?: $candidate['target_invitation_status'] ?: 'covered'))) ?>
                                </span>
                            <?php elseif (!empty($candidate['can_invite'])): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="invite_candidate">
                                    <input type="hidden" name="event_ref" value="<?= coveted_e((string)$target['public_id']) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int)$candidate['user_id'] ?>">
                                    <button class="cv-button cv-button-primary" type="submit">Invite</button>
                                </form>
                            <?php else: ?>
                                <span class="cv-status">Planning only</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>
<?php coveted_page_end(); ?>
