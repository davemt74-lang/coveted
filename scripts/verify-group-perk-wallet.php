<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . ltrim($path, '/'));
    if ($content === false) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
    return $content;
};
$contains = static function (string $content, string $needle, string $label): void {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "Group Perk Wallet contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Group Perk Wallet contract failed: {$label}\n");
        exit(1);
    }
};

$walletService = $read('app/member_wallet.php');
$economy = $read('app/benefit_economy.php');
$wallet = $read('wallet.php');
$benefits = $read('benefits.php');
$worker = $read('scripts/reconcile-lifecycle.php');
$admin = $read('admin/benefit-economy.php');
$adminUi = $read('app/admin_ui.php');
$rewards = $read('app/rewards.php');
$campaigns = $read('app/campaigns.php');
$media = $read('media.php');
$schema = $read('database/schema.sql');

// Member wallet is read-only until an explicit CSRF-protected redemption POST.
$contains($benefits, "require __DIR__ . '/wallet.php';", 'Benefits route must open the new wallet');
$contains($wallet, 'coveted_member_wallet_snapshot($userId)', 'wallet must use canonical snapshot service');
$contains($wallet, 'if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\')', 'redemption must remain an explicit POST');
$contains($wallet, 'coveted_require_csrf()', 'redemption must require CSRF');
$contains($wallet, 'coveted_reward_claim_with_code(', 'wallet redemption must use canonical claim service');
$contains($wallet, 'coveted_return_process_claim(', 'return-visit trigger must remain canonical after claim');
$contains($wallet, "['ready', 'upcoming', 'redeemed', 'expired']", 'wallet must expose four lifecycle states');
$contains($wallet, "'return' => 'Return visit'", 'wallet must expose return-visit source filtering');
$contains($wallet, 'data-play-audio', 'audio must retain the canonical shared player path');
$contains($wallet, 'action="/media.php"', 'video must use the canonical entitlement/view path');
$contains($media, 'coveted_member_video_mark_viewed', 'canonical video path must record intentional media use');
$contains($wallet, "claim_status'] ?? ''", 'redeemed history must expose claim status');
$contains($wallet, "type=\"password\" name=\"claim_code\"", 'partner claim code must not be displayed in plain text');
$contains($wallet, 'pattern="[A-Za-z0-9]{5,10}"', 'partner claim code input must retain bounded format');
$missing($walletService, 'INSERT INTO', 'wallet snapshot service must not mutate data');
$missing($walletService, 'UPDATE ', 'wallet snapshot service must not mutate data');
$missing($walletService, 'DELETE FROM', 'wallet snapshot service must not mutate data');

// Membership perks are scheduler-owned, bounded, and replay-safe.
$contains($economy, "c.trigger_key = 'membership'", 'membership distributor must only target membership campaigns');
$contains($economy, "gm.membership_status = 'active'", 'membership perks require active group membership');
$contains($economy, "u.status = 'active'", 'membership perks require active member accounts');
$contains($economy, 'c.quantity_limit IS NULL', 'membership pool quantity limits must be respected');
$contains($economy, 'c.per_user_limit IS NULL', 'per-user limits must be respected');
$contains($economy, "'membership',", 'membership issuance must use deterministic idempotency context');
$contains($economy, 'coveted_reward_existing_idempotent(', 'membership issuance must check canonical idempotency');
$contains($economy, 'coveted_reward_issue(', 'membership distribution must use canonical reward issuer');
$contains($economy, 'SELECT GET_LOCK(?, 0)', 'membership distribution must prevent overlapping workers');
$contains($economy, 'SELECT RELEASE_LOCK(?)', 'membership distribution must release its lock');
$contains($economy, 'LIMIT {$limit}', 'membership target query must be bounded');
$missing($economy, 'CREATE TABLE', 'benefit economy must not create runtime schema');
$missing($economy, 'ALTER TABLE', 'benefit economy must not alter runtime schema');
$contains($schema, 'UNIQUE KEY uq_reward_issuance_idempotency (idempotency_key)', 'canonical issuance idempotency constraint is required');
$contains($rewards, 'function coveted_reward_issue(', 'canonical reward issuer is required');
$contains($campaigns, "'membership'", 'canonical campaigns must retain membership trigger support');

// One existing CLI lifecycle command owns automation; no public worker endpoint.
$contains($worker, "if (PHP_SAPI !== 'cli')", 'lifecycle worker must remain CLI-only');
$contains($worker, "require_once dirname(__DIR__) . '/app/benefit_economy.php';", 'lifecycle worker must load benefit economy');
$contains($worker, 'coveted_membership_benefit_reconcile($limit)', 'lifecycle worker must run membership distribution');
$contains($worker, '!empty($membership[\'more_work_possible\'])', 'membership backlog must propagate to worker status');
$contains($worker, '(int)$membership[\'failures\'] > 0', 'membership failures must fail worker visibly');

// Admin analytics are aggregate/read-only and do not expose member PII.
$contains($economy, 'function coveted_benefit_economy_snapshot(array $actor', 'Admin economy snapshot is required');
$contains($economy, 'coveted_is_system_admin($actor)', 'economy analytics require System Admin');
$contains($economy, 'COUNT(DISTINCT ri.id)', 'claim-rate cohort and attribution must deduplicate issuances');
$contains($economy, 'COALESCE(c.business_id, rt.business_id, vl.business_id)', 'business attribution must include event venue ownership');
$contains($admin, 'coveted_require_system_admin()', 'Admin economy page requires System Admin');
$contains($admin, 'coveted_benefit_economy_snapshot($admin, 15)', 'Admin page must use bounded aggregate snapshot');
$contains($admin, 'GROUP REWARD POOLS', 'Admin page must expose group reward pools');
$contains($admin, 'Return claims', 'Admin page must expose return-visit performance');
$contains($admin, 'No member-level PII', 'Admin page must state its PII boundary');
$missing($admin, 'method="post"', 'Admin economy dashboard must remain read-only');
$missing($economy, 'u.email', 'aggregate economy snapshot must not expose member emails');
$missing($economy, 'u.display_name', 'aggregate economy snapshot must not expose member names');
$contains($adminUi, "'/admin/benefit-economy.php'", 'Admin navigation must expose Benefit Economy');

fwrite(STDOUT, "Group Perk Wallet contract verified.\n");
