<?php
declare(strict_types=1);

require_once __DIR__ . '/app/business_host_workspace.php';
require_once __DIR__ . '/app/benefit_sponsorships.php';

$user = coveted_require_user();
if (!coveted_business_host_has_access($user)) {
    http_response_code(403);
    coveted_page_start('Business Sponsorships', 'Events');
    ?>
    <section class="cv-page-heading"><span class="cv-eyebrow">BUSINESS BENEFITS</span><h1>Business Host access required.</h1></section>
    <div class="cv-card cv-empty"><p>Coveted Admin must assign this account to a business before sponsorship tools are available.</p></div>
    <?php coveted_page_end(); exit;
}

$businesses = coveted_business_host_businesses($user);
$businessRef = trim((string)($_GET['business'] ?? $_POST['business_ref'] ?? ''));
$business = null;
$error = '';
$notice = trim((string)($_GET['saved'] ?? '')) === 'submitted'
    ? 'Sponsorship proposal submitted to Coveted Admin.'
    : (trim((string)($_GET['saved'] ?? '')) === 'cancelled' ? 'Sponsorship proposal cancelled.' : '');

try {
    $business = coveted_business_host_resolve_business($user, $businessRef);
} catch (InvalidArgumentException $e) {
    $error = $e->getMessage();
    $business = $businesses[0] ?? null;
}

$normalizeLocal = static function (string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, coveted_timezone());
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))) {
        throw new InvalidArgumentException('Enter a valid sponsorship date and time.');
    }
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    coveted_require_csrf();
    try {
        if (!$business) throw new InvalidArgumentException('Choose a valid business first.');
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'submit_proposal') {
            coveted_benefit_sponsorship_create($user, (int)$business['id'], [
                'group_ref' => (string)($_POST['group_ref'] ?? ''),
                'location_ref' => (string)($_POST['location_ref'] ?? ''),
                'event_ref' => (string)($_POST['event_ref'] ?? ''),
                'program_title' => (string)($_POST['program_title'] ?? ''),
                'reward_title' => (string)($_POST['reward_title'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'reward_type' => (string)($_POST['reward_type'] ?? 'perk'),
                'claim_mode' => (string)($_POST['claim_mode'] ?? 'location_code'),
                'trigger_key' => (string)($_POST['trigger_key'] ?? 'attendance'),
                'quantity_limit' => (string)($_POST['quantity_limit'] ?? ''),
                'per_user_limit' => (string)($_POST['per_user_limit'] ?? '1'),
                'value_amount' => (string)($_POST['value_amount'] ?? ''),
                'value_text' => (string)($_POST['value_text'] ?? ''),
                'starts_at' => $normalizeLocal((string)($_POST['starts_at'] ?? '')),
                'ends_at' => $normalizeLocal((string)($_POST['ends_at'] ?? '')),
            ]);
            coveted_redirect('/business-sponsorships.php?business=' . rawurlencode((string)$business['public_id']) . '&saved=submitted');
        }
        if ($action === 'cancel_proposal') {
            coveted_benefit_sponsorship_cancel(
                $user,
                (int)$business['id'],
                (string)($_POST['proposal_ref'] ?? '')
            );
            coveted_redirect('/business-sponsorships.php?business=' . rawurlencode((string)$business['public_id']) . '&saved=cancelled');
        }
        throw new InvalidArgumentException('Unsupported sponsorship action.');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Business sponsorship workspace error: ' . $e->getMessage());
        $error = 'Unable to complete that sponsorship action right now.';
    }
}

$options = ['relationships' => [], 'events' => []];
$proposals = [];
$roi = ['summary' => [], 'programs' => [], 'privacy' => '', 'attribution_note' => ''];
if ($business) {
    try {
        $options = coveted_benefit_sponsorship_scope_options($user, (int)$business['id']);
        $proposals = coveted_benefit_sponsorship_list_for_business($user, (int)$business['id'], 100);
        $roi = coveted_benefit_sponsorship_roi_snapshot($user, (int)$business['id'], 50);
    } catch (Throwable $e) {
        error_log('Business sponsorship data load error: ' . $e->getMessage());
        $error = $error !== '' ? $error : 'Unable to load sponsorship data right now.';
    }
}

