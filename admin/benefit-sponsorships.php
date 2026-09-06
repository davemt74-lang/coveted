<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/benefit_sponsorship_conversion.php';
require_once dirname(__DIR__) . '/app/admin_ui.php';

$admin = coveted_require_system_admin();
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'submitted')));
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    coveted_require_csrf();
    try {
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        $proposalRef = trim((string)($_POST['proposal_ref'] ?? ''));
        if ($action === 'convert_to_draft') {
            $result = coveted_benefit_sponsorship_convert_proposal_to_draft($admin, $proposalRef);
            $message = 'Sponsorship proposal converted into Benefit Program draft ' . (string)$result['program_ref'] . '. It is not live.';
            $statusFilter = 'submitted';
        } elseif ($action === 'decline') {
            coveted_benefit_sponsorship_decline($admin, $proposalRef, (string)($_POST['review_note'] ?? ''));
            $message = 'Sponsorship proposal declined.';
            $statusFilter = 'submitted';
        } else {
            throw new InvalidArgumentException('Unsupported sponsorship review action.');
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Admin Benefit sponsorship review error: ' . $e->getMessage());
        $error = 'Unable to complete that sponsorship review right now.';
    }
}

$allowedFilters = ['submitted','converted','declined','cancelled','all'];
if (!in_array($statusFilter, $allowedFilters, true)) $statusFilter = 'submitted';
$proposals = coveted_benefit_sponsorship_admin_list($admin, $statusFilter === 'all' ? '' : $statusFilter, 250);
$context = coveted_benefit_sponsorship_agent_context();
$summary = (array)($context['summary'] ?? []);
$formatMoney = static fn(float|int|string|null $value): string => $value === null || $value === '' ? '—' : '$' . number_format((float)$value, 2);
$formatDate = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '') return '—';
    return coveted_utc_datetime($value)->setTimezone(coveted_timezone())->format('M j, Y · g:i A');
};

coveted_page_start('Benefit Sponsorships', '', true);
coveted_admin_ui_start($admin, 'benefit-sponsorships', 'Benefit Sponsorships');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">PARTNER-FUNDED VALUE</span>
        <h1>Review sponsorship proposals before they become programs.</h1>
        <p>Businesses can commit bounded rewards for approved Coveted relationships. Acceptance creates a canonical Benefit Program <strong>draft</strong>; launch remains a separate System Admin decision.</p>
    </div>
    <div class="cv-action-row">
        <a class="cv-button cv-button-soft" href="/admin/benefit-programs.php">Benefit Programs</a>
        <a class="cv-button cv-button-soft" href="/admin/benefit-performance.php">Benefit Performance</a>
    </div>
</div>

<?php if ($message !== ''): ?><div class="cv-alert"><?= coveted_e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>

<section class="cv-stat-grid" aria-label="Sponsorship summary">
    <div class="cv-card cv-stat"><strong><?= (int)($summary['submitted'] ?? 0) ?></strong><span>Awaiting review</span></div>
    <div class="cv-card cv-stat"><strong><?= (int)($summary['converted'] ?? 0) ?></strong><span>Accepted to drafts</span></div>
    <div class="cv-card cv-stat"><strong><?= (int)($summary['committed_quantity'] ?? 0) ?></strong><span>Committed rewards</span></div>
    <div class="cv-card cv-stat"><strong><?= coveted_e($formatMoney($summary['committed_face_value'] ?? 0)) ?></strong><span>Committed face value</span></div>
</section>

<nav class="cv-action-row cv-admin-section-gap" aria-label="Sponsorship status filters">
    <?php foreach (['submitted'=>'Awaiting review','converted'=>'Converted','declined'=>'Declined','cancelled'=>'Cancelled','all'=>'All'] as $value => $label): ?>
        <a class="cv-button <?= $statusFilter === $value ? '' : 'cv-button-soft' ?>" href="/admin/benefit-sponsorships.php?status=<?= coveted_e($value) ?>"><?= coveted_e($label) ?></a>
    <?php endforeach; ?>
</nav>

<div class="cv-section-head cv-admin-section-gap">
    <div><span class="cv-eyebrow">PROPOSALS</span><h2><?= coveted_e(ucwords(str_replace('_',' ',$statusFilter))) ?></h2></div>
    <span class="cv-pill"><?= count($proposals) ?> shown</span>
</div>

