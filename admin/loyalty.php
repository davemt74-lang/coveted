<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/loyalty.php';
require_once dirname(__DIR__) . '/app/admin_ui.php';

$admin = coveted_require_system_admin();
$snapshot = null;
$error = '';
try {
    $snapshot = coveted_loyalty_admin_snapshot();
} catch (Throwable $e) {
    error_log('Admin Loyalty workspace unavailable: ' . $e->getMessage());
    $error = 'Loyalty analytics are not available yet. Apply the Group Loyalty database migration, then reopen this page.';
}

$tierLabels = [];
foreach (coveted_loyalty_tiers() as $tier) $tierLabels[(string)$tier['key']] = (string)$tier['label'];

coveted_page_start('Group Loyalty', '', true);
coveted_admin_ui_start($admin, 'loyalty', 'Group Loyalty');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">RELATIONSHIP INTELLIGENCE</span>
        <h1>Group Loyalty + Membership Status</h1>
        <p>Private points preserve long-term participation across Coveted while group status measures local relationship depth. This is not a public leaderboard and balances are not manually editable.</p>
    </div>
    <div class="cv-action-row">
        <a class="cv-button cv-button-soft" href="/admin/benefit-programs.php">Benefit Programs</a>
        <a class="cv-button cv-button-soft" href="/admin/benefit-performance.php">Benefit Performance</a>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="cv-alert cv-alert-error" role="alert"><?= coveted_e($error) ?></div>
