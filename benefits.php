<?php
declare(strict_types=1);

require_once __DIR__ . '/app/rewards.php';
require_once __DIR__ . '/app/return_engine.php';

$user = coveted_require_user();
$userId = (int)$user['id'];
$message = '';
$error = '';

$box = strtolower(trim((string)($_GET['box'] ?? $_POST['box'] ?? 'inbox')));
if (!in_array($box, ['inbox', 'claims'], true)) {
    $box = 'inbox';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        $action = (string)($_POST['action'] ?? '');
        $issuanceRef = trim((string)($_POST['issuance_id'] ?? ''));

        if ($action !== 'claim') {
            throw new InvalidArgumentException('Unsupported benefit action.');
        }

        $claim = coveted_reward_claim_with_code(
            $user,
            $issuanceRef,
            (int)($_POST['location_id'] ?? 0),
            (string)($_POST['claim_code'] ?? '')
        );

        $returnSummary = null;
        try {
            $returnSummary = coveted_return_process_claim((string)$claim['public_id']);
        } catch (Throwable $returnError) {
            // The verified claim is already canonical truth. A return-program failure
            // must never turn a successful claim into a member-facing claim error.
            error_log('Coveted return trigger error after claim: ' . $returnError->getMessage());
        }

        $unlocked = (int)($returnSummary['issued_count'] ?? 0);
        $message = $unlocked > 0
            ? 'Reward claimed. Your return visit also unlocked ' . $unlocked . ' new benefit' . ($unlocked === 1 ? '.' : 's.')
            : 'Reward claimed. It is now in your Claim Box.';
        $box = 'claims';
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted benefit claim error: ' . $e->getMessage());
        $error = 'Unable to claim that reward right now.';
    }
}

$filters = [
    'all' => ['label' => 'All', 'types' => []],
    'gifts' => ['label' => 'Gifts', 'types' => ['credit', 'free_item', 'discount', 'perk', 'experience']],
    'access' => ['label' => 'Access', 'types' => ['access']],
    'music' => ['label' => 'Music', 'types' => ['audio', 'media_pack']],
    'video' => ['label' => 'Video', 'types' => ['video', 'media_pack']],
    'services' => ['label' => 'Services', 'types' => ['service']],
];

$activeFilter = strtolower(trim((string)($_GET['type'] ?? 'all')));
if (!isset($filters[$activeFilter])) {
    $activeFilter = 'all';
}

$inboxRewards = coveted_reward_list_for_user($userId, [], 'inbox');
$claimRewards = coveted_reward_list_for_user($userId, [], 'claimed');
$boxRewards = $box === 'claims' ? $claimRewards : $inboxRewards;
$activeTypes = $filters[$activeFilter]['types'];
$rewards = $activeTypes
    ? coveted_reward_list_for_user(
        $userId,
        $activeTypes,
        $box === 'claims' ? 'claimed' : 'inbox'
    )
    : $boxRewards;

$mediaSourceRows = array_merge($inboxRewards, $rewards);
$mediaTemplateIds = array_map(
    static fn(array $reward): int => (int)$reward['reward_template_id'],
    $mediaSourceRows
);
$mediaByTemplate = coveted_reward_media_for_templates($mediaTemplateIds);
$eligibleLocationsByReward = coveted_reward_eligible_locations_for_rows($inboxRewards);

$availableCount = count($inboxRewards);
$claimableCount = count(array_filter(
    $inboxRewards,
    static fn(array $reward): bool => !empty($eligibleLocationsByReward[(string)$reward['public_id']])
));
$mediaCount = count(array_filter(
    $inboxRewards,
    static fn(array $reward): bool => !empty($mediaByTemplate[(int)$reward['reward_template_id']])
));
$claimHistoryCount = count($claimRewards);
$displayTimezone = coveted_timezone();
$formatMemberTime = static function (?string $value) use ($displayTimezone): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    return coveted_utc_datetime($value)->setTimezone($displayTimezone)->format('M j, Y · g:i A');
};
$formatMemberDate = static function (?string $value) use ($displayTimezone): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    return coveted_utc_datetime($value)->setTimezone($displayTimezone)->format('M j, Y');
};

coveted_page_start('Benefits', 'Benefits');
?>
<section class="cv-page-heading">
    <span class="cv-eyebrow">BENEFITS</span>
    <h1>Your member wallet.</h1>
    <p>Gifts, access, services and Coveted Sessions stay together until you use them—and claims remain part of your history afterward.</p>
</section>

