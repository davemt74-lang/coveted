<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/benefit_programs.php';
require_once dirname(__DIR__) . '/app/admin_ui.php';

$admin = coveted_require_system_admin();
$message = '';
$error = '';
$preview = null;
$form = [
    'owner_type' => 'group',
    'group_ref' => '',
    'business_ref' => '',
    'artist_ref' => '',
    'program_title' => '',
    'reward_title' => '',
    'description' => '',
    'reward_type' => 'perk',
    'claim_mode' => 'none',
    'value_amount' => '',
    'value_text' => '',
    'cover_url' => '',
    'trigger_key' => 'membership',
    'quantity_limit' => '',
    'per_user_limit' => '1',
    'starts_at' => '',
    'ends_at' => '',
    'event_ref' => '',
    'location_ref' => '',
];

$ownerRefFrom = static function (array $values): string {
    return match ((string)($values['owner_type'] ?? '')) {
        'group' => trim((string)($values['group_ref'] ?? '')),
        'business' => trim((string)($values['business_ref'] ?? '')),
        'artist' => trim((string)($values['artist_ref'] ?? '')),
        'platform' => '',
        default => '',
    };
};
$normalizeLocal = static function (string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, coveted_timezone());
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))) {
        throw new InvalidArgumentException('Enter a valid program date and time.');
    }
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    foreach (array_keys($form) as $key) {
        if (array_key_exists($key, $_POST) && is_scalar($_POST[$key])) {
            $form[$key] = trim((string)$_POST[$key]);
        }
    }

    try {
        if (in_array($action, ['preview','create_draft'], true)) {
            $payload = $form;
            $payload['owner_ref'] = $ownerRefFrom($form);
            $payload['starts_at'] = $normalizeLocal((string)$form['starts_at']);
            $payload['ends_at'] = $normalizeLocal((string)$form['ends_at']);

            if ($action === 'preview') {
                $preview = coveted_benefit_program_audience_preview($payload);
                $message = 'Preview refreshed from current Coveted data. No program was created.';
            } else {
                $payload['created_surface'] = 'admin_builder';
                $created = coveted_benefit_program_create_draft($admin, $payload);
                $message = 'Benefit Program draft created: ' . (string)$created['public_id'] . '. Review it below, then launch when ready.';
                $form['program_title'] = '';
                $form['reward_title'] = '';
                $form['description'] = '';
                $form['value_amount'] = '';
                $form['value_text'] = '';
                $form['cover_url'] = '';
            }
        } elseif ($action === 'set_status') {
            $programRef = trim((string)($_POST['program_ref'] ?? ''));
            $status = strtolower(trim((string)($_POST['status'] ?? '')));
            coveted_benefit_program_set_status($admin, $programRef, $status);
            $message = 'Benefit Program ' . $programRef . ' is now ' . $status . '.';
        } else {
            throw new InvalidArgumentException('Unsupported Benefit Program action.');
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Benefit Program Builder error: ' . $e->getMessage());
        $error = 'Unable to complete that Benefit Program action right now.';
    }
}

$options = coveted_benefit_program_builder_options();
$programs = coveted_benefit_program_list(125);
$formatDate = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }
    return coveted_utc_datetime($value)->setTimezone(coveted_timezone())->format('M j, Y · g:i A');
};
$ownerLabel = static function (array $program): string {
    return match ((string)$program['owner_type']) {
        'group' => 'Group · ' . (string)($program['group_name'] ?? 'Unknown'),
        'business' => 'Business · ' . (string)($program['business_name'] ?? 'Unknown'),
        'artist' => 'Artist · ' . (string)($program['artist_name'] ?? 'Unknown'),
        default => 'Coveted platform',
    };
};

coveted_page_start('Benefit Program Builder', '', true);
coveted_admin_ui_start($admin, 'benefit-programs', 'Benefit Program Builder');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">BENEFIT PROGRAM BUILDER</span>
        <h1>Design value, then let Coveted operate it.</h1>
        <p>Build membership perks, attendance rewards, return-visit offers, artist media and bounded reward pools from one guided workflow. New programs always start as drafts.</p>
    </div>
    <div class="cv-action-row">
        <a class="cv-button cv-button-soft" href="/admin/benefit-economy.php">Benefit Economy</a>
        <a class="cv-button cv-button-soft" href="/admin/?view=benefits">Reward Library</a>
    </div>
