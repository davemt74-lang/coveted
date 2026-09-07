<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/event_invitation_execution.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
if (coveted_system_sample_mode($admin, $pdo)) {
    coveted_redirect('/admin/system-preview.php?view=events');
}

$eventRef = trim((string)($_GET['event'] ?? $_POST['event_ref'] ?? ''));
$error = '';
$notice = '';
$execution = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    try {
        if ((string)($_POST['action'] ?? '') !== 'send_selected') {
            throw new InvalidArgumentException('Unsupported Invitation Wave execution action.');
        }
        $recipientIds = $_POST['recipient_ids'] ?? [];
        if (!is_array($recipientIds)) {
            $recipientIds = [];
        }
        $execution = coveted_event_invitation_execution_send_selected(
            $admin,
            $eventRef,
            $recipientIds,
            'member',
            $pdo
        );
        $notice = (int)$execution['sent_count'] . ' invitation' . ((int)$execution['sent_count'] === 1 ? '' : 's')
            . ' sent through the canonical Event invitation service.';
        if ((int)$execution['failed_count'] > 0) {
            $notice .= ' ' . (int)$execution['failed_count'] . ' recipient' . ((int)$execution['failed_count'] === 1 ? '' : 's') . ' could not be sent; review the result list below.';
        }
        if (!empty($execution['agent_tracking_warning'])) {
            $notice .= ' Invitations were sent, but the Agent task changed concurrently; refresh Agent Tasks to confirm its current tracking state.';
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Invitation Wave execution failed: ' . $e->getMessage());
        $error = 'Unable to execute the invitation wave right now.';
    }
}

$snapshot = null;
if ($eventRef !== '') {
    try {
        $snapshot = coveted_event_invitation_execution_snapshot($admin, $eventRef, $pdo);
    } catch (InvalidArgumentException $e) {
        if ($error === '') $error = $e->getMessage();
    }
}

if (!$snapshot && $eventRef === '') {
    $first = $pdo->query("SELECT public_id FROM events WHERE status='published' AND starts_at>UTC_TIMESTAMP() ORDER BY starts_at,id LIMIT 1")->fetchColumn();
    if ($first) {
        $eventRef = (string)$first;
        $snapshot = coveted_event_invitation_execution_snapshot($admin, $eventRef, $pdo);
    }
}

$segmentLabel = static fn(string $value): string => match ($value) {
    'reconnect' => 'Reconnect',
    'widen_circle' => 'Widen circle',
    'reliable_repeat' => 'Reliable repeat',
    'small_format' => 'Small-format fit',
    'pace' => 'Pace / hold',
    default => 'Balanced',
};

coveted_page_start('Invitation Wave Execution', '', true);
coveted_admin_ui_start($admin, 'events', 'Invitation Wave Execution');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">INVITATION WAVE EXECUTION</span>
        <h1><?= $snapshot ? coveted_e((string)$snapshot['event']['title']) : 'Review and execute the next invitation wave.' ?></h1>
        <p>Agent intelligence recommends the wave. System Admin reviews the exact recipients, previews forecast impact, and explicitly sends the selected batch.</p>
    </div>
    <?php if ($snapshot): $event = (array)$snapshot['event']; ?>
        <div class="cv-action-row">
            <a class="cv-button cv-button-soft" href="/admin/event-invitation-waves.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">Invitation Waves</a>
            <a class="cv-button cv-button-soft" href="/admin/event-guest-mix.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">Guest Mix</a>
            <a class="cv-button cv-button-soft" href="/admin/event.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>&amp;tab=guests">Event Guests</a>
            <a class="cv-button cv-button-soft" href="/admin/agent-tasks.php">Agent Tasks</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<?php if (!$snapshot): ?>
<section class="cv-admin-panel cv-admin-section-gap"><div class="cv-admin-empty"><strong>No executable future Event is available.</strong><span>Publish a future Event and let Guest Mix + RSVP Forecasting determine the next safe invitation wave.</span></div></section>
<?php else:
    $event = (array)$snapshot['event'];
    $wave = (array)$snapshot['wave'];
    $state = (array)($wave['state'] ?? []);
    $rates = (array)($wave['rates'] ?? []);
    $forecast = (array)($wave['forecast'] ?? []);
    $decision = (array)$snapshot['decision'];
    $preview = (array)$snapshot['default_preview'];
    $task = is_array($snapshot['agent_task'] ?? null) ? (array)$snapshot['agent_task'] : null;
    $safeLimit = (int)$snapshot['safe_limit'];
