<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/event_communications_agent.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
if (coveted_system_sample_mode($admin, $pdo)) {
    coveted_redirect('/admin/system-preview.php?view=events');
}

$eventRef = trim((string)($_GET['event'] ?? $_POST['event_ref'] ?? ''));
$communicationType = strtolower(trim((string)($_GET['type'] ?? $_POST['communication_type'] ?? 'rsvp_reminder')));
$revealId = max(0, (int)($_GET['reveal'] ?? $_POST['reveal_id'] ?? 0));
$error = '';
$notice = '';
$execution = null;
$agentTrackingWarning = false;

$eventOptions = $pdo->query(
    "SELECT public_id, title, starts_at, status
     FROM events
     WHERE status IN ('published','closed') AND starts_at > UTC_TIMESTAMP()
     ORDER BY starts_at, id LIMIT 100"
)->fetchAll();
if ($eventRef === '' && $eventOptions) {
    $eventRef = (string)$eventOptions[0]['public_id'];
}

$currentEvent = null;
$reveals = [];
if ($eventRef !== '') {
    try {
        $currentEvent = coveted_event_communications_event($admin, $eventRef, $pdo);
        $reveals = coveted_event_communications_live_reveals($pdo, (int)$currentEvent['id']);
        if ($revealId < 1 && $communicationType === 'location' && (string)$currentEvent['location_visibility'] === 'scheduled_reveal') {
            foreach ($reveals as $row) {
                if ((string)$row['reveal_type'] === 'location') {
                    $revealId = (int)$row['id'];
                    break;
                }
            }
        } elseif ($revealId < 1 && $communicationType === 'reveal' && $reveals) {
            $revealId = (int)$reveals[0]['id'];
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    try {
        if ((string)($_POST['action'] ?? '') !== 'queue_selected') {
            throw new InvalidArgumentException('Unsupported Event communication action.');
        }
        $recipientIds = $_POST['recipient_ids'] ?? [];
        if (!is_array($recipientIds)) $recipientIds = [];
        $execution = coveted_event_communications_execute(
            $admin,
            $eventRef,
            $communicationType,
            $recipientIds,
            $revealId,
            $pdo
        );
        $summary = (array)$execution['summary'];
        $notice = (int)$summary['queued'] . ' canonical notification' . ((int)$summary['queued'] === 1 ? '' : 's') . ' queued';
        if ((int)$summary['duplicate'] > 0) $notice .= ' · ' . (int)$summary['duplicate'] . ' already queued';
        if ((int)$summary['skipped'] > 0) $notice .= ' · ' . (int)$summary['skipped'] . ' skipped after live revalidation';
        if ((int)$summary['failed'] > 0) $notice .= ' · ' . (int)$summary['failed'] . ' failed';
        $notice .= '. Transport delivery remains in the canonical notification delivery queue.';

        if ((int)$summary['queued'] > 0) {
            try {
                coveted_event_communications_agent_track_queue(
                    $admin,
                    $eventRef,
                    $communicationType,
                    (int)$summary['queued'],
                    (int)$summary['duplicate'],
                    $pdo
                );
                $notice .= ' The Agent lifecycle is now tracking canonical RSVP/forecast changes.';
            } catch (Throwable $e) {
                $agentTrackingWarning = true;
                error_log('Event Communications Agent tracking failed: ' . $e->getMessage());
            }
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Event Communications execution failed: ' . $e->getMessage());
        $error = 'Unable to queue the selected Event communication right now.';
    }
}

$preview = null;
$lifecycle = null;
$taskSyncWarning = false;
if ($eventRef !== '') {
    try {
        $preview = coveted_event_communications_preview($admin, $eventRef, $communicationType, $revealId, $pdo);
        $currentEvent = (array)$preview['event'];
        $reveals = (array)$preview['reveals'];
    } catch (InvalidArgumentException $e) {
        if ($error === '') $error = $e->getMessage();
    }
    try {
        $lifecycle = coveted_event_communications_lifecycle_snapshot($admin, $eventRef, $pdo);
        if (is_array($lifecycle['recommendation'] ?? null)) {
            coveted_event_communications_agent_sync_recommendation($admin, $lifecycle, $pdo);
            $lifecycle = coveted_event_communications_lifecycle_snapshot($admin, $eventRef, $pdo);
        }
    } catch (Throwable $e) {
        $taskSyncWarning = true;
        error_log('Event Communications lifecycle unavailable: ' . $e->getMessage());
    }
}

$typeLabel = static fn(string $value): string => match ($value) {
    'rsvp_reminder' => 'RSVP Reminder',
    'confirmation' => 'Attendance Confirmation',
    'location' => 'Location Update',
    'reveal' => 'Live Reveal / Instructions',
    default => 'Event Communication',
};
$stateLabel = static fn(string $value): string => match ($value) {
    'waitlist_first' => 'Waitlist first',
    'review_followup' => 'Review follow-up',
    'recalculate_after_responses' => 'Forecast refreshed',
    'waiting_response' => 'Waiting for response',
    'next_wave' => 'Next wave',
    'confirmation_ready' => 'Confirmation ready',
    default => 'Hold',
};
$revealLabel = static fn(array $row): string => ucfirst((string)$row['reveal_type']) . ' · ' . ((string)($row['title'] ?? '') !== '' ? (string)$row['title'] : mb_substr((string)$row['content'], 0, 70));

coveted_page_start('Event Communications', '', true);
coveted_admin_ui_start($admin, 'events', 'Event Communications');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">EVENT COMMUNICATIONS EXECUTION</span>
        <h1><?= $currentEvent ? coveted_e((string)$currentEvent['title']) : 'Queue reviewed Event communications.' ?></h1>
        <p>Choose the communication, review the live eligible recipients, then explicitly queue only the selected members through Coveted’s canonical notification delivery pipeline.</p>
    </div>
    <?php if ($currentEvent): ?>
        <div class="cv-action-row">
            <a class="cv-button cv-button-soft" href="/admin/agent-tasks.php">Agent Tasks</a>
            <a class="cv-button cv-button-soft" href="/admin/event-rsvp-followup.php?event=<?= coveted_e(rawurlencode((string)$currentEvent['public_id'])) ?>">RSVP Follow-Up</a>
            <a class="cv-button cv-button-soft" href="/admin/event-invitation-waves.php?event=<?= coveted_e(rawurlencode((string)$currentEvent['public_id'])) ?>">Invitation Waves</a>
            <a class="cv-button cv-button-soft" href="/admin/event-invitation-execution.php?event=<?= coveted_e(rawurlencode((string)$currentEvent['public_id'])) ?>">Wave Execution</a>
            <a class="cv-button cv-button-soft" href="/admin/event.php?event=<?= coveted_e(rawurlencode((string)$currentEvent['public_id'])) ?>">Event</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>
<?php if ($agentTrackingWarning): ?><div class="cv-alert cv-alert-error">The communication was queued, but Agent task tracking changed concurrently or was unavailable. Canonical notification state is unaffected.</div><?php endif; ?>
<?php if ($taskSyncWarning): ?><div class="cv-alert cv-alert-error">Event communications remain available, but the aggregate Agent lifecycle could not be refreshed.</div><?php endif; ?>

<?php if ($lifecycle): $lfForecast=(array)$lifecycle['forecast']; ?>
<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head">
        <div><span class="cv-eyebrow">AGENT COMMUNICATIONS LIFECYCLE</span><h2><?= coveted_e((string)$lifecycle['title']) ?></h2></div>
        <span class="cv-status"><?= coveted_e($stateLabel((string)$lifecycle['state'])) ?></span>
    </div>
    <p><?= coveted_e((string)$lifecycle['detail']) ?></p>
    <div class="cv-admin-metric-grid">
        <div><span>Reminder ready</span><strong><?= (int)$lifecycle['reminders']['ready'] ?></strong><small>current cycles not queued</small></div>
        <div><span>Reminder queued</span><strong><?= (int)$lifecycle['reminders']['already_queued'] ?></strong><small>deduped current cycles</small></div>
        <div><span>Responses since reminder</span><strong><?= (int)$lifecycle['responses_since_latest_reminder'] ?></strong><small>canonical RSVP changes</small></div>
        <div><span>Forecast</span><strong><?= (int)$lfForecast['expected'] ?>/<?= (int)$lfForecast['target'] ?></strong><small><?= coveted_e((string)$lifecycle['wave_decision']) ?></small></div>
    </div>
    <dl class="cv-admin-event-definition-list">
        <div><dt>Agent task</dt><dd><?= !empty($lifecycle['agent_task']) ? coveted_e(ucwords(str_replace('_',' ',(string)$lifecycle['agent_task']['status']))) . ' · ' . coveted_e((string)$lifecycle['agent_task']['task_ref']) : 'No active lifecycle task' ?></dd></div>
        <div><dt>Latest communication</dt><dd><?= coveted_e((string)($lifecycle['queue']['latest_at'] ?: 'None queued')) ?></dd></div>
        <div><dt>Learned response window</dt><dd><?= (int)$lifecycle['response_window_hours'] ?>h</dd></div>
        <div><dt>Agent recipient identities</dt><dd>Not exposed</dd></div>
    </dl>
    <?php if (!empty($lifecycle['actionable'])): ?><div class="cv-action-row"><a class="cv-button cv-button-soft" href="<?= coveted_e((string)$lifecycle['href']) ?>">Open Recommended Action</a></div><?php endif; ?>
    <div class="cv-alert"><strong>Agent boundary.</strong> <?= coveted_e((string)$lifecycle['authority']) ?> <?= coveted_e((string)$lifecycle['privacy']) ?></div>
</section>
<?php endif; ?>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">COMMUNICATION SOURCE</span><h2>Select Event + message source</h2></div><span class="cv-status">System Admin</span></div>
    <form method="get" class="cv-admin-form-grid">
        <label><span>Event</span><select name="event" required>
            <?php foreach ($eventOptions as $option): ?>
                <option value="<?= coveted_e((string)$option['public_id']) ?>" <?= (string)$option['public_id'] === $eventRef ? 'selected' : '' ?>><?= coveted_e((string)$option['title']) ?> · <?= coveted_e((string)$option['starts_at']) ?></option>
            <?php endforeach; ?>
        </select></label>
        <label><span>Communication</span><select name="type">
            <?php foreach (['rsvp_reminder','confirmation','location','reveal'] as $value): ?>
                <option value="<?= coveted_e($value) ?>" <?= $communicationType === $value ? 'selected' : '' ?>><?= coveted_e($typeLabel($value)) ?></option>
            <?php endforeach; ?>
        </select></label>
        <label><span>Live reveal / instruction</span><select name="reveal">
            <option value="0">None / automatic source</option>
            <?php foreach ($reveals as $row): ?>
                <option value="<?= (int)$row['id'] ?>" <?= (int)$row['id'] === $revealId ? 'selected' : '' ?>><?= coveted_e($revealLabel($row)) ?></option>
            <?php endforeach; ?>
        </select></label>
        <div class="cv-action-row"><button class="cv-button cv-button-soft" type="submit">Review Current Eligibility</button></div>
    </form>
    <p><small>Reveal choices include only canonical reveal records whose reveal time has already passed. This workspace does not create, edit, schedule, or publish reveal content.</small></p>
</section>

<?php if (!$preview): ?>
<section class="cv-admin-panel cv-admin-section-gap"><div class="cv-admin-empty"><strong>No executable communication preview.</strong><span>Choose a compatible future Event and communication source. Scheduled locations require a live location reveal; host-only locations are never available to attendees.</span></div></section>
<?php else:
    $event = (array)$preview['event'];
    $recipients = (array)$preview['recipients'];
?>
<div class="cv-admin-metric-grid cv-admin-section-gap">
    <div><span>Communication</span><strong><?= coveted_e($typeLabel((string)$preview['communication_type'])) ?></strong><small><?= coveted_e((string)$preview['notification_type']) ?></small></div>
    <div><span>Eligible now</span><strong><?= count($recipients) ?></strong><small>live-revalidated again on queue</small></div>
    <div><span>Priority</span><strong><?= coveted_e(ucfirst((string)$preview['priority'])) ?></strong><small>canonical notification priority</small></div>
    <div><span>Batch limit</span><strong>100</strong><small>explicit selections only</small></div>
</div>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">MESSAGE PREVIEW</span><h2><?= coveted_e((string)$preview['title']) ?></h2></div><span class="cv-pill">Queued, not globally dispatched</span></div>
    <p><?= nl2br(coveted_e((string)$preview['body'])) ?></p>
    <dl class="cv-admin-event-definition-list">
        <div><dt>Source of truth</dt><dd><?= coveted_e((string)$preview['source']) ?></dd></div>
        <div><dt>Action</dt><dd><?= coveted_e((string)$preview['action_url']) ?></dd></div>
        <div><dt>Transport</dt><dd>Canonical notification record + active device delivery queue</dd></div>
        <div><dt>Global push dispatch</dt><dd>Not invoked by this workspace</dd></div>
    </dl>
</section>

<?php if ($execution && !empty($execution['summary']['results'])): ?>
<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">QUEUE RESULTS</span><h2>Per-recipient execution</h2></div></div>
    <div class="cv-admin-list">
        <?php foreach ((array)$execution['summary']['results'] as $row): ?>
            <div class="cv-admin-list-row">
                <span class="cv-admin-list-copy"><strong>Member #<?= (int)$row['user_id'] ?></strong><small><?= coveted_e((string)($row['reason'] ?? $row['notification_ref'] ?? 'Canonical notification queued.')) ?></small></span>
                <span class="cv-status"><?= coveted_e(ucfirst((string)$row['status'])) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">EXPLICIT RECIPIENT REVIEW</span><h2>Select who receives this communication</h2></div><span class="cv-pill"><?= count($recipients) ?> eligible</span></div>
    <?php if ($recipients): ?>
        <form method="post" data-confirm="Queue this Event communication for only the selected members? Coveted will revalidate each recipient before creating a canonical notification.">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="action" value="queue_selected">
            <input type="hidden" name="event_ref" value="<?= coveted_e((string)$event['public_id']) ?>">
            <input type="hidden" name="communication_type" value="<?= coveted_e((string)$preview['communication_type']) ?>">
            <input type="hidden" name="reveal_id" value="<?= (int)($preview['reveal']['id'] ?? 0) ?>">
            <div class="cv-admin-list">
                <?php foreach (array_slice($recipients, 0, 100) as $recipient): ?>
                    <label class="cv-admin-list-row">
                        <span style="display:flex;gap:.75rem;align-items:flex-start;min-width:0">
                            <input type="checkbox" name="recipient_ids[]" value="<?= (int)$recipient['user_id'] ?>">
                            <span class="cv-admin-list-copy">
                                <strong><?= coveted_e((string)$recipient['display_name']) ?></strong>
                                <small><?php if ((string)$preview['communication_type'] === 'rsvp_reminder'): ?>Nudge-ready · <?= (int)$recipient['age_hours'] ?>h since current invitation cycle<?php else: ?>Attending<?= isset($recipient['guest_count']) && (int)$recipient['guest_count'] > 0 ? ' · +1' : '' ?><?php endif; ?></small>
                            </span>
                        </span>
                        <span><small>unchecked by default</small></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="cv-action-row cv-admin-section-gap"><button class="cv-button cv-button-primary" type="submit">Queue Selected Communication</button></div>
        </form>
    <?php else: ?>
        <div class="cv-admin-empty"><strong>No eligible recipients right now.</strong><span>Eligibility is derived from current RSVP/follow-up state. Hold and stop-contact members are never included in RSVP reminders.</span></div>
    <?php endif; ?>
</section>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">AUTHORITY + PRIVACY</span><h2>One communications pipeline, one approval boundary.</h2></div></div>
    <p><?= coveted_e((string)$preview['authority']) ?></p>
    <div class="cv-alert"><strong>Location safety.</strong> Host-only locations are blocked. Scheduled-reveal locations can be communicated only from a canonical location reveal whose reveal time is already live. Immediate locations use the canonical Event location.</div>
    <div class="cv-alert"><strong>No autonomous bulk messaging.</strong> Recipient checkboxes are intentionally unchecked. The server revalidates every selected member immediately before queueing, and a 100-recipient hard cap applies to each execution.</div>
</section>
<?php endif; ?>
<script src="/assets/js/event-invitation-waves-nav-v1.js?v=event-communications-20260907"></script>
<?php coveted_admin_ui_end(); coveted_page_end(); ?>
