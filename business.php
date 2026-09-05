<?php
declare(strict_types=1);

require_once __DIR__ . '/app/campaigns.php';
require_once __DIR__ . '/app/outcomes.php';
require_once __DIR__ . '/app/admin_ui.php';

$user = coveted_require_user();
$isSystemAdmin = coveted_is_system_admin($user);
$error = '';
$message = '';

$tabs = [
    'overview' => 'Overview',
    'locations' => 'Locations',
    'rewards' => 'Rewards',
    'campaigns' => 'Campaigns',
    'claims' => 'Claims',
    'insights' => 'Insights',
    'admins' => 'Admins',
];

$tab = strtolower(trim((string)($_GET['tab'] ?? $_POST['tab'] ?? 'overview')));
if (!isset($tabs[$tab])) {
    $tab = 'overview';
}

$requestedBusiness = trim((string)($_GET['business'] ?? $_POST['business'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'create_business') {
            $created = coveted_business_create(
                $user,
                (string)($_POST['name'] ?? ''),
                (string)($_POST['description'] ?? '')
            );
            coveted_redirect('/business.php?business=' . rawurlencode($created['public_id']) . '&saved=business');
        }

        $business = coveted_business_resolve_context($user, $requestedBusiness);
        if (!$business || !coveted_business_actor_can_manage($user, (int)$business['id'])) {
            throw new InvalidArgumentException('Business Admin access is required.');
        }

        $businessId = (int)$business['id'];
        $businessRef = (string)$business['public_id'];
        $return = static function (string $targetTab, string $saved) use ($businessRef): never {
            coveted_redirect(
                '/business.php?business=' . rawurlencode($businessRef)
                . '&tab=' . rawurlencode($targetTab)
                . '&saved=' . rawurlencode($saved)
            );
        };

        switch ($action) {
            case 'create_location':
                coveted_location_create($user, $businessId, $_POST);
                $return('locations', 'location');

            case 'create_claim_code':
                coveted_claim_code_create($user, $businessId, $_POST);
                $return('locations', 'code');

            case 'rotate_claim_code':
                coveted_claim_code_rotate(
                    $user,
                    (int)($_POST['claim_code_id'] ?? 0),
                    (string)($_POST['claim_code'] ?? '')
                );
                $return('locations', 'code');

            case 'create_reward':
                $reward = coveted_reward_create_template($user, [
                    'owner_type' => 'business',
                    'owner_id' => $businessId,
                    'title' => $_POST['title'] ?? '',
                    'description' => $_POST['description'] ?? '',
                    'reward_type' => $_POST['reward_type'] ?? 'custom',
                    'claim_mode' => $_POST['claim_mode'] ?? 'location_code',
                    'value_amount' => ($_POST['value_amount'] ?? '') !== '' ? $_POST['value_amount'] : null,
                    'value_text' => $_POST['value_text'] ?? '',
                    'cover_url' => $_POST['cover_url'] ?? '',
                    'status' => $_POST['status'] ?? 'draft',
                ]);

                $mediaUrl = trim((string)($_POST['media_url'] ?? ''));
                if ($mediaUrl !== '') {
                    coveted_reward_replace_media($user, $reward['public_id'], [[
                        'media_type' => $_POST['media_type'] ?? 'audio',
                        'title' => $_POST['media_title'] ?? '',
                        'media_url' => $mediaUrl,
                        'mime_type' => $_POST['mime_type'] ?? '',
                    ]]);
                }
                $return('rewards', 'reward');

            case 'reward_status':
                coveted_reward_set_status(
                    $user,
                    (string)($_POST['reward_id'] ?? ''),
                    (string)($_POST['status'] ?? '')
                );
                $return('rewards', 'reward');

            case 'create_campaign':
                coveted_campaign_create($user, [
                    'owner_type' => 'business',
                    'owner_id' => $businessId,
                    'reward_template' => $_POST['reward_template'] ?? '',
                    'title' => $_POST['title'] ?? '',
                    'campaign_type' => $_POST['campaign_type'] ?? 'manual',
                    'trigger_key' => $_POST['trigger_key'] ?? 'manual',
                    'location_id' => ($_POST['location_id'] ?? '') !== '' ? $_POST['location_id'] : null,
                    'quantity_limit' => ($_POST['quantity_limit'] ?? '') !== '' ? $_POST['quantity_limit'] : null,
                    'per_user_limit' => ($_POST['per_user_limit'] ?? '') !== '' ? $_POST['per_user_limit'] : 1,
                    'status' => $_POST['status'] ?? 'draft',
                ]);
                $return('campaigns', 'campaign');

            case 'campaign_status':
                coveted_campaign_set_status(
                    $user,
                    (string)($_POST['campaign_id'] ?? ''),
                    (string)($_POST['status'] ?? '')
                );
                $return('campaigns', 'campaign');

            case 'refund_claim':
                coveted_reward_refund_claim(
                    $user,
                    (string)($_POST['claim_id'] ?? ''),
                    (string)($_POST['refund_reason'] ?? '')
                );
                $return('claims', 'refund');

            case 'add_admin':
                $email = strtolower(trim((string)($_POST['admin_email'] ?? '')));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Enter a valid Coveted account email.');
                }

                $stmt = coveted_db()->prepare(
                    "SELECT id FROM users WHERE email = ? AND status = 'active' LIMIT 1"
                );
                $stmt->execute([$email]);
                $adminUserId = (int)$stmt->fetchColumn();
                if ($adminUserId < 1) {
                    throw new InvalidArgumentException('No active Coveted account was found for that email.');
                }

                coveted_business_add_admin($user, $businessId, $adminUserId);
                $return('admins', 'admin');

            default:
                throw new InvalidArgumentException('Unsupported business action.');
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted business workspace error: ' . $e->getMessage());
        $error = 'Unable to complete that business action right now.';
    }
}