<section class="cv-stack">
    <?php if (!$proposals): ?><div class="cv-card cv-empty"><h3>No proposals in this view.</h3></div><?php endif; ?>
    <?php foreach ($proposals as $proposal): ?>
        <?php $committedValue = $proposal['value_amount'] !== null ? (int)$proposal['quantity_limit'] * (float)$proposal['value_amount'] : null; ?>
        <article class="cv-card cv-copy-card" id="proposal-<?= coveted_e((string)$proposal['public_id']) ?>">
            <div class="cv-section-head">
                <div>
                    <div class="cv-tag-row">
                        <span class="cv-kicker"><?= coveted_e(strtoupper((string)$proposal['status'])) ?></span>
                        <span class="cv-pill"><?= coveted_e(strtoupper(str_replace('_',' ',(string)$proposal['trigger_key']))) ?></span>
                    </div>
                    <h3><?= coveted_e((string)$proposal['program_title']) ?></h3>
                    <p><?= coveted_e((string)$proposal['business_name']) ?> → <?= coveted_e((string)$proposal['group_name']) ?> · <?= coveted_e((string)$proposal['location_name']) ?><?= !empty($proposal['event_title']) ? ' · ' . coveted_e((string)$proposal['event_title']) : '' ?></p>
                </div>
                <code><?= coveted_e((string)$proposal['public_id']) ?></code>
            </div>

            <div class="cv-stat-grid">
                <div class="cv-card cv-stat"><strong><?= (int)$proposal['quantity_limit'] ?></strong><span>Committed quantity</span></div>
                <div class="cv-card cv-stat"><strong><?= coveted_e($committedValue !== null ? $formatMoney($committedValue) : '—') ?></strong><span>Committed face value</span></div>
                <div class="cv-card cv-stat"><strong><?= coveted_e(ucwords(str_replace('_',' ',(string)$proposal['reward_type']))) ?></strong><span>Reward type</span></div>
                <div class="cv-card cv-stat"><strong><?= (int)$proposal['per_user_limit'] ?></strong><span>Per-member limit</span></div>
            </div>

            <p><strong><?= coveted_e((string)$proposal['reward_title']) ?></strong><?= !empty($proposal['value_text']) ? ' · ' . coveted_e((string)$proposal['value_text']) : '' ?></p>
            <?php if (!empty($proposal['description'])): ?><p><?= nl2br(coveted_e((string)$proposal['description'])) ?></p><?php endif; ?>
            <p class="cv-muted">Submitted <?= coveted_e($formatDate((string)$proposal['created_at'])) ?> · Claim: <?= coveted_e(ucwords(str_replace('_',' ',(string)$proposal['claim_mode']))) ?> · Window: <?= coveted_e($formatDate($proposal['starts_at'])) ?> → <?= coveted_e($formatDate($proposal['ends_at'])) ?></p>

            <?php if ((string)$proposal['status'] === 'submitted'): ?>
                <div class="cv-action-row">
                    <form method="post" action="/admin/benefit-sponsorships.php">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="convert_to_draft">
                        <input type="hidden" name="proposal_ref" value="<?= coveted_e((string)$proposal['public_id']) ?>">
                        <button class="cv-button" type="submit">Accept → create draft</button>
                    </form>
                    <form method="post" action="/admin/benefit-sponsorships.php" class="cv-action-row">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="decline">
                        <input type="hidden" name="proposal_ref" value="<?= coveted_e((string)$proposal['public_id']) ?>">
                        <input name="review_note" maxlength="1000" placeholder="Optional review note">
                        <button class="cv-button cv-button-soft" type="submit">Decline</button>
                    </form>
                </div>
            <?php elseif ((string)$proposal['status'] === 'converted'): ?>
                <div class="cv-alert">Converted to Benefit Program <code><?= coveted_e((string)$proposal['benefit_program_ref']) ?></code>. Current program status: <?= coveted_e(ucwords(str_replace('_',' ',(string)($proposal['program_status'] ?? 'draft')))) ?>. Acceptance did not launch it.</div>
            <?php elseif (!empty($proposal['review_note'])): ?>
                <div class="cv-alert">Review note: <?= coveted_e((string)$proposal['review_note']) ?></div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>

<section class="cv-card cv-feature-card cv-copy-card cv-admin-section-gap">
    <span class="cv-kicker">AUTHORITY</span>
    <h2>Partner proposes → Coveted reviews → draft → explicit launch.</h2>
    <p>A business proposal never creates, configures or publishes an event and never grants itself audience access. The Admin Agent receives the same proposal data as stored, untrusted application data and cannot launch a sponsorship merely because it was submitted.</p>
</section>

<p class="cv-muted cv-admin-section-gap">No member-level PII appears in sponsorship review or ROI reporting.</p>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
