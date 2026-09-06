<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/benefit_performance.php';
require_once dirname(__DIR__) . '/app/admin_ui.php';

$admin = coveted_require_system_admin();
$allowedWindows = [30, 90, 180, 365];
$days = (int)($_GET['days'] ?? COVETED_BENEFIT_PERFORMANCE_DEFAULT_DAYS);
if (!in_array($days, $allowedWindows, true)) {
    $days = COVETED_BENEFIT_PERFORMANCE_DEFAULT_DAYS;
}
$snapshot = coveted_benefit_performance_snapshot($admin, $days, 125);
$summary = (array)$snapshot['summary'];
$programs = (array)$snapshot['programs'];
$insights = (array)$snapshot['insights'];
$benchmarks = (array)$snapshot['trigger_benchmarks'];

$formatRate = static fn(float|int $value): string => number_format((float)$value, 1) . '%';
$formatDate = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }
    return coveted_utc_datetime($value)->setTimezone(coveted_timezone())->format('M j, Y');
};
$triggerLabel = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$bandLabel = static function (string $band): string {
    return match ($band) {
        'strong_claim' => 'Strong claim signal',
        'weak_claim' => 'Weak claim signal',
        'developing' => 'Developing',
        default => 'More data needed',
    };
};

coveted_page_start('Benefit Performance', '', true);
coveted_admin_ui_start($admin, 'benefit-performance', 'Benefit Performance');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">BENEFIT PERFORMANCE</span>
        <h1>Measure what happened, then improve the next program.</h1>
        <p>Program-level issuance, claim, verified return, later attendance and follow-on Benefit Program activity from Coveted's canonical records.</p>
    </div>
    <div class="cv-action-row">
        <a class="cv-button cv-button-soft" href="/admin/benefit-programs.php">Benefit Programs</a>
        <a class="cv-button cv-button-soft" href="/admin/benefit-economy.php">Benefit Economy</a>
        <a class="cv-button cv-button-soft" href="/admin/agent.php">Ask Admin Agent</a>
    </div>
</div>

<div class="cv-tag-row cv-admin-section-gap" aria-label="Performance window">
    <?php foreach ($allowedWindows as $window): ?>
        <a class="cv-pill <?= $window === $days ? 'is-active' : '' ?>" href="/admin/benefit-performance.php?days=<?= $window ?>"><?= $window ?> days</a>
    <?php endforeach; ?>
</div>

<section class="cv-stat-grid cv-admin-section-gap" aria-label="Benefit performance summary">
    <div class="cv-card cv-stat"><strong><?= (int)$summary['programs_with_activity'] ?></strong><span>Programs with activity</span></div>
    <div class="cv-card cv-stat"><strong><?= (int)$summary['issued_count'] ?></strong><span>Issued · <?= $days ?>d</span></div>
    <div class="cv-card cv-stat"><strong><?= (int)$summary['unique_members'] ?></strong><span>Unique recipients</span></div>
    <div class="cv-card cv-stat"><strong><?= coveted_e($formatRate((float)$summary['claim_rate'])) ?></strong><span>Claim rate</span></div>
    <div class="cv-card cv-stat"><strong><?= (int)$summary['return_members'] ?></strong><span>Verified return conversions</span></div>
    <div class="cv-card cv-stat"><strong><?= coveted_e($formatRate((float)$summary['later_attendance_rate'])) ?></strong><span>Later-event participation</span></div>
</section>

<section class="cv-card cv-copy-card cv-admin-section-gap">
    <span class="cv-kicker">ATTRIBUTION RULE</span>
    <h2>Observed behavior, not invented causation.</h2>
    <p><?= coveted_e((string)$snapshot['attribution_note']) ?></p>
    <p><?= coveted_e((string)$snapshot['action_policy']) ?></p>
</section>

<div class="cv-section-head cv-admin-section-gap">
    <div><span class="cv-eyebrow">LEARNING LOOP</span><h2>What Coveted should review next</h2></div>
    <span class="cv-pill"><?= count($insights) ?> signal<?= count($insights) === 1 ? '' : 's' ?></span>
</div>
<section class="cv-stack">
    <?php if (!$insights): ?>
        <div class="cv-card cv-empty">
            <h3>No performance recommendation has enough evidence yet.</h3>
            <p>Coveted will surface stronger learning signals after Benefit Programs accumulate matured issuances, claims and follow-on activity.</p>
        </div>
    <?php endif; ?>
    <?php foreach ($insights as $insight): ?>
        <article class="cv-card cv-admin-row">
            <div>
                <div class="cv-tag-row">
                    <span class="cv-kicker">P<?= (int)$insight['priority'] ?> · <?= coveted_e(strtoupper(str_replace('_', ' ', (string)$insight['kind']))) ?></span>
                    <span class="cv-pill">Analysis only</span>
                </div>
                <h3><?= coveted_e((string)$insight['title']) ?></h3>
                <p><?= coveted_e((string)$insight['detail']) ?></p>
                <p><strong>Evidence:</strong> <?= coveted_e((string)$insight['evidence']) ?></p>
            </div>
            <a class="cv-button cv-button-soft" href="/admin/agent.php">Ask Agent</a>
        </article>
    <?php endforeach; ?>
</section>

