<?php
declare(strict_types=1);

require_once __DIR__ . '/app/member_wallet.php';
require_once __DIR__ . '/app/return_engine.php';
require_once __DIR__ . '/app/member_sample_data.php';

$user = coveted_require_user();
$pdo = coveted_db();
$userId = (int)$user['id'];
$sampleMode = coveted_member_sample_mode($user, $pdo);
$message = '';
$error = '';

$box = strtolower(trim((string)($_GET['box'] ?? $_POST['box'] ?? 'ready')));
if (!in_array($box, ['ready', 'upcoming', 'redeemed', 'expired'], true)) {
    $box = 'ready';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    try {
        if ($sampleMode) {
            throw new InvalidArgumentException('Sample benefits are preview-only. Turn Sample Data off to claim live benefits.');
        }
        if ((string)($_POST['action'] ?? '') !== 'claim') {
            throw new InvalidArgumentException('Unsupported wallet action.');
        }

        $claim = coveted_reward_claim_with_code(
            $user,
            trim((string)($_POST['issuance_id'] ?? '')),
            (int)($_POST['location_id'] ?? 0),
            (string)($_POST['claim_code'] ?? '')
        );

        $returnSummary = null;
        try {
            $returnSummary = coveted_return_process_claim((string)$claim['public_id']);
        } catch (Throwable $returnError) {
            error_log('Coveted wallet return trigger error: ' . $returnError->getMessage());
        }

        $unlocked = (int)($returnSummary['issued_count'] ?? 0);
        $message = $unlocked > 0
            ? 'Benefit redeemed. Your return visit also unlocked ' . $unlocked . ' new perk' . ($unlocked === 1 ? '.' : 's.')
            : 'Benefit redeemed and moved to your wallet history.';
        $box = 'redeemed';
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted wallet claim error: ' . $e->getMessage());
        $error = 'Unable to redeem that benefit right now.';
    }
}

$filters = [
    'all' => ['label' => 'All', 'types' => []],
    'gifts' => ['label' => 'Gifts', 'types' => ['credit', 'free_item', 'discount', 'perk', 'experience']],
    'access' => ['label' => 'Access', 'types' => ['access']],
    'media' => ['label' => 'Media', 'types' => ['audio', 'video', 'media_pack']],
    'services' => ['label' => 'Services', 'types' => ['service']],
];
$type = strtolower(trim((string)($_GET['type'] ?? 'all')));
if (!isset($filters[$type])) {
    $type = 'all';
}

$sources = [
    'all' => 'All sources',
    'group' => 'Group',
    'event' => 'Event',
    'business' => 'Business',
    'artist' => 'Artist',
    'return' => 'Return visit',
];
$source = strtolower(trim((string)($_GET['source'] ?? 'all')));
if (!isset($sources[$source])) {
    $source = 'all';
}

$mediaByTemplate = [];
$eligibleLocations = [];
$summary = [
    'ready' => 0, 'redeemed' => 0, 'expired' => 0, 'upcoming' => 0,
    'expiring_soon' => 0, 'return_ready' => 0, 'media_ready' => 0,
    'group_ready' => 0, 'business_ready' => 0, 'artist_ready' => 0,
    'event_ready' => 0, 'claimable' => 0,
];

if ($sampleMode) {
    $sample = coveted_member_sample_data();
    $ready = [];
    $redeemed = [];
    foreach ((array)($sample['benefits'] ?? []) as $benefit) {
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
            'owner_type' => 'business',
            'business_name' => (string)($benefit['partner'] ?? 'Coveted partner'),
            'group_name' => null,
            'event_title' => null,
            'artist_name' => null,
            'trigger_key' => 'manual',
            'expires_at' => null,
            'issued_at' => gmdate('Y-m-d H:i:s'),
            'is_sample' => true,
        ];
        if ((string)($benefit['state'] ?? 'inbox') === 'claimed') {
            $redeemed[] = $row + ['claim_recorded_at' => gmdate('Y-m-d H:i:s', time() - 864000)];
        } else {
            $ready[] = $row;
        }
    }
    $upcoming = [];
    $expired = [];
    $summary['ready'] = count($ready);
    $summary['redeemed'] = count($redeemed);
} else {
    $wallet = coveted_member_wallet_snapshot($userId);
    $ready = $wallet['ready'];
    $upcoming = $wallet['upcoming'];
    $redeemed = $wallet['redeemed'];
    $expired = $wallet['expired'];
    $mediaByTemplate = $wallet['media_by_template'];
    $eligibleLocations = $wallet['eligible_locations'];
    $summary = $wallet['summary'];
}

$rewards = match ($box) {
    'upcoming' => $upcoming,
    'redeemed' => $redeemed,
    'expired' => $expired,
    default => $ready,
};

