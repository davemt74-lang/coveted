<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/event_learning.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
if (coveted_system_sample_mode($admin, $pdo)) {
    coveted_redirect('/admin/system-preview.php?view=events');
}

$snapshot = coveted_event_learning_snapshot($admin, 140, $pdo);
$global = (array)($snapshot['global'] ?? []);
$playbooks = array_slice((array)($snapshot['playbooks'] ?? []), 0, 14);
$venues = array_slice((array)($snapshot['venues'] ?? []), 0, 14);
$groups = array_slice((array)($snapshot['groups'] ?? []), 0, 16);
$hosts = array_slice((array)($snapshot['hosts'] ?? []), 0, 16);
$benefits = array_slice((array)($snapshot['benefits'] ?? []), 0, 16);
$recommendations = array_slice((array)($snapshot['recommendations'] ?? []), 0, 12);

$confidenceLabel = static fn(array $row): string => ucfirst((string)($row['confidence']['label'] ?? 'low'));
$percent = static fn(mixed $value): string => number_format((float)$value, 1) . '%';
$score = static fn(mixed $value): string => number_format((float)$value, 1) . '/100';

coveted_page_start('Event Learning', '', true);
coveted_admin_ui_start($admin, 'event-learning', 'Event Learning');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">EVENT LEARNING + PREDICTIVE PLANNING</span>
        <h1>Turn completed events into better next events.</h1>
        <p>Read-only learning from canonical Event Results. Coveted measures what worked, assigns confidence, and feeds evidence back into Event Opportunities and the Admin Agent.</p>
    </div>
    <div class="cv-action-row">
        <a class="cv-button cv-button-soft" href="/admin/event-opportunities.php">Event Opportunities</a>
        <a class="cv-button cv-button-soft" href="/admin/event-results.php">Event Results</a>
        <a class="cv-button cv-button-soft" href="/admin/event-playbooks.php">Playbooks</a>
    </div>
</div>

<div class="cv-admin-metric-grid cv-admin-section-gap">
    <div><span>Learning sample</span><strong><?= (int)($snapshot['event_count'] ?? 0) ?></strong><small>Completed canonical events</small></div>
    <div><span>Avg result</span><strong><?= coveted_e($score($global['avg_score'] ?? 0)) ?></strong><small><?= coveted_e($confidenceLabel($global)) ?> confidence overall</small></div>
    <div><span>Attendance</span><strong><?= coveted_e($percent($global['attendance_rate'] ?? 0)) ?></strong><small>RSVP → verified attendance</small></div>
    <div><span>Repeat</span><strong><?= coveted_e($percent($global['repeat_rate'] ?? 0)) ?></strong><small>Returning group attendees</small></div>
</div>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PREDICTIVE LOOP</span><h2>How recommendations improve</h2></div><span class="cv-pill">Derived · no new schema</span></div>
    <div class="cv-learning-flow">
        <div><strong>1. Event Result</strong><span>Attendance, no-shows, repeat attendance, Production, rewards, claims, incidents and partner outcome.</span></div>
        <div><strong>2. Learning</strong><span>Compare Playbook, group, venue, time slot, host operations and benefit performance.</span></div>
        <div><strong>3. Prediction</strong><span>Choose evidence-backed timing, capacity, Playbook and benefit with an explicit confidence level.</span></div>
        <div><strong>4. Opportunity</strong><span>The canonical Event Opportunity recipe is refined before Proposal creation; System Admin still decides.</span></div>
    </div>
    <div class="cv-alert"><strong>Confidence matters.</strong> One completed event is advisory only. Two events are emerging evidence, three to four are medium confidence, and five or more comparable events are high confidence. Low-confidence history never overrides the deterministic planning fallback.</div>
</section>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PLAYBOOK PERFORMANCE</span><h2>Which operating patterns work?</h2></div><span class="cv-pill"><?= count($playbooks) ?> measured</span></div>
    <?php if (!$playbooks): ?>
        <div class="cv-admin-empty"><strong>No converted Playbook history yet.</strong><span>Playbook performance begins once approved Proposals convert into completed canonical Events.</span></div>
    <?php else: ?>
        <div class="cv-learning-table-wrap"><table class="cv-learning-table"><thead><tr><th>Playbook</th><th>Events</th><th>Result</th><th>Attendance</th><th>Repeat</th><th>Claims</th><th>Best slot</th><th>Capacity</th></tr></thead><tbody>
        <?php foreach ($playbooks as $row): ?><tr>
            <td><strong><?= coveted_e((string)$row['name']) ?></strong><small><?= coveted_e($confidenceLabel($row)) ?> confidence</small></td>
            <td><?= (int)$row['events'] ?></td><td><?= coveted_e($score($row['avg_score'])) ?></td><td><?= coveted_e($percent($row['attendance_rate'])) ?></td><td><?= coveted_e($percent($row['repeat_rate'])) ?></td><td><?= coveted_e($percent($row['claim_rate'])) ?></td>
            <td><?= coveted_e((string)($row['best_slot']['label'] ?? '—')) ?></td><td><?= (int)$row['recommended_capacity'] ?></td>
        </tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>

