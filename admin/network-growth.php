<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/network_growth.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
if (coveted_system_sample_mode($admin, $pdo)) {
    coveted_redirect('/admin/system-preview.php?view=people');
}

$error = '';
$groups = [];
$selected = null;
$groupRef = trim((string)($_GET['group'] ?? ''));
$totals = [
    'introductions'=>0,
    'verified_participation'=>0,
    'repeat_participation'=>0,
    'conversions'=>0,
    'post_conversion_participation'=>0,
    'attendance_gaps'=>0,
    'conversion_ready'=>0,
];
try {
    $groups = coveted_network_growth_groups($admin, $pdo);
    if ($groupRef === '' && $groups) $groupRef = (string)$groups[0]['group']['public_id'];
    foreach ($groups as $snapshot) {
        foreach (array_keys($totals) as $key) $totals[$key] += (int)($snapshot['counts'][$key] ?? 0);
        if ($groupRef !== '' && ((string)$snapshot['group']['public_id'] === $groupRef || (string)$snapshot['group']['id'] === $groupRef)) {
            $selected = $snapshot;
        }
    }
    if (!$selected && $groupRef !== '') $selected = coveted_network_growth_group_snapshot($admin, $groupRef, $pdo);
} catch (Throwable $e) {
    error_log('Network Growth workspace unavailable: ' . $e->getMessage());
    $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Network Growth evidence is temporarily unavailable.';
}

$fmt = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '') return 'Not recorded';
    try { return coveted_utc_datetime($value)->setTimezone(coveted_timezone())->format('M j, Y g:i A'); }
    catch (Throwable) { return $value; }
};
$stateLabel = static fn(string $value): string => match ($value) {
    'no_evidence' => 'No outcome evidence',
    'introduction_only' => 'Introductions awaiting evidence',
    'relationship_building' => 'Relationship building',
    'healthy_growth' => 'Healthy growth evidence',
    'sustained_growth' => 'Sustained member growth',
    default => ucwords(str_replace('_',' ',$value)),
};

coveted_page_start('Referral / Network Growth', '', true);
coveted_admin_ui_start($admin, 'network-growth', 'Referral / Network Growth');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">PEOPLE · NETWORK GROWTH</span>
        <h1>Measure what happens after a member makes an introduction.</h1>
        <p>Follow canonical Guest Pass introductions into verified attendance, repeat participation, membership conversion and post-conversion participation. Raw invite volume is not treated as success and no member/referrer ranking is created.</p>
    </div>
    <div class="cv-action-row">
        <a class="cv-button cv-button-soft" href="/admin/guest-conversions.php">Guest Conversion</a>
        <a class="cv-button cv-button-soft" href="/admin/member-journeys.php">Member Journeys</a>
        <a class="cv-button cv-button-soft" href="/admin/member-relationships.php">Relationship Intelligence</a>
        <a class="cv-button cv-button-soft" href="/admin/agent-tasks.php">Agent Tasks</a>
    </div>
</div>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>

<div class="cv-admin-metric-grid cv-admin-section-gap">
    <div><span>Used introductions</span><strong><?= (int)$totals['introductions'] ?></strong><small>accepted Guest Pass paths</small></div>
    <div><span>Verified participants</span><strong><?= (int)$totals['verified_participation'] ?></strong><small>completed Event evidence</small></div>
    <div><span>Repeat participation</span><strong><?= (int)$totals['repeat_participation'] ?></strong><small>2+ verified Events</small></div>
    <div><span>Member conversions</span><strong><?= (int)$totals['conversions'] ?></strong><small>accepted Invite to Stay</small></div>
</div>

<div class="cv-admin-dashboard-grid cv-admin-section-gap">
<section class="cv-admin-panel">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">GROUP EVIDENCE</span><h2>Network-growth coverage</h2></div><span class="cv-status">Not a leaderboard</span></div>
    <div class="cv-admin-list">
        <?php if (!$groups): ?><div class="cv-admin-empty"><strong>No active groups.</strong><span>Network Growth begins from existing canonical group and Guest Pass activity.</span></div><?php endif; ?>
        <?php foreach ($groups as $snapshot): $c=(array)$snapshot['counts']; ?>
            <a class="cv-admin-list-row" href="/admin/network-growth.php?group=<?= coveted_e(rawurlencode((string)$snapshot['group']['public_id'])) ?>">
                <span class="cv-admin-list-copy">
                    <strong><?= coveted_e((string)$snapshot['group']['name']) ?></strong>
                    <small><?= (int)$c['introductions'] ?> used introduction<?= (int)$c['introductions']===1?'':'s' ?> · <?= (int)$c['verified_participation'] ?> verified · <?= (int)$c['repeat_participation'] ?> repeat · <?= (int)$c['conversions'] ?> converted</small>
                </span>
                <span class="cv-status"><?= coveted_e($stateLabel((string)$snapshot['state'])) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($selected): $counts=(array)$selected['counts']; ?>
