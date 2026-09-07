<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/event_communication_delivery.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
if (coveted_system_sample_mode($admin, $pdo)) {
    coveted_redirect('/admin/system-preview.php?view=events');
}

$eventRef = trim((string)($_GET['event'] ?? ''));
$error = '';
$eventOptions = $pdo->query(
    "SELECT public_id,title,starts_at,status
     FROM events
     WHERE status IN ('published','closed') AND starts_at>UTC_TIMESTAMP()
     ORDER BY starts_at,id LIMIT 100"
)->fetchAll();
if ($eventRef === '' && $eventOptions) $eventRef = (string)$eventOptions[0]['public_id'];

$snapshot = null;
if ($eventRef !== '') {
    try {
        $snapshot = coveted_event_communication_delivery_snapshot($admin,$eventRef,$pdo);
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Event Communication Delivery Health failed: ' . $e->getMessage());
        $error = 'Unable to read Event communication delivery health right now.';
    }
}

$stateLabel = static fn(string $state): string => match ($state) {
    'in_app_only' => 'In-app only',
    'partial' => 'Partial',
    'permanent_failure' => 'Permanent failure',
    'retrying' => 'Retrying',
    'stuck' => 'Stuck',
    'sending' => 'Sending',
    'pending' => 'Pending',
    default => 'Sent',
};
$typeLabel = static fn(string $type): string => match ($type) {
    'event.rsvp_reminder' => 'RSVP reminder',
    'event.confirmation' => 'Confirmation',
    'event.location' => 'Location',
    'event.mystery_reveal' => 'Reveal / instructions',
    default => $type,
};

coveted_page_start('Event Communication Delivery', '', true);
coveted_admin_ui_start($admin,'events','Event Communication Delivery');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">EVENT COMMUNICATION DELIVERY HEALTH</span>
        <h1><?= $snapshot ? coveted_e((string)$snapshot['event']['title']) : 'Inspect canonical communication delivery.' ?></h1>
        <p>Review Event-specific Web Push transport health without creating a second dispatcher. Canonical in-app notifications remain valid even when a member has no active push route.</p>
    </div>
    <?php if ($snapshot): ?>
        <div class="cv-action-row">
            <a class="cv-button cv-button-soft" href="/admin/event-communications.php?event=<?= coveted_e(rawurlencode((string)$snapshot['event']['event_ref'])) ?>">Communications</a>
            <a class="cv-button cv-button-soft" href="/admin/operations.php">Operations</a>
            <a class="cv-button cv-button-soft" href="/admin/event.php?event=<?= coveted_e(rawurlencode((string)$snapshot['event']['event_ref'])) ?>">Event</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">EVENT</span><h2>Choose delivery scope</h2></div><span class="cv-status">System Admin</span></div>
    <form method="get" class="cv-admin-form-grid">
        <label><span>Event</span><select name="event" required>
            <?php foreach ($eventOptions as $option): ?>
                <option value="<?= coveted_e((string)$option['public_id']) ?>" <?= (string)$option['public_id'] === $eventRef ? 'selected' : '' ?>><?= coveted_e((string)$option['title']) ?> · <?= coveted_e((string)$option['starts_at']) ?></option>
            <?php endforeach; ?>
        </select></label>
        <div class="cv-action-row"><button class="cv-button cv-button-soft" type="submit">Review Delivery Health</button></div>
    </form>
</section>

<?php if ($snapshot): $counts=(array)$snapshot['counts']; $rows=(array)$snapshot['rows']; ?>
<div id="delivery-health" class="cv-admin-metric-grid cv-admin-section-gap">
    <div><span>Notifications</span><strong><?= (int)$counts['notifications'] ?></strong><small>canonical Event communications</small></div>
    <div><span>Push routed</span><strong><?= (int)$counts['push_routed'] ?></strong><small><?= coveted_e((string)$snapshot['push_coverage_percent']) ?>% notification coverage</small></div>
    <div><span>Stuck</span><strong><?= (int)$counts['stuck'] ?></strong><small>transport needs review</small></div>
    <div><span>Permanent failure</span><strong><?= (int)$counts['permanent_failure'] ?></strong><small>transport cannot currently deliver</small></div>
