<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/event_opportunities.php';
require_once dirname(__DIR__) . '/app/event_proposals.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
$error = '';
$notice = '';
$selectedKey = trim((string)($_GET['opportunity'] ?? $_POST['opportunity_key'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    try {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action !== 'create_proposal') throw new InvalidArgumentException('Unsupported Event Opportunity action.');
        $result = coveted_event_proposal_create_from_opportunity($admin, $selectedKey, (string)($_POST['playbook_ref'] ?? ''), $pdo);
        coveted_redirect('/admin/event-proposals.php?proposal=' . rawurlencode((string)$result['public_id']) . '&created=1');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Event Opportunity proposal action failed: ' . $e->getMessage());
        $error = 'Unable to create that Event Proposal right now.';
    }
}

$opportunities = coveted_event_opportunities($admin, $pdo);
$selected = $selectedKey !== '' ? coveted_event_opportunity_by_key($admin, $selectedKey, $pdo) : null;
if ($selected === null && $opportunities) {$selected = $opportunities[0];$selectedKey = (string)$selected['key'];}
$high = count(array_filter($opportunities, static fn(array $row): bool => (int)$row['priority'] === 1));
$avgScore = $opportunities ? (int)round(array_sum(array_map(static fn(array $row): int => (int)$row['score'], $opportunities)) / count($opportunities)) : 0;
$learningBacked = count(array_filter($opportunities, static fn(array $row): bool => in_array((string)($row['predictive_plan']['confidence'] ?? ''), ['emerging','medium','high'], true)));
$proposalReady = coveted_event_proposal_schema_available($pdo);
$playbooks = $proposalReady ? coveted_event_playbooks($admin, false, $pdo) : [];
$recommendedPlaybook = null;
if ($selected && $playbooks) {
    $predictedKey = trim((string)($selected['predictive_plan']['playbook_key'] ?? ''));
    if ($predictedKey !== '') {
        foreach ($playbooks as $playbook) if ((string)$playbook['playbook_key'] === $predictedKey) {$recommendedPlaybook = $playbook;break;}
    }
    $recommendedPlaybook ??= coveted_event_proposal_suggest_playbook($selected, $playbooks);
}

$formatStart = static function (string $utc, string $timezone): string {
    try {return coveted_utc_datetime($utc)->setTimezone(coveted_timezone($timezone))->format('D, M j · g:i A T');}
    catch (Throwable) {return $utc;}
};

coveted_page_start('Event Opportunities', '', true);
coveted_admin_ui_start($admin, 'event-opportunities', 'Event Opportunities');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">EVENT OPPORTUNITY ENGINE</span>
        <h1>What should Coveted host next?</h1>
        <p>Deterministic partner/cadence rules refined by completed Event Results when historical confidence is sufficient.</p>
    </div>
    <div class="cv-admin-event-top-actions">
        <a class="cv-button cv-button-soft" href="/admin/event-learning.php">Event Learning</a>
        <a class="cv-button cv-button-soft" href="/admin/event-proposals.php">Proposals</a>
        <a class="cv-button cv-button-soft" href="/admin/event-playbooks.php">Playbooks</a>
        <a class="cv-button cv-button-soft" href="/admin/?view=events">All Events</a>
    </div>
</div>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>
<?php if (!$proposalReady): ?><div class="cv-alert cv-alert-error"><strong>Planning migration required.</strong> Import the Event Proposals + Playbooks migration before turning recommendations into proposals.</div><?php endif; ?>

<div class="cv-admin-metric-grid cv-admin-section-gap">
    <div><span>Recommendations</span><strong><?= count($opportunities) ?></strong><small>No future event already scheduled</small></div>
    <div><span>Priority</span><strong><?= $high ?></strong><small>P1 opportunities</small></div>
    <div><span>Avg score</span><strong><?= $avgScore ?></strong><small>Rules + historical adjustment</small></div>
    <div><span>Learned plans</span><strong><?= $learningBacked ?></strong><small>Emerging+ predictive confidence</small></div>
</div>

<?php if (!$opportunities): ?>
    <section class="cv-admin-panel cv-admin-section-gap"><div class="cv-admin-empty"><strong>No event gap is currently recommended.</strong><span>Groups with established partner relationships already have future coverage or do not meet the recommendation threshold.</span></div></section>
<?php else: ?>
    <div class="cv-admin-dashboard-grid cv-admin-section-gap">
        <section class="cv-admin-panel">
            <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">RANKED OPPORTUNITIES</span><h2>Next-event queue</h2></div><span class="cv-pill">Agent-visible</span></div>
            <div class="cv-admin-list">
                <?php foreach ($opportunities as $item): ?>
                    <?php $entity=(array)$item['entity'];$plan=(array)($item['predictive_plan']??[]); ?>
                    <a class="cv-admin-list-row" href="/admin/event-opportunities.php?opportunity=<?= coveted_e(rawurlencode((string)$item['key'])) ?>">
                        <span class="cv-admin-list-copy">
                            <strong><?= coveted_e((string)$item['title']) ?></strong>
                            <small><?= coveted_e((string)$entity['group_name']) ?> · <?= coveted_e((string)$entity['location_name']) ?> · <?= coveted_e((string)$entity['business_name']) ?></small>
                            <small><?= coveted_e((string)$item['evidence']) ?></small>
                            <?php if (!empty($plan['available'])): ?><small>Predictive confidence: <?= coveted_e(ucfirst((string)$plan['confidence'])) ?> · <?= (int)$plan['comparable_events'] ?> comparable events</small><?php endif; ?>
                        </span><span><strong><?= (int)$item['score'] ?></strong><br><small>P<?= (int)$item['priority'] ?></small></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <?php if ($selected): ?>
            <?php $draft=(array)$selected['suggested_draft'];$entity=(array)$selected['entity'];$signals=(array)$selected['signals'];$plan=(array)($selected['predictive_plan']??[]); ?>
            <section class="cv-admin-panel">
                <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">RECOMMENDED BUILD</span><h2><?= coveted_e((string)$draft['title']) ?></h2></div><span class="cv-status">Score <?= (int)$selected['score'] ?></span></div>
                <p><?= coveted_e((string)$selected['detail']) ?></p>
                <dl class="cv-admin-event-definition-list">
                    <div><dt>Group</dt><dd><?= coveted_e((string)$entity['group_name']) ?></dd></div>
                    <div><dt>Venue</dt><dd><?= coveted_e((string)$entity['business_name']) ?> · <?= coveted_e((string)$entity['location_name']) ?></dd></div>
                    <div><dt>Relationship</dt><dd><?= coveted_e(ucwords(str_replace('_',' ',(string)$entity['relationship_status']))) ?></dd></div>
                    <div><dt>Suggested start</dt><dd><?= coveted_e($formatStart((string)$draft['starts_at'], (string)$draft['timezone'])) ?></dd></div>
                    <div><dt>Capacity</dt><dd><?= (int)$draft['capacity'] ?></dd></div>
                    <div><dt>Format</dt><dd><?= coveted_e(ucwords(str_replace('_',' ',(string)$draft['event_type']))) ?></dd></div>
                    <div><dt>Days since last</dt><dd><?= (int)$signals['days_since_last_event'] ?></dd></div>
                    <div><dt>Verified visits</dt><dd><?= (int)$signals['verified_visits'] ?></dd></div>
                    <div><dt>Active perks</dt><dd><?= (int)$signals['active_perks'] ?></dd></div>
                    <div><dt>Active campaigns</dt><dd><?= (int)$signals['active_campaigns'] ?></dd></div>
                </dl>

                <?php if (!empty($plan['available'])): ?>
                    <div class="cv-alert">
                        <strong>Predictive plan · <?= coveted_e(ucfirst((string)$plan['confidence'])) ?> confidence.</strong>
                        <?= (int)$plan['comparable_events'] ?> comparable completed events · <?= coveted_e(number_format((float)$plan['avg_result_score'],1)) ?>/100 avg result · <?= coveted_e(number_format((float)$plan['attendance_rate'],1)) ?>% attendance.
                        <?php if (!empty($plan['playbook_name'])): ?> Recommended Playbook: <strong><?= coveted_e((string)$plan['playbook_name']) ?></strong>.<?php endif; ?>
                        <?php if (!empty($plan['benefit']['reward_title'])): ?> Best observed benefit: <strong><?= coveted_e((string)$plan['benefit']['reward_title']) ?></strong> (<?= coveted_e(number_format((float)$plan['benefit']['claim_rate'],1)) ?>% claims).<?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="cv-alert"><strong>Planning first.</strong> Create a proposal, work the partner relationship, approve the terms, then convert it into a canonical draft Event. Publishing remains a separate System Admin decision.</div>
                <?php if ($proposalReady && $playbooks): ?>
                    <form method="post" data-confirm="Create an Event Proposal from this recommendation?">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="create_proposal">
                        <input type="hidden" name="opportunity_key" value="<?= coveted_e((string)$selected['key']) ?>">
                        <label>Event Playbook
                            <select name="playbook_ref" required>
                                <?php foreach ($playbooks as $playbook): ?>
                                    <option value="<?= coveted_e((string)$playbook['public_id']) ?>" <?= $recommendedPlaybook && (int)$recommendedPlaybook['id']===(int)$playbook['id']?'selected':'' ?>><?= coveted_e((string)$playbook['name']) ?> · <?= (int)$playbook['default_capacity'] ?> default guests</option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button class="cv-button cv-button-primary" type="submit">Create Proposal</button>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
<?php endif; ?>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">HOW IT THINKS</span><h2>Rules first, learning second</h2></div></div>
    <p>The engine first requires a real event gap and an established or inferred partner venue relationship. It scores cadence, relationship tier, verified attendance, membership, benefits, Partner Perks and campaigns. Completed Event Results can then adjust the score and refine timing, capacity, Playbook and benefit evidence. Low-confidence history remains advisory and never replaces the deterministic fallback.</p>
</section>
<?php coveted_admin_ui_end(); coveted_page_end(); ?>