$savedMessages = [
    'business' => 'Business created.',
    'location' => 'Location saved.',
    'code' => 'Claim code saved.',
    'reward' => 'Reward saved.',
    'campaign' => 'Campaign saved.',
    'refund' => 'Claim refunded. The reward returned to the member Inbox if still valid.',
    'admin' => 'Business Admin added.',
];
$saved = trim((string)($_GET['saved'] ?? ''));
if (isset($savedMessages[$saved])) {
    $message = $savedMessages[$saved];
}

$businesses = coveted_businesses_for_actor($user);
$business = null;
try {
    $business = coveted_business_resolve_context($user, $requestedBusiness);
} catch (InvalidArgumentException $e) {
    $error = $error ?: $e->getMessage();
}

$formatBusinessTime = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    return coveted_utc_datetime($value)->setTimezone(coveted_timezone())->format('M j, Y · g:i A');
};

if ($isSystemAdmin) {
    coveted_page_start('Business', '', true);
    coveted_admin_ui_start(
        $user,
        'businesses',
        $business ? (string)$business['name'] : 'Business Workspace'
    );
} else {
    coveted_page_start('Business');
}
?>
<section class="cv-page-heading">
    <span class="cv-eyebrow">BUSINESS WORKSPACE</span>
    <h1><?= $business ? coveted_e($business['name']) : 'Business operations' ?></h1>
    <p>Locations, claim identities, rewards, campaigns and measurable partner outcomes in one scoped workspace.</p>
</section>

<?php if ($message !== ''): ?><div class="cv-alert"><?= coveted_e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>

<?php if (!$business): ?>
    <?php if ($isSystemAdmin): ?>
        <form id="create-business" class="cv-card cv-form cv-narrow-form cv-anchor-target" method="post">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="action" value="create_business">
            <span class="cv-eyebrow">NEW BUSINESS</span>
            <h2>Create the first business</h2>
            <p>Business Admin access is resource-scoped. Create the business first, then assign its administrators.</p>
            <label>Business name<input name="name" maxlength="180" required></label>
            <label>Description<textarea name="description" maxlength="4000" rows="5"></textarea></label>
            <button class="cv-button cv-button-primary" type="submit">Create Business</button>
        </form>
    <?php else: ?>
        <div class="cv-card cv-empty">
            <h2>No business assigned.</h2>
            <p>A Coveted System Admin must assign your account as a Business Admin before you can manage business data.</p>
            <a class="cv-text-link" href="/profile.php">Back to Profile →</a>
        </div>
    <?php endif; ?>
    <?php
    if ($isSystemAdmin) {
        coveted_admin_ui_end();
    }
    coveted_page_end();
    exit;
    ?>
<?php endif; ?>

<?php
$businessId = (int)$business['id'];
$businessRef = (string)$business['public_id'];
$permission = coveted_business_actor_permission($user, $businessId) ?? 'business_admin';
$locations = [];
$claimCodes = [];
$rewards = [];
$campaigns = [];
$claims = [];
$businessAdmins = [];
$insights = null;
$insightPeriod = trim((string)($_GET['period'] ?? '90'));
$overview = [
    'locations' => 0,
    'active_campaigns' => 0,
    'rewards' => 0,
    'active_claims' => 0,
];

if ($tab === 'overview') {
    $overviewStmt = coveted_db()->prepare(
        "SELECT
            (SELECT COUNT(*) FROM locations l WHERE l.business_id = ? AND l.status <> 'archived') AS locations,
            (SELECT COUNT(*) FROM campaigns c WHERE c.business_id = ? AND c.status = 'active') AS active_campaigns,
            (SELECT COUNT(*) FROM reward_templates rt WHERE rt.business_id = ? AND rt.status <> 'archived') AS rewards,
            (SELECT COUNT(*)
             FROM reward_claims rc
             JOIN reward_issuances ri ON ri.id = rc.reward_issuance_id
             JOIN reward_templates rt ON rt.id = ri.reward_template_id
             WHERE rt.business_id = ? AND rc.status = 'claimed') AS active_claims"
    );
    $overviewStmt->execute([$businessId, $businessId, $businessId, $businessId]);
    $overview = $overviewStmt->fetch() ?: $overview;
}

