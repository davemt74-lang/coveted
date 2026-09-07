<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/event_opportunities.php';
require_once dirname(__DIR__) . '/app/event_production.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
$error = '';
$notice = '';
$selectedKey = trim((string)($_GET['opportunity'] ?? $_POST['opportunity_key'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    try {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action !== 'create_draft') {
            throw new InvalidArgumentException('Unsupported Event Opportunity action.');
        }

        $result = coveted_event_opportunity_create_draft($admin, $selectedKey, $pdo);
        $eventRef = (string)$result['event']['public_id'];
        if (coveted_event_production_schema_available($pdo)) {
            try {
                coveted_event_production_seed_defaults($admin, $eventRef, $pdo);
            } catch (Throwable $e) {
                error_log('Unable to seed Event Production defaults after opportunity draft: ' . $e->getMessage());
            }
        }
        coveted_redirect('/admin/event-production.php?event=' . rawurlencode($eventRef) . '&created=1');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Event Opportunity action failed: ' . $e->getMessage());
        $error = 'Unable to create that recommended event draft right now.';
    }
}

$opportunities = coveted_event_opportunities($admin, $pdo);
$selected = $selectedKey !== '' ? coveted_event_opportunity_by_key($admin, $selectedKey, $pdo) : null;
if ($selected === null && $opportunities) {
    $selected = $opportunities[0];
    $selectedKey = (string)$selected['key'];
}
$high = count(array_filter($opportunities, static fn(array $row): bool => (int)$row['priority'] === 1));
$avgScore = $opportunities ? (int)round(array_sum(array_map(static fn(array $row): int => (int)$row['score'], $opportunities)) / count($opportunities)) : 0;

$formatStart = static function (string $utc, string $timezone): string {
    try {
        return coveted_utc_datetime($utc)->setTimezone(coveted_timezone($timezone))->format('D, M j · g:i A T');
    } catch (Throwable) {
        return $utc;
    }
};

coveted_page_start('Event Opportunities', '', true);
coveted_admin_ui_start($admin, 'event-opportunities', 'Event Opportunities');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">EVENT OPPORTUNITY ENGINE</span>
        <h1>What should Coveted host next?</h1>
        <p>Deterministic recommendations from group cadence, partner strength, attendance, member value and existing future-event coverage.</p>
    </div>
    <a class="cv-button cv-button-soft" href="/admin/?view=events">All Events</a>
</div>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<div class="cv-admin-metric-grid cv-admin-section-gap">
    <div><span>Recommendations</span><strong><?= count($opportunities) ?></strong><small>No future event already scheduled</small></div>
    <div><span>Priority</span><strong><?= $high ?></strong><small>P1 opportunities</small></div>
    <div><span>Avg score</span><strong><?= $avgScore ?></strong><small>0–100 deterministic score</small></div>
    <div><span>Authority</span><strong>Admin</strong><small>Recommendations never create events automatically</small></div>
</div>

<?php if (!$opportunities): ?>
    <section class="cv-admin-panel cv-admin-section-gap">
        <div class="cv-admin-empty"><strong>No event gap is currently recommended.</strong><span>Groups with established partner relationships already have future coverage or do not meet the recommendation threshold.</span></div>
    </section>
<?php else: ?>
    <div class="cv-admin-dashboard-grid cv-admin-section-gap">
        <section class="cv-admin-panel">
            <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">RANKED OPPORTUNITIES</span><h2>Next-event queue</h2></div><span class="cv-pill">Read model</span></div>
            <div class="cv-admin-list">
                <?php foreach ($opportunities as $item): ?>
                    <?php $entity = (array)$item['entity']; ?>
                    <a class="cv-admin-list-row" href="/admin/event-opportunities.php?opportunity=<?= coveted_e(rawurlencode((string)$item['key'])) ?>">
                        <span class="cv-admin-list-copy">
                            <strong><?= coveted_e((string)$item['title']) ?></strong>
                            <small><?= coveted_e((string)$entity['group_name']) ?> · <?= coveted_e((string)$entity['location_name']) ?> · <?= coveted_e((string)$entity['business_name']) ?></small>
                            <small><?= coveted_e((string)$item['evidence']) ?></small>
                        </span>
                        <span><strong><?= (int)$item['score'] ?></strong><br><small>P<?= (int)$item['priority'] ?></small></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <?php if ($selected): ?>
            <?php $draft = (array)$selected['suggested_draft']; $entity = (array)$selected['entity']; $signals = (array)$selected['signals']; ?>
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
                <div class="cv-alert"><strong>Draft only.</strong> Creating this recommendation opens a canonical draft Event and preloads its Event Production checklist. Publishing remains a separate System Admin decision.</div>
                <form method="post" data-confirm="Create this recommended event as a draft?">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_draft">
                    <input type="hidden" name="opportunity_key" value="<?= coveted_e((string)$selected['key']) ?>">
                    <button class="cv-button cv-button-primary" type="submit">Create Draft + Production Plan</button>
                </form>
            </section>
        <?php endif; ?>
    </div>
<?php endif; ?>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">HOW IT THINKS</span><h2>Evidence, not guesswork</h2></div></div>
    <p>The engine favors groups without another future event, then scores established venue relationships using time since the last event, relationship tier, verified attendance, active membership, enabled benefits, standing Partner Perks and active partner campaigns. It never invents a venue, group, member or event reference.</p>
</section>
<?php coveted_admin_ui_end(); coveted_page_end(); ?>