</div>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head">
        <div><span class="cv-eyebrow">DELIVERY INTELLIGENCE</span><h2><?= coveted_e((string)$snapshot['title']) ?></h2></div>
        <span class="cv-status"><?= coveted_e(ucwords(str_replace('_',' ',(string)$snapshot['severity']))) ?></span>
    </div>
    <p><?= coveted_e((string)$snapshot['detail']) ?></p>
    <dl class="cv-admin-event-definition-list">
        <div><dt>Sent cleanly</dt><dd><?= (int)$counts['sent'] ?></dd></div>
        <div><dt>Partial</dt><dd><?= (int)$counts['partial'] ?></dd></div>
        <div><dt>Processing / retrying</dt><dd><?= (int)$counts['pending'] + (int)$counts['sending'] + (int)$counts['retrying'] ?></dd></div>
        <div><dt>In-app only</dt><dd><?= (int)$counts['in_app_only'] ?></dd></div>
        <div><dt>Critical still open</dt><dd><?= (int)$snapshot['critical_open'] ?></dd></div>
        <div><dt>Time to Event</dt><dd><?= coveted_e((string)$snapshot['hours_to_event']) ?>h</dd></div>
        <div><dt>Latest queued</dt><dd><?= coveted_e((string)($snapshot['latest_queued_at'] ?: 'None')) ?></dd></div>
        <div><dt>Latest critical</dt><dd><?= coveted_e((string)($snapshot['latest_critical_queued_at'] ?: 'None')) ?></dd></div>
    </dl>
    <?php if (is_array($snapshot['recommendation'] ?? null)): ?>
        <div class="cv-alert"><strong>Agent opportunity.</strong> <?= coveted_e((string)$snapshot['recommendation']['evidence']) ?></div>
    <?php endif; ?>
</section>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">EXACT SYSTEM ADMIN VIEW</span><h2>Notification delivery records</h2></div><span class="cv-pill"><?= count($rows) ?> shown</span></div>
    <div class="cv-admin-list">
        <?php foreach ($rows as $row): ?>
            <div class="cv-admin-list-row">
                <span class="cv-admin-list-copy">
                    <strong><?= coveted_e((string)$row['display_name']) ?> · <?= coveted_e($typeLabel((string)$row['notification_type'])) ?></strong>
                    <small><?= coveted_e((string)$row['title']) ?> · queued <?= coveted_e((string)$row['created_at']) ?></small>
                    <small>Push routes <?= (int)$row['delivery_total'] ?> · sent <?= (int)$row['sent'] ?> · pending <?= (int)$row['pending'] ?> · sending <?= (int)$row['sending'] ?> · failed <?= (int)$row['failed'] ?> · permanent <?= (int)$row['permanent_failure'] ?> · attempts <?= (int)$row['max_attempts'] ?><?= $row['response_code'] !== null ? ' · HTTP ' . (int)$row['response_code'] : '' ?></small>
                </span>
                <span class="cv-status"><?= coveted_e($stateLabel((string)$row['transport_state'])) ?></span>
            </div>
        <?php endforeach; ?>
        <?php if (!$rows): ?><div class="cv-admin-empty"><strong>No Event communications have been queued.</strong><span>Use Event Communications to explicitly select recipients and queue canonical notifications.</span></div><?php endif; ?>
    </div>
</section>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">AUTHORITY + PRIVACY</span><h2>Observe transport. Do not create a second dispatcher.</h2></div></div>
    <p><?= coveted_e((string)$snapshot['authority']) ?></p>
    <p><?= coveted_e((string)$snapshot['privacy']) ?></p>
    <div class="cv-alert"><strong>No retry button by design.</strong> The existing canonical notification worker owns retries and dispatch. This workspace never invokes the global push dispatcher and never exposes endpoint URLs, push keys, authentication material or provider response bodies.</div>
</section>
<?php endif; ?>
<script src="/assets/js/event-invitation-waves-nav-v1.js?v=event-communication-delivery-20260907"></script>
<?php coveted_admin_ui_end(); coveted_page_end(); ?>