<div class="cv-section-head cv-admin-section-gap">
    <div><span class="cv-eyebrow">PROGRAM PERFORMANCE</span><h2>Benefit Programs in the learning window</h2></div>
    <span class="cv-pill"><?= count($programs) ?> shown</span>
</div>
<section class="cv-stack">
    <?php if (!$programs): ?>
        <div class="cv-card cv-empty"><h3>No Benefit Programs yet.</h3><p>Create a draft in the Benefit Program Builder to start the performance loop.</p></div>
    <?php endif; ?>
    <?php foreach ($programs as $program): ?>
        <?php
        $remaining = $program['pool_remaining'];
        $eventCopy = (int)$program['linked_event_count'] > 0
            ? ((string)($program['event_title'] ?? 'Event') . ((int)$program['linked_event_count'] > 1 ? ' +' . ((int)$program['linked_event_count'] - 1) . ' more' : ''))
            : 'No linked event';
        ?>
        <article class="cv-card cv-copy-card">
            <div class="cv-section-head">
                <div>
                    <div class="cv-tag-row">
                        <span class="cv-kicker"><?= coveted_e(strtoupper((string)$program['status'])) ?></span>
                        <span class="cv-pill"><?= coveted_e($triggerLabel((string)$program['trigger_key'])) ?></span>
                        <span class="cv-pill"><?= coveted_e($bandLabel((string)$program['learning_band'])) ?></span>
                    </div>
                    <h3><?= coveted_e((string)$program['title']) ?></h3>
                    <p><?= coveted_e((string)$program['owner_label']) ?> · <?= coveted_e((string)$program['reward_title']) ?> · <?= coveted_e($eventCopy) ?></p>
                </div>
                <a class="cv-button cv-button-soft" href="/admin/benefit-programs.php">Manage</a>
            </div>

            <section class="cv-stat-grid" aria-label="<?= coveted_e((string)$program['title']) ?> metrics">
                <div class="cv-card cv-stat"><strong><?= (int)$program['issued_count'] ?></strong><span>Issued</span></div>
                <div class="cv-card cv-stat"><strong><?= (int)$program['claimed_count'] ?></strong><span>Claimed</span></div>
                <div class="cv-card cv-stat"><strong><?= coveted_e($formatRate((float)$program['claim_rate'])) ?></strong><span>Claim rate</span></div>
                <div class="cv-card cv-stat"><strong><?= coveted_e($formatRate((float)$program['matured_claim_rate'])) ?></strong><span>Matured claim rate</span></div>
                <div class="cv-card cv-stat"><strong><?= (int)$program['return_members'] ?></strong><span>Return conversions</span></div>
                <div class="cv-card cv-stat"><strong><?= coveted_e($formatRate((float)$program['later_attendance_rate'])) ?></strong><span>Later attendance</span></div>
            </section>

            <p>
                <?= (int)$program['unique_members'] ?> unique recipient<?= (int)$program['unique_members'] === 1 ? '' : 's' ?> ·
                <?= (int)$program['viewed_count'] ?> viewed ·
                <?= (int)$program['expired_count'] ?> expired/no-use ·
                <?= (int)$program['later_benefit_members'] ?> later claimed another Benefit Program
                <?php if ($remaining !== null): ?> · <?= (int)$remaining ?> of <?= (int)$program['quantity_limit'] ?> pool remaining<?php endif; ?>
            </p>
            <p class="cv-muted">Program <?= coveted_e((string)$program['public_id']) ?> · <?= coveted_e($formatDate((string)$program['starts_at'])) ?> → <?= coveted_e($formatDate((string)$program['ends_at'])) ?></p>
        </article>
    <?php endforeach; ?>
</section>

<div class="cv-section-head cv-admin-section-gap">
    <div><span class="cv-eyebrow">TRIGGER BENCHMARKS</span><h2>Compare program structures</h2></div>
    <span class="cv-pill">7-day matured cohort</span>
</div>
<section class="cv-stack">
    <?php if (!$benchmarks): ?><div class="cv-card cv-empty"><h3>No trigger benchmarks yet.</h3></div><?php endif; ?>
    <?php foreach ($benchmarks as $benchmark): ?>
        <article class="cv-card cv-admin-row">
            <div>
                <h3><?= coveted_e($triggerLabel((string)$benchmark['trigger_key'])) ?></h3>
                <p><?= (int)$benchmark['measured_program_count'] ?> measured of <?= (int)$benchmark['program_count'] ?> program<?= (int)$benchmark['program_count'] === 1 ? '' : 's' ?> · <?= coveted_e($formatRate((float)$benchmark['weighted_claim_rate'])) ?> weighted matured claim rate · <?= coveted_e($formatRate((float)$benchmark['observed_return_rate'])) ?> observed return rate.</p>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<section class="cv-card cv-feature-card cv-copy-card cv-admin-section-gap">
    <span class="cv-kicker">LEARNING CYCLE</span>
    <h2>CRM demand → Event → Attendance → Benefit → Claim → Return → Relationship → Next recommendation.</h2>
    <p>Coveted uses canonical program, issuance, claim, attendance and return-reward records to improve future decisions without scoring individual members or inventing purchasing intent.</p>
</section>

<p class="cv-muted cv-admin-section-gap">Snapshot generated <?= coveted_e($formatDate((string)$snapshot['generated_at'])) ?>. <?= coveted_e((string)$snapshot['privacy']) ?></p>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
