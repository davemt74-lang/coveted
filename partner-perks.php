<?php
declare(strict_types=1);

require_once __DIR__ . '/app/partner_perks.php';

$user = coveted_require_user();
$error = '';
$notice = trim((string)($_SESSION['partner_perk_notice'] ?? ''));
unset($_SESSION['partner_perk_notice']);

$businessRef = trim((string)($_GET['business'] ?? $_POST['business'] ?? ''));
$groupRef = trim((string)($_GET['group'] ?? $_POST['group'] ?? ''));
$locationRef = trim((string)($_GET['location'] ?? $_POST['location'] ?? ''));

$business = null;
$relationship = null;
$relationshipState = null;
try {
    $business = coveted_business_resolve_context($user, $businessRef);
    if (!$business) {
        throw new InvalidArgumentException('Business Admin access is required.');
    }
    $relationship = coveted_venue_relationship_resolve(
        $user,
        (int)$business['id'],
        $groupRef,
        $locationRef
    );
    $businessRef = (string)$business['public_id'];
    $groupRef = (string)$relationship['group_public_id'];
    $locationRef = (string)$relationship['location_public_id'];
    $relationshipState = coveted_partner_perk_relationship_state(
        (int)$business['id'],
        (int)$relationship['group_id'],
        (int)$relationship['location_id']
    );
} catch (InvalidArgumentException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    error_log('Partner Perks relationship load failed: ' . $e->getMessage());
    $error = 'Unable to load that Partner relationship right now.';
}

$returnPath = $business && $relationship
    ? '/partner-perks.php?business=' . rawurlencode($businessRef)
        . '&group=' . rawurlencode($groupRef)
        . '&location=' . rawurlencode($locationRef)
    : '/venue-relationships.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $business && $relationship) {
    coveted_require_csrf();
    try {
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        if ($action === 'create') {
            $created = coveted_partner_perk_create(
                $user,
                (int)$business['id'],
                $groupRef,
                $locationRef,
                $_POST
            );
            $_SESSION['partner_perk_notice'] = 'Partner Perk created.';
        } elseif ($action === 'status') {
            coveted_partner_perk_set_status(
                $user,
                trim((string)($_POST['perk_ref'] ?? '')),
                trim((string)($_POST['status'] ?? ''))
            );
            $_SESSION['partner_perk_notice'] = 'Partner Perk status updated.';
        } elseif ($action === 'issue_today') {
            $summary = coveted_partner_perk_issue_today(
                $user,
                trim((string)($_POST['perk_ref'] ?? ''))
            );
            $issued = (int)$summary['issued'];
            $skips = (int)$summary['limit_skips'];
            $failures = (int)$summary['failures'];
            $_SESSION['partner_perk_notice'] = $issued . ' member perk' . ($issued === 1 ? '' : 's') . ' issued today.'
                . ($skips > 0 ? ' ' . $skips . ' campaign-limit/state skip' . ($skips === 1 ? '' : 's') . '.' : '')
                . ($failures > 0 ? ' ' . $failures . ' item' . ($failures === 1 ? '' : 's') . ' need server-log review.' : '');
        } else {
            throw new InvalidArgumentException('Unsupported Partner Perk action.');
        }
        coveted_redirect($returnPath);
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Partner Perk action failed: ' . $e->getMessage());
        $error = str_contains($e->getMessage(), 'migration')
            ? $e->getMessage()
            : 'Unable to complete that Partner Perk action right now.';
    }
}

$schemaReady = coveted_partner_perks_schema_available();
$perks = [];
$campaigns = [];
if ($business && $relationship && $schemaReady) {
    try {
        $perks = coveted_partner_perks_for_relationship(
            $user,
            (int)$business['id'],
            $groupRef,
            $locationRef
        );
        $campaigns = coveted_partner_perk_campaign_candidates(
            $user,
            (int)$business['id'],
            (int)$relationship['location_id']
        );
    } catch (Throwable $e) {
        error_log('Partner Perks workspace data failed: ' . $e->getMessage());
        $error = $error !== '' ? $error : 'Unable to load Partner Perk data right now.';
    }
}