if (in_array($tab, ['locations', 'campaigns'], true)) {
    $locations = coveted_locations_for_business($businessId);
}
if ($tab === 'locations') {
    $claimCodes = coveted_claim_codes_for_business($businessId);
}
if (in_array($tab, ['rewards', 'campaigns'], true)) {
    $rewards = coveted_reward_templates_for_owner('business', $businessId);
}
if ($tab === 'campaigns') {
    $campaigns = coveted_campaigns_for_owner('business', $businessId);
}
if ($tab === 'claims') {
    $claims = coveted_reward_claims_for_business($businessId);
}
if ($tab === 'insights') {
    try {
        $insights = coveted_business_outcomes($user, $businessId, $insightPeriod);
        $insightPeriod = (string)$insights['period']['key'];
    } catch (InvalidArgumentException $e) {
        $error = $error ?: $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted business insights error: ' . $e->getMessage());
        $error = $error ?: 'Unable to load business insights right now.';
    }
}
if ($tab === 'admins') {
    $adminsStmt = coveted_db()->prepare(
        "SELECT u.display_name, u.email, ba.created_at
         FROM business_admins ba
         JOIN users u ON u.id = ba.user_id
         WHERE ba.business_id = ?
         ORDER BY u.display_name, u.id"
    );
    $adminsStmt->execute([$businessId]);
    $businessAdmins = $adminsStmt->fetchAll();
}
?>