<section class="cv-admin-panel">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">SELECTED GROUP</span><h2><?= coveted_e((string)$selected['group']['name']) ?></h2></div><span class="cv-status"><?= coveted_e($stateLabel((string)$selected['state'])) ?></span></div>
    <dl class="cv-admin-event-definition-list">
        <div><dt>Used Guest Pass introductions</dt><dd><?= (int)$counts['introductions'] ?></dd></div>
        <div><dt>Reached verified attendance</dt><dd><?= (int)$counts['verified_participation'] ?></dd></div>
        <div><dt>Reached repeat participation</dt><dd><?= (int)$counts['repeat_participation'] ?></dd></div>
        <div><dt>Converted to Member</dt><dd><?= (int)$counts['conversions'] ?></dd></div>
        <div><dt>Participated after conversion</dt><dd><?= (int)$counts['post_conversion_participation'] ?></dd></div>
        <div><dt>60-day attendance gaps</dt><dd><?= (int)$counts['attendance_gaps'] ?></dd></div>
        <div><dt>Conversion-ready now</dt><dd><?= (int)$counts['conversion_ready'] ?></dd></div>
    </dl>
    <?php foreach ((array)$selected['recommendations'] as $recommendation): ?>
        <div class="cv-alert"><strong><?= coveted_e((string)$recommendation['title']) ?></strong><br><?= coveted_e((string)$recommendation['detail']) ?><br><small><?= coveted_e((string)$recommendation['evidence']) ?></small></div>
    <?php endforeach; ?>
    <div class="cv-action-row">
        <a class="cv-button cv-button-soft" href="/admin/member-relationships.php?group=<?= coveted_e(rawurlencode((string)$selected['group']['public_id'])) ?>">Group Relationship Planning</a>
        <a class="cv-button cv-button-soft" href="/admin/guest-conversions.php">Guest Conversion</a>
    </div>
</section>
<?php endif; ?>
</div>

<?php if ($selected): ?>
<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">RELATIONSHIP OUTCOMES</span><h2>Introduction → participation → membership</h2></div><span class="cv-status">System Admin only</span></div>
    <p>Each row starts with a <strong>used</strong> canonical Guest Pass—not a sent invitation. This keeps the measurement focused on actual introductions and what followed.</p>
    <div class="cv-admin-list">
        <?php if (!$selected['referrals']): ?><div class="cv-admin-empty"><strong>No used Guest Pass evidence yet.</strong><span>Coveted does not recommend increasing invitation volume just to create a metric.</span></div><?php endif; ?>
        <?php foreach ((array)$selected['referrals'] as $row): ?>
            <div class="cv-admin-list-row">
                <span class="cv-admin-list-copy">
                    <strong><?= coveted_e((string)$row['referrer_name']) ?> → <?= coveted_e((string)$row['guest_name']) ?></strong>
                    <small>Introduced <?= coveted_e($fmt((string)$row['introduced_at'])) ?> · <?= (int)$row['verified_events'] ?> verified Event<?= (int)$row['verified_events']===1?'':'s' ?> · <?= coveted_e((string)$row['outcome_label']) ?></small>
                    <?php if ((int)$row['post_conversion_verified'] > 0): ?><small><?= (int)$row['post_conversion_verified'] ?> verified Event<?= (int)$row['post_conversion_verified']===1?'':'s' ?> after becoming a Member.</small><?php endif; ?>
                    <?php if (!empty($row['attendance_gap'])): ?><small>Gap: no verified completed-Event attendance after 60 days. Review fit/context before more outreach.</small><?php endif; ?>
                </span>
                <span class="cv-action-row">
                    <?php if (!empty($row['conversion_ready_here'])): ?><a class="cv-button cv-button-primary" href="/admin/guest-conversions.php?guest=<?= coveted_e(rawurlencode((string)$row['guest_ref'])) ?>">Review conversion</a><?php endif; ?>
                    <?php if (!empty($row['converted'])): ?><a class="cv-button cv-button-soft" href="/admin/member-journeys.php?member=<?= coveted_e(rawurlencode((string)$row['guest_ref'])) ?>">Member Journey</a><?php endif; ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">MEASUREMENT MODEL</span><h2>Outcome evidence, not referral popularity</h2></div></div>
    <p><strong>Healthy growth evidence</strong> means verified attendance, repeat participation, accepted Guest → Member conversion, or continued verified participation after conversion. A member does not become “better” because they sent more invitations, and Coveted does not create referral points, public leaderboards, member-value scores or personality labels.</p>
    <div class="cv-alert"><strong>Authority:</strong> <?= coveted_e((string)$selected['authority']) ?></div>
</section>
<?php endif; ?>

<?php coveted_admin_ui_end(); coveted_page_end(); ?>