?>
<div class="cv-admin-metric-grid cv-admin-section-gap">
    <div><span>Current forecast</span><strong><?= (int)($forecast['expected'] ?? 0) ?></strong><small><?= (int)($forecast['low'] ?? 0) ?>–<?= (int)($forecast['high'] ?? 0) ?> likely arrivals</small></div>
    <div><span>Target</span><strong><?= (int)($state['desired_arrivals'] ?? 0) ?></strong><small><?= (int)($state['attending_seats'] ?? 0) ?> confirmed seats</small></div>
    <div><span>Recommended batch</span><strong><?= $safeLimit ?></strong><small><?= coveted_e(ucwords(str_replace('_', ' ', (string)($decision['wave'] ?? 'hold')))) ?></small></div>
    <div><span>Agent task</span><strong><?= $task ? coveted_e(ucwords(str_replace('_', ' ', (string)$task['status']))) : 'Tracked' ?></strong><small><?= $task ? 'P' . (int)$task['priority'] . ' proactive task' : 'Brain + opportunity stream active' ?></small></div>
</div>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head">
        <div><span class="cv-eyebrow">AGENT MANAGEMENT</span><h2><?= coveted_e((string)($decision['title'] ?? 'Hold invitations')) ?></h2></div>
        <span class="cv-status"><?= coveted_e(ucwords(str_replace('_', ' ', (string)($decision['key'] ?? 'hold')))) ?></span>
    </div>
    <p><?= coveted_e((string)($decision['detail'] ?? '')) ?></p>
    <dl class="cv-admin-event-definition-list">
        <div><dt>Brain context</dt><dd>Live</dd></div>
        <div><dt>Proactive task</dt><dd><?= $task ? coveted_e((string)$task['public_id']) : 'Created when Agent opportunities synchronize' ?></dd></div>
        <div><dt>Exact recipients in broad Agent context</dt><dd>No</dd></div>
        <div><dt>Autonomous sending</dt><dd>Disabled</dd></div>
    </dl>
    <div class="cv-alert"><strong>Human approval boundary.</strong> <?= coveted_e((string)$snapshot['agent_management']['execution_boundary']) ?> When this batch is explicitly sent, the related proactive Agent task moves to In Progress so the Agent can continue managing the response/next-wave lifecycle.</div>
</section>

<?php if ($execution && !empty($execution['results'])): ?>
<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">EXECUTION RESULTS</span><h2><?= (int)$execution['sent_count'] ?> sent · <?= (int)$execution['failed_count'] ?> failed</h2></div></div>
    <div class="cv-admin-list">
        <?php foreach ((array)$execution['results'] as $result): ?>
            <div class="cv-admin-list-row">
                <span class="cv-admin-list-copy"><strong><?= coveted_e((string)$result['display_name']) ?></strong><small><?= coveted_e((string)$result['message']) ?></small></span>
                <span class="cv-status"><?= coveted_e(ucfirst((string)$result['status'])) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head">
        <div><span class="cv-eyebrow">FORECAST IMPACT</span><h2>Preview the room before sending</h2></div>
        <span class="cv-pill"><?= coveted_e(ucfirst((string)($rates['confidence'] ?? 'low'))) ?> confidence</span>
    </div>
    <div class="cv-admin-metric-grid"
         data-wave-preview
         data-base-low="<?= (int)$preview['base_low'] ?>"
         data-base-expected="<?= (int)$preview['base_expected'] ?>"
         data-base-high="<?= (int)$preview['base_high'] ?>"
         data-low-yield="<?= coveted_e((string)$preview['increment_low_per_invite']) ?>"
         data-expected-yield="<?= coveted_e((string)$preview['increment_expected_per_invite']) ?>"
         data-high-yield="<?= coveted_e((string)$preview['increment_high_per_invite']) ?>"
         data-capacity="<?= (int)$preview['capacity'] ?>">
        <div><span>Selected</span><strong data-wave-selected-count><?= $safeLimit ?></strong><small>of <?= $safeLimit ?> safe invitations</small></div>
        <div><span>Low case</span><strong data-wave-projected-low><?= (int)$preview['projected_low'] ?></strong><small>projected arrivals</small></div>
        <div><span>Expected</span><strong data-wave-projected-expected><?= (int)$preview['projected_expected'] ?></strong><small>projected arrivals</small></div>
        <div><span>High case</span><strong data-wave-projected-high><?= (int)$preview['projected_high'] ?></strong><small>projected arrivals</small></div>
    </div>
    <p>Forecast impact is directional, not a guarantee. The server revalidates the live recommendation, recipient eligibility and safe batch limit again at the moment you send.</p>