<?php if ($message !== ''): ?>
    <div class="cv-alert"><?= coveted_e($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div>
<?php endif; ?>

<section class="cv-stat-grid cv-home-stats" aria-label="Benefit summary">
    <a class="cv-card cv-stat" href="/benefits.php?box=inbox">
        <strong><?= $availableCount ?></strong>
        <span>Available</span>
    </a>
    <a class="cv-card cv-stat" href="/benefits.php?box=inbox">
        <strong><?= $claimableCount ?></strong>
        <span>Claimable now</span>
    </a>
    <div class="cv-card cv-stat">
        <strong><?= $mediaCount ?></strong>
        <span>With media</span>
    </div>
    <a class="cv-card cv-stat" href="/benefits.php?box=claims">
        <strong><?= $claimHistoryCount ?></strong>
        <span>Claim history</span>
    </a>
</section>

<div class="cv-section-head">
    <div>
        <span class="cv-eyebrow"><?= $box === 'claims' ? 'CLAIM BOX' : 'INBOX' ?></span>
        <h2><?= $box === 'claims' ? 'Your permanent claim history' : 'Ready when you are' ?></h2>
    </div>
    <span class="cv-status"><?= count($rewards) ?> shown</span>
</div>

<nav class="cv-tab-row" aria-label="Benefit boxes">
    <a class="cv-tab <?= $box === 'inbox' ? 'is-active' : '' ?>" href="/benefits.php?box=inbox">Inbox · <?= $availableCount ?></a>
    <a class="cv-tab <?= $box === 'claims' ? 'is-active' : '' ?>" href="/benefits.php?box=claims">Claim Box · <?= $claimHistoryCount ?></a>
</nav>

<nav class="cv-filter-row" aria-label="Benefit filters">
    <?php foreach ($filters as $key => $filter): ?>
        <a
            class="cv-pill <?= $activeFilter === $key ? 'is-selected' : '' ?>"
            href="/benefits.php?box=<?= coveted_e($box) ?>&amp;type=<?= coveted_e($key) ?>"
        ><?= coveted_e($filter['label']) ?></a>
    <?php endforeach; ?>
</nav>

<section class="cv-benefit-grid">
    <?php if (!$rewards): ?>
        <div class="cv-card cv-empty">
            <h2><?= $box === 'claims' ? 'Nothing here yet.' : 'Your Inbox is clear.' ?></h2>
            <p><?= $box === 'claims'
                ? 'Claims and refunded claim records will stay here as part of your Coveted history.'
                : 'When a venue, artist, group or Coveted campaign leaves something for you, it will appear here.' ?></p>
        </div>
    <?php endif; ?>

    <?php foreach ($rewards as $reward): ?>
        <?php
        $templateId = (int)$reward['reward_template_id'];
        $media = $mediaByTemplate[$templateId] ?? [];
        $cover = coveted_safe_url($reward['cover_url'] ?? null, false);
        $expired = !empty($reward['expires_at']) && strtotime((string)$reward['expires_at']) <= time();
        $issuanceStatus = $expired ? 'expired' : (string)$reward['status'];
        $claimStatus = $box === 'claims' ? (string)($reward['claim_status'] ?? 'claimed') : null;
        $displayStatus = $box === 'claims'
            ? $claimStatus
            : ($expired ? 'expired' : 'available');
        $eligibleLocations = $eligibleLocationsByReward[(string)$reward['public_id']] ?? [];
        $description = trim((string)($reward['description'] ?? ''));
        ?>
        <article class="cv-card cv-benefit-card">
            <?php if ($cover !== null): ?>
                <img class="cv-benefit-cover" src="<?= coveted_e($cover) ?>" alt="">
            <?php endif; ?>

            <div class="cv-benefit-body">
                <div class="cv-tag-row">
                    <span class="cv-kicker"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$reward['reward_type']))) ?></span>
                    <span class="cv-status"><?= coveted_e(ucfirst(str_replace('_', ' ', (string)$displayStatus))) ?></span>
                </div>

                <h2><?= coveted_e($reward['title']) ?></h2>

                <?php if ($description !== ''): ?>
                    <p><?= coveted_e(mb_strimwidth($description, 0, 320, '…')) ?></p>
                <?php endif; ?>

                <?php if ($reward['value_text']): ?>
                    <p><strong><?= coveted_e($reward['value_text']) ?></strong></p>
                <?php elseif ($reward['value_amount'] !== null): ?>
                    <p><strong>$<?= coveted_e(number_format((float)$reward['value_amount'], 2)) ?></strong></p>
                <?php endif; ?>

                <div class="cv-meta-row">
                    <?php if ($reward['event_title']): ?>
                        <span>From <?= coveted_e($reward['event_title']) ?></span>
                    <?php elseif ($reward['location_name']): ?>
                        <span>From <?= coveted_e($reward['location_name']) ?></span>
                    <?php endif; ?>
                    <?php if ($reward['artist_name']): ?>
                        <span><?= coveted_e($reward['artist_name']) ?></span>
                    <?php endif; ?>
                    <?php if ($box === 'inbox' && !empty($reward['expires_at'])): ?>
                        <span>Expires <?= coveted_e($formatMemberDate((string)$reward['expires_at'])) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($box === 'claims' && $reward['claim_public_id']): ?>
                    <div class="cv-claim-receipt <?= $claimStatus === 'refunded' ? 'is-refunded' : '' ?>">
                        <?php if ($claimStatus === 'refunded'): ?>
                            <strong>Refunded · <?= coveted_e($reward['location_name'] ?: 'partner location') ?></strong>
                            <span>Originally claimed <?= coveted_e($formatMemberTime((string)$reward['claim_recorded_at'])) ?></span>
                            <?php if (!empty($reward['claim_refunded_at'])): ?>
                                <span>Refunded <?= coveted_e($formatMemberTime((string)$reward['claim_refunded_at'])) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($reward['claim_refund_reason'])): ?>
                                <span><?= coveted_e((string)$reward['claim_refund_reason']) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <strong>Claimed at <?= coveted_e($reward['location_name'] ?: 'partner location') ?></strong>
                            <span><?= coveted_e($formatMemberTime((string)$reward['claim_recorded_at'])) ?></span>
                        <?php endif; ?>
                        <span>Verified by <?= coveted_e($reward['claim_code_label'] ?: 'claim code') ?></span>
                    </div>
                <?php endif; ?>

                <?php if (
                    $media
                    && !$expired
                    && $issuanceStatus !== 'cancelled'
                    && ($box === 'inbox' || $claimStatus === 'claimed')
                ): ?>
                    <div class="cv-media-list">
                        <?php foreach ($media as $item): ?>
                            <?php
                            $mediaUrl = coveted_safe_url($item['media_url'] ?? null, false);
                            if ($mediaUrl === null) {
                                continue;
                            }
                            ?>
                            <?php if ($item['media_type'] === 'audio'): ?>
                                <button
                                    type="button"
                                    class="cv-media-action"
                                    data-play-audio
                                    data-src="<?= coveted_e($mediaUrl) ?>"
                                    data-title="<?= coveted_e($item['title'] ?: $reward['title']) ?>"
                                    data-artist="<?= coveted_e($reward['artist_name'] ?? 'Coveted') ?>"
                                    data-artwork="<?= coveted_e($cover ?? '') ?>"
                                >▶ <?= coveted_e($item['title'] ?: 'Play audio') ?></button>
                            <?php elseif ($item['media_type'] === 'video'): ?>
                                <form method="post" action="/media.php">
                                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="open">
                                    <input type="hidden" name="issuance" value="<?= coveted_e($reward['public_id']) ?>">
                                    <input type="hidden" name="media" value="<?= (int)$item['sort_order'] ?>">
                                    <button class="cv-media-action" type="submit">Watch · <?= coveted_e($item['title'] ?: 'Video') ?></button>
                                </form>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (
                    $box === 'inbox'
                    && !$expired
                    && $reward['claim_mode'] === 'location_code'
                    && in_array($issuanceStatus, ['issued', 'viewed'], true)
                ): ?>
                    <?php if ($eligibleLocations): ?>
                        <details class="cv-form-details">
                            <summary>Claim at partner location</summary>
                            <form method="post" autocomplete="off">
                                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                <input type="hidden" name="action" value="claim">
                                <input type="hidden" name="box" value="inbox">
                                <input type="hidden" name="issuance_id" value="<?= coveted_e($reward['public_id']) ?>">

                                <label>
                                    <span>Location</span>
                                    <select name="location_id" required>
                                        <?php foreach ($eligibleLocations as $location): ?>
                                            <option value="<?= (int)$location['id'] ?>"><?= coveted_e($location['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>

                                <label>
                                    <span>Host claim code</span>
                                    <input
                                        type="password"
                                        name="claim_code"
                                        minlength="5"
                                        maxlength="10"
                                        pattern="[A-Za-z0-9]{5,10}"
                                        inputmode="text"
                                        autocomplete="off"
                                        required
                                    >
                                </label>

                                <p class="cv-form-help">Show this benefit to the host. They enter their location or employee claim code on your device.</p>
                                <button class="cv-button" type="submit">Verify &amp; Claim</button>
                            </form>
                        </details>
                    <?php else: ?>
                        <p class="cv-form-help">No active claim location is available for this benefit yet.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</section>
<?php coveted_page_end(); ?>