<div class="cv-admin-dashboard-grid cv-admin-section-gap">
    <section class="cv-admin-panel">
        <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">VENUE INTELLIGENCE</span><h2>Where events perform best</h2></div></div>
        <div class="cv-admin-list">
            <?php if (!$venues): ?><div class="cv-admin-empty"><strong>No canonical venue history yet.</strong></div><?php endif; ?>
            <?php foreach ($venues as $row): ?>
                <div class="cv-admin-list-row"><span class="cv-admin-list-copy">
                    <strong><?= coveted_e((string)$row['business_name']) ?> · <?= coveted_e((string)$row['location_name']) ?></strong>
                    <small><?= (int)$row['events'] ?> events · <?= coveted_e($score($row['avg_score'])) ?> · <?= coveted_e($percent($row['attendance_rate'])) ?> attendance</small>
                    <small>Best slot: <?= coveted_e((string)($row['best_slot']['label'] ?? '—')) ?> · Top group: <?= coveted_e((string)($row['top_group']['label'] ?? '—')) ?> · Suggested capacity <?= (int)$row['recommended_capacity'] ?></small>
                </span><span class="cv-status"><?= coveted_e($confidenceLabel($row)) ?></span></div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="cv-admin-panel">
        <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">GROUP EVENT INTELLIGENCE</span><h2>Cadence and fatigue</h2></div></div>
        <div class="cv-admin-list">
            <?php if (!$groups): ?><div class="cv-admin-empty"><strong>No completed group-event history yet.</strong></div><?php endif; ?>
            <?php foreach ($groups as $row): ?>
                <div class="cv-admin-list-row"><span class="cv-admin-list-copy">
                    <strong><?= coveted_e((string)$row['group_name']) ?></strong>
                    <small><?= (int)$row['events'] ?> events · <?= coveted_e($score($row['avg_score'])) ?> · <?= coveted_e($percent($row['repeat_rate'])) ?> repeat attendance</small>
                    <small>Cadence ≈ <?= (int)$row['ideal_cadence_days'] ?> days · <?= (int)$row['days_since_last_event'] ?> days since last · Best slot <?= coveted_e((string)($row['best_slot']['label'] ?? '—')) ?></small>
                    <small>Preferred Playbook: <?= coveted_e((string)($row['preferred_playbook']['label'] ?? '—')) ?> · Venue: <?= coveted_e((string)($row['preferred_venue']['label'] ?? '—')) ?></small>
                </span><span class="cv-status cv-learning-fatigue-<?= coveted_e((string)$row['fatigue_risk']) ?>"><?= coveted_e(ucfirst((string)$row['fatigue_risk'])) ?> fatigue</span></div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<div class="cv-admin-dashboard-grid cv-admin-section-gap">
    <section class="cv-admin-panel">
        <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">HOST OPERATIONS</span><h2>Internal execution learning</h2></div><span class="cv-pill">Not a public leaderboard</span></div>
        <p class="cv-learning-note">Host metrics are used to improve assignments and Production process. Coveted does not claim per-host check-in accuracy because the current attendance record does not reliably attribute every check-in to a specific host.</p>
        <div class="cv-learning-table-wrap"><table class="cv-learning-table"><thead><tr><th>Host</th><th>Events</th><th>Lead</th><th>Tasks</th><th>Completion</th><th>Blocked</th><th>Avg event result</th></tr></thead><tbody>
            <?php foreach ($hosts as $row): ?><tr><td><strong><?= coveted_e((string)$row['display_name']) ?></strong><small><?= coveted_e($confidenceLabel($row)) ?> history</small></td><td><?= (int)$row['events'] ?></td><td><?= (int)$row['lead_events'] ?></td><td><?= (int)$row['assigned_tasks'] ?></td><td><?= $row['task_completion_rate']===null?'—':coveted_e($percent($row['task_completion_rate'])) ?></td><td><?= (int)$row['blocked_tasks'] ?></td><td><?= coveted_e($score($row['avg_event_score'])) ?></td></tr><?php endforeach; ?>
            <?php if (!$hosts): ?><tr><td colspan="7">No host execution history yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

    <section class="cv-admin-panel">
        <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">BENEFIT INTELLIGENCE</span><h2>What value gets used?</h2></div></div>
        <div class="cv-admin-list">
            <?php if (!$benefits): ?><div class="cv-admin-empty"><strong>No event reward issuance history yet.</strong></div><?php endif; ?>
            <?php foreach ($benefits as $row): ?>
                <div class="cv-admin-list-row"><span class="cv-admin-list-copy">
                    <strong><?= coveted_e((string)$row['reward_title']) ?></strong>
                    <small><?= (int)$row['issuances'] ?> issued · <?= (int)$row['claims'] ?> claims · <?= coveted_e($percent($row['claim_rate'])) ?> claim rate</small>
                    <small><?= coveted_e($percent($row['return_rate'])) ?> return-visit signal · <?= coveted_e($percent($row['refund_rate'])) ?> refund rate · <?= (int)$row['events'] ?> events</small>
                </span><span class="cv-status"><?= coveted_e($confidenceLabel($row)) ?></span></div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">AGENT LEARNING QUEUE</span><h2>What should change next?</h2></div><span class="cv-pill">Agent-visible</span></div>
    <div class="cv-admin-list">
        <?php if (!$recommendations): ?><div class="cv-admin-empty"><strong>No learning intervention is currently needed.</strong><span>Predictive evidence is still available inside Event Opportunities.</span></div><?php endif; ?>
        <?php foreach ($recommendations as $row): ?>
            <a class="cv-admin-list-row" href="<?= coveted_e((string)$row['href']) ?>"><span class="cv-admin-list-copy"><strong><?= coveted_e((string)$row['title']) ?></strong><small><?= coveted_e((string)$row['detail']) ?></small><small><?= coveted_e((string)$row['evidence']) ?></small></span><span class="cv-status">P<?= (int)$row['priority'] ?></span></a>
        <?php endforeach; ?>
    </div>
</section>

<div class="cv-alert cv-admin-section-gap"><strong>Agent boundary.</strong> Event Learning is evidence, not autonomous event authority. The Agent can explain predictions and recommend Playbooks, timing, capacity and benefits; Proposal approval, Event creation/configuration and publishing remain System Admin decisions.</div>

<?php coveted_admin_ui_end(); coveted_page_end(); ?>
