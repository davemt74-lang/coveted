<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/event_invitation_waves.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
if (coveted_system_sample_mode($admin,$pdo)) coveted_redirect('/admin/system-preview.php?view=events');

$eventRef = trim((string)($_GET['event'] ?? $_POST['event_ref'] ?? ''));
if ($eventRef === '') {
    $first = $pdo->query("SELECT public_id FROM events WHERE status IN ('draft','published') AND starts_at>UTC_TIMESTAMP() ORDER BY FIELD(status,'published','draft'),starts_at,id LIMIT 1")->fetchColumn();
    $eventRef = (string)($first ?: '');
}
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    try {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action !== 'reconcile_waitlist') throw new InvalidArgumentException('Unsupported Invitation Wave action.');
        $promoted = coveted_event_invitation_wave_reconcile_waitlist($admin,$eventRef,$pdo);
        $notice = $promoted
            ? count($promoted).' eligible waitlisted RSVP'.(count($promoted)===1?' was':'s were').' promoted through the canonical RSVP service.'
            : 'The waitlist is already reconciled with current capacity.';
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Invitation Wave waitlist reconciliation failed: '.$e->getMessage());
        $error = 'Unable to reconcile the waitlist right now.';
    }
}

$snapshot = $eventRef !== '' ? coveted_event_invitation_wave_snapshot($admin,$eventRef,$pdo) : null;
$percent = static fn(float $value): string => number_format($value * 100, 0).'%';
$hours = static function (mixed $value): string {
    if ($value === null) return '—';
    $v=(float)$value;
    return $v < 24 ? number_format($v,1).'h' : number_format($v/24,1).'d';
};
$segmentLabel = static fn(string $v): string => match($v) {
    'reconnect'=>'Reconnect','widen_circle'=>'Widen circle','reliable_repeat'=>'Reliable repeat','small_format'=>'Small-format fit','pace'=>'Pace / hold',default=>'Balanced'
};

coveted_page_start('Invitation Waves', '', true);
coveted_admin_ui_start($admin,'events','Invitation Waves');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">INVITATION WAVES / RSVP FORECASTING</span>
        <h1><?= $snapshot ? coveted_e((string)$snapshot['event']['title']) : 'Plan invitation timing from real response behavior.' ?></h1>
        <p>Forecast likely attendance, hold while a wave is still responding, and open the next invitation wave only when the event actually needs it.</p>
    </div>
    <?php if ($snapshot): $event=(array)$snapshot['event']; ?>
        <div class="cv-action-row">
            <a class="cv-button cv-button-soft" href="/admin/event-guest-mix.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">Guest Mix</a>
            <a class="cv-button cv-button-soft" href="/admin/event.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">Event Workspace</a>
            <a class="cv-button cv-button-primary" href="/admin/event.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>#invitations">Canonical Invitations</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<?php if (!$snapshot): ?>
<section class="cv-admin-panel cv-admin-section-gap"><div class="cv-admin-empty"><strong>No future draft or published event is available.</strong><span>Create an Event before forecasting invitation waves.</span></div></section>
<?php else:
    $event=(array)$snapshot['event'];
    $state=(array)$snapshot['state'];
    $history=(array)$snapshot['history'];
    $rates=(array)$snapshot['rates'];
    $forecast=(array)$snapshot['forecast'];
    $decision=(array)$snapshot['decision'];
?>
<div class="cv-admin-metric-grid cv-admin-section-gap">
    <div><span>Expected arrivals</span><strong><?= (int)$forecast['expected'] ?></strong><small><?= (int)$forecast['low'] ?>–<?= (int)$forecast['high'] ?> forecast range</small></div>
    <div><span>Target</span><strong><?= (int)$state['desired_arrivals'] ?></strong><small><?= (int)$state['attending_seats'] ?> confirmed seats</small></div>
    <div><span>Pending</span><strong><?= (int)$state['pending_invites'] ?></strong><small><?= coveted_e($hours($forecast['oldest_pending_age_hours'])) ?> oldest pending</small></div>
    <div><span>Forecast confidence</span><strong><?= coveted_e(ucfirst((string)$rates['confidence'])) ?></strong><small><?= (int)$history['event_count'] ?> historical events · <?= (int)$history['invitation_count'] ?> invitations</small></div>
</div>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head">
        <div><span class="cv-eyebrow">NEXT ACTION</span><h2><?= coveted_e((string)$decision['title']) ?></h2></div>
        <span class="cv-status"><?= coveted_e(ucwords(str_replace('_',' ',(string)$decision['wave']))) ?></span>
    </div>
    <p><?= coveted_e((string)$decision['detail']) ?></p>
    <dl class="cv-admin-event-definition-list">
        <div><dt>Recommended new invitations</dt><dd><?= (int)$decision['recommended_invitations'] ?></dd></div>
        <div><dt>Safe new slots now</dt><dd><?= (int)$state['safe_invitation_slots'] ?></dd></div>
        <div><dt>Forecast gap</dt><dd><?= (int)$forecast['gap_to_target'] ?> arrivals</dd></div>
        <div><dt>Days to event</dt><dd><?= number_format((float)$state['days_to_event'],1) ?></dd></div>
        <div><dt>Waitlist</dt><dd><?= (int)$state['waitlist_rsvps'] ?> RSVP<?= (int)$state['waitlist_rsvps']===1?'':'s' ?></dd></div>
        <div><dt>Response window</dt><dd><?= (int)$rates['response_window_hours'] ?> hours</dd></div>
    </dl>
    <?php if ((string)$decision['key'] === 'reconcile_waitlist'): ?>
        <form method="post" class="cv-action-row">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="action" value="reconcile_waitlist">
            <input type="hidden" name="event_ref" value="<?= coveted_e((string)$event['public_id']) ?>">
            <button class="cv-button cv-button-primary" type="submit">Reconcile Waitlist</button>
        </form>
    <?php elseif (str_starts_with((string)$decision['key'],'open_wave_')): ?>
        <div class="cv-action-row"><a class="cv-button cv-button-primary" href="/admin/event.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>#invitations">Review Recipients + Send Canonically</a></div>
    <?php endif; ?>
    <div class="cv-alert"><strong>No autonomous invitations.</strong> The Agent and forecast recommend timing and volume. System Admin explicitly chooses recipients and sends through the canonical Event invitation controls.</div>
