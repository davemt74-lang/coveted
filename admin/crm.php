<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/invite_profile.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
coveted_invite_crm_ensure_schema($pdo);
coveted_invite_profile_ensure_schema($pdo);

$error = '';
$notice = '';
$status = strtolower(trim((string)($_GET['status'] ?? 'new')));
$search = trim((string)($_GET['q'] ?? ''));
$cityFilter = max(0, (int)($_GET['city_id'] ?? 0));
$interestFilter = trim((string)($_GET['interest'] ?? ''));
$allowedStatuses = ['new', 'contacted', 'qualified', 'converted', 'declined', 'all'];
$interestOptions = coveted_invite_event_interest_options();
$goalOptions = coveted_invite_goal_options();
$sourceOptions = coveted_invite_source_options();
$genderOptions = coveted_invite_gender_options();
$linkLabels = [
    'personal_website' => 'Personal website',
    'business_website' => 'Business website',
    'instagram' => 'Instagram',
    'linkedin' => 'LinkedIn',
    'tiktok' => 'TikTok',
    'x_profile' => 'X / Twitter',
];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'new';
}
if ($interestFilter !== '' && !isset($interestOptions[$interestFilter])) {
    $interestFilter = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    try {
        $action = trim((string)($_POST['action'] ?? ''));
        $requestId = (int)($_POST['request_id'] ?? 0);

        if ($action === 'crm_update') {
            coveted_invite_request_update(
                $admin,
                $requestId,
                (string)($_POST['status'] ?? 'new'),
                (string)($_POST['admin_note'] ?? ''),
                $pdo
            );
            $_SESSION['crm_notice'] = 'CRM record updated.';
            coveted_redirect('/admin/crm.php?status=' . rawurlencode($status));
        }

        if ($action === 'crm_convert') {
            $conversion = coveted_invite_request_convert($admin, $requestId, $pdo);
            coveted_invite_profile_apply_to_user($requestId, (int)$conversion['user_id'], $pdo);
            $_SESSION['crm_conversion'] = $conversion;
            coveted_redirect('/admin/crm.php?status=converted');
        }

        throw new InvalidArgumentException('Unsupported CRM action.');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Invite CRM action failed: ' . $e->getMessage());
        $error = 'Unable to complete that CRM action.';
    }
}

$notice = trim((string)($_SESSION['crm_notice'] ?? ''));
unset($_SESSION['crm_notice']);
$conversion = is_array($_SESSION['crm_conversion'] ?? null) ? (array)$_SESSION['crm_conversion'] : null;
unset($_SESSION['crm_conversion']);

$counts = coveted_invite_request_counts($pdo);
$requests = coveted_invite_requests_list($status, $search, $pdo);
$cityOptions = coveted_cities_list('active', $pdo);

if ($cityFilter > 0) {
    $requests = array_values(array_filter(
        $requests,
        static fn(array $request): bool => (int)($request['city_id'] ?? 0) === $cityFilter
    ));
}
if ($interestFilter !== '') {
    $requests = array_values(array_filter($requests, static function (array $request) use ($interestFilter): bool {
        try {
            $decoded = json_decode((string)($request['event_interests_json'] ?? '[]'), true, 32, JSON_THROW_ON_ERROR);
            return is_array($decoded) && in_array($interestFilter, coveted_invite_normalize_interests($decoded), true);
        } catch (Throwable) {
            return false;
        }
    }));
}

$profileDetails = coveted_invite_profile_details_map(array_map(
    static fn(array $request): int => (int)$request['id'],
    $requests
), $pdo);
$adminCounts = coveted_admin_ui_counts($pdo);

coveted_page_start('Invite CRM', '', true);
coveted_admin_ui_start($admin, 'crm', 'Invite CRM', $adminCounts);
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">PEOPLE · CRM</span>
        <h1>People CRM</h1>
        <p>Review invite requests and newsletter signups, qualify people by city and event fit, then convert the right submissions into Coveted member accounts.</p>
    </div>
    <a class="cv-button cv-button-soft" href="/request-invite.php" target="_blank" rel="noopener">Open Public Form</a>
