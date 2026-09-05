<?php
declare(strict_types=1);

require_once __DIR__ . '/app/rewards.php';
require_once __DIR__ . '/app/return_engine.php';
require_once __DIR__ . '/app/member_sample_data.php';

$user = coveted_require_user();
$pdo = coveted_db();
$userId = (int)$user['id'];
$sampleMode = coveted_member_sample_mode($user, $pdo);
$message = '';
$error = '';

$box = strtolower(trim((string)($_GET['box'] ?? $_POST['box'] ?? 'inbox')));
if (!in_array($box, ['inbox', 'claims'], true)) {
    $box = 'inbox';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        if ($sampleMode) {
            throw new InvalidArgumentException('Sample benefits are preview-only. Turn Sample Data off to claim live benefits.');
        }

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

$mediaByTemplate = [];
$eligibleLocationsByReward = [];
if ($sampleMode) {
    $sample = coveted_member_sample_data();
    $inboxRewards = [];
    $claimRewards = [];
    foreach ((array)($sample['benefits'] ?? []) as $benefit) {
        $state = (string)($benefit['state'] ?? 'inbox');
        $row = [
            'reward_template_id' => 0,
            'public_id' => 'sample-benefit-' . (string)$benefit['id'],
            'title' => (string)$benefit['title'],
            'description' => (string)($benefit['description'] ?? ''),
            'reward_type' => (string)($benefit['reward_type'] ?? 'perk'),
            'claim_mode' => 'none',
            'value_text' => (string)($benefit['value'] ?? ''),
            'value_amount' => null,
            'cover_url' => (string)($benefit['image'] ?? ''),
            'status' => $state === 'claimed' ? 'claimed' : 'issued',
            'expires_at' => null,
            'event_title' => null,
            'location_name' => (string)($benefit['partner'] ?? ''),
            'artist_name' => null,
            'claim_public_id' => $state === 'claimed' ? 'sample-claim-' . (string)$benefit['id'] : null,
            'claim_status' => $state === 'claimed' ? 'claimed' : null,
            'claim_recorded_at' => $state === 'claimed' ? gmdate('Y-m-d H:i:s', time() - (10 * 86400)) : null,
            'claim_refunded_at' => null,
            'claim_refund_reason' => null,
            'claim_code_label' => 'Preview',
            'sample_status' => (string)($benefit['status'] ?? 'Ready'),
            'is_sample' => true,
        ];
        if ($state === 'claimed') {
            $claimRewards[] = $row;
        } else {
            $inboxRewards[] = $row;
        }
    }
} else {
    $inboxRewards = coveted_reward_list_for_user($userId, [], 'inbox');
    $claimRewards = coveted_reward_list_for_user($userId, [], 'claimed');
    $mediaTemplateIds = array_map(
        static fn(array $reward): int => (int)$reward['reward_template_id'],
        array_merge($inboxRewards, $claimRewards)
    );
    $mediaByTemplate = coveted_reward_media_for_templates($mediaTemplateIds);
    $eligibleLocationsByReward = coveted_reward_eligible_locations_for_rows($inboxRewards);
}

$boxRewards = $box === 'claims' ? $claimRewards : $inboxRewards;
$activeTypes = $filters[$activeFilter]['types'];
if ($activeTypes) {
    if ($sampleMode) {
        $rewards = array_values(array_filter(
            $boxRewards,
            static fn(array $reward): bool => in_array((string)$reward['reward_type'], $activeTypes, true)
        ));
    } else {
        $rewards = coveted_reward_list_for_user($userId, $activeTypes, $box === 'claims' ? 'claimed' : 'inbox');
    }
} else {
    $rewards = $boxRewards;
}

$availableCount = count($inboxRewards);
$claimHistoryCount = count($claimRewards);
$claimableCount = $sampleMode
    ? $availableCount
    : count(array_filter($inboxRewards, static fn(array $reward): bool => !empty($eligibleLocationsByReward[(string)$reward['public_id']])));
$mediaCount = $sampleMode
    ? 0
    : count(array_filter($inboxRewards, static fn(array $reward): bool => !empty($mediaByTemplate[(int)$reward['reward_template_id']])));

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

$featuredReward = $box === 'inbox' ? ($rewards[0] ?? null) : null;
$gridRewards = $featuredReward ? array_slice($rewards, 1) : $rewards;

coveted_page_start('Benefits', 'Benefits');
?>
<div class="cv-member-page-v2 cv-benefits-v2">
    <section class="cv-member-page-intro">
        <div>
            <span class="cv-eyebrow">BENEFITS</span>
            <h1>Rewards for showing up.</h1>
            <p>Gifts, access, services and media rewards stay here until you use them. The value comes after the gathering, not during it.</p>
        </div>
        <?php if ($sampleMode): ?>
            <a class="cv-member-preview-pill" href="/admin/sample-data.php">Sample data · ON</a>
        <?php endif; ?>
    </section>

    <?php if ($message !== ''): ?><div class="cv-alert"><?= coveted_e($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>

    <nav class="cv-member-segmented-tabs" aria-label="Benefit boxes">
        <a class="<?= $box === 'inbox' ? 'is-active' : '' ?>" href="/benefits.php?box=inbox"><span>Ready</span><small><?= $availableCount ?></small></a>
        <a class="<?= $box === 'claims' ? 'is-active' : '' ?>" href="/benefits.php?box=claims"><span>Redeemed</span><?php if ($claimHistoryCount): ?><small><?= $claimHistoryCount ?></small><?php endif; ?></a>
    </nav>

    <nav class="cv-benefit-filter-v2" aria-label="Benefit filters">
        <?php foreach ($filters as $key => $filter): ?>
            <a class="<?= $activeFilter === $key ? 'is-active' : '' ?>" href="/benefits.php?box=<?= coveted_e($box) ?>&amp;type=<?= coveted_e($key) ?>"><?= coveted_e((string)$filter['label']) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($featuredReward): ?>
        <?php
        $featuredCover = $sampleMode
            ? trim((string)($featuredReward['cover_url'] ?? ''))
            : (coveted_safe_url($featuredReward['cover_url'] ?? null, false) ?? '');
        ?>
        <section class="cv-benefit-feature" aria-label="Featured benefit">
            <div class="cv-benefit-feature-media <?= $featuredCover === '' ? 'is-empty' : '' ?>">
                <?php if ($featuredCover !== ''): ?><img src="<?= coveted_e($featuredCover) ?>" alt="" loading="eager" decoding="async"><?php endif; ?>
            </div>
            <div class="cv-benefit-feature-copy">
                <span class="cv-member-overline">READY TO USE</span>
                <h2><?= coveted_e((string)$featuredReward['title']) ?></h2>
                <?php if (!empty($featuredReward['description'])): ?><p><?= coveted_e((string)$featuredReward['description']) ?></p><?php endif; ?>
                <div class="cv-benefit-value-v2">
                    <?= $featuredReward['value_text'] ? coveted_e((string)$featuredReward['value_text']) : ($featuredReward['value_amount'] !== null ? '$' . coveted_e(number_format((float)$featuredReward['value_amount'], 2)) : 'Member benefit') ?>
                </div>
                <dl class="cv-member-detail-list">
                    <?php if (!empty($featuredReward['location_name'])): ?><div><dt>Partner</dt><dd><?= coveted_e((string)$featuredReward['location_name']) ?></dd></div><?php endif; ?>
                    <div><dt>Type</dt><dd><?= coveted_e(ucwords(str_replace('_', ' ', (string)$featuredReward['reward_type']))) ?></dd></div>
                    <?php if (!empty($featuredReward['expires_at'])): ?><div><dt>Expires</dt><dd><?= coveted_e($formatMemberDate((string)$featuredReward['expires_at'])) ?></dd></div><?php endif; ?>
                </dl>
                <?php if ($sampleMode): ?>
                    <div class="cv-member-preview-note">Preview only · live claim actions stay disabled in Sample Data mode.</div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="cv-member-section-head">
        <div>
            <span class="cv-member-overline"><?= $box === 'claims' ? 'REDEEMED' : 'YOUR WALLET' ?></span>
            <h2><?= $box === 'claims' ? 'What you’ve already used.' : 'A little extra for coming back.' ?></h2>
        </div>
        <span class="cv-benefit-count-v2"><?= count($rewards) ?> <?= count($rewards) === 1 ? 'benefit' : 'benefits' ?></span>
    </section>

    <?php if (!$rewards): ?>
        <div class="cv-member-empty-v2">
            <span><?= $box === 'claims' ? 'No history yet' : 'Wallet clear' ?></span>
            <h2><?= $box === 'claims' ? 'Redeemed benefits will stay here.' : 'Nothing waiting right now.' ?></h2>
            <p><?= $box === 'claims'
                ? 'Claimed and refunded rewards remain part of your Coveted history.'
                : 'When a venue, artist, group or campaign leaves something for you, it will appear here.' ?></p>
        </div>
    <?php elseif ($gridRewards): ?>
        <div class="cv-benefit-card-grid-v2">
            <?php foreach ($gridRewards as $reward): ?>
                <?php
                $templateId = (int)$reward['reward_template_id'];
                $media = $mediaByTemplate[$templateId] ?? [];
                $cover = $sampleMode
                    ? trim((string)($reward['cover_url'] ?? ''))
                    : (coveted_safe_url($reward['cover_url'] ?? null, false) ?? '');
                $expired = !empty($reward['expires_at']) && strtotime((string)$reward['expires_at']) <= time();
                $issuanceStatus = $expired ? 'expired' : (string)$reward['status'];
                $claimStatus = $box === 'claims' ? (string)($reward['claim_status'] ?? 'claimed') : null;
                $eligibleLocations = $eligibleLocationsByReward[(string)$reward['public_id']] ?? [];
                ?>
                <article class="cv-benefit-card-v2">
                    <div class="cv-benefit-card-media <?= $cover === '' ? 'is-empty' : '' ?>">
                        <?php if ($cover !== ''): ?><img src="<?= coveted_e($cover) ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                    </div>
                    <div class="cv-benefit-card-copy-v2">
                        <div class="cv-benefit-card-topline">
                            <span class="cv-member-overline"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$reward['reward_type']))) ?></span>
                            <?php if ($sampleMode): ?>
                                <span class="cv-member-preview-chip">Preview</span>
                            <?php else: ?>
                                <span class="cv-member-status-chip"><?= coveted_e($box === 'claims' ? ucfirst(str_replace('_', ' ', (string)$claimStatus)) : ($expired ? 'Expired' : 'Ready')) ?></span>
                            <?php endif; ?>
                        </div>
                        <h3><?= coveted_e((string)$reward['title']) ?></h3>
                        <?php if (!empty($reward['description'])): ?><p><?= coveted_e(mb_strimwidth((string)$reward['description'], 0, 220, '…')) ?></p><?php endif; ?>
                        <strong class="cv-benefit-card-value"><?= $reward['value_text'] ? coveted_e((string)$reward['value_text']) : ($reward['value_amount'] !== null ? '$' . coveted_e(number_format((float)$reward['value_amount'], 2)) : 'Member benefit') ?></strong>
                        <div class="cv-member-card-meta">
                            <?php if (!empty($reward['event_title'])): ?><span>From <?= coveted_e((string)$reward['event_title']) ?></span>
                            <?php elseif (!empty($reward['location_name'])): ?><span><?= coveted_e((string)$reward['location_name']) ?></span><?php endif; ?>
                            <?php if (!empty($reward['artist_name'])): ?><span><?= coveted_e((string)$reward['artist_name']) ?></span><?php endif; ?>
                            <?php if ($box === 'inbox' && !empty($reward['expires_at'])): ?><span>Expires <?= coveted_e($formatMemberDate((string)$reward['expires_at'])) ?></span><?php endif; ?>
                        </div>

                        <?php if (!$sampleMode && $box === 'claims' && !empty($reward['claim_public_id'])): ?>
                            <div class="cv-benefit-receipt-v2 <?= $claimStatus === 'refunded' ? 'is-refunded' : '' ?>">
                                <?php if ($claimStatus === 'refunded'): ?>
                                    <strong>Refunded · <?= coveted_e((string)($reward['location_name'] ?: 'partner location')) ?></strong>
                                    <span>Originally claimed <?= coveted_e($formatMemberTime((string)$reward['claim_recorded_at'])) ?></span>
                                    <?php if (!empty($reward['claim_refunded_at'])): ?><span>Refunded <?= coveted_e($formatMemberTime((string)$reward['claim_refunded_at'])) ?></span><?php endif; ?>
                                    <?php if (!empty($reward['claim_refund_reason'])): ?><span><?= coveted_e((string)$reward['claim_refund_reason']) ?></span><?php endif; ?>
                                <?php else: ?>
                                    <strong>Claimed at <?= coveted_e((string)($reward['location_name'] ?: 'partner location')) ?></strong>
                                    <span><?= coveted_e($formatMemberTime((string)$reward['claim_recorded_at'])) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!$sampleMode && $media && !$expired && $issuanceStatus !== 'cancelled' && ($box === 'inbox' || $claimStatus === 'claimed')): ?>
                            <div class="cv-media-list">
                                <?php foreach ($media as $item): ?>
                                    <?php $mediaUrl = coveted_safe_url($item['media_url'] ?? null, false); if ($mediaUrl === null) { continue; } ?>
                                    <?php if ($item['media_type'] === 'audio'): ?>
                                        <button type="button" class="cv-media-action" data-play-audio data-src="<?= coveted_e($mediaUrl) ?>" data-title="<?= coveted_e((string)($item['title'] ?: $reward['title'])) ?>" data-artist="<?= coveted_e((string)($reward['artist_name'] ?? 'Coveted')) ?>" data-artwork="<?= coveted_e($cover) ?>">▶ <?= coveted_e((string)($item['title'] ?: 'Play audio')) ?></button>
                                    <?php elseif ($item['media_type'] === 'video'): ?>
                                        <form method="post" action="/media.php">
                                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="open">
                                            <input type="hidden" name="issuance" value="<?= coveted_e((string)$reward['public_id']) ?>">
                                            <input type="hidden" name="media" value="<?= (int)$item['sort_order'] ?>">
                                            <button class="cv-media-action" type="submit">Watch · <?= coveted_e((string)($item['title'] ?: 'Video')) ?></button>
                                        </form>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!$sampleMode && $box === 'inbox' && !$expired && $reward['claim_mode'] === 'location_code' && in_array($issuanceStatus, ['issued', 'viewed'], true)): ?>
                            <?php if ($eligibleLocations): ?>
                                <details class="cv-form-details cv-benefit-claim-v2">
                                    <summary>Claim at partner location</summary>
                                    <form method="post" autocomplete="off">
                                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                        <input type="hidden" name="action" value="claim">
                                        <input type="hidden" name="box" value="inbox">
                                        <input type="hidden" name="issuance_id" value="<?= coveted_e((string)$reward['public_id']) ?>">
                                        <label><span>Location</span><select name="location_id" required><?php foreach ($eligibleLocations as $location): ?><option value="<?= (int)$location['id'] ?>"><?= coveted_e((string)$location['name']) ?></option><?php endforeach; ?></select></label>
                                        <label><span>Host claim code</span><input type="password" name="claim_code" minlength="5" maxlength="10" pattern="[A-Za-z0-9]{5,10}" autocomplete="off" required></label>
                                        <p class="cv-form-help">Show this benefit to the host. They enter their location or employee claim code on your device.</p>
                                        <button class="cv-button cv-button-primary" type="submit">Verify &amp; claim</button>
                                    </form>
                                </details>
                            <?php else: ?>
                                <p class="cv-form-help">No active claim location is available for this benefit yet.</p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($box === 'inbox' && $claimableCount > 0): ?>
        <p class="cv-benefit-footnote-v2"><?= $sampleMode ? 'Sample mode shows how available rewards will appear to members.' : $claimableCount . ' benefit' . ($claimableCount === 1 ? ' is' : 's are') . ' currently claimable at an eligible partner location.' ?></p>
    <?php elseif (!$sampleMode && $mediaCount > 0): ?>
        <p class="cv-benefit-footnote-v2"><?= $mediaCount ?> available benefit<?= $mediaCount === 1 ? ' includes' : 's include' ?> audio or video media.</p>
    <?php endif; ?>
</div>
<?php coveted_page_end(); ?>