$activeTypes = $filters[$type]['types'];
$rewards = array_values(array_filter($rewards, static function (array $reward) use ($activeTypes, $source, $box): bool {
    if ($activeTypes && !in_array((string)($reward['reward_type'] ?? ''), $activeTypes, true)) {
        return false;
    }
    if ($source === 'all') {
        return true;
    }
    if ($box === 'upcoming') {
        return $source === 'group';
    }
    return match ($source) {
        'group' => (string)($reward['owner_type'] ?? '') === 'group',
        'event' => !empty($reward['event_id']),
        'business' => (string)($reward['owner_type'] ?? '') === 'business',
        'artist' => (string)($reward['owner_type'] ?? '') === 'artist',
        'return' => in_array((string)($reward['trigger_key'] ?? ''), ['return_visit', 'guest_return'], true),
        default => true,
    };
}));

$timezone = coveted_timezone();
$formatDate = static function (?string $value) use ($timezone): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    return coveted_utc_datetime($value)->setTimezone($timezone)->format('M j, Y');
};

$valueLabel = static function (array $reward): string {
    if (!empty($reward['value_text'])) {
        return (string)$reward['value_text'];
    }
    if (($reward['value_amount'] ?? null) !== null) {
        return '$' . number_format((float)$reward['value_amount'], 2);
    }
    return 'Member benefit';
};