$typeLabels = coveted_partner_perk_types();
$modeLabels = coveted_partner_perk_distribution_modes();
$activeCount = count(array_filter($perks, static fn(array $perk): bool => (string)$perk['status'] === 'active'));
$issuedCount = array_sum(array_map(static fn(array $perk): int => (int)$perk['issued_count'], $perks));
$claimedCount = array_sum(array_map(static fn(array $perk): int => (int)$perk['claimed_count'], $perks));
$claimRate = $issuedCount > 0 ? round(($claimedCount / $issuedCount) * 100, 1) : 0.0;

$formatDate = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '') return 'Open-ended';
    try {
        return coveted_utc_datetime($value)->setTimezone(coveted_timezone())->format('M j, Y · g:i A');
    } catch (Throwable) {
        return $value;
    }
};

coveted_page_start('Partner Perks');
?>
<section class="cv-page-heading">
    <span class="cv-eyebrow">PARTNER OFFERS / PERKS</span>
    <h1><?= $relationship ? coveted_e((string)$relationship['group_name']) . ' × ' . coveted_e((string)$relationship['location_name']) : 'Partner Perks' ?></h1>
    <p>Give an established group × venue relationship ongoing value between Daily Events using the existing Business reward, campaign, wallet and claim infrastructure.</p>
</section>

<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>

<?php if (!$business || !$relationship): ?>
    <div class="cv-card cv-empty">
        <h2>Choose a venue relationship first.</h2>
        <p>Partner Perks are always scoped to one existing Coveted group × business location relationship.</p>
        <a class="cv-button cv-button-soft" href="/venue-relationships.php">Venue Relationships</a>
    </div>
    <?php coveted_page_end(); exit; ?>
<?php endif; ?>

<div class="cv-section-head">
    <div>
        <span class="cv-eyebrow">RELATIONSHIP</span>
        <h2><?= coveted_e((string)$business['name']) ?> · <?= coveted_e((string)$relationship['location_name']) ?></h2>
    </div>
    <div class="cv-member-actions">
        <a class="cv-button cv-button-soft" href="/venue-relationships.php?business=<?= coveted_e(rawurlencode($businessRef)) ?>&amp;group=<?= coveted_e(rawurlencode($groupRef)) ?>&amp;location=<?= coveted_e(rawurlencode($locationRef)) ?>">Relationship</a>
        <a class="cv-button cv-button-soft" href="/business.php?business=<?= coveted_e(rawurlencode($businessRef)) ?>&amp;tab=rewards">Rewards</a>
        <a class="cv-button cv-button-soft" href="/business.php?business=<?= coveted_e(rawurlencode($businessRef)) ?>&amp;tab=campaigns">Campaigns</a>
    </div>
</div>

<?php if (!$schemaReady): ?>
    <div class="cv-card cv-empty">
        <h2>Partner Perks migration required.</h2>
        <p>Import <code>database/migrations/20260906_partner_perks.sql</code> before deploying this feature. The rest of Coveted remains available while the table is absent.</p>
    </div>
    <?php coveted_page_end(); exit; ?>
<?php endif; ?>

<section class="cv-stat-grid" aria-label="Partner Perk summary">
    <div class="cv-card cv-stat"><strong><?= $activeCount ?></strong><span>Active perks</span></div>
    <div class="cv-card cv-stat"><strong><?= $issuedCount ?></strong><span>Issued</span></div>
    <div class="cv-card cv-stat"><strong><?= $claimedCount ?></strong><span>Claims</span></div>
    <div class="cv-card cv-stat"><strong><?= number_format($claimRate, 1) ?>%</strong><span>Claim rate</span></div>
</section>