$prefillEventRef = trim((string)($_GET['event'] ?? ''));
$prefill = null;
foreach ($options['events'] as $eventOption) {
    if ((string)$eventOption['event_ref'] === $prefillEventRef) {
        $prefill = $eventOption;
        break;
    }
}
$formatMoney = static fn(float|int|string|null $value): string => $value === null || $value === '' ? '—' : '$' . number_format((float)$value, 2);
$summary = (array)($roi['summary'] ?? []);

coveted_page_start('Business Sponsorships', 'Events');
?>
<div class="cv-business-host">
    <section class="cv-business-host-hero">
        <div>
            <span class="cv-eyebrow">BENEFITS / SPONSORSHIP</span>
            <h1><?= $business ? coveted_e((string)$business['name']) : 'Partner-funded member value.' ?></h1>
            <p>Propose bounded perks for Coveted relationships and events, then review aggregate redemption and return behavior. Coveted System Admin controls approval, Benefit Program setup and launch.</p>
        </div>
        <?php if (count($businesses) > 1): ?>
            <form class="cv-business-host-switcher" method="get" action="/business-sponsorships.php">
                <label for="sponsor-business">Business</label>
                <select id="sponsor-business" name="business">
                    <?php foreach ($businesses as $candidate): ?>
                        <option value="<?= coveted_e((string)$candidate['public_id']) ?>" <?= $business && (int)$candidate['id'] === (int)$business['id'] ? 'selected' : '' ?>><?= coveted_e((string)$candidate['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="cv-button cv-button-soft" type="submit">Switch</button>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($notice !== ''): ?><div class="cv-business-host-notice" role="status"><?= coveted_e($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="cv-business-host-error" role="alert"><?= coveted_e($error) ?></div><?php endif; ?>

    <section class="cv-business-host-banner">
        <div><strong>Proposal, not event authority.</strong><p>Your business can commit value and propose where it applies. It cannot create events, choose audiences, assign itself to groups, or launch a Benefit Program.</p></div>
        <?php if ($business): ?><a class="cv-button cv-button-soft" href="/business-host.php?business=<?= coveted_e(rawurlencode((string)$business['public_id'])) ?>">Back to Business Host</a><?php endif; ?>
    </section>

    <section class="cv-business-host-stats" aria-label="Sponsorship overview">
        <div class="cv-business-host-stat"><span>Awaiting review</span><strong><?= (int)($summary['submitted'] ?? 0) ?></strong></div>
        <div class="cv-business-host-stat"><span>Accepted programs</span><strong><?= (int)($summary['converted'] ?? 0) ?></strong></div>
        <div class="cv-business-host-stat"><span>Rewards issued</span><strong><?= (int)($summary['issued'] ?? 0) ?></strong></div>
        <div class="cv-business-host-stat"><span>Rewards claimed</span><strong><?= (int)($summary['claimed'] ?? 0) ?></strong></div>
        <div class="cv-business-host-stat"><span>Claim rate</span><strong><?= number_format((float)($summary['claim_rate'] ?? 0), 1) ?>%</strong></div>
        <div class="cv-business-host-stat"><span>Verified returns</span><strong><?= (int)($summary['return_members'] ?? 0) ?></strong></div>
    </section>

    <?php if ($business): ?>
        <section class="cv-business-host-panel">
            <div class="cv-business-host-panel-head">
                <div><span class="cv-eyebrow">PROPOSE VALUE</span><h2>Submit a sponsored Benefit proposal</h2><p>Only benefit-enabled Coveted venue relationships are available. Event-driven triggers require an event at the selected relationship location.</p></div>
                <span class="cv-business-host-pill">Admin approval required</span>
            </div>

            <?php if (!$options['relationships']): ?>
                <div class="cv-business-host-empty"><p>No benefit-enabled venue relationship is available yet. Coveted Admin can enable benefits after the business has an established group/location relationship.</p></div>
            <?php else: ?>
                <form method="post" action="/business-sponsorships.php" class="cv-form-stack">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="action" value="submit_proposal">
                    <input type="hidden" name="business_ref" value="<?= coveted_e((string)$business['public_id']) ?>">
                    <div class="cv-form-grid">
                        <label><span>Group relationship</span><select name="group_ref" required>
                            <option value="">Choose group</option>
                            <?php foreach ($options['relationships'] as $relationship): ?>
                                <option value="<?= coveted_e((string)$relationship['group_public_id']) ?>" <?= $prefill && (string)$prefill['group_ref'] === (string)$relationship['group_public_id'] ? 'selected' : '' ?>><?= coveted_e((string)$relationship['group_name']) ?></option>
                            <?php endforeach; ?>
                        </select></label>
                        <label><span>Business location</span><select name="location_ref" required>
                            <option value="">Choose location</option>
                            <?php foreach ($options['relationships'] as $relationship): ?>
                                <option value="<?= coveted_e((string)$relationship['location_public_id']) ?>" <?= $prefill && (string)$prefill['location_ref'] === (string)$relationship['location_public_id'] ? 'selected' : '' ?>><?= coveted_e((string)$relationship['location_name']) ?> · <?= coveted_e((string)$relationship['group_name']) ?></option>
                            <?php endforeach; ?>
                        </select></label>
                        <label><span>Originating event (optional for Manual)</span><select name="event_ref">
                            <option value="">Group / relationship only</option>
                            <?php foreach ($options['events'] as $eventOption): ?>
                                <option value="<?= coveted_e((string)$eventOption['event_ref']) ?>" <?= (string)$eventOption['event_ref'] === $prefillEventRef ? 'selected' : '' ?>><?= coveted_e((string)$eventOption['event_title']) ?> · <?= coveted_e((string)$eventOption['group_name']) ?> · <?= coveted_e((string)$eventOption['location_name']) ?></option>
                            <?php endforeach; ?>
                        </select></label>
                        <label><span>Trigger</span><select name="trigger_key" required>
                            <option value="attendance">Verified attendance</option>
                            <option value="completion">Completed attendance</option>
                            <option value="return_visit">Verified return visit</option>
                            <option value="guest_return">Verified guest return</option>
                            <option value="manual">Admin distribution</option>
                        </select></label>
                    </div>
                    <div class="cv-form-grid">
                        <label><span>Program title</span><input name="program_title" maxlength="190" placeholder="Dinner Return Perk" required></label>
                        <label><span>Reward title</span><input name="reward_title" maxlength="190" placeholder="Complimentary appetizer" required></label>
                        <label><span>Reward type</span><select name="reward_type">
                            <?php foreach (['free_item'=>'Free item','discount'=>'Discount','credit'=>'Credit','perk'=>'Perk','access'=>'Access','service'=>'Service','experience'=>'Experience','custom'=>'Custom'] as $value => $label): ?><option value="<?= coveted_e($value) ?>"><?= coveted_e($label) ?></option><?php endforeach; ?>
                        </select></label>
                        <label><span>Redemption</span><select name="claim_mode"><option value="location_code">Business location code</option><option value="none">No-code / digital</option></select></label>
                    </div>
                    <label><span>Description</span><textarea name="description" maxlength="4000" rows="3" placeholder="What the member receives and any operating details Coveted Admin should review."></textarea></label>
                    <div class="cv-form-grid">
                        <label><span>Committed quantity</span><input name="quantity_limit" type="number" min="1" max="100000" value="25" required></label>
                        <label><span>Per-member limit</span><input name="per_user_limit" type="number" min="1" max="100" value="1" required></label>
                        <label><span>Face value (optional)</span><input name="value_amount" type="number" min="0" max="1000000" step="0.01" placeholder="12.00"></label>
                        <label><span>Value description</span><input name="value_text" maxlength="255" placeholder="One appetizer, up to $12"></label>
                    </div>
                    <div class="cv-form-grid">
                        <label><span>Starts</span><input name="starts_at" type="datetime-local"></label>
                        <label><span>Ends</span><input name="ends_at" type="datetime-local"></label>
                    </div>
                    <div class="cv-business-host-actions"><button class="cv-button" type="submit">Submit proposal to Coveted</button></div>
                </form>
            <?php endif; ?>
        </section>

        <section class="cv-business-host-panel">
            <div class="cv-business-host-panel-head"><div><span class="cv-eyebrow">PARTNER ROI</span><h2>Sponsored value and observed return behavior</h2><p>Aggregate performance from canonical Benefit Program issuance, claims and exact source-linked return rewards.</p></div><span class="cv-business-host-pill"><?= count($roi['programs']) ?> proposals</span></div>
            <div class="cv-business-host-report">
                <div><strong><?= (int)($summary['committed_quantity'] ?? 0) ?></strong><span>Committed rewards</span></div>
                <div><strong><?= $formatMoney($summary['committed_face_value'] ?? 0) ?></strong><span>Committed face value</span></div>
                <div><strong><?= (int)($summary['claimed'] ?? 0) ?></strong><span>Claims</span></div>
                <div><strong><?= $formatMoney($summary['estimated_redeemed_face_value'] ?? 0) ?></strong><span>Est. redeemed face value</span></div>
                <div><strong><?= (int)($summary['return_members'] ?? 0) ?></strong><span>Verified returns</span></div>
            </div>
            <?php if (!$roi['programs']): ?><div class="cv-business-host-empty"><p>No sponsorship proposals yet.</p></div><?php endif; ?>
            <div class="cv-business-host-list">
                <?php foreach ($roi['programs'] as $row): ?>
                    <article class="cv-business-host-item">
                        <div class="cv-business-host-panel-head">
                            <div><strong><?= coveted_e((string)$row['program_title']) ?></strong><p><?= coveted_e((string)$row['group_name']) ?> · <?= coveted_e((string)$row['location_name']) ?><?= !empty($row['event_title']) ? ' · ' . coveted_e((string)$row['event_title']) : '' ?></p></div>
                            <span class="cv-business-host-pill"><?= coveted_e(ucwords(str_replace('_',' ',(string)$row['proposal_status']))) ?></span>
                        </div>
                        <p><?= (int)$row['quantity_limit'] ?> committed · <?= (int)($row['issued_count'] ?? 0) ?> issued · <?= (int)($row['claimed_count'] ?? 0) ?> claimed · <?= number_format((float)($row['claim_rate'] ?? 0),1) ?>% claim rate · <?= (int)($row['return_members'] ?? 0) ?> verified returns</p>
                        <?php if ($row['benefit_program_ref']): ?><p>Benefit Program: <code><?= coveted_e((string)$row['benefit_program_ref']) ?></code> · <?= coveted_e(ucwords(str_replace('_',' ',(string)($row['program_status'] ?? 'draft')))) ?></p><?php endif; ?>
                        <?php if ((string)$row['proposal_status'] === 'submitted'): ?>
                            <form method="post" action="/business-sponsorships.php">
                                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                <input type="hidden" name="action" value="cancel_proposal">
                                <input type="hidden" name="business_ref" value="<?= coveted_e((string)$business['public_id']) ?>">
                                <input type="hidden" name="proposal_ref" value="<?= coveted_e((string)$row['proposal_ref']) ?>">
                                <button class="cv-button cv-button-soft" type="submit">Cancel proposal</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <p class="cv-muted"><?= coveted_e((string)$roi['privacy']) ?> <?= coveted_e((string)$roi['attribution_note']) ?></p>
        </section>
    <?php endif; ?>
</div>
<?php coveted_page_end(); ?>