</div>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<?php if ($conversion): ?>
    <section class="cv-admin-panel cv-crm-conversion-notice">
        <div class="cv-admin-panel-head">
            <div>
                <span class="cv-eyebrow">CONVERSION COMPLETE</span>
                <h2><?= !empty($conversion['existing_user']) ? 'Linked to an existing user' : 'Member account created' ?></h2>
            </div>
            <span class="cv-status">Converted</span>
        </div>
        <p><?= coveted_e((string)$conversion['email']) ?> is now linked to a Coveted user record.</p>
        <?php if (!empty($conversion['activation_url'])): ?>
            <div class="cv-crm-activation-link">
                <label>One-time activation link · expires in 7 days</label>
                <input readonly value="<?= coveted_e((string)$conversion['activation_url']) ?>" onclick="this.select()">
                <small>Copy this link now and send it to the approved member. For security it is only shown in this Admin confirmation.</small>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<div class="cv-crm-metrics">
    <a class="<?= $status === 'new' ? 'is-active' : '' ?>" href="/admin/crm.php?status=new"><span>New</span><strong><?= (int)$counts['new'] ?></strong></a>
    <a class="<?= $status === 'qualified' ? 'is-active' : '' ?>" href="/admin/crm.php?status=qualified"><span>Qualified</span><strong><?= (int)$counts['qualified'] ?></strong></a>
    <a class="<?= $status === 'contacted' ? 'is-active' : '' ?>" href="/admin/crm.php?status=contacted"><span>Contacted</span><strong><?= (int)$counts['contacted'] ?></strong></a>
    <a class="<?= $status === 'converted' ? 'is-active' : '' ?>" href="/admin/crm.php?status=converted"><span>Converted</span><strong><?= (int)$counts['converted'] ?></strong></a>
    <a class="<?= $status === 'declined' ? 'is-active' : '' ?>" href="/admin/crm.php?status=declined"><span>Declined</span><strong><?= (int)$counts['declined'] ?></strong></a>
</div>

<form class="cv-admin-toolbar cv-crm-toolbar" method="get" action="/admin/crm.php">
    <input type="hidden" name="status" value="<?= coveted_e($status) ?>">
    <label>
        <span class="cv-sr-only">Search CRM</span>
        <input type="search" name="q" value="<?= coveted_e($search) ?>" placeholder="Search name, email, phone or city">
    </label>
    <select name="city_id" aria-label="Filter by city">
        <option value="0">All cities</option>
        <?php foreach ($cityOptions as $city): ?>
            <option value="<?= (int)$city['id'] ?>" <?= $cityFilter === (int)$city['id'] ? 'selected' : '' ?>><?= coveted_e(coveted_city_label($city)) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="interest" aria-label="Filter by event interest">
        <option value="">All event interests</option>
        <?php foreach ($interestOptions as $key => $label): ?>
            <option value="<?= coveted_e($key) ?>" <?= $interestFilter === $key ? 'selected' : '' ?>><?= coveted_e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="cv-button cv-button-soft" type="submit">Filter</button>
    <?php if ($search !== '' || $cityFilter > 0 || $interestFilter !== ''): ?><a class="cv-button cv-button-soft" href="/admin/crm.php?status=<?= coveted_e($status) ?>">Clear</a><?php endif; ?>
    <a class="cv-button cv-button-soft" href="/admin/crm.php?status=all">All records</a>
</form>

<div class="cv-crm-results-summary"><?= count($requests) ?> record<?= count($requests) === 1 ? '' : 's' ?> shown</div>