</section>

<div class="cv-admin-dashboard-grid cv-admin-section-gap">
    <section class="cv-admin-panel">
        <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">RSVP FORECAST</span><h2>What current outreach is likely to produce</h2></div><span class="cv-pill"><?= coveted_e(ucfirst((string)$rates['confidence'])) ?> confidence</span></div>
        <dl class="cv-admin-event-definition-list">
            <div><dt>Confirmed attending seats</dt><dd><?= (int)$state['attending_seats'] ?></dd></div>
            <div><dt>Pending invitations</dt><dd><?= (int)$state['pending_invites'] ?></dd></div>
            <div><dt>Expected positive seats from pending</dt><dd><?= number_format((float)$forecast['expected_pending_positive_seats'],1) ?></dd></div>
            <div><dt>Expected arrivals</dt><dd><?= (int)$forecast['expected'] ?></dd></div>
            <div><dt>Low case</dt><dd><?= (int)$forecast['low'] ?></dd></div>
            <div><dt>High case</dt><dd><?= (int)$forecast['high'] ?></dd></div>
        </dl>
        <?php if ((int)$state['pending_invites'] > 0): ?>
            <p>The current wave is <?= coveted_e($hours($forecast['newest_pending_age_hours'])) ?> old at its newest edge and <?= coveted_e($hours($forecast['oldest_pending_age_hours'])) ?> at its oldest. The learned response window is <?= (int)$rates['response_window_hours'] ?> hours.</p>
        <?php endif; ?>
    </section>

    <section class="cv-admin-panel">
        <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">LEARNED RESPONSE MODEL</span><h2>Historical invitation behavior</h2></div></div>
        <dl class="cv-admin-event-definition-list">
            <div><dt>Response rate</dt><dd><?= coveted_e($percent((float)$rates['response_rate'])) ?></dd></div>
            <div><dt>Positive RSVP rate</dt><dd><?= coveted_e($percent((float)$rates['positive_rate'])) ?></dd></div>
            <div><dt>Show rate</dt><dd><?= coveted_e($percent((float)$rates['show_rate'])) ?></dd></div>
            <div><dt>+1 seat rate</dt><dd><?= coveted_e($percent((float)$rates['guest_rate'])) ?></dd></div>
            <div><dt>Historical declines</dt><dd><?= (int)$history['declined_count'] ?></dd></div>
            <div><dt>Historical no-shows</dt><dd><?= (int)$history['no_show_count'] ?></dd></div>
        </dl>
        <p>Small historical samples are blended with conservative defaults so a single unusual Event cannot create an extreme forecast.</p>
    </section>
</div>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">WAVE PLAN</span><h2>Core → Broaden → Fill</h2></div><span class="cv-pill"><?= (int)$state['sent_total'] ?> total outreach</span></div>
    <div class="cv-admin-list">
        <?php foreach ((array)$snapshot['waves'] as $wave): ?>
            <div class="cv-admin-list-row">
                <span class="cv-admin-list-copy"><strong><?= coveted_e((string)$wave['label']) ?></strong><small><?= coveted_e((string)$wave['purpose']) ?></small></span>
                <span><strong><?= (int)$wave['target_outreach'] ?></strong><br><small><?= coveted_e(ucfirst((string)$wave['status'])) ?></small></span>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">RECOMMENDED RECIPIENTS</span><h2><?= (int)$decision['recommended_invitations'] > 0 ? 'Use this mix for the next wave' : 'No new recipient wave recommended' ?></h2></div><a class="cv-button cv-button-soft" href="<?= coveted_e((string)$snapshot['guest_mix']['href']) ?>">Full Guest Mix</a></div>
    <div class="cv-admin-list">
        <?php foreach ((array)$decision['recommended_candidates'] as $candidate): ?>
            <div class="cv-admin-list-row">
                <span class="cv-admin-list-copy"><strong><?= coveted_e((string)$candidate['display_name']) ?></strong><small><?= coveted_e($segmentLabel((string)$candidate['segment'])) ?> · <?= coveted_e(implode(' · ',array_slice((array)$candidate['reasons'],0,3))) ?></small></span>
                <span><strong><?= (int)$candidate['fit_score'] ?></strong><br><small>event fit</small></span>
            </div>
        <?php endforeach; ?>
        <?php if (!$decision['recommended_candidates']): ?><div class="cv-admin-empty"><strong>Hold the recipient list.</strong><span>Pending responses, waitlist demand, capacity, or pacing currently make another wave unnecessary.</span></div><?php endif; ?>
    </div>
</section>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PRIVACY + AUTHORITY</span><h2>Forecast the room, not the person.</h2></div></div>
    <p><?= coveted_e((string)$snapshot['privacy']) ?> <?= coveted_e((string)$snapshot['authority']) ?></p>
</section>
<?php endif; ?>
<?php coveted_admin_ui_end(); coveted_page_end(); ?>
