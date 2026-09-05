<?php
declare(strict_types=1);

require_once __DIR__ . '/app/member_people_v2.php';

$user = coveted_require_user();
$userId = (int)$user['id'];
$pdo = coveted_db();
$sampleMode = coveted_member_sample_mode($user, $pdo);
$message = '';
$error = '';

$parsePreferenceInput = static function (string $value, string $label): array {
    $parts = preg_split('/[,\n]+/u', $value) ?: [];
    $clean = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if (mb_strlen($part) > 60) {
            throw new InvalidArgumentException($label . ' entries must be 60 characters or fewer.');
        }
        $clean[] = $part;
        if (count($clean) > 12) {
            throw new InvalidArgumentException('Choose no more than 12 ' . strtolower($label) . ' entries.');
        }
    }
    return array_values(array_unique($clean));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        if ($sampleMode) {
            throw new InvalidArgumentException('The sample profile is preview-only. Turn Sample Data off to edit your live profile.');
        }

        $action = (string)($_POST['action'] ?? 'profile');
        if ($action !== 'profile') {
            throw new InvalidArgumentException('Unsupported profile action.');
        }

        $displayName = trim((string)($_POST['display_name'] ?? ''));
        $bio = trim((string)($_POST['bio'] ?? ''));
        $city = trim((string)($_POST['city'] ?? ''));
        $avatarUrl = trim((string)($_POST['avatar_url'] ?? ''));
        $coverUrl = trim((string)($_POST['cover_url'] ?? ''));
        $interests = $parsePreferenceInput((string)($_POST['interests'] ?? ''), 'Interest');
        $gatheringStyles = $parsePreferenceInput((string)($_POST['gathering_styles'] ?? ''), 'Gathering style');

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

        $preferencesJson = coveted_json([
            'interests' => $interests,
            'gathering_styles' => $gatheringStyles,
        ]);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET display_name = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$displayName, $userId]);

            $pdo->prepare(
                'INSERT INTO profiles (user_id, bio, city, avatar_url, cover_url, interests_json, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    bio = VALUES(bio),
                    city = VALUES(city),
                    avatar_url = VALUES(avatar_url),
                    cover_url = VALUES(cover_url),
                    interests_json = VALUES(interests_json),
                    updated_at = NOW()'
            )->execute([
                $userId,
                $bio !== '' ? $bio : null,
                $city !== '' ? $city : null,
                $avatarUrl !== '' ? $avatarUrl : null,
                $coverUrl !== '' ? $coverUrl : null,
                $preferencesJson,
            ]);

            coveted_audit(
                'profile.updated',
                'user',
                (string)$user['public_id'],
                ['fields' => ['display_name', 'city', 'bio', 'avatar_url', 'cover_url', 'interests_json']],
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

$profile = coveted_member_v2_profile_data($user, $pdo);
$displayName = (string)$profile['display_name'];
$cityLabel = trim((string)$profile['city']) ?: 'Coveted member';
$profileAvatarUrl = $sampleMode
    ? (string)$profile['avatar_url']
    : coveted_safe_url((string)($profile['avatar_url'] ?? ''), true);
$profileCoverUrl = $sampleMode
    ? (string)$profile['cover_url']
    : coveted_safe_url((string)($profile['cover_url'] ?? ''), true);
$interests = array_values((array)($profile['interests'] ?? []));
$gatheringStyles = array_values((array)($profile['gathering_styles'] ?? []));
$profileInitials = '';
foreach (preg_split('/\s+/u', trim($displayName), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
    $profileInitials .= mb_strtoupper(mb_substr($part, 0, 1));
    if (mb_strlen($profileInitials) >= 2) {
        break;
    }
}
$profileInitials = $profileInitials !== '' ? $profileInitials : 'C';
$interestsInput = implode(', ', $interests);
$gatheringStylesInput = implode(', ', $gatheringStyles);

coveted_page_start('Profile', 'Profile');
?>
<div class="cv-member-page-v2 cv-profile-v2">
    <section class="cv-member-page-intro">
        <div>
            <span class="cv-eyebrow">PROFILE</span>
            <h1>Your place in Coveted.</h1>
            <p>A little context for the people and experiences around you—without followers, public scores or popularity metrics.</p>
        </div>
        <?php if ($sampleMode): ?>
            <a class="cv-member-preview-pill" href="/admin/sample-data.php">Sample data · ON</a>
        <?php endif; ?>
    </section>

    <?php if ($message !== ''): ?><div class="cv-alert"><?= coveted_e($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>

    <section class="cv-profile-v2-hero">
        <div class="cv-profile-v2-cover <?= $profileCoverUrl === null || $profileCoverUrl === '' ? 'is-empty' : '' ?>">
            <?php if ($profileCoverUrl !== null && $profileCoverUrl !== ''): ?><img src="<?= coveted_e($profileCoverUrl) ?>" alt="" loading="eager" decoding="async"><?php endif; ?>
        </div>
        <div class="cv-profile-v2-identity">
            <div class="cv-profile-v2-avatar">
                <?php if ($profileAvatarUrl !== null && $profileAvatarUrl !== ''): ?>
                    <img src="<?= coveted_e($profileAvatarUrl) ?>" alt="<?= coveted_e($displayName) ?>" loading="eager" decoding="async">
                <?php else: ?>
                    <span><?= coveted_e($profileInitials) ?></span>
                <?php endif; ?>
            </div>
            <div class="cv-profile-v2-name">
                <span class="cv-member-overline">MEMBER</span>
                <h2><?= coveted_e($displayName) ?></h2>
                <p><?= coveted_e($cityLabel) ?></p>
            </div>
            <div class="cv-profile-v2-state">
                <span class="cv-member-status-chip is-private">Private by default</span>
                <?php if (!empty($profile['member_since'])): ?><small>Member since <?= coveted_e((string)$profile['member_since']) ?></small><?php endif; ?>
            </div>
        </div>
    </section>

    <section class="cv-profile-v2-stats" aria-label="Member activity">
        <a href="/groups.php"><strong><?= (int)$profile['group_count'] ?></strong><span>Groups</span></a>
        <a href="/events.php?view=history"><strong><?= (int)$profile['event_count'] ?></strong><span>Shared events</span></a>
        <a href="/benefits.php"><strong><?= (int)$profile['benefit_count'] ?></strong><span>Active benefits</span></a>
        <a href="/reconnect.php"><strong><?= (int)$profile['reconnect_count'] ?></strong><span>Reconnects</span></a>
    </section>

    <section class="cv-profile-v2-grid">
        <div class="cv-profile-v2-main">
            <article class="cv-profile-v2-panel cv-profile-v2-about">
                <span class="cv-member-overline">ABOUT</span>
                <h2><?= trim((string)$profile['bio']) !== '' ? 'A little context.' : 'Say a little about yourself.' ?></h2>
                <p><?= coveted_e(trim((string)$profile['bio']) !== '' ? (string)$profile['bio'] : 'Add a short bio so your profile carries useful context into groups and real-world gatherings.') ?></p>
            </article>

            <article class="cv-profile-v2-panel">
                <span class="cv-member-overline">INTERESTS</span>
                <h2>What catches your attention.</h2>
                <?php if ($interests): ?>
                    <div class="cv-profile-v2-tags">
                        <?php foreach ($interests as $interest): ?><span><?= coveted_e((string)$interest) ?></span><?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>Add a few interests to give your groups and experiences more useful context.</p>
                <?php endif; ?>
            </article>

            <article class="cv-profile-v2-panel">
                <span class="cv-member-overline">GATHERING STYLE</span>
                <h2>How you like to show up.</h2>
                <?php if ($gatheringStyles): ?>
                    <div class="cv-profile-v2-tags">
                        <?php foreach ($gatheringStyles as $style): ?><span><?= coveted_e((string)$style) ?></span><?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>Dinners, listening nights, mystery gatherings—add the formats that feel like your kind of room.</p>
                <?php endif; ?>
            </article>
        </div>

        <aside class="cv-profile-v2-side">
            <article class="cv-profile-v2-panel cv-profile-v2-privacy">
                <span class="cv-member-overline">PRIVACY</span>
                <h2>Context, not clout.</h2>
                <p>Coveted uses your profile to support membership and real-world participation. It is not designed around follower counts, public popularity or incoming reconnect interest.</p>
                <ul>
                    <li>Mutual Reconnect stays private until both people choose.</li>
                    <li>Your administrative roles are managed outside this profile.</li>
                    <li>Profile details can be kept intentionally minimal.</li>
                </ul>
            </article>

            <?php if ($sampleMode): ?>
                <article class="cv-profile-v2-panel cv-profile-v2-preview-note">
                    <span class="cv-member-overline">PREVIEW MODE</span>
                    <h2>This profile is synthetic.</h2>
                    <p>Sample Data is showing a populated member profile without writing anything to your live profile record.</p>
                    <a class="cv-member-text-link" href="/admin/sample-data.php">Manage Sample Data →</a>
                </article>
            <?php else: ?>
                <details class="cv-profile-v2-editor" <?= $error !== '' ? 'open' : '' ?>>
                    <summary>
                        <span>
                            <small>PROFILE SETTINGS</small>
                            <strong>Edit profile</strong>
                        </span>
                        <span aria-hidden="true">+</span>
                    </summary>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="profile">

                        <label>
                            Display name
                            <input name="display_name" maxlength="180" required value="<?= coveted_e($displayName) ?>" autocomplete="name">
                        </label>

                        <label>
                            City
                            <input name="city" maxlength="160" value="<?= coveted_e((string)$profile['city']) ?>" placeholder="Phoenix" autocomplete="address-level2">
                        </label>

                        <label>
                            Profile photo URL
                            <input name="avatar_url" maxlength="700" value="<?= coveted_e((string)($profile['avatar_url'] ?? '')) ?>" placeholder="https://…" inputmode="url" autocomplete="url">
                        </label>

                        <label>
                            Cover image URL
                            <input name="cover_url" maxlength="700" value="<?= coveted_e((string)($profile['cover_url'] ?? '')) ?>" placeholder="https://…" inputmode="url">
                        </label>

                        <label>
                            About you
                            <textarea name="bio" maxlength="2000" rows="6" placeholder="A little about you."><?= coveted_e((string)$profile['bio']) ?></textarea>
                        </label>

                        <label>
                            Interests
                            <input name="interests" maxlength="720" value="<?= coveted_e($interestsInput) ?>" placeholder="Local dining, live music, design">
                            <small>Separate interests with commas.</small>
                        </label>

                        <label>
                            Gathering styles
                            <input name="gathering_styles" maxlength="720" value="<?= coveted_e($gatheringStylesInput) ?>" placeholder="Dinner table, listening night">
                            <small>Separate gathering styles with commas.</small>
                        </label>

                        <button class="cv-button cv-button-primary cv-button-block" type="submit">Save Profile</button>
                    </form>
                </details>
            <?php endif; ?>
        </aside>
    </section>
</div>
<?php coveted_page_end(); ?>