<section class="cv-crm-list">
    <?php if (!$requests): ?>
        <div class="cv-card cv-empty"><h3>No CRM records here.</h3><p>Change the filters or wait for new invite requests and newsletter signups.</p></div>
    <?php endif; ?>

    <?php foreach ($requests as $request): ?>
        <?php
        $interestKeys = [];
        try {
            $decoded = json_decode((string)$request['event_interests_json'], true, 32, JSON_THROW_ON_ERROR);
            $interestKeys = is_array($decoded) ? coveted_invite_normalize_interests($decoded) : [];
        } catch (Throwable) {
            $interestKeys = [];
        }
        $isNewsletter = trim((string)($request['how_heard'] ?? '')) === 'Newsletter signup';
        $cityLabel = trim((string)$request['city_other']);
        if (!empty($request['city_name'])) {
            $cityLabel = (string)$request['city_name'];
            if (!empty($request['city_region'])) {
                $cityLabel .= ', ' . (string)$request['city_region'];
            }
        }

        $detail = $profileDetails[(int)$request['id']] ?? [];
        $goalKeys = coveted_invite_profile_decode_list($detail['goals_json'] ?? '[]');
        $sourceKeys = coveted_invite_profile_decode_list($detail['source_keys_json'] ?? '[]');
        $socialLinks = coveted_invite_profile_decode_links($detail['social_links_json'] ?? null);
        $genderKey = trim((string)($detail['gender_key'] ?? ''));
        $genderLabel = $genderOptions[$genderKey] ?? '';
        if ($genderKey === 'self_describe' && trim((string)($detail['gender_self_description'] ?? '')) !== '') {
            $genderLabel = trim((string)$detail['gender_self_description']);
        }
        ?>
        <article class="cv-admin-panel cv-crm-record<?= $isNewsletter ? ' is-newsletter' : '' ?>">
            <div class="cv-crm-record-main">
                <div class="cv-crm-record-head">
                    <div>
                        <div class="cv-tag-row">
                            <span class="cv-status"><?= coveted_e(ucfirst((string)$request['status'])) ?></span>
                            <?php if ($isNewsletter): ?><span class="cv-pill">Newsletter</span><?php endif; ?>
                            <span class="cv-pill"><?= coveted_e($cityLabel !== '' ? $cityLabel : 'City not provided') ?></span>
                        </div>
                        <h2><?= coveted_e((string)$request['full_name']) ?></h2>
                        <p><?= coveted_e((string)$request['email']) ?><?php if (!empty($request['phone'])): ?> · <?= coveted_e((string)$request['phone']) ?><?php endif; ?></p>
                    </div>
                    <div class="cv-crm-record-date">
                        <span><?= $isNewsletter ? 'SIGNED UP' : 'REQUESTED' ?></span>
                        <strong><?= coveted_e(coveted_utc_datetime((string)$request['created_at'])->setTimezone(coveted_timezone())->format('M j')) ?></strong>
                        <small><?= coveted_e(coveted_utc_datetime((string)$request['created_at'])->setTimezone(coveted_timezone())->format('Y')) ?></small>
                    </div>
                </div>

                <?php if ($interestKeys): ?>
                    <div class="cv-crm-interest-row">
                        <?php foreach ($interestKeys as $key): ?>
                            <span><?= coveted_e($interestOptions[$key] ?? $key) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($isNewsletter): ?>
                    <div class="cv-crm-interest-row"><span>Email newsletter</span></div>
                <?php endif; ?>

                <?php if ($goalKeys): ?>
                    <div class="cv-crm-copy">
                        <span>LOOKING FOR</span>
                        <div class="cv-crm-interest-row">
                            <?php foreach ($goalKeys as $key): ?><span><?= coveted_e($goalOptions[$key] ?? $key) ?></span><?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($detail['additional_note'])): ?>
                    <div class="cv-crm-copy"><span>ADDITIONAL NOTE</span><p><?= nl2br(coveted_e((string)$detail['additional_note'])) ?></p></div>
                <?php elseif (!empty($request['message']) && !$isNewsletter): ?>
                    <div class="cv-crm-copy"><span>ADDITIONAL NOTE</span><p><?= nl2br(coveted_e((string)$request['message'])) ?></p></div>
                <?php endif; ?>

                <?php if ($sourceKeys || !empty($request['how_heard']) || $genderLabel !== '' || $socialLinks): ?>
                    <div class="cv-crm-profile-meta">
                        <?php if ($sourceKeys): ?>
                            <div class="cv-crm-profile-row"><strong>Source</strong><span><?= coveted_e(implode(', ', array_values(array_filter(array_map(static fn(string $key): ?string => $sourceOptions[$key] ?? null, $sourceKeys))))) ?></span></div>
                        <?php elseif (!empty($request['how_heard'])): ?>
                            <div class="cv-crm-profile-row"><strong>Source</strong><span><?= coveted_e((string)$request['how_heard']) ?></span></div>
                        <?php endif; ?>
                        <?php if ($genderLabel !== ''): ?>
                            <div class="cv-crm-profile-row"><strong>Gender</strong><span><?= coveted_e($genderLabel) ?></span></div>
                        <?php endif; ?>
                        <?php if ($socialLinks): ?>
                            <div class="cv-crm-profile-row">
                                <strong>Links</strong>
                                <div class="cv-crm-profile-links">
                                    <?php foreach ($socialLinks as $key => $url): ?>
                                        <a href="<?= coveted_e($url) ?>" target="_blank" rel="noopener noreferrer"><?= coveted_e($linkLabels[$key] ?? ucfirst(str_replace('_', ' ', $key))) ?> ↗</a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($request['converted_user_id'])): ?>
                    <div class="cv-crm-inline-meta"><strong>User</strong><span><?= coveted_e((string)($request['converted_user_name'] ?? 'Coveted user')) ?></span></div>
                <?php endif; ?>
            </div>

            <div class="cv-crm-record-actions">
                <?php if ($request['status'] !== 'converted'): ?>
                    <form method="post" class="cv-crm-review-form">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="crm_update">
                        <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                        <label>
                            CRM status
                            <select name="status">
                                <?php foreach (['new' => 'New', 'contacted' => 'Contacted', 'qualified' => 'Qualified', 'declined' => 'Declined'] as $key => $label): ?>
                                    <option value="<?= coveted_e($key) ?>" <?= $request['status'] === $key ? 'selected' : '' ?>><?= coveted_e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            Admin note
                            <textarea name="admin_note" maxlength="3000" rows="3" placeholder="Private CRM note"><?= coveted_e((string)($request['admin_note'] ?? '')) ?></textarea>
                        </label>
                        <button class="cv-button cv-button-soft" type="submit">Save CRM</button>
                    </form>

                    <form method="post" class="cv-crm-convert-form" onsubmit="return confirm('Convert this submission into a Coveted user account?');">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="crm_convert">
                        <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                        <button class="cv-button cv-button-primary" type="submit">Convert to User</button>
                        <small><?= $isNewsletter ? 'Name, email and city will carry into the member record if you decide to convert this subscriber.' : 'Name, email, city, event interests and submitted profile details will carry into the member intake record.' ?></small>
                    </form>
                <?php else: ?>
                    <div class="cv-crm-converted-state">
                        <span>USER ACCOUNT</span>
                        <strong>Converted</strong>
                        <small>This CRM submission is locked to preserve conversion history.</small>
                    </div>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</section>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>