<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/benefit_economy.php';
require_once dirname(__DIR__) . '/app/admin_ui.php';

$admin = coveted_require_system_admin();
$snapshot = coveted_benefit_economy_snapshot($admin, 15);
$summary = $snapshot['summary'];

$formatDate = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }
    return coveted_utc_datetime($value)->setTimezone(coveted_timezone())->format('M j, Y');
};

coveted_page_start('Benefit Economy', '', true);
coveted_admin_ui_start($admin, 'benefits', 'Benefit Economy');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">BENEFIT ECONOMY</span>
        <h1>Value after the gathering.</h1>
        <p>Aggregate wallet, reward-pool, return-visit and attribution intelligence from Coveted's canonical campaign, issuance and claim records.</p>
    </div>
    <div class="cv-action-row">
        <a class="cv-button cv-button-soft" href="/admin/?view=benefits">Manage Benefits</a>
        <a class="cv-button cv-button-soft" href="/admin/?view=distribution">Distribution</a>
    </div>
</div>

<section class="cv-stat-grid" aria-label="Benefit economy summary">
    <div class="cv-card cv-stat"><strong><?= (int)$summary['ready'] ?></strong><span>Ready in wallets</span></div>
    <div class="cv-card cv-stat"><strong><?= (int)$summary['claimed'] ?></strong><span>Claimed total</span></div>
    <div class="cv-card cv-stat"><strong><?= coveted_e(number_format((float)$summary['claim_rate_30d'], 1)) ?>%</strong><span>30d claim rate</span></div>
    <div class="cv-card cv-stat"><strong><?= (int)$summary['membership_issued'] ?></strong><span>Membership perks</span></div>
    <div class="cv-card cv-stat"><strong><?= (int)$summary['return_claims'] ?></strong><span>Return claims</span></div>
    <div class="cv-card cv-stat"><strong><?= (int)$summary['expiring_7d'] ?></strong><span>Expiring · 7d</span></div>
</section>

<?php if ((int)$snapshot['membership_backlog'] > 0): ?>
    <div class="cv-alert cv-admin-section-gap">
        <?= (int)$snapshot['membership_backlog'] ?> active membership perk assignment<?= (int)$snapshot['membership_backlog'] === 1 ? '' : 's' ?> waiting for the lifecycle worker. Server command: <code>php scripts/reconcile-lifecycle.php</code>
    </div>
<?php endif; ?>

<div class="cv-section-head cv-admin-section-gap">
    <div><span class="cv-eyebrow">GROUP REWARD POOLS</span><h2>Membership inventory</h2></div>
    <span class="cv-pill"><?= count($snapshot['pools']) ?> shown</span>
</div>
<section class="cv-stack">
    <?php if (!$snapshot['pools']): ?>
        <div class="cv-card cv-empty"><h3>No bounded membership pools yet.</h3><p>Create a group-owned Membership campaign with a quantity limit to operate it as a Group Reward Pool.</p></div>
    <?php endif; ?>
    <?php foreach ($snapshot['pools'] as $pool): ?>
        <article class="cv-card cv-admin-row">
            <div>
                <div class="cv-tag-row">
                    <span class="cv-kicker"><?= coveted_e(strtoupper((string)$pool['status'])) ?></span>
                    <span class="cv-pill"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$pool['reward_type']))) ?></span>
                </div>
                <h3><?= coveted_e((string)$pool['title']) ?></h3>
                <p><?= coveted_e((string)$pool['group_name']) ?> · <?= coveted_e((string)$pool['reward_title']) ?></p>
                <p><?= (int)$pool['issued_count'] ?> issued · <?= (int)$pool['claimed_count'] ?> claimed · <?= (int)$pool['remaining_count'] ?> remaining of <?= (int)$pool['quantity_limit'] ?></p>
                <?php if ($pool['starts_at'] || $pool['ends_at']): ?><p><?= coveted_e($formatDate($pool['starts_at'])) ?> → <?= coveted_e($formatDate($pool['ends_at'])) ?></p><?php endif; ?>
            </div>
            <a class="cv-button cv-button-soft" href="/admin/?view=benefits">Manage</a>
        </article>
    <?php endforeach; ?>
</section>

<?php
$sections = [
    ['EVENT ATTRIBUTION', 'Events driving wallet value', $snapshot['event_attribution'], 'title', 'event'],
    ['GROUP ATTRIBUTION', 'Groups generating member value', $snapshot['group_attribution'], 'name', 'group'],
    ['BUSINESS ATTRIBUTION', 'Partners creating return value', $snapshot['business_attribution'], 'name', 'business'],
    ['ARTIST ATTRIBUTION', 'Artist reward engagement', $snapshot['artist_attribution'], 'artist_name', 'artist'],
];
?>
<?php foreach ($sections as [$eyebrow, $title, $rows, $nameKey, $kind]): ?>
    <div class="cv-section-head cv-admin-section-gap">
        <div><span class="cv-eyebrow"><?= coveted_e($eyebrow) ?></span><h2><?= coveted_e($title) ?></h2></div>
        <span class="cv-pill">Last 90 days</span>
    </div>
    <section class="cv-stack">
        <?php if (!$rows): ?><div class="cv-card cv-empty"><h3>No attributed activity yet.</h3></div><?php endif; ?>
        <?php foreach ($rows as $row): ?>
            <article class="cv-card cv-admin-row">
                <div>
                    <h3><?= coveted_e((string)$row[$nameKey]) ?></h3>
                    <p><?= (int)$row['issued_count'] ?> issued · <?= (int)$row['claimed_count'] ?> claimed<?php if ($kind === 'business'): ?> · <?= (int)$row['return_claim_count'] ?> return claims<?php endif; ?><?php if ($kind === 'group'): ?> · <?= (int)$row['membership_count'] ?> membership perks<?php endif; ?><?php if ($kind === 'artist'): ?> · <?= (int)$row['media_count'] ?> media rewards<?php endif; ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
<?php endforeach; ?>

<section class="cv-card cv-feature-card cv-copy-card cv-admin-section-gap">
    <span class="cv-kicker">ECONOMY LOOP</span>
    <h2>Membership → Event → Reward → Merchant Return Visit.</h2>
    <p>Coveted measures value through canonical issuance and redemption activity. The dashboard does not score members or infer purchasing intent.</p>
</section>

<p class="cv-muted cv-admin-section-gap">Snapshot generated <?= coveted_e($formatDate((string)$snapshot['generated_at'])) ?>. No member-level PII is exposed on this dashboard.</p>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
