<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/event_rsvp_followup.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
if (coveted_system_sample_mode($admin, $pdo)) coveted_redirect('/admin/system-preview.php?view=events');

$eventRef = trim((string)($_GET['event'] ?? ''));
if ($eventRef === '') {
    $first = $pdo->query("SELECT public_id FROM events WHERE status='published' AND starts_at>UTC_TIMESTAMP() ORDER BY starts_at,id LIMIT 1")->fetchColumn();
    $eventRef = (string)($first ?: '');
}

$snapshot = $eventRef !== '' ? coveted_event_rsvp_followup_snapshot($admin, $eventRef, $pdo) : null;
$taskSync = null;
$taskSyncError = false;
if ($snapshot && is_array($snapshot['recommendation'] ?? null)) {
    try {
        $taskSync = coveted_event_rsvp_followup_sync_agent_task($admin, $snapshot, $pdo);
    } catch (Throwable $e) {
        $taskSyncError = true;
        error_log('RSVP Follow-Up Agent task sync failed: ' . $e->getMessage());
    }
}

$hours = static function (int $value): string {
    if ($value < 24) return $value . 'h';
    return number_format($value / 24, 1) . 'd';
};
$statusLabel = static fn(string $key): string => match ($key) {
    'nudge_ready' => 'Nudge ready',
    'stop_contact' => 'Stop contact',
    default => 'Hold',
};

coveted_page_start('RSVP Follow-Up', '', true);
coveted_admin_ui_start($admin, 'events', 'RSVP Follow-Up');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">RSVP FOLLOW-UP INTELLIGENCE</span>
        <h1><?= $snapshot ? coveted_e((string)$snapshot['event']['title']) : 'Review RSVP timing without over-contacting members.' ?></h1>
        <p>Use learned response timing, current waitlist demand and the live attendance forecast to decide who should wait, who is ready for review, and when follow-up should stop.</p>
    </div>
    <?php if ($snapshot): $event=(array)$snapshot['event']; ?>
        <div class="cv-action-row">
            <a class="cv-button cv-button-soft" href="/admin/event-communications.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>&amp;type=rsvp_reminder">Communications</a>
            <a class="cv-button cv-button-soft" href="/admin/event-invitation-waves.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">Invitation Waves</a>
            <a class="cv-button cv-button-soft" href="/admin/event-invitation-execution.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">Wave Execution</a>
            <a class="cv-button cv-button-primary" href="/admin/event.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>#invitations">Canonical Invitations</a>
        </div>
    <?php endif; ?>
</div>

<?php if (!$snapshot): ?>
<section class="cv-admin-panel cv-admin-section-gap"><div class="cv-admin-empty"><strong>No future published Event is available.</strong><span>Publish an Event before reviewing RSVP follow-up timing.</span></div></section>
<?php else:
    $event=(array)$snapshot['event'];
    $wave=(array)$snapshot['wave'];
    $state=(array)($wave['state'] ?? []);
    $forecast=(array)($wave['forecast'] ?? []);
    $counts=(array)$snapshot['counts'];
    $recommendation=is_array($snapshot['recommendation'] ?? null) ? (array)$snapshot['recommendation'] : null;
?>
<div class="cv-admin-metric-grid cv-admin-section-gap">
    <div><span>Pending RSVPs</span><strong><?= (int)$counts['pending'] ?></strong><small>no response yet</small></div>
    <div><span>Nudge ready</span><strong><?= (int)$counts['nudge_ready'] ?></strong><small>beyond learned response window</small></div>
    <div><span>Stop contact</span><strong><?= (int)$counts['stop_contact'] ?></strong><small>aged out or too close to Event</small></div>
    <div><span>Learned window</span><strong><?= (int)$snapshot['response_window_hours'] ?>h</strong><small><?= coveted_e(ucfirst((string)($wave['rates']['confidence'] ?? 'low'))) ?> confidence</small></div>
