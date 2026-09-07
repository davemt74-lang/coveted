<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/event_results.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
if (coveted_system_sample_mode($admin, $pdo)) {
    coveted_redirect('/admin/system-preview.php?view=events');
}

$eventRef = trim((string)($_GET['event'] ?? $_POST['event_ref'] ?? ''));
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    try {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'create_partner_followup') {
            coveted_event_results_create_partner_followup($admin, $eventRef, $pdo);
            $notice = 'Partner CRM follow-up created and assigned.';
        } else {
            throw new InvalidArgumentException('Unsupported Event Results action.');
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Event Results action failed: ' . $e->getMessage());
        $error = 'Unable to update post-event follow-up right now.';
    }
}

$formatTime = static function (?string $value, string $timezone, string $format = 'M j, Y · g:i A T'): string {
    $value = trim((string)$value);
    if ($value === '') return '—';
    try { return coveted_utc_datetime($value)->setTimezone(coveted_timezone($timezone))->format($format); }
    catch (Throwable) { return $value; }
};

$snapshot = null;
$recent = [];
if ($eventRef !== '') {
    try {
        $snapshot = coveted_event_results_snapshot($admin, $eventRef, $pdo);
        $eventRef = (string)$snapshot['event']['public_id'];
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
        $eventRef = '';
    }
}
if ($eventRef === '') {
    try { $recent = coveted_event_results_recent($admin, 60, $pdo); }
    catch (Throwable $e) {
        error_log('Event Results list failed: ' . $e->getMessage());
        $error = $error !== '' ? $error : 'Unable to load Event Results right now.';
    }
}

coveted_page_start('Event Results', '', true);
coveted_admin_ui_start($admin, 'events', 'Event Results');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">POST-EVENT INTELLIGENCE</span>
        <h1><?= $snapshot ? coveted_e((string)$snapshot['event']['title']) : 'Event Results' ?></h1>
        <p><?= $snapshot
            ? 'Turn the finished event into attendance, partner, host, value and next-event intelligence.'
            : 'Completed and closed events become measurable operating history for the Agent, Partner CRM and Opportunity Engine.' ?></p>
    </div>
    <div class="cv-action-row">
        <?php if ($snapshot): ?>
            <a class="cv-button cv-button-soft" href="/admin/event-production.php?event=<?= coveted_e(rawurlencode($eventRef)) ?>">Event Production</a>
            <a class="cv-button cv-button-soft" href="/admin/event.php?event=<?= coveted_e(rawurlencode($eventRef)) ?>">Event Workspace</a>
        <?php endif; ?>
        <a class="cv-button cv-button-soft" href="/admin/event-opportunities.php">Event Opportunities</a>
    </div>