</div>

<?php if ($message !== ''): ?><div class="cv-alert"><?= coveted_e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>

<section class="cv-card cv-copy-card cv-admin-section-gap">
    <div class="cv-section-head">
        <div><span class="cv-eyebrow">CREATE PROGRAM</span><h2>Program → trigger → reward → pool → redemption.</h2></div>
        <span class="cv-pill">Draft first</span>
    </div>

    <form method="post" class="cv-form-stack" data-benefit-program-builder>
        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">

        <div class="cv-form-grid">
            <label><span>Program owner</span>
                <select name="owner_type" data-program-owner-type required>
                    <?php foreach (['group'=>'Group','business'=>'Business','artist'=>'Artist','platform'=>'Coveted platform'] as $value => $label): ?>
                        <option value="<?= coveted_e($value) ?>" <?= $form['owner_type'] === $value ? 'selected' : '' ?>><?= coveted_e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label data-program-owner="group"><span>Group</span>
                <select name="group_ref">
                    <option value="">Choose group</option>
                    <?php foreach ($options['groups'] as $row): ?><option value="<?= coveted_e((string)$row['public_id']) ?>" <?= $form['group_ref'] === (string)$row['public_id'] ? 'selected' : '' ?>><?= coveted_e((string)$row['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label data-program-owner="business"><span>Business</span>
                <select name="business_ref" data-program-business-ref>
                    <option value="">Choose business</option>
                    <?php foreach ($options['businesses'] as $row): ?><option value="<?= coveted_e((string)$row['public_id']) ?>" <?= $form['business_ref'] === (string)$row['public_id'] ? 'selected' : '' ?>><?= coveted_e((string)$row['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label data-program-owner="artist"><span>Artist</span>
                <select name="artist_ref">
                    <option value="">Choose artist</option>
                    <?php foreach ($options['artists'] as $row): ?><option value="<?= coveted_e((string)$row['public_id']) ?>" <?= $form['artist_ref'] === (string)$row['public_id'] ? 'selected' : '' ?>><?= coveted_e((string)$row['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="cv-form-grid">
            <label><span>Program title</span><input name="program_title" maxlength="190" value="<?= coveted_e($form['program_title']) ?>" placeholder="Member Welcome Perk" required></label>
            <label><span>Trigger</span>
                <select name="trigger_key" required>
                    <?php foreach ([
                        'membership'=>'Group membership',
                        'attendance'=>'Verified attendance',
                        'completion'=>'Event completion',
                        'return_visit'=>'Verified return visit',
                        'guest_return'=>'Guest return visit',
                        'mystery_unlock'=>'Mystery unlock',
                        'birthday'=>'Birthday',
                        'manual'=>'Manual distribution',
                    ] as $value => $label): ?><option value="<?= coveted_e($value) ?>" <?= $form['trigger_key'] === $value ? 'selected' : '' ?>><?= coveted_e($label) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label><span>Event · optional</span>
                <select name="event_ref">
                    <option value="">No direct event</option>
                    <?php foreach ($options['events'] as $row): ?><option value="<?= coveted_e((string)$row['public_id']) ?>" <?= $form['event_ref'] === (string)$row['public_id'] ? 'selected' : '' ?>><?= coveted_e((string)$row['name']) ?> · <?= coveted_e(strtoupper((string)$row['status'])) ?></option><?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="cv-form-grid">
            <label><span>Reward title</span><input name="reward_title" maxlength="190" value="<?= coveted_e($form['reward_title']) ?>" placeholder="Dinner on us" required></label>
            <label><span>Reward type</span>
                <select name="reward_type">
                    <?php foreach ([
                        'credit'=>'Credit','free_item'=>'Free item','discount'=>'Discount','perk'=>'Perk','access'=>'Access',
                        'service'=>'Service','experience'=>'Experience','audio'=>'Audio','video'=>'Video','media_pack'=>'Media pack','custom'=>'Custom',
                    ] as $value => $label): ?><option value="<?= coveted_e($value) ?>" <?= $form['reward_type'] === $value ? 'selected' : '' ?>><?= coveted_e($label) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label><span>Redemption</span>
                <select name="claim_mode" data-program-claim-mode>
                    <option value="none" <?= $form['claim_mode'] === 'none' ? 'selected' : '' ?>>Digital / no partner code</option>
                    <option value="location_code" <?= $form['claim_mode'] === 'location_code' ? 'selected' : '' ?>>Partner location code</option>
                </select>
            </label>
        </div>

        <label><span>Reward description</span><textarea name="description" maxlength="4000" rows="4" placeholder="What the member receives and why it matters."><?= coveted_e($form['description']) ?></textarea></label>

        <div class="cv-form-grid">
            <label><span>Face value · optional</span><input name="value_amount" type="number" min="0" step="0.01" value="<?= coveted_e($form['value_amount']) ?>" placeholder="25.00"></label>
            <label><span>Value label · optional</span><input name="value_text" maxlength="255" value="<?= coveted_e($form['value_text']) ?>" placeholder="Complimentary appetizer"></label>
            <label><span>Cover URL · optional</span><input name="cover_url" type="url" maxlength="2000" value="<?= coveted_e($form['cover_url']) ?>" placeholder="https://..."></label>
        </div>

        <div class="cv-form-grid">
            <label><span>Reward pool · blank = unlimited</span><input name="quantity_limit" type="number" min="1" step="1" value="<?= coveted_e($form['quantity_limit']) ?>" placeholder="50"></label>
            <label><span>Per-member limit</span><input name="per_user_limit" type="number" min="1" step="1" value="<?= coveted_e($form['per_user_limit']) ?>" required></label>
            <label><span>Partner location · Business programs only</span>
                <select name="location_ref" data-program-location-ref>
                    <option value="">No specific location</option>
                    <?php foreach ($options['locations'] as $row): ?><option value="<?= coveted_e((string)$row['public_id']) ?>" data-business-ref="<?= coveted_e((string)$row['business_public_id']) ?>" <?= $form['location_ref'] === (string)$row['public_id'] ? 'selected' : '' ?>><?= coveted_e((string)$row['business_name']) ?> · <?= coveted_e((string)$row['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="cv-form-grid">
            <label><span>Starts · <?= coveted_e(coveted_timezone()->getName()) ?></span><input name="starts_at" type="datetime-local" value="<?= coveted_e($form['starts_at']) ?>"></label>
            <label><span>Ends / reward expires · <?= coveted_e(coveted_timezone()->getName()) ?></span><input name="ends_at" type="datetime-local" value="<?= coveted_e($form['ends_at']) ?>"></label>
        </div>

        <div class="cv-action-row">
            <button class="cv-button cv-button-soft" type="submit" name="action" value="preview">Preview Audience &amp; Exposure</button>
            <button class="cv-button cv-button-primary" type="submit" name="action" value="create_draft">Create Program Draft</button>
        </div>
    </form>
</section>

<?php if ($preview !== null): ?>
<section class="cv-card cv-copy-card cv-admin-section-gap" aria-label="Benefit Program preview">
    <span class="cv-eyebrow">LIVE PREVIEW</span>
    <h2><?= coveted_e((string)$preview['owner_name']) ?><?= $preview['event_title'] ? ' · ' . coveted_e((string)$preview['event_title']) : '' ?></h2>
    <div class="cv-stat-grid">
        <div class="cv-card cv-stat"><strong><?= $preview['eligible_now'] === null ? '—' : (int)$preview['eligible_now'] ?></strong><span>Eligible now</span></div>
        <div class="cv-card cv-stat"><strong><?= $preview['reachable'] === null ? '—' : (int)$preview['reachable'] ?></strong><span>Reachable</span></div>
        <div class="cv-card cv-stat"><strong><?= $preview['quantity_limit'] === null ? '∞' : (int)$preview['quantity_limit'] ?></strong><span>Pool size</span></div>
        <div class="cv-card cv-stat"><strong><?= $preview['maximum_face_value_exposure'] === null ? '—' : '$' . coveted_e(number_format((float)$preview['maximum_face_value_exposure'], 2)) ?></strong><span>Max face-value exposure</span></div>
    </div>
    <p><?= coveted_e((string)$preview['basis']) ?></p>
    <p class="cv-muted">Exposure is face value × bounded pool quantity, not a prediction of redemption or accounting liability.</p>
</section>
<?php endif; ?>

<div class="cv-section-head cv-admin-section-gap">
    <div><span class="cv-eyebrow">PROGRAMS</span><h2>Launch, pause and measure.</h2></div>
    <span class="cv-pill"><?= count($programs) ?> shown</span>
</div>
<section class="cv-stack">
    <?php if (!$programs): ?><div class="cv-card cv-empty"><h3>No Benefit Programs yet.</h3><p>Create the first program above. Coveted will keep the underlying reward and campaign paired.</p></div><?php endif; ?>
    <?php foreach ($programs as $program): ?>
        <?php
        $status = (string)$program['status'];
        $issued = (int)$program['issued_count'];
        $claimed = (int)$program['claimed_count'];
        $claimRate = $issued > 0 ? round(($claimed / $issued) * 100, 1) : 0.0;
        $remaining = $program['quantity_limit'] !== null ? max(0, (int)$program['quantity_limit'] - $issued) : null;
        ?>
        <article class="cv-card cv-admin-row">
            <div>
                <div class="cv-tag-row">
                    <span class="cv-kicker"><?= coveted_e(strtoupper($status)) ?></span>
                    <span class="cv-pill"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$program['trigger_key']))) ?></span>
                    <span class="cv-pill"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$program['reward_type']))) ?></span>
                </div>
                <h3><?= coveted_e((string)$program['title']) ?></h3>
                <p><?= coveted_e($ownerLabel($program)) ?> · Reward: <?= coveted_e((string)$program['reward_title']) ?></p>
                <?php if (!empty($program['event_title'])): ?><p>Event · <?= coveted_e((string)$program['event_title']) ?></p><?php endif; ?>
                <p><?= $issued ?> issued · <?= $claimed ?> claimed · <?= coveted_e(number_format($claimRate, 1)) ?>% claim rate<?= $remaining !== null ? ' · ' . $remaining . ' pool remaining' : '' ?></p>
                <p><?= coveted_e($formatDate($program['starts_at'])) ?> → <?= coveted_e($formatDate($program['ends_at'])) ?></p>
                <code><?= coveted_e((string)$program['public_id']) ?></code>
            </div>
            <div class="cv-action-row">
                <?php if (in_array($status, ['draft','paused'], true)): ?>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="set_status"><input type="hidden" name="program_ref" value="<?= coveted_e((string)$program['public_id']) ?>"><input type="hidden" name="status" value="active"><button class="cv-button cv-button-primary" type="submit">Launch</button></form>
                <?php endif; ?>
                <?php if ($status === 'active'): ?>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="set_status"><input type="hidden" name="program_ref" value="<?= coveted_e((string)$program['public_id']) ?>"><input type="hidden" name="status" value="paused"><button class="cv-button cv-button-soft" type="submit">Pause</button></form>
                <?php endif; ?>
                <?php if ($status !== 'archived'): ?>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="set_status"><input type="hidden" name="program_ref" value="<?= coveted_e((string)$program['public_id']) ?>"><input type="hidden" name="status" value="archived"><button class="cv-button cv-button-soft" type="submit">Archive</button></form>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<section class="cv-card cv-feature-card cv-copy-card cv-admin-section-gap">
    <span class="cv-kicker">ADMIN AGENT</span>
    <h2>The Agent sees the same program state.</h2>
    <p>Benefit Program counts and low-inventory signals are added to the Agent's live operational context. With Autonomous Actions enabled—or through an explicitly approved Agent task—it can create draft programs and change the status of known programs through the same canonical services used on this page.</p>
</section>

<script src="/assets/js/benefit-program-builder-v1.js?v=20260906" defer></script>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