</section>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head">
        <div><span class="cv-eyebrow">REVIEW RECIPIENTS</span><h2><?= !empty($snapshot['send_enabled']) ? 'Select the exact members to invite' : 'No invitation batch is currently executable' ?></h2></div>
        <?php if (!empty($snapshot['send_enabled'])): ?><span class="cv-pill">Max <?= $safeLimit ?></span><?php endif; ?>
    </div>

    <?php if (!empty($snapshot['send_enabled']) && $safeLimit > 0): ?>
        <form method="post" data-wave-execution-form data-max-selection="<?= $safeLimit ?>" data-confirm="Send invitations to the selected members? The Agent will track this wave as In Progress, but it will not send anything without this explicit action.">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="action" value="send_selected">
            <input type="hidden" name="event_ref" value="<?= coveted_e((string)$event['public_id']) ?>">
            <div class="cv-action-row">
                <button class="cv-button cv-button-soft" type="button" data-wave-select-recommended>Select Recommended</button>
                <button class="cv-button cv-button-soft" type="button" data-wave-clear>Clear</button>
            </div>
            <div class="cv-admin-list cv-admin-section-gap">
                <?php foreach (array_slice((array)$snapshot['candidates'], 0, $safeLimit) as $index => $candidate): ?>
                    <label class="cv-admin-list-row">
                        <span style="display:flex;gap:.75rem;align-items:flex-start;min-width:0">
                            <input type="checkbox" name="recipient_ids[]" value="<?= (int)$candidate['user_id'] ?>" data-wave-recipient <?= $index < $safeLimit ? 'checked' : '' ?>>
                            <span class="cv-admin-list-copy">
                                <strong><?= coveted_e((string)$candidate['display_name']) ?></strong>
                                <small><?= coveted_e($segmentLabel((string)($candidate['segment'] ?? 'balanced'))) ?> · <?= coveted_e(implode(' · ', array_slice((array)($candidate['reasons'] ?? []), 0, 3))) ?></small>
                            </span>
                        </span>
                        <span><strong><?= (int)($candidate['fit_score'] ?? 0) ?></strong><br><small>event fit</small></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="cv-action-row cv-admin-section-gap">
                <button class="cv-button cv-button-primary" type="submit" data-wave-send>Send <?= $safeLimit ?> Selected Invitations</button>
                <a class="cv-button cv-button-soft" href="/admin/event.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>&amp;tab=guests">Manual Email Invite</a>
            </div>
        </form>
    <?php else: ?>
        <div class="cv-admin-empty"><strong><?= coveted_e((string)($decision['title'] ?? 'Hold invitations')) ?></strong><span><?= coveted_e((string)($decision['detail'] ?? 'The live forecast does not recommend another invitation wave right now.')) ?></span></div>
        <div class="cv-action-row cv-admin-section-gap"><a class="cv-button cv-button-soft" href="/admin/event-invitation-waves.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">Review Forecast</a></div>
    <?php endif; ?>
</section>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">AUTHORITY + PRIVACY</span><h2>Agent-managed, Admin-approved.</h2></div></div>
    <p><?= coveted_e((string)$snapshot['authority']) ?> <?= coveted_e((string)$snapshot['privacy']) ?></p>
</section>
<?php endif; ?>
<script src="/assets/js/event-invitation-execution-v1.js?v=event-invitation-execution-v1-20260907"></script>
<?php coveted_admin_ui_end(); coveted_page_end(); ?>