</div>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<?php if (!$snapshot): ?>
    <section class="cv-admin-panel cv-admin-section-gap">
        <div class="cv-admin-panel-head">
            <div><span class="cv-eyebrow">RESULT HISTORY</span><h2>Finished events</h2></div>
            <span class="cv-pill"><?= count($recent) ?> loaded</span>
        </div>
        <?php if (!$recent): ?>
            <div class="cv-admin-empty"><strong>No finished events yet.</strong><span>Results appear after an event is closed or completed and its end time has passed.</span></div>
        <?php else: ?>
            <div class="cv-event-results-list">
                <?php foreach ($recent as $row): ?>
                    <?php
                    $attending=(int)$row['attending']; $verified=(int)$row['verified'];
                    $rate=$attending>0?(int)round(min(1,$verified/$attending)*100):($verified>0?100:0);
                    ?>
                    <a class="cv-event-result-card" href="/admin/event-results.php?event=<?= coveted_e(rawurlencode((string)$row['public_id'])) ?>">
                        <div class="cv-event-result-card-main">
                            <span class="cv-eyebrow"><?= coveted_e(strtoupper((string)$row['status'])) ?></span>
                            <strong><?= coveted_e((string)$row['title']) ?></strong>
                            <small><?= coveted_e((string)$row['group_name']) ?> · <?= coveted_e($formatTime((string)$row['starts_at'], (string)$row['timezone'], 'M j, Y')) ?></small>
                        </div>
                        <div class="cv-event-result-card-metrics">
                            <span><strong><?= $verified ?></strong><small>Verified</small></span>
                            <span><strong><?= $rate ?>%</strong><small>RSVP conversion</small></span>
                            <span><strong><?= (int)$row['claims'] ?></strong><small>Claims</small></span>
                        </div>
                        <span class="cv-event-result-arrow">→</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php else: ?>
    <?php
    $event=(array)$snapshot['event']; $people=(array)$snapshot['people']; $value=(array)$snapshot['value'];
    $production=(array)$snapshot['production']; $partner=(array)$snapshot['partner']; $location=(array)($snapshot['location']??[]);
    $hosts=(array)$snapshot['hosts']; $recommendations=(array)$snapshot['recommendations'];
    $attendanceRate=(int)$people['attending']>0?round(((int)$people['verified']/(int)$people['attending'])*100,1):0;
    $noShowRate=(int)$people['attending']>0?round(((int)$people['no_show']/(int)$people['attending'])*100,1):0;
    $repeatRate=(int)$people['verified']>0?round(((int)$people['repeat_attendees']/(int)$people['verified'])*100,1):0;
    ?>
    <div class="cv-event-result-hero cv-admin-section-gap">
        <div>
            <span class="cv-eyebrow">RESULT SCORE</span>
            <strong class="cv-event-result-score"><?= (int)$snapshot['score'] ?><small>/100</small></strong>
            <span class="cv-status"><?= coveted_e(ucwords(str_replace('_',' ',(string)$snapshot['outcome']))) ?></span>
        </div>
        <dl>
            <div><dt>Group</dt><dd><?= coveted_e((string)$event['group_name']) ?></dd></div>
            <div><dt>When</dt><dd><?= coveted_e($formatTime((string)$event['starts_at'], (string)$event['timezone'])) ?></dd></div>
            <div><dt>Venue</dt><dd><?= coveted_e((string)($location['location_name'] ?? $location['private_location_label'] ?? 'Private / not linked')) ?></dd></div>
            <div><dt>Partner</dt><dd><?= coveted_e((string)($location['business_name'] ?? '—')) ?></dd></div>
        </dl>
    </div>

    <div class="cv-admin-metric-grid cv-admin-section-gap">
        <div><span>Verified attendance</span><strong><?= (int)$people['verified'] ?></strong><small><?= $attendanceRate ?>% of attending RSVPs</small></div>
        <div><span>No-shows</span><strong><?= (int)$people['no_show'] ?></strong><small><?= $noShowRate ?>% of attending RSVPs</small></div>
        <div><span>Repeat attendees</span><strong><?= (int)$people['repeat_attendees'] ?></strong><small><?= $repeatRate ?>% of verified attendance</small></div>
        <div><span>Reward claims</span><strong><?= (int)$value['claims'] ?></strong><small><?= coveted_e((string)$value['claim_rate']) ?>% of <?= (int)$value['rewards_issued'] ?> issuances</small></div>
    </div>

    <div class="cv-admin-dashboard-grid cv-admin-section-gap">
        <section class="cv-admin-panel">
            <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">ATTENDANCE</span><h2>Guest quality</h2></div></div>
            <dl class="cv-admin-event-definition-list">
                <div><dt>Invited</dt><dd><?= (int)$people['invited'] ?></dd></div>
                <div><dt>Attending RSVP</dt><dd><?= (int)$people['attending'] ?></dd></div>
                <div><dt>Waitlist</dt><dd><?= (int)$people['waitlist'] ?></dd></div>
                <div><dt>Declined</dt><dd><?= (int)$people['declined'] ?></dd></div>
                <div><dt>Checked in</dt><dd><?= (int)$people['checked_in'] ?></dd></div>
                <div><dt>Attended</dt><dd><?= (int)$people['attended'] ?></dd></div>
                <div><dt>Left early</dt><dd><?= (int)$people['left_early'] ?></dd></div>
                <div><dt>First-time attendees</dt><dd><?= (int)$people['first_time_attendees'] ?></dd></div>
            </dl>
        </section>

        <section class="cv-admin-panel">
            <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">VALUE</span><h2>Rewards & claims</h2></div></div>
            <dl class="cv-admin-event-definition-list">
                <div><dt>Linked campaigns</dt><dd><?= (int)$value['linked_campaigns'] ?></dd></div>
                <div><dt>Rewards issued</dt><dd><?= (int)$value['rewards_issued'] ?></dd></div>
                <div><dt>Members reached</dt><dd><?= (int)$value['members_reached'] ?></dd></div>
                <div><dt>Claims</dt><dd><?= (int)$value['claims'] ?></dd></div>
                <div><dt>Claiming members</dt><dd><?= (int)$value['claiming_members'] ?></dd></div>
                <div><dt>Return claims</dt><dd><?= (int)$value['return_claims'] ?></dd></div>
                <div><dt>Refunds</dt><dd><?= (int)$value['refunds'] ?></dd></div>
                <div><dt>Claim rate</dt><dd><?= coveted_e((string)$value['claim_rate']) ?>%</dd></div>
            </dl>
        </section>
    </div>

    <div class="cv-admin-dashboard-grid cv-admin-section-gap">
        <section class="cv-admin-panel">
            <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PRODUCTION</span><h2>Operational closeout</h2></div><span class="cv-status"><?= !empty($production['available']) ? coveted_e((string)$production['completion_rate']).'%' : 'Unavailable' ?></span></div>
            <?php if (empty($production['available'])): ?>
                <div class="cv-admin-empty"><strong>Production metrics unavailable.</strong><span>The Event Production migration is not installed.</span></div>
            <?php else: ?>
                <dl class="cv-admin-event-definition-list">
                    <div><dt>Completed tasks</dt><dd><?= (int)$production['completed'] ?>/<?= (int)$production['total'] ?></dd></div>
                    <div><dt>Open tasks</dt><dd><?= (int)$production['open'] ?></dd></div>
                    <div><dt>Blocked tasks</dt><dd><?= (int)$production['blocked'] ?></dd></div>
                    <div><dt>Incidents</dt><dd><?= (int)$production['incidents'] ?></dd></div>
                    <div><dt>Closeout notes</dt><dd><?= (int)$production['closeout_notes'] ?></dd></div>
                </dl>
            <?php endif; ?>
        </section>

        <section class="cv-admin-panel">
            <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PARTNER</span><h2>Relationship impact</h2></div></div>
            <?php if (empty($partner['available'])): ?>
                <div class="cv-admin-empty"><strong>No canonical partner location.</strong><span>Private-location events still produce attendance, host and value intelligence.</span></div>
            <?php else: ?>
                <dl class="cv-admin-event-definition-list">
                    <div><dt>Status</dt><dd><?= coveted_e(ucwords(str_replace('_',' ',(string)$partner['relationship_status']))) ?></dd></div>
                    <div><dt>Completed events</dt><dd><?= (int)$partner['completed_events'] ?></dd></div>
                    <div><dt>Verified visits</dt><dd><?= (int)$partner['verified_visits'] ?></dd></div>
                    <div><dt>Repeat attendees</dt><dd><?= (int)$partner['repeat_attendees'] ?></dd></div>
                    <div><dt>Claims</dt><dd><?= (int)$partner['claims'] ?></dd></div>
                    <div><dt>Return claims</dt><dd><?= (int)$partner['return_claims'] ?></dd></div>
                    <div><dt>Open follow-ups</dt><dd><?= (int)$partner['open_followups'] ?></dd></div>
                    <div><dt>Overdue follow-ups</dt><dd><?= (int)$partner['overdue_followups'] ?></dd></div>
                </dl>
                <?php if ((int)$partner['open_followups'] === 0 && coveted_partner_crm_schema_available($pdo)): ?>
                    <form method="post" class="cv-event-result-followup-form">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="create_partner_followup">
                        <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                        <button class="cv-button cv-button-primary" type="submit">Create Partner Follow-up</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>

    <section class="cv-admin-panel cv-admin-section-gap">
        <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">HOST PERFORMANCE</span><h2>Assigned execution</h2></div><span class="cv-pill"><?= count($hosts) ?> hosts</span></div>
        <?php if (!$hosts): ?><div class="cv-admin-empty"><strong>No event hosts were assigned.</strong></div><?php else: ?>
            <div class="cv-event-host-results">
                <?php foreach ($hosts as $host): ?>
                    <div class="cv-event-host-result">
                        <div><strong><?= coveted_e((string)$host['display_name']) ?></strong><small><?= coveted_e(ucfirst((string)$host['host_role'])) ?></small></div>
                        <span><strong><?= (int)$host['completed_tasks'] ?>/<?= (int)$host['assigned_tasks'] ?></strong><small>Tasks complete</small></span>
                        <span><strong><?= $host['task_completion_rate'] === null ? '—' : coveted_e((string)$host['task_completion_rate']).'%' ?></strong><small>Completion</small></span>
                        <span><strong><?= (int)$host['blocked_tasks'] ?></strong><small>Blocked</small></span>
                        <span><strong><?= (int)$host['operational_notes'] ?></strong><small>Closeout / incident notes</small></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="cv-admin-panel cv-admin-section-gap">
        <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">AGENT INTELLIGENCE</span><h2>What should happen next</h2></div><span class="cv-pill"><?= count($recommendations) ?> signals</span></div>
        <?php if (!$recommendations): ?>
            <div class="cv-admin-empty"><strong>No post-event intervention is required.</strong><span>The Agent can still use this result as evidence when the Opportunity Engine evaluates what to plan next.</span></div>
        <?php else: ?>
            <div class="cv-admin-list">
                <?php foreach ($recommendations as $recommendation): ?>
                    <a class="cv-admin-list-row" href="<?= coveted_e((string)$recommendation['href']) ?>">
                        <span class="cv-admin-list-copy"><strong><?= coveted_e((string)$recommendation['title']) ?></strong><small><?= coveted_e((string)$recommendation['detail']) ?></small><?php if (!empty($recommendation['evidence'])): ?><small><?= coveted_e((string)$recommendation['evidence']) ?></small><?php endif; ?></span>
                        <span class="cv-status">P<?= (int)$recommendation['priority'] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php
coveted_admin_ui_end();
coveted_page_end();
