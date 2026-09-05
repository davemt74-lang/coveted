<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = coveted_require_user();
$userId = (int)$user['id'];
$pdo = coveted_db();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        $action = (string)($_POST['action'] ?? 'profile');
        if ($action !== 'profile') {
            throw new InvalidArgumentException('Unsupported profile action.');
        }

        $displayName = trim((string)($_POST['display_name'] ?? ''));
        $bio = trim((string)($_POST['bio'] ?? ''));
        $city = trim((string)($_POST['city'] ?? ''));
        $avatarUrl = trim((string)($_POST['avatar_url'] ?? ''));
        $coverUrl = trim((string)($_POST['cover_url'] ?? ''));

        if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 180) {
            throw new InvalidArgumentException('Enter a name between 2 and 180 characters.');
        }
        if (mb_strlen($bio) > 2000) {
            throw new InvalidArgumentException('Keep your bio under 2,000 characters.');
        }
        if (mb_strlen($city) > 160) {
            throw new InvalidArgumentException('Keep your city under 160 characters.');
        }
        if (mb_strlen($avatarUrl) > 700) {
            throw new InvalidArgumentException('Profile photo URL is too long.');
        }
        if (mb_strlen($coverUrl) > 700) {
            throw new InvalidArgumentException('Cover image URL is too long.');
        }
        if ($avatarUrl !== '' && coveted_safe_url($avatarUrl, true) === null) {
            throw new InvalidArgumentException('Enter a valid profile photo URL.');
        }
        if ($coverUrl !== '' && coveted_safe_url($coverUrl, true) === null) {
            throw new InvalidArgumentException('Enter a valid cover image URL.');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET display_name = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$displayName, $userId]);

            $pdo->prepare(
                'INSERT INTO profiles (user_id, bio, city, avatar_url, cover_url, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    bio = VALUES(bio),
                    city = VALUES(city),
                    avatar_url = VALUES(avatar_url),
                    cover_url = VALUES(cover_url),
                    updated_at = NOW()'
            )->execute([
                $userId,
                $bio !== '' ? $bio : null,
                $city !== '' ? $city : null,
                $avatarUrl !== '' ? $avatarUrl : null,
                $coverUrl !== '' ? $coverUrl : null,
            ]);

            coveted_audit(
                'profile.updated',
                'user',
                (string)$user['public_id'],
                ['fields' => ['display_name', 'city', 'bio', 'avatar_url', 'cover_url']],
                $userId
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $user['display_name'] = $displayName;
        $message = 'Profile updated.';
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted profile error: ' . $e->getMessage());
        $error = 'Unable to update your profile right now.';
    }
}

$stmt = $pdo->prepare('SELECT bio, city, avatar_url, cover_url FROM profiles WHERE user_id = ? LIMIT 1');
$stmt->execute([$userId]);
$profile = $stmt->fetch() ?: [];

$memberSince = !empty($user['created_at'])
    ? coveted_utc_datetime((string)$user['created_at'])->setTimezone(coveted_timezone())->format('F Y')
    : '';