<div class="cv-section-head">
    <div>
        <span class="cv-eyebrow">CURRENT BUSINESS</span>
        <h2><?= coveted_e($business['name']) ?></h2>
        <div class="cv-meta-row">
            <span><?= coveted_e(ucwords(str_replace('_', ' ', (string)$permission))) ?></span>
            <span><?= coveted_e(ucfirst((string)$business['status'])) ?></span>
            <?php if (!empty($business['description'])): ?><span><?= coveted_e(mb_strimwidth((string)$business['description'], 0, 110, '…')) ?></span><?php endif; ?>
        </div>
    </div>

    <?php if (count($businesses) > 1): ?>
        <form class="cv-business-selector" method="get">
            <input type="hidden" name="tab" value="<?= coveted_e($tab) ?>">
            <?php if ($tab === 'insights'): ?><input type="hidden" name="period" value="<?= coveted_e($insightPeriod) ?>"><?php endif; ?>
            <label>
                <span>Switch business</span>
                <select name="business" data-submit-on-change>
                    <?php foreach ($businesses as $item): ?>
                        <option value="<?= coveted_e($item['public_id']) ?>" <?= (int)$item['id'] === $businessId ? 'selected' : '' ?>><?= coveted_e($item['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
    <?php endif; ?>
</div>

<nav class="cv-tab-row" aria-label="Business workspace">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="cv-tab <?= $tab === $key ? 'is-active' : '' ?>" href="/business.php?business=<?= coveted_e($businessRef) ?>&amp;tab=<?= coveted_e($key) ?>"><?= coveted_e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'overview'): ?>
    <section class="cv-stat-grid cv-home-stats" aria-label="Business summary">
        <a class="cv-card cv-stat" href="/business.php?business=<?= coveted_e($businessRef) ?>&amp;tab=locations"><strong><?= (int)$overview['locations'] ?></strong><span>Locations</span></a>
        <a class="cv-card cv-stat" href="/business.php?business=<?= coveted_e($businessRef) ?>&amp;tab=campaigns"><strong><?= (int)$overview['active_campaigns'] ?></strong><span>Active campaigns</span></a>
        <a class="cv-card cv-stat" href="/business.php?business=<?= coveted_e($businessRef) ?>&amp;tab=rewards"><strong><?= (int)$overview['rewards'] ?></strong><span>Rewards</span></a>
        <a class="cv-card cv-stat" href="/business.php?business=<?= coveted_e($businessRef) ?>&amp;tab=claims"><strong><?= (int)$overview['active_claims'] ?></strong><span>Active claims</span></a>
    </section>

    <div class="cv-two-column">
        <article class="cv-card cv-feature-card cv-copy-card">
            <span class="cv-kicker">REWARD LIFECYCLE</span>
            <h2>Prepare the value. Verify the visit.</h2>
            <p>Business Admins create rewards, campaign rules and location claim identities. System Admin controls platform distribution; the business verifies redemption when the member arrives.</p>
            <div class="cv-tag-row">
                <span class="cv-pill">Scoped administration</span>
                <span class="cv-pill">Location verification</span>
                <span class="cv-pill">Permanent claim history</span>
            </div>
        </article>

        <aside class="cv-stack">
            <article class="cv-card cv-copy-card">
                <span class="cv-eyebrow">NEXT SETUP STEP</span>
                <h2><?= (int)$overview['locations'] === 0 ? 'Add a location.' : ((int)$overview['rewards'] === 0 ? 'Create a reward.' : ((int)$overview['active_campaigns'] === 0 ? 'Prepare a campaign.' : 'Ready for member activity.')) ?></h2>
                <p><?= (int)$overview['locations'] === 0
                    ? 'Locations anchor claim codes and physical redemption.'
                    : ((int)$overview['rewards'] === 0
                        ? 'Rewards define the actual value members receive.'
                        : ((int)$overview['active_campaigns'] === 0
                            ? 'Campaigns define when and where an active reward can be distributed.'
                            : 'Use Claims to review verified redemptions and issue refunds when needed.')) ?></p>
            </article>
        </aside>
    </div>
<?php endif; ?>

<?php if ($tab === 'insights' && $insights): ?>
    <?php $summary = $insights['summary']; ?>
    <div class="cv-section-head">
        <div>
            <span class="cv-eyebrow">PARTNER OUTCOMES</span>
            <h2>What Coveted gatherings create after the door opens</h2>
            <p>Aggregate member outcomes only. No attendee identity list is exposed here.</p>
        </div>
        <form class="cv-business-selector" method="get">
            <input type="hidden" name="business" value="<?= coveted_e($businessRef) ?>">
            <input type="hidden" name="tab" value="insights">
            <label>
                <span>Measurement period</span>
                <select name="period" data-submit-on-change>
                    <?php foreach (coveted_outcome_periods() as $periodKey => $periodLabel): ?>
                        <option value="<?= coveted_e($periodKey) ?>" <?= $insightPeriod === $periodKey ? 'selected' : '' ?>><?= coveted_e($periodLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
    </div>

    <section class="cv-stat-grid cv-home-stats" aria-label="Business outcome summary">
        <article class="cv-card cv-stat"><strong><?= (int)$summary['completed_events'] ?></strong><span>Completed gatherings</span></article>
        <article class="cv-card cv-stat"><strong><?= (int)$summary['verified_visits'] ?></strong><span>Verified event visits</span></article>
        <article class="cv-card cv-stat"><strong><?= (int)$summary['repeat_attendees'] ?></strong><span>Repeat attendees</span></article>
        <article class="cv-card cv-stat"><strong><?= coveted_e(number_format((float)$summary['repeat_rate'], 1)) ?>%</strong><span>Repeat attendance</span></article>
        <article class="cv-card cv-stat"><strong><?= (int)$summary['benefits_issued'] ?></strong><span>Benefits delivered</span></article>
        <article class="cv-card cv-stat"><strong><?= (int)$summary['claims'] ?></strong><span>Benefit claims</span></article>
        <article class="cv-card cv-stat"><strong><?= (int)$summary['verified_returns'] ?></strong><span>Verified returns</span></article>
        <article class="cv-card cv-stat"><strong><?= coveted_e(number_format((float)$summary['return_rate'], 1)) ?>%</strong><span>Members who returned</span></article>
    </section>

    <section class="cv-two-column">
        <article class="cv-card cv-copy-card">
            <span class="cv-kicker">RETURN VALUE</span>
            <h2><?= (int)$summary['returning_members'] ?> member<?= (int)$summary['returning_members'] === 1 ? '' : 's' ?> made a verified return.</h2>
            <p>A return is counted only when Coveted's canonical return engine confirms a later-date claim at the same venue after verified attendance at a completed gathering with partner benefits enabled.</p>
            <div class="cv-tag-row">
                <span class="cv-pill"><?= (int)$summary['guest_returns'] ?> guest return<?= (int)$summary['guest_returns'] === 1 ? '' : 's' ?></span>
                <span class="cv-pill"><?= (int)$summary['groups_hosted'] ?> group<?= (int)$summary['groups_hosted'] === 1 ? '' : 's' ?> hosted</span>
                <span class="cv-pill"><?= coveted_e(number_format((float)$summary['claim_rate'], 1)) ?>% issuance-to-claim</span>
            </div>
        </article>
        <aside class="cv-card cv-copy-card">
            <span class="cv-kicker">RELATIONSHIP DEPTH</span>
            <h2>Group → venue partnerships</h2>
            <div class="cv-role-request-list">
                <?php foreach ([
                    'home_venue' => 'Home Venue',
                    'preferred_partner' => 'Preferred Partner',
                    'partner' => 'Partner',
                    'event_venue' => 'Event Venue',
                    'new' => 'New',
                ] as $relationshipKey => $relationshipLabel): ?>
                    <div class="cv-mini-row"><div><strong><?= (int)($insights['relationship_counts'][$relationshipKey] ?? 0) ?></strong><span><?= coveted_e($relationshipLabel) ?></span></div></div>
                <?php endforeach; ?>
            </div>
            <a class="cv-text-link" href="/venue-relationships.php?business=<?= coveted_e($businessRef) ?>">Open Relationships →</a>
        </aside>
    </section>

    <section class="cv-card cv-table-card">
        <div class="cv-section-heading"><span class="cv-kicker">LOCATION OUTCOMES</span><h2>Where the relationship compounds</h2></div>
        <?php if (!$insights['locations']): ?>
            <p>No active business locations are available.</p>
        <?php else: ?>
            <div class="cv-table-wrap"><table class="cv-table">
                <thead><tr><th>Location</th><th>Events</th><th>Visits</th><th>People</th><th>Claims</th><th>Returns</th><th>Guest returns</th></tr></thead>
                <tbody>
                <?php foreach ($insights['locations'] as $location): ?>
                    <tr>
                        <td><strong><?= coveted_e($location['name']) ?></strong><br><small><?= coveted_e(trim((string)($location['city'] ?? '') . (($location['city'] ?? '') && ($location['region'] ?? '') ? ', ' : '') . (string)($location['region'] ?? '')) ?: ucfirst((string)$location['status'])) ?></small></td>
                        <td><?= (int)$location['completed_events'] ?></td>
                        <td><?= (int)$location['verified_visits'] ?></td>
                        <td><?= (int)$location['unique_attendees'] ?></td>
                        <td><?= (int)$location['claims'] ?><?php if ((int)$location['refunds'] > 0): ?><br><small><?= (int)$location['refunds'] ?> refunded</small><?php endif; ?></td>
                        <td><?= (int)$location['verified_returns'] ?></td>
                        <td><?= (int)$location['guest_returns'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <section class="cv-card cv-table-card">
        <div class="cv-section-heading"><span class="cv-kicker">CAMPAIGN OUTCOMES</span><h2>Which benefits get used</h2></div>
        <?php if (!$insights['campaigns']): ?>
            <p>No non-archived business campaigns yet.</p>
        <?php else: ?>
            <div class="cv-table-wrap"><table class="cv-table">
                <thead><tr><th>Campaign</th><th>Trigger</th><th>Delivered</th><th>Members</th><th>Used</th><th>Use rate</th><th>Refunds</th></tr></thead>
                <tbody>
                <?php foreach ($insights['campaigns'] as $campaign): ?>
                    <tr>
                        <td><strong><?= coveted_e($campaign['title']) ?></strong><br><small><?= coveted_e($campaign['reward_title']) ?> · <?= coveted_e(ucfirst((string)$campaign['status'])) ?></small></td>
                        <td><?= coveted_e(ucwords(str_replace('_', ' ', (string)$campaign['trigger_key']))) ?></td>
                        <td><?= (int)$campaign['issued_count'] ?></td>
                        <td><?= (int)$campaign['members_reached'] ?></td>
                        <td><?= (int)$campaign['use_count'] ?></td>
                        <td><?= coveted_e(number_format((float)$campaign['use_rate'], 1)) ?>%</td>
                        <td><?= (int)$campaign['refund_count'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($tab === 'locations'): ?>
    <div class="cv-section-head">
        <div><span class="cv-eyebrow">LOCATIONS & CLAIM CODES</span><h2>Where benefits are verified</h2></div>
        <span class="cv-status"><?= count($locations) ?> locations · <?= count($claimCodes) ?> codes</span>
    </div>

    <section class="cv-workspace-grid">
        <form class="cv-card cv-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="action" value="create_location">
            <input type="hidden" name="business" value="<?= coveted_e($businessRef) ?>">
            <span class="cv-eyebrow">LOCATION</span>
            <h2>Add location</h2>
            <label>Name<input name="name" maxlength="180" required></label>
            <label>Address<input name="address1" maxlength="255"></label>
            <div class="cv-form-row">
                <label>City<input name="city" maxlength="160"></label>
                <label>State / Region<input name="region" maxlength="160"></label>
            </div>
            <label>Timezone<input name="timezone" value="<?= coveted_e((string)(coveted_config('app')['default_timezone'] ?? 'UTC')) ?>" maxlength="64" required></label>
            <button class="cv-button cv-button-primary" type="submit">Add Location</button>
        </form>

        <form class="cv-card cv-form" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="action" value="create_claim_code">
            <input type="hidden" name="business" value="<?= coveted_e($businessRef) ?>">
            <span class="cv-eyebrow">VERIFICATION IDENTITY</span>
            <h2>Create claim code</h2>
            <p>Claim codes verify redemption. They are not employee accounts or login credentials.</p>
            <label>Code type<select name="code_type"><option value="location">Location</option><option value="employee">Employee</option></select></label>
            <label>Label<input name="label" maxlength="180" placeholder="Front Desk or Sarah" required></label>
            <label>Location
                <select name="location_id">
                    <option value="">All locations (employee codes only)</option>
                    <?php foreach ($locations as $location): ?><option value="<?= (int)$location['id'] ?>"><?= coveted_e($location['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Claim code<input name="claim_code" type="password" minlength="5" maxlength="10" pattern="[A-Za-z0-9]{5,10}" autocomplete="off" required></label>
            <button class="cv-button cv-button-primary" type="submit">Create Claim Code</button>
        </form>
    </section>

    <section class="cv-card cv-table-card">
        <div class="cv-section-heading"><span class="cv-kicker">LOCATIONS</span><h2>Business location roster</h2></div>
        <?php if (!$locations): ?>
            <p>No locations yet.</p>
        <?php else: ?>
            <div class="cv-table-wrap"><table class="cv-table">
                <thead><tr><th>Location</th><th>City / Region</th><th>Timezone</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($locations as $location): ?>
                    <tr>
                        <td><strong><?= coveted_e($location['name']) ?></strong><?php if (!empty($location['address1'])): ?><br><small><?= coveted_e($location['address1']) ?></small><?php endif; ?></td>
                        <td><?= coveted_e(trim((string)($location['city'] ?? '') . (($location['city'] ?? '') && ($location['region'] ?? '') ? ', ' : '') . (string)($location['region'] ?? '')) ?: 'Not set') ?></td>
                        <td><?= coveted_e($location['timezone']) ?></td>
                        <td><span class="cv-status"><?= coveted_e(ucfirst($location['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <section class="cv-card cv-table-card">
        <div class="cv-section-heading"><span class="cv-kicker">CLAIM CODES</span><h2>Verification identities</h2></div>
        <?php if (!$claimCodes): ?>
            <p>No claim codes yet. Create one after a location is ready for redemption.</p>
        <?php else: ?>
            <div class="cv-table-wrap"><table class="cv-table">
                <thead><tr><th>Label</th><th>Type</th><th>Location</th><th>Status</th><th>Rotate</th></tr></thead>
                <tbody>
                <?php foreach ($claimCodes as $code): ?>
                    <tr>
                        <td><?= coveted_e($code['label']) ?></td>
                        <td><?= coveted_e(ucfirst($code['code_type'])) ?></td>
                        <td><?= coveted_e($code['location_name'] ?: 'All business locations') ?></td>
                        <td><span class="cv-status"><?= coveted_e(ucfirst($code['status'])) ?></span></td>
                        <td>
                            <form class="cv-inline-form" method="post" autocomplete="off">
                                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                <input type="hidden" name="action" value="rotate_claim_code">
                                <input type="hidden" name="business" value="<?= coveted_e($businessRef) ?>">
                                <input type="hidden" name="claim_code_id" value="<?= (int)$code['id'] ?>">
                                <input class="cv-compact-input" name="claim_code" type="password" minlength="5" maxlength="10" pattern="[A-Za-z0-9]{5,10}" placeholder="New code" required>
                                <button class="cv-button cv-button-soft" type="submit">Rotate</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($tab === 'rewards'): ?>
    <div class="cv-section-head"><div><span class="cv-eyebrow">REWARDS</span><h2>Value members can receive</h2></div><span class="cv-status"><?= count($rewards) ?> total</span></div>
    <section class="cv-workspace-grid">
        <form class="cv-card cv-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="action" value="create_reward">
            <input type="hidden" name="business" value="<?= coveted_e($businessRef) ?>">
            <span class="cv-eyebrow">NEW REWARD</span>
            <h2>Create reward</h2>
            <label>Title<input name="title" maxlength="190" required></label>
            <label>Description<textarea name="description" maxlength="4000" rows="4"></textarea></label>
            <div class="cv-form-row">
                <label>Type<select name="reward_type">
                    <?php foreach (['credit','free_item','discount','perk','access','service','audio','video','media_pack','experience','custom'] as $type): ?><option value="<?= coveted_e($type) ?>"><?= coveted_e(ucwords(str_replace('_', ' ', $type))) ?></option><?php endforeach; ?>
                </select></label>
                <label>Claim mode<select name="claim_mode"><option value="location_code">Business claim code</option><option value="none">No physical claim</option></select></label>
            </div>
            <div class="cv-form-row">
                <label>Value amount<input name="value_amount" type="number" min="0" step="0.01"></label>
                <label>Value text<input name="value_text" maxlength="255" placeholder="Complimentary appetizer"></label>
            </div>
            <label>Cover image URL<input name="cover_url" type="url" maxlength="700"></label>
            <details class="cv-form-details">
                <summary>Optional media</summary>
                <label>Media type<select name="media_type"><option value="audio">Audio</option><option value="video">Video</option><option value="file">File</option><option value="image">Image</option></select></label>
                <label>Media title<input name="media_title" maxlength="190"></label>
                <label>Media URL<input name="media_url" type="url" maxlength="1000"></label>
                <label>MIME type<input name="mime_type" maxlength="120" placeholder="audio/mpeg"></label>
            </details>
            <label>Initial status<select name="status"><option value="draft">Draft</option><option value="active">Active</option></select></label>
            <button class="cv-button cv-button-primary" type="submit">Create Reward</button>
        </form>

        <div class="cv-stack">
            <?php if (!$rewards): ?><div class="cv-card cv-empty"><h3>No rewards yet.</h3><p>Create the value before building a campaign around it.</p></div><?php endif; ?>
            <?php foreach ($rewards as $reward): ?>
                <article class="cv-card cv-admin-item">
                    <div class="cv-tag-row"><span class="cv-kicker"><?= coveted_e(strtoupper(str_replace('_', ' ', $reward['reward_type']))) ?></span><span class="cv-status"><?= coveted_e(ucfirst($reward['status'])) ?></span></div>
                    <h3><?= coveted_e($reward['title']) ?></h3>
                    <p><?= coveted_e(str_replace('_', ' ', $reward['claim_mode'])) ?><?php if (!empty($reward['value_text'])): ?> · <?= coveted_e($reward['value_text']) ?><?php elseif ($reward['value_amount'] !== null): ?> · $<?= coveted_e(number_format((float)$reward['value_amount'], 2)) ?><?php endif; ?></p>
                    <form class="cv-inline-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="reward_status">
                        <input type="hidden" name="business" value="<?= coveted_e($businessRef) ?>">
                        <input type="hidden" name="reward_id" value="<?= coveted_e($reward['public_id']) ?>">
                        <select name="status" aria-label="Reward status for <?= coveted_e($reward['title']) ?>">
                            <?php foreach (['active','paused','draft','archived'] as $status): ?><option value="<?= $status ?>" <?= $reward['status'] === $status ? 'selected' : '' ?>><?= coveted_e(ucfirst($status)) ?></option><?php endforeach; ?>
                        </select>
                        <button class="cv-button cv-button-soft" type="submit">Update Status</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($tab === 'campaigns'): ?>
    <div class="cv-section-head"><div><span class="cv-eyebrow">CAMPAIGNS</span><h2>Rules for distribution</h2></div><span class="cv-status"><?= count($campaigns) ?> total</span></div>
    <section class="cv-workspace-grid">
        <form class="cv-card cv-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="action" value="create_campaign">
            <input type="hidden" name="business" value="<?= coveted_e($businessRef) ?>">
            <span class="cv-eyebrow">NEW CAMPAIGN</span>
            <h2>Create campaign</h2>
            <?php if (!$rewards): ?>
                <p>Create a reward before creating a campaign.</p>
            <?php else: ?>
                <label>Title<input name="title" maxlength="190" required></label>
                <label>Reward<select name="reward_template" required><option value="">Select reward</option><?php foreach ($rewards as $reward): ?><option value="<?= coveted_e($reward['public_id']) ?>"><?= coveted_e($reward['title']) ?></option><?php endforeach; ?></select></label>
                <div class="cv-form-row">
                    <label>Campaign type<select name="campaign_type"><option value="manual">Manual</option><option value="attendance">Attendance</option><option value="event_completion">Event completion</option><option value="return_visit">Return visit</option><option value="guest_return">Guest return</option><option value="random_reward">Random reward</option><option value="mystery_unlock">Mystery unlock</option></select></label>
                    <label>Trigger<select name="trigger_key"><option value="manual">Manual</option><option value="attendance">Attendance</option><option value="completion">Completion</option><option value="return_visit">Return visit</option><option value="guest_return">Guest return</option><option value="random_reward">Random reward</option><option value="mystery_unlock">Mystery unlock</option></select></label>
                </div>
                <label>Restrict to location<select name="location_id"><option value="">Any business location</option><?php foreach ($locations as $location): ?><option value="<?= (int)$location['id'] ?>"><?= coveted_e($location['name']) ?></option><?php endforeach; ?></select></label>
                <div class="cv-form-row"><label>Total quantity<input name="quantity_limit" type="number" min="1"></label><label>Per-member limit<input name="per_user_limit" type="number" min="1" value="1"></label></div>
                <label>Initial status<select name="status"><option value="draft">Draft</option><option value="active">Active</option></select></label>
                <button class="cv-button cv-button-primary" type="submit">Create Campaign</button>
            <?php endif; ?>
        </form>

        <div class="cv-stack">
            <?php if (!$campaigns): ?><div class="cv-card cv-empty"><h3>No campaigns yet.</h3><p>Campaigns connect active rewards to eligibility and distribution rules.</p></div><?php endif; ?>
            <?php foreach ($campaigns as $campaign): ?>
                <article class="cv-card cv-admin-item">
                    <div class="cv-tag-row"><span class="cv-kicker"><?= coveted_e(strtoupper(str_replace('_', ' ', $campaign['trigger_key']))) ?></span><span class="cv-status"><?= coveted_e(ucfirst($campaign['status'])) ?></span></div>
                    <h3><?= coveted_e($campaign['title']) ?></h3>
                    <p><?= coveted_e($campaign['reward_title']) ?> · <?= coveted_e($campaign['location_name'] ?: 'All locations') ?></p>
                    <form class="cv-inline-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="campaign_status">
                        <input type="hidden" name="business" value="<?= coveted_e($businessRef) ?>">
                        <input type="hidden" name="campaign_id" value="<?= coveted_e($campaign['public_id']) ?>">
                        <select name="status" aria-label="Campaign status for <?= coveted_e($campaign['title']) ?>">
                            <?php foreach (['active','paused','draft','archived'] as $status): ?><option value="<?= $status ?>" <?= $campaign['status'] === $status ? 'selected' : '' ?>><?= coveted_e(ucfirst($status)) ?></option><?php endforeach; ?>
                        </select>
                        <button class="cv-button cv-button-soft" type="submit">Update Status</button>
                    </form>
                    <?php if ($campaign['status'] === 'active'): ?>
                        <p class="cv-form-help">Active and ready for System Admin distribution when this campaign should be sent.</p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($tab === 'claims'): ?>
    <div class="cv-section-head"><div><span class="cv-eyebrow">CLAIMS</span><h2>Verified redemption history</h2></div><span class="cv-status"><?= count($claims) ?> records</span></div>
    <section class="cv-card cv-table-card">
        <?php if (!$claims): ?>
            <div class="cv-empty"><h2>No claims yet.</h2><p>Verified redemptions and refund history will appear here.</p></div>
        <?php else: ?>
            <div class="cv-table-wrap"><table class="cv-table">
                <thead><tr><th>Member</th><th>Reward</th><th>Location</th><th>Verified by</th><th>Status</th><th>Claimed</th><th>Refund</th></tr></thead>
                <tbody>
                <?php foreach ($claims as $claim): ?>
                    <tr>
                        <td><?= coveted_e($claim['display_name']) ?><br><small><?= coveted_e($claim['email']) ?></small></td>
                        <td><?= coveted_e($claim['reward_title']) ?></td>
                        <td><?= coveted_e($claim['location_name']) ?></td>
                        <td><?= coveted_e($claim['claim_code_label']) ?><br><small><?= coveted_e(ucfirst((string)$claim['claim_code_type'])) ?></small></td>
                        <td><span class="cv-status"><?= coveted_e(ucfirst($claim['status'])) ?></span></td>
                        <td><?= coveted_e($formatBusinessTime((string)$claim['claimed_at'])) ?></td>
                        <td>
                            <?php if ($claim['status'] === 'claimed'): ?>
                                <form class="cv-refund-form" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="refund_claim">
                                    <input type="hidden" name="business" value="<?= coveted_e($businessRef) ?>">
                                    <input type="hidden" name="claim_id" value="<?= coveted_e($claim['public_id']) ?>">
                                    <input class="cv-compact-input" name="refund_reason" maxlength="500" placeholder="Reason (optional)">
                                    <button class="cv-button cv-button-soft" type="submit" data-confirm="Refund this claim and return the reward to the member Inbox if still valid?">Refund</button>
                                </form>
                            <?php else: ?>
                                <small>Refunded <?= coveted_e($formatBusinessTime($claim['refunded_at'] ?? null)) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($tab === 'admins'): ?>
    <div class="cv-section-head"><div><span class="cv-eyebrow">ADMINISTRATION</span><h2>Who can manage this business</h2></div><span class="cv-status"><?= count($businessAdmins) ?> scoped admins</span></div>
    <section class="cv-workspace-grid">
        <form class="cv-card cv-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="action" value="add_admin">
            <input type="hidden" name="business" value="<?= coveted_e($businessRef) ?>">
            <span class="cv-eyebrow">BUSINESS ADMIN</span>
            <h2>Add administrator</h2>
            <p>Business Admins can modify this business, locations, claim codes, rewards, campaigns, claims and refunds.</p>
            <label>Coveted account email<input name="admin_email" type="email" maxlength="255" required></label>
            <button class="cv-button cv-button-primary" type="submit">Add Business Admin</button>
        </form>

        <div class="cv-stack">
            <?php foreach ($businessAdmins as $businessAdmin): ?>
                <article class="cv-card cv-admin-item">
                    <div class="cv-tag-row"><span class="cv-kicker">BUSINESS ADMIN</span><span class="cv-status">Scoped</span></div>
                    <h3><?= coveted_e($businessAdmin['display_name']) ?></h3>
                    <p><?= coveted_e($businessAdmin['email']) ?></p>
                    <p class="cv-form-help">Assigned <?= coveted_e($formatBusinessTime((string)$businessAdmin['created_at'])) ?></p>
                </article>
            <?php endforeach; ?>
            <?php if (!$businessAdmins): ?><div class="cv-card cv-empty"><h3>No scoped Business Admins.</h3><p>System Admin can still manage this business globally.</p></div><?php endif; ?>
            <?php if ($isSystemAdmin): ?>
                <article class="cv-card cv-admin-item"><div class="cv-tag-row"><span class="cv-kicker">SYSTEM ADMIN</span><span class="cv-status">Global</span></div><h3>Coveted System Admin</h3><p>System Admin authority applies across all businesses and is not stored as a Business Admin assignment.</p></article>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php
if ($isSystemAdmin) {
    coveted_admin_ui_end();
}
coveted_page_end();
?>