coveted_page_start('Perk Wallet', 'Benefits');
?>
<div class="cv-member-page-v2 cv-benefits-v2">
    <section class="cv-member-page-intro">
        <div>
            <span class="cv-eyebrow">GROUP PERK WALLET</span>
            <h1>Your value between events.</h1>
            <p>Membership perks, event rewards, venue return offers, artist media and services stay together in one wallet.</p>
        </div>
        <?php if ($sampleMode): ?><a class="cv-member-preview-pill" href="/admin/sample-data.php">Sample data · ON</a><?php endif; ?>
    </section>

    <?php if ($message !== ''): ?><div class="cv-alert"><?= coveted_e($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>

    <section class="cv-stat-grid" aria-label="Wallet summary">
        <div class="cv-card cv-stat"><strong><?= (int)$summary['ready'] ?></strong><span>Ready</span></div>
        <div class="cv-card cv-stat"><strong><?= (int)$summary['expiring_soon'] ?></strong><span>Expiring · 7d</span></div>
        <div class="cv-card cv-stat"><strong><?= (int)$summary['return_ready'] ?></strong><span>Return perks</span></div>
        <div class="cv-card cv-stat"><strong><?= (int)$summary['redeemed'] ?></strong><span>Redeemed</span></div>
    </section>

    <nav class="cv-member-segmented-tabs" aria-label="Wallet states">
        <?php foreach ([
            'ready' => ['Ready', $summary['ready']],
            'upcoming' => ['Coming Soon', $summary['upcoming']],
            'redeemed' => ['Redeemed', $summary['redeemed']],
            'expired' => ['Expired', $summary['expired']],
        ] as $key => $item): ?>
            <a class="<?= $box === $key ? 'is-active' : '' ?>" href="/benefits.php?box=<?= coveted_e($key) ?>"><span><?= coveted_e($item[0]) ?></span><small><?= (int)$item[1] ?></small></a>
        <?php endforeach; ?>
    </nav>

    <nav class="cv-benefit-filter-v2" aria-label="Benefit type filters">
        <?php foreach ($filters as $key => $filter): ?>
            <a class="<?= $type === $key ? 'is-active' : '' ?>" href="/benefits.php?box=<?= coveted_e($box) ?>&amp;type=<?= coveted_e($key) ?>&amp;source=<?= coveted_e($source) ?>"><?= coveted_e($filter['label']) ?></a>
        <?php endforeach; ?>
    </nav>

    <nav class="cv-benefit-filter-v2" aria-label="Benefit source filters">
        <?php foreach ($sources as $key => $label): ?>
            <a class="<?= $source === $key ? 'is-active' : '' ?>" href="/benefits.php?box=<?= coveted_e($box) ?>&amp;type=<?= coveted_e($type) ?>&amp;source=<?= coveted_e($key) ?>"><?= coveted_e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if (!$rewards): ?>
        <div class="cv-member-empty-v2">
            <span><?= $box === 'upcoming' ? 'Nothing scheduled' : 'Wallet clear' ?></span>
            <h2><?= match ($box) {
                'upcoming' => 'No membership perks are waiting to open.',
                'redeemed' => 'No redeemed benefits match this view.',
                'expired' => 'No expired benefits match this view.',
                default => 'Nothing waiting right now.',
            } ?></h2>
            <p>Coveted keeps value tied to membership, attendance, partners and real return visits—not an endless coupon feed.</p>
        </div>
    <?php else: ?>
        <div class="cv-benefit-card-grid-v2">
            <?php foreach ($rewards as $reward): ?>
                <?php
                $cover = $sampleMode
                    ? trim((string)($reward['cover_url'] ?? ''))
                    : (coveted_safe_url($reward['cover_url'] ?? null, false) ?? '');
                $templateId = (int)($reward['reward_template_id'] ?? 0);
                $media = $mediaByTemplate[$templateId] ?? [];
                $rewardLocations = $eligibleLocations[(string)($reward['public_id'] ?? '')] ?? [];
                $sourceLabel = $box === 'upcoming'
                    ? 'Group · ' . (string)($reward['group_name'] ?? 'Membership')
                    : coveted_member_wallet_source_label($reward);
                ?>
                <article class="cv-benefit-card-v2">
                    <div class="cv-benefit-card-media <?= $cover === '' ? 'is-empty' : '' ?>">
                        <?php if ($cover !== ''): ?><img src="<?= coveted_e($cover) ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                    </div>
                    <div class="cv-benefit-card-copy-v2">
                        <div class="cv-benefit-card-topline">
                            <span class="cv-member-overline"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)($reward['reward_type'] ?? 'perk')))) ?></span>
                            <span class="cv-member-status-chip"><?= coveted_e(match ($box) {
                                'upcoming' => 'Coming soon',
                                'redeemed' => 'Redeemed',
                                'expired' => 'Expired',
                                default => in_array((string)($reward['trigger_key'] ?? ''), ['return_visit','guest_return'], true) ? 'Return perk' : 'Ready',
                            }) ?></span>
                        </div>
                        <h3><?= coveted_e((string)$reward['title']) ?></h3>
                        <?php if (!empty($reward['description'])): ?><p><?= coveted_e(mb_strimwidth((string)$reward['description'], 0, 240, '…')) ?></p><?php endif; ?>
                        <strong class="cv-benefit-card-value"><?= coveted_e($valueLabel($reward)) ?></strong>
                        <div class="cv-member-card-meta">
                            <span><?= coveted_e($sourceLabel) ?></span>
                            <?php if ($box === 'upcoming' && !empty($reward['available_at'])): ?><span>Opens <?= coveted_e($formatDate((string)$reward['available_at'])) ?></span><?php endif; ?>
                            <?php if ($box === 'ready' && !empty($reward['expires_at'])): ?><span>Expires <?= coveted_e($formatDate((string)$reward['expires_at'])) ?></span><?php endif; ?>
                            <?php if ($box === 'redeemed' && !empty($reward['claim_recorded_at'])): ?><span>Used <?= coveted_e($formatDate((string)$reward['claim_recorded_at'])) ?></span><?php endif; ?>
                        </div>

                        <?php if ($media): ?>
                            <div class="cv-benefit-media-list">
                                <?php foreach ($media as $item): ?>
                                    <?php $mediaUrl = coveted_safe_url($item['media_url'] ?? null, false); if ($mediaUrl === null) continue; ?>
                                    <?php if ((string)$item['media_type'] === 'audio'): ?>
                                        <audio controls preload="none" src="<?= coveted_e($mediaUrl) ?>"></audio>
                                    <?php elseif ((string)$item['media_type'] === 'video'): ?>
                                        <video controls preload="metadata" src="<?= coveted_e($mediaUrl) ?>"></video>
                                    <?php else: ?>
                                        <a class="cv-text-link" href="<?= coveted_e($mediaUrl) ?>" target="_blank" rel="noopener">Open <?= coveted_e((string)($item['title'] ?: 'reward media')) ?> →</a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($box === 'ready' && $rewardLocations && !$sampleMode): ?>
                            <details class="cv-benefit-claim-panel">
                                <summary>Redeem at partner</summary>
                                <form method="post" action="/benefits.php">
                                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="claim">
                                    <input type="hidden" name="box" value="ready">
                                    <input type="hidden" name="issuance_id" value="<?= coveted_e((string)$reward['public_id']) ?>">
                                    <label>Location
                                        <select name="location_id" required>
                                            <?php foreach ($rewardLocations as $location): ?><option value="<?= (int)$location['id'] ?>"><?= coveted_e((string)$location['name']) ?></option><?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label>Partner claim code<input type="text" name="claim_code" inputmode="numeric" autocomplete="one-time-code" required></label>
                                    <button class="cv-button cv-button-primary" type="submit">Redeem Benefit</button>
                                </form>
                            </details>
                        <?php elseif ($sampleMode && $box === 'ready'): ?>
                            <div class="cv-member-preview-note">Preview only · live redemption is disabled in Sample Data mode.</div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="cv-card cv-feature-card cv-copy-card cv-admin-section-gap">
        <span class="cv-kicker">HOW VALUE MOVES</span>
        <h2>Membership → Event → Reward → Return Visit.</h2>
        <p>Group perks can enter your wallet automatically. Event rewards require canonical attendance. Venue return rewards unlock only after a verified later visit. Redeemed and expired value stays visible as history.</p>
    </section>
</div>
<?php coveted_page_end(); ?>