<article class="cv-card cv-feature-card cv-copy-card">
    <span class="cv-kicker">STANDING RELATIONSHIP VALUE</span>
    <h2>Event value can continue after the gathering ends.</h2>
    <p>Partner Perks can represent a member discount, recurring perk, preferred access, surprise reward or return-visit offer. The perk itself never creates an event and never bypasses campaign quantity, member limits, reward status or location-code verification.</p>
    <div class="cv-tag-row">
        <span class="cv-pill"><?= !empty($relationshipState['benefits_enabled']) ? 'Partner benefits enabled' : 'Partner benefits disabled' ?></span>
        <span class="cv-pill">Exact location scope</span>
        <span class="cv-pill">Existing Perk Wallet</span>
        <span class="cv-pill">Canonical reward claims</span>
    </div>
</article>

<div class="cv-section-head">
    <div>
        <span class="cv-eyebrow">CURRENT PERKS</span>
        <h2>Relationship benefit portfolio</h2>
    </div>
    <span class="cv-status"><?= count($perks) ?> configured</span>
</div>

<?php if (!$perks): ?>
    <div class="cv-card cv-empty">
        <h2>No Partner Perks yet.</h2>
        <p>Create a location-scoped manual Business campaign first, then connect it below as the standing relationship offer.</p>
    </div>
<?php else: ?>
    <section class="cv-list" aria-label="Partner Perks">
        <?php foreach ($perks as $perk): ?>
            <article class="cv-card cv-event-row">
                <div class="cv-event-copy">
                    <div class="cv-tag-row">
                        <span class="cv-status"><?= coveted_e(ucfirst((string)$perk['status'])) ?></span>
                        <span class="cv-pill"><?= coveted_e($typeLabels[(string)$perk['perk_type']] ?? 'Partner perk') ?></span>
                        <span class="cv-pill"><?= coveted_e($modeLabels[(string)$perk['distribution_mode']] ?? (string)$perk['distribution_mode']) ?></span>
                    </div>
                    <h2><?= coveted_e((string)$perk['title']) ?></h2>
                    <?php if (!empty($perk['description'])): ?><p><?= nl2br(coveted_e((string)$perk['description'])) ?></p><?php endif; ?>
                    <div class="cv-meta-row">
                        <span>Reward · <?= coveted_e((string)$perk['reward_title']) ?></span>
                        <span>Campaign · <?= coveted_e((string)$perk['campaign_title']) ?></span>
                        <span><?= (int)$perk['issued_count'] ?> issued</span>
                        <span><?= (int)$perk['claimed_count'] ?> claimed</span>
                    </div>
                    <div class="cv-meta-row">
                        <span>Starts · <?= coveted_e($formatDate($perk['starts_at'])) ?></span>
                        <span>Ends · <?= coveted_e($formatDate($perk['ends_at'])) ?></span>
                        <span>Campaign · <?= coveted_e((string)$perk['campaign_status']) ?></span>
                        <span>Reward · <?= coveted_e((string)$perk['reward_status']) ?></span>
                    </div>
                    <div class="cv-action-row">
                        <?php if ((string)$perk['status'] === 'active' && (string)$perk['distribution_mode'] === 'manual'): ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                <input type="hidden" name="business" value="<?= coveted_e($businessRef) ?>">
                                <input type="hidden" name="group" value="<?= coveted_e($groupRef) ?>">
                                <input type="hidden" name="location" value="<?= coveted_e($locationRef) ?>">
                                <input type="hidden" name="action" value="issue_today">
                                <input type="hidden" name="perk_ref" value="<?= coveted_e((string)$perk['public_id']) ?>">
                                <button class="cv-button cv-button-primary" type="submit">Issue Today</button>
                            </form>
                        <?php endif; ?>

                        <?php foreach (match ((string)$perk['status']) {
                            'draft' => ['active' => 'Activate', 'archived' => 'Archive'],
                            'active' => ['paused' => 'Pause'],
                            'paused' => ['active' => 'Reactivate', 'archived' => 'Archive'],
                            default => [],
                        } as $nextStatus => $label): ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                <input type="hidden" name="business" value="<?= coveted_e($businessRef) ?>">
                                <input type="hidden" name="group" value="<?= coveted_e($groupRef) ?>">
                                <input type="hidden" name="location" value="<?= coveted_e($locationRef) ?>">
                                <input type="hidden" name="action" value="status">
                                <input type="hidden" name="perk_ref" value="<?= coveted_e((string)$perk['public_id']) ?>">
                                <input type="hidden" name="status" value="<?= coveted_e($nextStatus) ?>">
                                <button class="cv-button cv-button-soft" type="submit"><?= coveted_e($label) ?></button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<div class="cv-section-head">
    <div>
        <span class="cv-eyebrow">NEW PARTNER PERK</span>
        <h2>Connect relationship value</h2>
    </div>