$cityLabel = trim((string)($profile['city'] ?? '')) ?: 'Coveted member';
$profileAvatarUrl = coveted_safe_url((string)($profile['avatar_url'] ?? ''), true);
$profileCoverUrl = coveted_safe_url((string)($profile['cover_url'] ?? ''), true);
$profileInitials = '';
foreach (preg_split('/\s+/u', trim((string)$user['display_name']), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
    $profileInitials .= mb_strtoupper(mb_substr($part, 0, 1));
    if (mb_strlen($profileInitials) >= 2) {
        break;
    }
}
$profileInitials = $profileInitials !== '' ? $profileInitials : 'C';

coveted_page_start('Profile', 'Profile');
?>
<section class="cv-page-heading cv-profile-heading">
    <span class="cv-eyebrow">PROFILE</span>
    <h1>Your profile.</h1>
    <p>Keep your personal information current. Administrative tools live in the Admin Control Center, not here.</p>
</section>

<?php if ($message): ?>
    <div class="cv-alert"><?= coveted_e($message) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div>
<?php endif; ?>

<article class="cv-card cv-profile-hero cv-profile-hero-clean">
    <div class="cv-profile-cover" aria-hidden="true">
        <?php if ($profileCoverUrl !== null): ?>
            <img src="<?= coveted_e($profileCoverUrl) ?>" alt="">
        <?php endif; ?>
    </div>
    <div class="cv-profile-avatar">
        <?php if ($profileAvatarUrl !== null): ?>
            <img src="<?= coveted_e($profileAvatarUrl) ?>" alt="<?= coveted_e($user['display_name']) ?>">
        <?php else: ?>
            <span><?= coveted_e($profileInitials) ?></span>
        <?php endif; ?>
    </div>
    <div class="cv-profile-hero-copy">
        <span class="cv-kicker">MEMBER</span>
        <h2><?= coveted_e($user['display_name']) ?></h2>
        <p><?= coveted_e($cityLabel) ?></p>
        <div class="cv-profile-meta-row">
            <span><?= coveted_e($user['email']) ?></span>
            <?php if ($memberSince !== ''): ?><span>Member since <?= coveted_e($memberSince) ?></span><?php endif; ?>
        </div>
    </div>
</article>

<section class="cv-profile-edit-layout">
    <form class="cv-card cv-form cv-profile-editor" method="post">
        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
        <input type="hidden" name="action" value="profile">

        <div class="cv-profile-section-heading">
            <span class="cv-eyebrow">PERSONAL INFORMATION</span>
            <h2>Edit profile</h2>
            <p>This is your member identity. It does not control roles, permissions, businesses, groups or system administration.</p>
        </div>

        <div class="cv-form-grid cv-profile-form-grid">
            <label>
                Display name
                <input name="display_name" maxlength="180" required value="<?= coveted_e($user['display_name']) ?>" autocomplete="name">
            </label>

            <label>
                Email
                <input value="<?= coveted_e($user['email']) ?>" readonly aria-readonly="true" autocomplete="email">
                <small>Email changes are handled separately from your public profile.</small>
            </label>

            <label>
                City
                <input name="city" maxlength="160" value="<?= coveted_e($profile['city'] ?? '') ?>" placeholder="Phoenix" autocomplete="address-level2">
            </label>

            <label>
                Profile photo URL
                <input name="avatar_url" maxlength="700" value="<?= coveted_e($profile['avatar_url'] ?? '') ?>" placeholder="https://…" inputmode="url" autocomplete="url">
            </label>

            <label class="cv-profile-field-wide">
                Cover image URL
                <input name="cover_url" maxlength="700" value="<?= coveted_e($profile['cover_url'] ?? '') ?>" placeholder="https://…" inputmode="url">
            </label>

            <label class="cv-profile-field-wide">
                About you
                <textarea name="bio" maxlength="2000" rows="7" placeholder="A little about you."><?= coveted_e($profile['bio'] ?? '') ?></textarea>
            </label>
        </div>

        <div class="cv-profile-save-row">
            <button class="cv-button cv-button-primary" type="submit">Save Profile</button>
            <span>Only your personal profile is edited here.</span>
        </div>
    </form>

    <aside class="cv-card cv-profile-preview-card">
        <span class="cv-eyebrow">PROFILE PREVIEW</span>
        <div class="cv-profile-preview-avatar">
            <?php if ($profileAvatarUrl !== null): ?>
                <img src="<?= coveted_e($profileAvatarUrl) ?>" alt="">
            <?php else: ?>
                <span><?= coveted_e($profileInitials) ?></span>
            <?php endif; ?>
        </div>
        <h2><?= coveted_e($user['display_name']) ?></h2>
        <p class="cv-profile-preview-location"><?= coveted_e($cityLabel) ?></p>
        <p><?= coveted_e(trim((string)($profile['bio'] ?? '')) ?: 'Add a short bio to give your profile a little context.') ?></p>
        <div class="cv-profile-privacy-note">
            <strong>Private by default.</strong>
            <span>Coveted is built around real-world participation, not public popularity metrics.</span>
        </div>
    </aside>
</section>
<?php coveted_page_end(); ?>
