<?php
declare(strict_types=1);

require_once __DIR__ . '/app/loyalty.php';

$user = coveted_require_user();
$snapshot = null;
$error = '';
try {
    $snapshot = coveted_loyalty_member_snapshot((int)$user['id']);
} catch (Throwable $e) {
    error_log('Member loyalty workspace unavailable: ' . $e->getMessage());
    $error = 'Your loyalty history is not available yet. Coveted Admin may still need to apply the Loyalty database upgrade.';
}

$milestoneLabels = [
    'first_event' => 'First event',
    'event_3' => '3 events',
    'event_5' => '5 events',
    'event_10' => '10 events',
    'event_25' => '25 events',
    'first_return' => 'First partner return',
    'first_host' => 'First hosted event',
    'membership_year_1' => '1 year together',
];
$sourceLabels = [
    'verified_attendance' => 'Verified attendance',
    'host_contribution' => 'Host contribution',
    'verified_return_visit' => 'Partner return',
];
$formatDate = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '') return '—';
    return coveted_utc_datetime($value)->setTimezone(coveted_timezone())->format('M j, Y');
};

coveted_page_start('Loyalty', 'Loyalty');
?>
<section class="cv-page-heading">
    <span class="cv-eyebrow">PRIVATE MEMBERSHIP HISTORY</span>
    <h1>Your Coveted relationship, over time.</h1>
    <p>Points recognize verified participation across Coveted while group status reflects the relationship you have built locally. Your point balance is private.</p>
</section>

<?php if ($error !== ''): ?>
    <div class="cv-alert cv-alert-error" role="alert"><?= coveted_e($error) ?></div>