</div>

<?php if (empty($relationshipState['benefits_enabled'])): ?>
    <div class="cv-alert cv-alert-error">Partner benefits are currently disabled for this relationship. You may prepare a draft, but activation is blocked until benefits are enabled in Venue Relationships.</div>
<?php endif; ?>

<?php if (!$campaigns): ?>
    <div class="cv-card cv-empty">
        <h2>Create a location-scoped manual campaign first.</h2>
        <p>Partner Perks deliberately reuse Business rewards and campaigns. The campaign must belong to <?= coveted_e((string)$business['name']) ?>, use <?= coveted_e((string)$relationship['location_name']) ?> as its location, and use the Manual trigger.</p>
        <a class="cv-button cv-button-primary" href="/business.php?business=<?= coveted_e(rawurlencode($businessRef)) ?>&amp;tab=campaigns">Create Campaign</a>
    </div>
<?php else: ?>
    <form class="cv-card cv-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
        <input type="hidden" name="business" value="<?= coveted_e($businessRef) ?>">
        <input type="hidden" name="group" value="<?= coveted_e($groupRef) ?>">
        <input type="hidden" name="location" value="<?= coveted_e($locationRef) ?>">
        <input type="hidden" name="action" value="create">

        <label>Perk title
            <input type="text" name="title" maxlength="190" required placeholder="Coveted members get 15% off lunch">
        </label>
        <label>Description / terms
            <textarea name="description" rows="4" maxlength="4000" placeholder="What members receive, when it applies, and any partner terms."></textarea>
        </label>
        <label>Perk type
            <select name="perk_type" required>
                <?php foreach ($typeLabels as $value => $label): ?><option value="<?= coveted_e($value) ?>"><?= coveted_e($label) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Distribution
            <select name="distribution_mode" required>
                <?php foreach ($modeLabels as $value => $label): ?><option value="<?= coveted_e($value) ?>"><?= coveted_e($label) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Business campaign
            <select name="campaign_ref" required>
                <option value="">Choose campaign</option>
                <?php foreach ($campaigns as $campaign): ?>
                    <option value="<?= coveted_e((string)$campaign['public_id']) ?>"><?= coveted_e((string)$campaign['title']) ?> · <?= coveted_e((string)$campaign['reward_title']) ?> · <?= coveted_e(ucfirst((string)$campaign['status'])) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="cv-two-column">
            <label>Starts (UTC, optional)<input type="datetime-local" name="starts_at"></label>
            <label>Ends (UTC, optional)<input type="datetime-local" name="ends_at"></label>
        </div>
        <label>Status
            <select name="status">
                <option value="draft">Draft</option>
                <option value="active">Active</option>
            </select>
        </label>
        <p class="cv-help">Once and monthly perks are issued by the existing lifecycle worker. Manual perks are issued only when a Business Admin or System Admin uses “Issue Today”; repeating the action on the same UTC day is idempotent.</p>
        <button class="cv-button cv-button-primary" type="submit">Create Partner Perk</button>
    </form>
<?php endif; ?>

<?php coveted_page_end(); ?>