<?php elseif ($snapshot !== null): ?>
    <?php $summary = (array)$snapshot['summary']; ?>
    <section class="cv-stat-grid" aria-label="Loyalty summary">
        <div class="cv-card cv-stat"><strong><?= number_format((int)$summary['lifetime_points_issued']) ?></strong><span>Lifetime points issued</span></div>
        <div class="cv-card cv-stat"><strong><?= number_format((int)$summary['points_30d']) ?></strong><span>Points · last 30d</span></div>
        <div class="cv-card cv-stat"><strong><?= (int)$summary['active_memberships'] ?></strong><span>Active group relationships</span></div>
        <div class="cv-card cv-stat"><strong><?= (int)$summary['reconnect_relationships'] ?></strong><span>Reconnect relationships</span></div>
        <div class="cv-card cv-stat"><strong><?= $summary['second_event_rate'] === null ? '—' : number_format((float)$summary['second_event_rate'], 1) . '%' ?></strong><span>Second-event rate</span></div>
        <div class="cv-card cv-stat"><strong><?= (int)$summary['travel_ready_members'] ?></strong><span>Cross-group members</span></div>
    </section>

    <section class="cv-card cv-feature-card cv-copy-card cv-admin-section-gap">
        <span class="cv-kicker">POINT POLICY</span>
        <h2>Points come from verified behavior.</h2>
        <div class="cv-stat-grid">
            <div class="cv-card cv-stat"><strong>+<?= COVETED_LOYALTY_ATTENDANCE_POINTS ?></strong><span>Completed verified attendance</span></div>
            <div class="cv-card cv-stat"><strong>+<?= COVETED_LOYALTY_HOST_POINTS ?></strong><span>Lead / cohost contribution</span></div>
            <div class="cv-card cv-stat"><strong>+<?= COVETED_LOYALTY_RETURN_POINTS ?></strong><span>Exact verified return visit</span></div>
            <div class="cv-card cv-stat"><strong>0</strong><span>Benefit claim alone</span></div>
        </div>
        <p>Lifetime points are deliberately group-independent for future travel and cross-city recognition. Group points use the same verified entries for local status. There is no Admin control that directly overwrites a balance.</p>
    </section>

    <div class="cv-section-head cv-admin-section-gap">
        <div><span class="cv-eyebrow">STATUS DISTRIBUTION</span><h2>Where active relationships sit today</h2></div>
    </div>
    <section class="cv-stat-grid">
        <?php foreach ((array)$snapshot['tier_distribution'] as $key => $count): ?>
            <div class="cv-card cv-stat"><strong><?= (int)$count ?></strong><span><?= coveted_e($tierLabels[(string)$key] ?? ucwords(str_replace('_',' ',(string)$key))) ?></span></div>
        <?php endforeach; ?>
    </section>

    <div class="cv-section-head cv-admin-section-gap">
        <div><span class="cv-eyebrow">MILESTONE PIPELINE</span><h2>Members approaching meaningful participation moments</h2></div>
        <span class="cv-pill">Aggregate only</span>
    </div>
    <section class="cv-stat-grid">
        <div class="cv-card cv-stat"><strong><?= (int)$snapshot['near_milestones']['event_3'] ?></strong><span>One event from 3</span></div>
        <div class="cv-card cv-stat"><strong><?= (int)$snapshot['near_milestones']['event_5'] ?></strong><span>One event from 5</span></div>
        <div class="cv-card cv-stat"><strong><?= (int)$snapshot['near_milestones']['event_10'] ?></strong><span>One event from 10</span></div>
        <div class="cv-card cv-stat"><strong><?= (int)$snapshot['near_milestones']['event_25'] ?></strong><span>One event from 25</span></div>
        <div class="cv-card cv-stat"><strong><?= (int)$summary['milestones_30d'] ?></strong><span>Milestones reached · 30d</span></div>
    </section>

    <section class="cv-card cv-copy-card cv-admin-section-gap">
        <span class="cv-kicker">RETENTION</span>
        <h2>Second-event relationship</h2>
        <p><?= (int)$summary['second_event_relationships'] ?> of <?= (int)$summary['second_event_eligible'] ?> matured first-event group relationships have at least one later verified event<?= $summary['second_event_rate'] !== null ? ' (' . number_format((float)$summary['second_event_rate'], 1) . '%)' : '' ?>.</p>
        <p class="cv-muted">A relationship enters this cohort only after its first verified event is at least 30 days old. The metric is observational and does not attribute retention to any single event, venue or Benefit Program.</p>
    </section>

    <div class="cv-section-head cv-admin-section-gap">
        <div><span class="cv-eyebrow">GROUP HEALTH</span><h2>Participation by group</h2></div>
    </div>
    <section class="cv-stack">
        <?php if (!(array)$snapshot['groups']): ?><div class="cv-card cv-empty"><p>No loyalty activity has been reconciled yet.</p></div><?php endif; ?>
        <?php foreach ((array)$snapshot['groups'] as $group): ?>
            <article class="cv-card cv-copy-card">
                <div class="cv-section-head">
                    <div><strong><?= coveted_e((string)$group['group_name']) ?></strong><p><?= (int)$group['active_members'] ?> active relationship<?= (int)$group['active_members'] === 1 ? '' : 's' ?></p></div>
                    <span class="cv-pill"><?= number_format((int)$group['group_points']) ?> points</span>
                </div>
                <div class="cv-stat-grid">
                    <div class="cv-card cv-stat"><strong><?= (int)$group['verified_events'] ?></strong><span>Verified attendance records</span></div>
                    <div class="cv-card cv-stat"><strong><?= (int)$group['returns'] ?></strong><span>Verified partner returns</span></div>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="cv-card cv-feature-card cv-copy-card cv-admin-section-gap">
        <span class="cv-kicker">LONG-TERM / TRAVEL READY</span>
        <h2>One private lifetime ledger can follow the member across Coveted.</h2>
        <p><?= (int)$summary['travel_ready_members'] ?> member<?= (int)$summary['travel_ready_members'] === 1 ? '' : 's' ?> already have verified point activity in at least two groups. Future city access, travel programs or partner recognition can build from lifetime points without merging local group tiers.</p>
        <p class="cv-muted"><?= coveted_e((string)$snapshot['privacy']) ?></p>
    </section>
<?php endif; ?>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