</div>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head">
        <div><span class="cv-eyebrow">NEXT ACTION</span><h2><?= $recommendation ? coveted_e((string)$recommendation['title']) : 'No RSVP follow-up action is needed right now' ?></h2></div>
        <span class="cv-status"><?= $recommendation ? 'Agent opportunity' : 'Hold' ?></span>
    </div>
    <?php if ($recommendation): ?>
        <p><?= coveted_e((string)$recommendation['detail']) ?></p>
        <div class="cv-alert"><strong>Evidence:</strong> <?= coveted_e((string)$recommendation['evidence']) ?></div>
        <?php if ($taskSync): ?><p><small>Proactive Agent task synchronized with stable key <code><?= coveted_e((string)$recommendation['key']) ?></code>.</small></p><?php endif; ?>
        <?php if ($taskSyncError): ?><div class="cv-alert cv-alert-error">The intelligence is available, but Agent task synchronization could not be completed.</div><?php endif; ?>
    <?php else: ?>
        <p>Pending invitations are either still inside the response window or the live forecast does not justify another follow-up action.</p>
    <?php endif; ?>
    <dl class="cv-admin-event-definition-list">
        <div><dt>Expected arrivals</dt><dd><?= (int)($forecast['expected'] ?? 0) ?></dd></div>
        <div><dt>Target arrivals</dt><dd><?= (int)($state['desired_arrivals'] ?? 0) ?></dd></div>
        <div><dt>Forecast gap</dt><dd><?= (int)($forecast['gap_to_target'] ?? 0) ?></dd></div>
        <div><dt>Waitlist</dt><dd><?= (int)($state['waitlist_rsvps'] ?? 0) ?></dd></div>
        <div><dt>Inside response window</dt><dd><?= (int)$counts['hold'] ?></dd></div>
        <div><dt>Stop-contact threshold</dt><dd><?= (int)$snapshot['close_window_hours'] ?>h</dd></div>
    </dl>
</section>

<?php if (!empty($snapshot['waitlist_ready'])): ?>
<div class="cv-alert cv-admin-section-gap"><strong>Waitlist first.</strong> Existing waitlisted demand should be reconciled before another reminder or invitation wave is considered.</div>
<?php endif; ?>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PENDING RECIPIENT REVIEW</span><h2>Exact identities stay in System Admin</h2></div><span class="cv-pill"><?= (int)$counts['pending'] ?> pending</span></div>
    <div class="cv-admin-list">
        <?php foreach ((array)$snapshot['pending'] as $row): ?>
            <div class="cv-admin-list-row">
                <span class="cv-admin-list-copy">
                    <strong><?= coveted_e((string)$row['display_name']) ?></strong>
                    <small><?= coveted_e($statusLabel((string)$row['followup_status'])) ?> · invited <?= coveted_e($hours((int)$row['age_hours'])) ?> ago · <?= coveted_e((string)$row['reason']) ?></small>
                </span>
                <span><strong><?= coveted_e(ucfirst(str_replace('_',' ',(string)$row['invite_type']))) ?></strong><br><small><?= coveted_e((string)$row['followup_status']) ?></small></span>
            </div>
        <?php endforeach; ?>
        <?php if (!$snapshot['pending']): ?><div class="cv-admin-empty"><strong>No unanswered invitations.</strong><span>There are no active pending invitations without an RSVP for this Event.</span></div><?php endif; ?>
    </div>
</section>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">DECISION RULES</span><h2>Follow up with pacing, not pressure.</h2></div></div>
    <dl class="cv-admin-event-definition-list">
        <div><dt>Hold</dt><dd>Inside the learned response window.</dd></div>
        <div><dt>Nudge ready</dt><dd>Beyond one learned response window and still far enough from the Event for Admin review.</dd></div>
        <div><dt>Stop contact</dt><dd>Beyond two response windows / 72h minimum, or within 12h of the Event.</dd></div>
        <div><dt>Waitlist first</dt><dd>Use existing waitlisted demand before more follow-up outreach.</dd></div>
    </dl>
    <div class="cv-alert"><strong>No autonomous messaging.</strong> <?= coveted_e((string)$snapshot['authority']) ?></div>
</section>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PRIVACY</span><h2>Agent sees pressure, not people.</h2></div></div>
    <p><?= coveted_e((string)$snapshot['privacy']) ?></p>
</section>
<?php endif; ?>
<?php coveted_admin_ui_end(); coveted_page_end(); ?>