<?php elseif ($snapshot !== null): ?>
    <section class="cv-stat-grid" aria-label="Private loyalty overview">
        <div class="cv-card cv-stat"><strong><?= number_format((int)$snapshot['lifetime_points']) ?></strong><span>Lifetime Coveted Points</span></div>
        <div class="cv-card cv-stat"><strong><?= (int)$snapshot['groups_with_points'] ?></strong><span>Groups with verified activity</span></div>
        <div class="cv-card cv-stat"><strong><?= count((array)$snapshot['milestones']) ?></strong><span>Milestones reached</span></div>
        <div class="cv-card cv-stat"><strong><?= (int)$snapshot['point_events'] ?></strong><span>Verified point events</span></div>
    </section>

    <section class="cv-card cv-feature-card cv-copy-card cv-admin-section-gap">
        <span class="cv-kicker">PRIVATE BY DESIGN</span>
        <h2>Your points travel with you. Your score does not become a leaderboard.</h2>
        <p><?= coveted_e((string)$snapshot['travel_note']) ?></p>
        <p class="cv-muted"><?= coveted_e((string)$snapshot['privacy']) ?></p>
    </section>

    <div class="cv-section-head cv-admin-section-gap">
        <div><span class="cv-eyebrow">GROUP STATUS</span><h2>Relationship status</h2></div>
        <a class="cv-button cv-button-soft" href="/benefits.php">Open Benefits</a>
    </div>

    <section class="cv-stack">
        <?php if (!(array)$snapshot['groups']): ?>
            <div class="cv-card cv-empty"><h3>No active group relationship yet.</h3><p>Your status begins when you join a Coveted group and start showing up.</p></div>
        <?php endif; ?>
        <?php foreach ((array)$snapshot['groups'] as $group): ?>
            <?php
            $status = (array)$group['status'];
            $activity = (string)($status['activity_state'] ?? 'current');
            $next = is_array($status['next'] ?? null) ? (array)$status['next'] : null;
            ?>
            <article class="cv-card cv-copy-card">
                <div class="cv-section-head">
                    <div>
                        <span class="cv-kicker"><?= coveted_e(strtoupper((string)$group['group_name'])) ?></span>
                        <h3><?= coveted_e((string)$status['label']) ?><?= $activity === 'reconnect' ? ' · Reconnect' : '' ?></h3>
                        <p><?= !empty($group['city']) ? coveted_e((string)$group['city']) . ' · ' : '' ?><?= number_format((int)$group['group_points']) ?> group points</p>
                    </div>
                    <span class="cv-pill"><?= (int)$group['attendance_count'] ?> verified event<?= (int)$group['attendance_count'] === 1 ? '' : 's' ?></span>
                </div>

                <div class="cv-stat-grid">
                    <div class="cv-card cv-stat"><strong><?= number_format((int)$group['group_points']) ?></strong><span>Group points</span></div>
                    <div class="cv-card cv-stat"><strong><?= (int)$group['attendance_count'] ?></strong><span>Events attended</span></div>
                    <div class="cv-card cv-stat"><strong><?= (int)$group['host_count'] ?></strong><span>Host contributions</span></div>
                    <div class="cv-card cv-stat"><strong><?= (int)$group['return_count'] ?></strong><span>Verified returns</span></div>
                </div>

                <?php if ($activity === 'reconnect'): ?>
                    <div class="cv-alert">Your earned status is preserved. It has been <?= (int)($status['days_since_attendance'] ?? 0) ?> days since your last verified event, so Coveted marks this relationship for Reconnect rather than reducing your tier.</div>
                <?php endif; ?>

                <?php if ($next !== null): ?>
                    <p><strong>Next:</strong> <?= coveted_e((string)$next['label']) ?> · <?= (int)$status['next_progress_percent'] ?>% of the combined participation requirements reached.</p>
                    <p class="cv-muted">
                        <?php if ((int)$status['events_needed'] > 0): ?><?= (int)$status['events_needed'] ?> more verified event<?= (int)$status['events_needed'] === 1 ? '' : 's' ?>. <?php endif; ?>
                        <?php if ((int)$status['points_needed'] > 0): ?><?= number_format((int)$status['points_needed']) ?> more group points. <?php endif; ?>
                        <?php if ((int)$status['hosts_needed'] > 0): ?>A lead/cohost contribution is also part of Community Contributor status.<?php endif; ?>
                    </p>
                <?php else: ?>
                    <p><strong>Highest current relationship tier reached.</strong> Future status layers can build on this history without resetting it.</p>
                <?php endif; ?>
                <p class="cv-muted">Last verified event: <?= coveted_e($formatDate($group['last_attended_at'] ?? null)) ?> · Joined: <?= coveted_e($formatDate($group['joined_at'] ?? null)) ?></p>
            </article>
        <?php endforeach; ?>
    </section>

    <div class="cv-section-head cv-admin-section-gap">
        <div><span class="cv-eyebrow">MILESTONES</span><h2>Moments that stay with your membership</h2></div>
    </div>
    <section class="cv-stack">
        <?php if (!(array)$snapshot['milestones']): ?><div class="cv-card cv-empty"><p>Your first milestone appears after verified participation.</p></div><?php endif; ?>
        <?php foreach ((array)$snapshot['milestones'] as $milestone): ?>
            <article class="cv-card cv-copy-card">
                <div class="cv-section-head">
                    <div><strong><?= coveted_e($milestoneLabels[(string)$milestone['milestone_key']] ?? ucwords(str_replace('_', ' ', (string)$milestone['milestone_key']))) ?></strong><p><?= coveted_e((string)$milestone['group_name']) ?></p></div>
                    <span class="cv-pill"><?= coveted_e($formatDate((string)$milestone['achieved_at'])) ?></span>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <div class="cv-section-head cv-admin-section-gap">
        <div><span class="cv-eyebrow">POINT HISTORY</span><h2>What earned your points</h2></div>
        <span class="cv-pill">Private</span>
    </div>
    <section class="cv-stack">
        <?php if (!(array)$snapshot['recent_points']): ?><div class="cv-card cv-empty"><p>No verified point entries yet.</p></div><?php endif; ?>
        <?php foreach ((array)$snapshot['recent_points'] as $entry): ?>
            <article class="cv-card cv-copy-card">
                <div class="cv-section-head">
                    <div>
                        <strong><?= coveted_e($sourceLabels[(string)$entry['source_type']] ?? (string)$entry['description']) ?></strong>
                        <p><?= coveted_e((string)($entry['group_name'] ?? 'Coveted')) ?><?= !empty($entry['event_title']) ? ' · ' . coveted_e((string)$entry['event_title']) : '' ?></p>
                    </div>
                    <strong>+<?= number_format((int)$entry['global_points']) ?></strong>
                </div>
                <p class="cv-muted"><?= coveted_e($formatDate((string)$entry['occurred_at'])) ?></p>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
<?php coveted_page_end(); ?>
