<?php
declare(strict_types=1);

require_once __DIR__ . '/app/artists.php';
require_once __DIR__ . '/app/events.php';
require_once __DIR__ . '/app/rewards.php';
require_once __DIR__ . '/app/campaigns.php';
require_once __DIR__ . '/app/outcomes.php';

$user = coveted_require_user();
$userId = (int)$user['id'];
$isApproved = coveted_artist_actor_has_partner_approval($user);
$pdo = coveted_db();
$message = '';
$error = '';
$view = strtolower(trim((string)($_GET['view'] ?? $_POST['view'] ?? 'overview')));
if (!in_array($view, ['overview', 'profile', 'team', 'appearances', 'rewards', 'insights'], true)) {
    $view = 'overview';
}

if (!$isApproved) {
    http_response_code(403);
    coveted_page_start('Artist Partner');
    ?>
    <section class="cv-page-heading">
        <span class="cv-eyebrow">ARTIST PARTNER</span>
        <h1>Artist Partner approval required.</h1>
        <p>This workspace is available after System Admin approves the Artist Partner role on your Coveted account.</p>
    </section>
    <article class="cv-card cv-copy-card">
        <h2>Request access from Profile.</h2>
        <p>Artist Partner is a platform role because it can manage artist identities, media rewards and artist-owned campaigns.</p>
        <a class="cv-button cv-button-primary" href="/profile.php">Open Profile</a>
    </article>
    <?php
    coveted_page_end();
    exit;
}

$selectedRef = trim((string)($_GET['artist'] ?? $_POST['artist_ref'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        $action = (string)($_POST['action'] ?? '');

        switch ($action) {
            case 'create_artist':
                $created = coveted_artist_create(
                    $user,
                    (string)($_POST['artist_name'] ?? ''),
                    (string)($_POST['bio'] ?? '')
                );
                $selectedRef = (string)$created['public_id'];
                $view = 'profile';
                $message = 'Artist profile created.';
                break;

            case 'update_artist':
                coveted_artist_update($user, $selectedRef, [
                    'artist_name' => (string)($_POST['artist_name'] ?? ''),
                    'bio' => (string)($_POST['bio'] ?? ''),
                    'avatar_url' => (string)($_POST['avatar_url'] ?? ''),
                    'cover_url' => (string)($_POST['cover_url'] ?? ''),
                    'links' => [
                        'Website' => (string)($_POST['website_url'] ?? ''),
                        'Instagram' => (string)($_POST['instagram_url'] ?? ''),
                        'Spotify' => (string)($_POST['spotify_url'] ?? ''),
                    ],
                ]);
                $view = 'profile';
                $message = 'Artist profile updated.';
                break;

            case 'artist_status':
                coveted_artist_set_status($user, $selectedRef, (string)($_POST['status'] ?? ''));
                $view = 'profile';
                $message = 'Artist status updated.';
                break;

            case 'add_member':
                $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Enter a valid Coveted member email.');
                }
                $memberStmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? AND status = \'active\' LIMIT 1');
                $memberStmt->execute([$email]);
                $targetUserId = (int)($memberStmt->fetchColumn() ?: 0);
                if ($targetUserId < 1) {
                    throw new InvalidArgumentException('No active Coveted member was found with that email.');
                }
                coveted_artist_add_member(
                    $user,
                    $selectedRef,
                    $targetUserId,
                    (string)($_POST['member_role'] ?? 'member')
                );
                $view = 'team';
                $message = 'Artist team updated.';
                break;

            case 'remove_member':
                coveted_artist_remove_member($user, $selectedRef, (int)($_POST['user_id'] ?? 0));
                $view = 'team';
                $message = 'Artist team member removed.';
                break;

            case 'create_reward':
                coveted_reward_create_template($user, [
                    'owner_type' => 'artist',
                    'owner_id' => (int)($_POST['artist_id'] ?? 0),
                    'title' => (string)($_POST['title'] ?? ''),
                    'description' => (string)($_POST['description'] ?? ''),
                    'reward_type' => (string)($_POST['reward_type'] ?? 'audio'),
                    'claim_mode' => 'none',
                    'value_text' => (string)($_POST['value_text'] ?? ''),
                    'cover_url' => (string)($_POST['cover_url'] ?? ''),
                    'status' => (string)($_POST['status'] ?? 'draft'),
                ]);
                $view = 'rewards';
                $message = 'Artist reward created.';
                break;

            case 'reward_status':
                coveted_reward_set_status(
                    $user,
                    (string)($_POST['reward_ref'] ?? ''),
                    (string)($_POST['status'] ?? '')
                );
                $view = 'rewards';
                $message = 'Reward status updated.';
                break;

            case 'replace_reward_media':
                $types = (array)($_POST['media_type'] ?? []);
                $titles = (array)($_POST['media_title'] ?? []);
                $urls = (array)($_POST['media_url'] ?? []);
                $mimes = (array)($_POST['mime_type'] ?? []);
                $items = [];
                $rowCount = max(count($types), count($titles), count($urls), count($mimes));
                for ($i = 0; $i < $rowCount; $i++) {
                    $url = trim((string)($urls[$i] ?? ''));
                    if ($url === '') {
                        continue;
                    }
                    $items[] = [
                        'media_type' => (string)($types[$i] ?? 'audio'),
                        'title' => (string)($titles[$i] ?? ''),
                        'media_url' => $url,
                        'mime_type' => (string)($mimes[$i] ?? ''),
                    ];
                }
                coveted_reward_replace_media($user, (string)($_POST['reward_ref'] ?? ''), $items);
                $view = 'rewards';
                $message = 'Reward media updated.';
                break;

            case 'create_campaign':
                coveted_campaign_create($user, [
                    'owner_type' => 'artist',
                    'owner_id' => (int)($_POST['artist_id'] ?? 0),
                    'reward_template' => (string)($_POST['reward_template'] ?? ''),
                    'title' => (string)($_POST['title'] ?? ''),
                    'campaign_type' => 'manual',
                    'trigger_key' => 'manual',
                    'quantity_limit' => trim((string)($_POST['quantity_limit'] ?? '')) !== ''
                        ? (int)$_POST['quantity_limit']
                        : null,
                    'per_user_limit' => 1,
                    'status' => (string)($_POST['status'] ?? 'draft'),
                ]);
                $view = 'rewards';
                $message = 'Artist campaign created.';
                break;

            case 'campaign_status':
                coveted_campaign_set_status(
                    $user,
                    (string)($_POST['campaign_ref'] ?? ''),
                    (string)($_POST['status'] ?? '')
                );
                $view = 'rewards';
                $message = 'Campaign status updated.';
                break;

            default:
                throw new InvalidArgumentException('Unsupported Artist Partner action.');
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted artist workspace error: ' . $e->getMessage());
        $error = 'Unable to complete that Artist Partner action.';
    }
}

$artists = coveted_artists_for_actor($user);
$createMode = $_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['new']) && coveted_is_system_admin($user);
$artistByRef = [];
foreach ($artists as $row) {
    $artistByRef[(string)$row['public_id']] = $row;
    $artistByRef[(string)$row['id']] = $row;
}

if ($selectedRef === '' && $artists) {
    $selectedRef = (string)$artists[0]['public_id'];
}
$artist = $selectedRef !== '' ? ($artistByRef[$selectedRef] ?? null) : null;
if ($selectedRef !== '' && !$artist) {
    http_response_code(403);
    $error = 'That artist is not available to this account.';
    $selectedRef = '';
}

$permission = $artist ? coveted_artist_actor_permission($user, (int)$artist['id']) : 'none';
$canManage = $artist ? coveted_artist_actor_can_manage($user, (int)$artist['id']) : false;
$canManageTeam = $artist ? coveted_artist_actor_can_manage_team($user, (int)$artist['id']) : false;
$team = [];
$appearances = [];
$relationships = [];
$rewards = [];
$rewardMedia = [];
$campaigns = [];
$insights = null;
$insightPeriod = trim((string)($_GET['period'] ?? '90'));
$metrics = [
    'team' => 0,
    'upcoming' => 0,
    'rewards' => 0,
    'campaigns' => 0,
    'issued' => 0,
];

if ($artist) {
    $artistId = (int)$artist['id'];

    $teamStmt = $pdo->prepare(
        "SELECT am.user_id, am.member_role, am.created_at, u.display_name, u.email, u.status
         FROM artist_members am
         JOIN users u ON u.id = am.user_id
         WHERE am.artist_id = ?
         ORDER BY FIELD(am.member_role, 'owner','manager','member'), u.display_name, u.id"
    );
    $teamStmt->execute([$artistId]);
    $team = $teamStmt->fetchAll();

    $appearanceStmt = $pdo->prepare(
        "SELECT e.public_id, e.title, e.starts_at, e.ends_at, e.timezone, e.status,
                g.name AS group_name, ea.appearance_type
         FROM event_artists ea
         JOIN events e ON e.id = ea.event_id
         JOIN social_groups g ON g.id = e.group_id
         WHERE ea.artist_id = ?
         ORDER BY (e.starts_at < NOW()), e.starts_at ASC, e.id ASC
         LIMIT 100"
    );
    $appearanceStmt->execute([$artistId]);
    $appearances = $appearanceStmt->fetchAll();

    $relationshipStmt = $pdo->prepare(
        "SELECT agr.relationship_status, agr.first_event_at, agr.last_event_at, agr.notes,
                g.public_id AS group_public_id, g.name AS group_name, g.status AS group_status
         FROM artist_group_relationships agr
         JOIN social_groups g ON g.id = agr.group_id
         WHERE agr.artist_id = ?
         ORDER BY FIELD(agr.relationship_status, 'preferred_partner','partner','featured','new'), g.name"
    );
    $relationshipStmt->execute([$artistId]);
    $relationships = $relationshipStmt->fetchAll();

    $rewards = coveted_reward_templates_for_owner('artist', $artistId);
    $rewardMedia = coveted_reward_media_for_templates(array_column($rewards, 'id'));
    $campaigns = coveted_campaigns_for_owner('artist', $artistId);

    $issuedStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM reward_issuances WHERE artist_id = ? AND status <> 'cancelled'"
    );
    $issuedStmt->execute([$artistId]);
    $issuedCount = (int)$issuedStmt->fetchColumn();

    $metrics = [
        'team' => count($team),
        'upcoming' => count(array_filter(
            $appearances,
            static fn(array $row): bool => in_array((string)$row['status'], ['draft','published','closed'], true)
                && strtotime((string)$row['starts_at']) >= time()
        )),
        'rewards' => count(array_filter($rewards, static fn(array $row): bool => $row['status'] !== 'archived')),
        'campaigns' => count(array_filter($campaigns, static fn(array $row): bool => $row['status'] !== 'archived')),
        'issued' => $issuedCount,
    ];

    if ($view === 'insights') {
        try {
            $insights = coveted_artist_outcomes($user, $artistId, $insightPeriod);
            $insightPeriod = (string)$insights['period']['key'];
        } catch (InvalidArgumentException $e) {
            $error = $error ?: $e->getMessage();
        } catch (Throwable $e) {
            error_log('Coveted artist insights error: ' . $e->getMessage());
            $error = $error ?: 'Unable to load artist insights right now.';
        }
    }
}

$links = [];
if ($artist && !empty($artist['links_json'])) {
    $decoded = json_decode((string)$artist['links_json'], true);
    $links = is_array($decoded) ? $decoded : [];
}

$formatAccountTime = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    return coveted_utc_datetime($value)->setTimezone(coveted_timezone())->format('M j, Y · g:i A');
};

coveted_page_start('Artist Partner');
?>
<section class="cv-page-heading">
    <span class="cv-eyebrow">ARTIST PARTNER</span>
    <h1>Music belongs around the gathering.</h1>
    <p>Manage your Coveted artist identity, event appearances, media rewards and real-world outcomes without turning the experience into a public follower feed.</p>
</section>

<?php if ($message): ?><div class="cv-alert"><?= coveted_e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>

<?php if (!$artists || $createMode): ?>
    <section class="cv-two-column">
        <article class="cv-card cv-feature-card cv-copy-card">
            <span class="cv-kicker">ARTIST PARTNER ACTIVE</span>
            <h2><?= $artists ? 'Create another artist identity.' : 'Create your first artist identity.' ?></h2>
            <p>Artist profiles connect performances, partner relationships and audio/video rewards to one private Coveted workspace.</p>
            <?php if ($artists): ?><a class="cv-text-link" href="/artist.php">Back to Artist Workspace →</a><?php endif; ?>
        </article>
        <form id="create-artist" class="cv-card cv-form cv-anchor-target" method="post">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="action" value="create_artist">
            <span class="cv-eyebrow">NEW ARTIST</span>
            <h2>Artist profile</h2>
            <label>
                Artist or project name
                <input name="artist_name" maxlength="180" required>
            </label>
            <label>
                Short bio
                <textarea name="bio" maxlength="5000" rows="6"></textarea>
            </label>
            <button class="cv-button cv-button-primary" type="submit">Create Artist</button>
        </form>
    </section>
<?php else: ?>
    <?php if (count($artists) > 1): ?>
        <form class="cv-business-selector" method="get">
            <input type="hidden" name="view" value="<?= coveted_e($view) ?>">
            <?php if ($view === 'insights'): ?><input type="hidden" name="period" value="<?= coveted_e($insightPeriod) ?>"><?php endif; ?>
            <label>
                Artist workspace
                <select name="artist" data-submit-on-change>
                    <?php foreach ($artists as $option): ?>
                        <option value="<?= coveted_e($option['public_id']) ?>" <?= $artist && (int)$option['id'] === (int)$artist['id'] ? 'selected' : '' ?>>
                            <?= coveted_e($option['artist_name']) ?> · <?= coveted_e(ucfirst((string)$option['permission'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
    <?php endif; ?>

    <?php if ($artist): ?>
        <article class="cv-card cv-feature-card cv-copy-card">
            <span class="cv-kicker"><?= coveted_e(strtoupper((string)$artist['status'])) ?> ARTIST</span>
            <h2><?= coveted_e($artist['artist_name']) ?></h2>
            <p><?= coveted_e(trim((string)$artist['bio']) !== '' ? mb_strimwidth((string)$artist['bio'], 0, 360, '…') : 'Artist Partner workspace') ?></p>
            <div class="cv-tag-row">
                <span class="cv-pill"><?= coveted_e(ucfirst(str_replace('_', ' ', $permission))) ?></span>
                <?php foreach ($links as $label => $url): ?>
                    <?php $safeLink = coveted_safe_url((string)$url, false); ?>
                    <?php if ($safeLink !== null): ?>
                        <a class="cv-pill" href="<?= coveted_e($safeLink) ?>" target="_blank" rel="noopener noreferrer"><?= coveted_e((string)$label) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </article>

        <section class="cv-stat-grid cv-home-stats" aria-label="Artist summary">
            <a class="cv-card cv-stat" href="?artist=<?= coveted_e($artist['public_id']) ?>&view=team"><strong><?= (int)$metrics['team'] ?></strong><span>Team</span></a>
            <a class="cv-card cv-stat" href="?artist=<?= coveted_e($artist['public_id']) ?>&view=appearances"><strong><?= (int)$metrics['upcoming'] ?></strong><span>Upcoming</span></a>
            <a class="cv-card cv-stat" href="?artist=<?= coveted_e($artist['public_id']) ?>&view=rewards"><strong><?= (int)$metrics['rewards'] ?></strong><span>Rewards</span></a>
            <a class="cv-card cv-stat" href="?artist=<?= coveted_e($artist['public_id']) ?>&view=rewards"><strong><?= (int)$metrics['issued'] ?></strong><span>Delivered</span></a>
        </section>

        <nav class="cv-tab-row" aria-label="Artist workspace views">
            <?php foreach ([
                'overview' => 'Overview',
                'profile' => 'Profile',
                'team' => 'Team',
                'appearances' => 'Appearances',
                'rewards' => 'Rewards',
                'insights' => 'Insights',
            ] as $tabKey => $tabLabel): ?>
                <a class="cv-tab <?= $view === $tabKey ? 'is-active' : '' ?>" href="?artist=<?= coveted_e($artist['public_id']) ?>&view=<?= coveted_e($tabKey) ?>"><?= coveted_e($tabLabel) ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if ($view === 'overview'): ?>
            <section class="cv-two-column">
                <div>
                    <div class="cv-section-head"><div><span class="cv-eyebrow">NEXT UP</span><h2>Upcoming appearances</h2></div><a class="cv-text-link" href="?artist=<?= coveted_e($artist['public_id']) ?>&view=appearances">View all →</a></div>
                    <div class="cv-list">
                        <?php $futureAppearances = array_values(array_filter($appearances, static fn(array $row): bool => strtotime((string)$row['starts_at']) >= time())); ?>
                        <?php if (!$futureAppearances): ?><div class="cv-card cv-empty"><h3>No upcoming appearances.</h3><p>When a Host assigns this artist to an event, it will appear here.</p></div><?php endif; ?>
                        <?php foreach (array_slice($futureAppearances, 0, 4) as $appearance): ?>
                            <article class="cv-card cv-event-row">
                                <div class="cv-event-date"><strong><?= coveted_e(coveted_event_format($appearance, 'M')) ?></strong><span><?= coveted_e(coveted_event_format($appearance, 'j')) ?></span></div>
                                <div class="cv-event-copy">
                                    <div class="cv-tag-row"><span class="cv-kicker"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$appearance['appearance_type']))) ?></span><span class="cv-status"><?= coveted_e(ucfirst((string)$appearance['status'])) ?></span></div>
                                    <h2><?= coveted_e($appearance['title']) ?></h2>
                                    <p><?= coveted_e($appearance['group_name']) ?> · <?= coveted_e(coveted_event_format($appearance, 'g:i A T')) ?></p>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>

                <aside class="cv-stack">
                    <article class="cv-card">
                        <span class="cv-kicker">PARTNER RELATIONSHIPS</span>
                        <h2><?= count($relationships) ?> Coveted group<?= count($relationships) === 1 ? '' : 's' ?>.</h2>
                        <div class="cv-role-request-list">
                            <?php if (!$relationships): ?><p>No group partnership history yet.</p><?php endif; ?>
                            <?php foreach (array_slice($relationships, 0, 6) as $relationship): ?>
                                <div class="cv-mini-row"><div><strong><?= coveted_e($relationship['group_name']) ?></strong><span><?= coveted_e(ucwords(str_replace('_', ' ', (string)$relationship['relationship_status']))) ?></span></div><a class="cv-text-link" href="/group.php?id=<?= coveted_e($relationship['group_public_id']) ?>">Group →</a></div>
                            <?php endforeach; ?>
                        </div>
                    </article>
                    <article class="cv-card">
                        <span class="cv-kicker">REWARD CHANNEL</span>
                        <h2><?= (int)$metrics['campaigns'] ?> campaign<?= (int)$metrics['campaigns'] === 1 ? '' : 's' ?>.</h2>
                        <p>Artist rewards can carry audio, video or access content into the member Benefits wallet. System Admin controls manual distribution.</p>
                        <a class="cv-text-link" href="?artist=<?= coveted_e($artist['public_id']) ?>&view=rewards">Manage rewards →</a>
                    </article>
                </aside>
            </section>
        <?php endif; ?>

        <?php if ($view === 'profile'): ?>
            <section class="cv-two-column">
                <?php if ($canManage): ?>
                    <form class="cv-card cv-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_artist">
                        <input type="hidden" name="artist_ref" value="<?= coveted_e($artist['public_id']) ?>">
                        <input type="hidden" name="view" value="profile">
                        <span class="cv-eyebrow">ARTIST IDENTITY</span>
                        <h2>Profile details</h2>
                        <label>Artist name<input name="artist_name" maxlength="180" value="<?= coveted_e($artist['artist_name']) ?>" required></label>
                        <label>Bio<textarea name="bio" maxlength="5000" rows="7"><?= coveted_e($artist['bio'] ?? '') ?></textarea></label>
                        <div class="cv-form-row">
                            <label>Avatar image URL<input type="url" name="avatar_url" maxlength="700" value="<?= coveted_e($artist['avatar_url'] ?? '') ?>"></label>
                            <label>Cover image URL<input type="url" name="cover_url" maxlength="700" value="<?= coveted_e($artist['cover_url'] ?? '') ?>"></label>
                        </div>
                        <label>Website<input type="url" name="website_url" maxlength="700" value="<?= coveted_e($links['Website'] ?? '') ?>"></label>
                        <label>Instagram<input type="url" name="instagram_url" maxlength="700" value="<?= coveted_e($links['Instagram'] ?? '') ?>"></label>
                        <label>Spotify<input type="url" name="spotify_url" maxlength="700" value="<?= coveted_e($links['Spotify'] ?? '') ?>"></label>
                        <button class="cv-button cv-button-primary" type="submit">Save Artist</button>
                    </form>
                <?php else: ?>
                    <article class="cv-card cv-copy-card"><h2>Profile is read-only.</h2><p>Your artist team role does not include profile management.</p></article>
                <?php endif; ?>

                <aside class="cv-stack">
                    <article class="cv-card">
                        <span class="cv-kicker">STATUS</span>
                        <h2><?= coveted_e(ucfirst((string)$artist['status'])) ?></h2>
                        <p>Pausing prevents active artist rewards/campaigns from being published. Archived artists can only be restored by System Admin.</p>
                        <?php if ($canManageTeam): ?>
                            <form method="post" class="cv-inline-form">
                                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                <input type="hidden" name="action" value="artist_status">
                                <input type="hidden" name="artist_ref" value="<?= coveted_e($artist['public_id']) ?>">
                                <input type="hidden" name="view" value="profile">
                                <select name="status">
                                    <?php foreach (['active','paused','archived'] as $status): ?>
                                        <option value="<?= coveted_e($status) ?>" <?= $artist['status'] === $status ? 'selected' : '' ?>><?= coveted_e(ucfirst($status)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="cv-button cv-button-soft" type="submit">Update</button>
                            </form>
                        <?php endif; ?>
                    </article>
                    <article class="cv-card"><span class="cv-kicker">OWNERSHIP</span><h2><?= coveted_e($artist['owner_display_name']) ?></h2><p>Created <?= coveted_e($formatAccountTime((string)$artist['created_at'])) ?> · Your permission: <?= coveted_e(ucfirst($permission)) ?></p></article>
                </aside>
            </section>
        <?php endif; ?>

        <?php if ($view === 'team'): ?>
            <section class="cv-two-column">
                <div>
                    <div class="cv-section-head"><div><span class="cv-eyebrow">TEAM</span><h2>Artist access</h2></div></div>
                    <div class="cv-stack">
                        <?php foreach ($team as $member): ?>
                            <article class="cv-card cv-member-row">
                                <div><strong><?= coveted_e($member['display_name']) ?></strong><span><?= coveted_e($member['email']) ?> · <?= coveted_e(ucfirst((string)$member['member_role'])) ?></span></div>
                                <div class="cv-member-actions">
                                    <span class="cv-status"><?= coveted_e(ucfirst((string)$member['status'])) ?></span>
                                    <?php if ($canManageTeam && $member['member_role'] !== 'owner'): ?>
                                        <form method="post" data-confirm="Remove this member from the artist team?">
                                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="remove_member">
                                            <input type="hidden" name="artist_ref" value="<?= coveted_e($artist['public_id']) ?>">
                                            <input type="hidden" name="view" value="team">
                                            <input type="hidden" name="user_id" value="<?= (int)$member['user_id'] ?>">
                                            <button class="cv-button cv-button-soft" type="submit">Remove</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>

                <aside class="cv-stack">
                    <?php if ($canManageTeam): ?>
                        <form class="cv-card cv-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                            <input type="hidden" name="action" value="add_member">
                            <input type="hidden" name="artist_ref" value="<?= coveted_e($artist['public_id']) ?>">
                            <input type="hidden" name="view" value="team">
                            <span class="cv-eyebrow">ADD TEAM MEMBER</span>
                            <h2>Share artist access.</h2>
                            <label>Coveted member email<input type="email" name="email" required></label>
                            <label>Role<select name="member_role"><option value="member">Member</option><option value="manager">Manager</option></select></label>
                            <p class="cv-form-help">Managers can edit the artist and manage rewards. Manager accounts must already have Artist Partner approval.</p>
                            <button class="cv-button cv-button-primary" type="submit">Add Member</button>
                        </form>
                    <?php endif; ?>
                    <article class="cv-card"><span class="cv-kicker">AUTHORITY</span><h2>Owner → Manager → Member</h2><p>Owners manage team and status. Managers manage artist content and rewards. Members have read-only workspace access.</p></article>
                </aside>
            </section>
        <?php endif; ?>

        <?php if ($view === 'appearances'): ?>
            <div class="cv-section-head"><div><span class="cv-eyebrow">APPEARANCES</span><h2>Coveted gatherings</h2></div><span class="cv-status"><?= count($appearances) ?> total</span></div>
            <div class="cv-list">
                <?php if (!$appearances): ?><div class="cv-card cv-empty"><h3>No appearances yet.</h3><p>Hosts can assign Artist Partners from the Event Host Workspace.</p></div><?php endif; ?>
                <?php foreach ($appearances as $appearance): ?>
                    <article class="cv-card cv-event-row">
                        <div class="cv-event-date"><strong><?= coveted_e(coveted_event_format($appearance, 'M')) ?></strong><span><?= coveted_e(coveted_event_format($appearance, 'j')) ?></span></div>
                        <div class="cv-event-copy">
                            <div class="cv-tag-row"><span class="cv-kicker"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$appearance['appearance_type']))) ?></span><span class="cv-status"><?= coveted_e(ucfirst((string)$appearance['status'])) ?></span></div>
                            <h2><?= coveted_e($appearance['title']) ?></h2>
                            <p><?= coveted_e($appearance['group_name']) ?> · <?= coveted_e(coveted_event_format($appearance, 'M j, Y · g:i A T')) ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($view === 'insights' && $insights): ?>
            <?php $summary = $insights['summary']; ?>
            <div class="cv-section-head">
                <div>
                    <span class="cv-eyebrow">ARTIST OUTCOMES</span>
                    <h2>Measure the gathering, not a follower count</h2>
                    <p>Aggregate event and reward outcomes only. Individual attendees, reconnect choices and private feedback stay private.</p>
                </div>
                <form class="cv-business-selector" method="get">
                    <input type="hidden" name="artist" value="<?= coveted_e($artist['public_id']) ?>">
                    <input type="hidden" name="view" value="insights">
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

            <section class="cv-stat-grid cv-home-stats" aria-label="Artist outcome summary">
                <article class="cv-card cv-stat"><strong><?= (int)$summary['completed_appearances'] ?></strong><span>Completed appearances</span></article>
                <article class="cv-card cv-stat"><strong><?= (int)$summary['verified_audience_visits'] ?></strong><span>Verified audience visits</span></article>
                <article class="cv-card cv-stat"><strong><?= (int)$summary['unique_audience'] ?></strong><span>People reached</span></article>
                <article class="cv-card cv-stat"><strong><?= (int)$summary['repeat_audience'] ?></strong><span>Repeat audience</span></article>
                <article class="cv-card cv-stat"><strong><?= coveted_e(number_format((float)$summary['repeat_rate'], 1)) ?>%</strong><span>Repeat audience rate</span></article>
                <article class="cv-card cv-stat"><strong><?= (int)$summary['rewards_delivered'] ?></strong><span>Media benefits delivered</span></article>
                <article class="cv-card cv-stat"><strong><?= (int)$summary['rewards_opened'] ?></strong><span>Benefits opened</span></article>
                <article class="cv-card cv-stat"><strong><?= coveted_e(number_format((float)$summary['open_rate'], 1)) ?>%</strong><span>Benefit open rate</span></article>
            </section>

            <section class="cv-two-column">
                <article class="cv-card cv-copy-card">
                    <span class="cv-kicker">REAL-WORLD REACH</span>
                    <h2><?= (int)$summary['groups_reached'] ?> Coveted group<?= (int)$summary['groups_reached'] === 1 ? '' : 's' ?> reached.</h2>
                    <p>Audience reach comes only from verified attendance at completed Coveted gatherings where this artist appeared. Repeat audience means the same member attended at least two qualifying appearances in the selected period.</p>
                    <div class="cv-tag-row">
                        <span class="cv-pill"><?= (int)$summary['reward_recipients'] ?> reward recipient<?= (int)$summary['reward_recipients'] === 1 ? '' : 's' ?></span>
                        <span class="cv-pill"><?= (int)$summary['completed_appearances'] ?> completed appearance<?= (int)$summary['completed_appearances'] === 1 ? '' : 's' ?></span>
                    </div>
                </article>
                <aside class="cv-card cv-copy-card">
                    <span class="cv-kicker">GROUP RELATIONSHIPS</span>
                    <h2>Repeat partnership depth</h2>
                    <div class="cv-role-request-list">
                        <?php foreach ([
                            'preferred_partner' => 'Preferred Partner',
                            'partner' => 'Partner',
                            'featured' => 'Featured',
                            'new' => 'New',
                        ] as $relationshipKey => $relationshipLabel): ?>
                            <div class="cv-mini-row"><div><strong><?= (int)($insights['relationship_counts'][$relationshipKey] ?? 0) ?></strong><span><?= coveted_e($relationshipLabel) ?></span></div></div>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </section>

            <section class="cv-card cv-table-card">
                <div class="cv-section-heading"><span class="cv-kicker">APPEARANCE OUTCOMES</span><h2>Gathering-by-gathering reach</h2></div>
                <?php if (!$insights['appearances']): ?>
                    <p>No qualifying appearances in this period.</p>
                <?php else: ?>
                    <div class="cv-table-wrap"><table class="cv-table">
                        <thead><tr><th>Gathering</th><th>Group</th><th>Status</th><th>Attendance</th><th>Rewards</th><th>Opened</th><th>Open rate</th></tr></thead>
                        <tbody>
                        <?php foreach ($insights['appearances'] as $appearance): ?>
                            <tr>
                                <td><strong><?= coveted_e($appearance['title']) ?></strong><br><small><?= coveted_e(coveted_event_format($appearance, 'M j, Y · g:i A T')) ?> · <?= coveted_e(ucwords(str_replace('_', ' ', (string)$appearance['appearance_type']))) ?></small></td>
                                <td><a class="cv-text-link" href="/group.php?id=<?= coveted_e($appearance['group_public_id']) ?>"><?= coveted_e($appearance['group_name']) ?></a></td>
                                <td><span class="cv-status"><?= coveted_e(ucfirst((string)$appearance['status'])) ?></span></td>
                                <td><?= (int)$appearance['verified_attendance'] ?></td>
                                <td><?= (int)$appearance['rewards_delivered'] ?></td>
                                <td><?= (int)$appearance['rewards_opened'] ?></td>
                                <td><?= coveted_e(number_format((float)$appearance['open_rate'], 1)) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </section>

            <section class="cv-card cv-table-card">
                <div class="cv-section-heading"><span class="cv-kicker">CAMPAIGN OUTCOMES</span><h2>Media reward performance</h2></div>
                <?php if (!$insights['campaigns']): ?>
                    <p>No non-archived artist campaigns yet.</p>
                <?php else: ?>
                    <div class="cv-table-wrap"><table class="cv-table">
                        <thead><tr><th>Campaign</th><th>Trigger</th><th>Delivered</th><th>Recipients</th><th>Opened</th><th>Open rate</th></tr></thead>
                        <tbody>
                        <?php foreach ($insights['campaigns'] as $campaign): ?>
                            <tr>
                                <td><strong><?= coveted_e($campaign['title']) ?></strong><br><small><?= coveted_e($campaign['reward_title']) ?> · <?= coveted_e(ucfirst((string)$campaign['status'])) ?></small></td>
                                <td><?= coveted_e(ucwords(str_replace('_', ' ', (string)$campaign['trigger_key']))) ?></td>
                                <td><?= (int)$campaign['delivered_count'] ?></td>
                                <td><?= (int)$campaign['recipients'] ?></td>
                                <td><?= (int)$campaign['opened_count'] ?></td>
                                <td><?= coveted_e(number_format((float)$campaign['open_rate'], 1)) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($view === 'rewards'): ?>
            <?php if ($canManage): ?>
                <section class="cv-workspace-grid">
                    <form class="cv-card cv-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="create_reward">
                        <input type="hidden" name="artist_ref" value="<?= coveted_e($artist['public_id']) ?>">
                        <input type="hidden" name="artist_id" value="<?= (int)$artist['id'] ?>">
                        <input type="hidden" name="view" value="rewards">
                        <span class="cv-eyebrow">NEW REWARD</span>
                        <h2>Member media benefit</h2>
                        <label>Title<input name="title" maxlength="190" required></label>
                        <label>Description<textarea name="description" maxlength="4000" rows="4"></textarea></label>
                        <div class="cv-form-row">
                            <label>Type<select name="reward_type"><option value="audio">Audio</option><option value="video">Video</option><option value="media_pack">Media Pack</option><option value="access">Access</option><option value="experience">Experience</option><option value="custom">Custom</option></select></label>
                            <label>Status<select name="status"><option value="draft">Draft</option><option value="active">Active</option></select></label>
                        </div>
                        <label>Value / member copy<input name="value_text" maxlength="255" placeholder="Exclusive demo, backstage clip, early access…"></label>
                        <label>Cover image URL<input type="url" name="cover_url" maxlength="700"></label>
                        <button class="cv-button cv-button-primary" type="submit">Create Reward</button>
                    </form>

                    <form class="cv-card cv-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="create_campaign">
                        <input type="hidden" name="artist_ref" value="<?= coveted_e($artist['public_id']) ?>">
                        <input type="hidden" name="artist_id" value="<?= (int)$artist['id'] ?>">
                        <input type="hidden" name="view" value="rewards">
                        <span class="cv-eyebrow">NEW CAMPAIGN</span>
                        <h2>Prepare distribution</h2>
                        <?php if (!$rewards): ?>
                            <p>Create a reward first.</p>
                        <?php else: ?>
                            <label>Campaign title<input name="title" maxlength="190" required></label>
                            <label>Reward<select name="reward_template" required><?php foreach ($rewards as $reward): ?><option value="<?= coveted_e($reward['public_id']) ?>"><?= coveted_e($reward['title']) ?> · <?= coveted_e($reward['status']) ?></option><?php endforeach; ?></select></label>
                            <div class="cv-form-row"><label>Quantity limit<input type="number" min="1" name="quantity_limit" placeholder="Unlimited"></label><label>Status<select name="status"><option value="draft">Draft</option><option value="active">Active</option></select></label></div>
                            <p class="cv-form-help">Artist campaigns start as manual distribution. System Admin controls the actual member send from Distribution.</p>
                            <button class="cv-button cv-button-primary" type="submit">Create Campaign</button>
                        <?php endif; ?>
                    </form>
                </section>
            <?php endif; ?>

            <div class="cv-section-head"><div><span class="cv-eyebrow">REWARDS</span><h2>Artist-owned benefits</h2></div><span class="cv-status"><?= count($rewards) ?> reward<?= count($rewards) === 1 ? '' : 's' ?></span></div>
            <section class="cv-benefit-grid">
                <?php if (!$rewards): ?><div class="cv-card cv-empty"><h3>No artist rewards yet.</h3><p>Create audio, video or access benefits for future distribution.</p></div><?php endif; ?>
                <?php foreach ($rewards as $reward): ?>
                    <?php $mediaItems = $rewardMedia[(int)$reward['id']] ?? []; $cover = coveted_safe_url($reward['cover_url'] ?? null, false); ?>
                    <article class="cv-card cv-benefit-card">
                        <?php if ($cover !== null): ?><img class="cv-benefit-cover" src="<?= coveted_e($cover) ?>" alt=""><?php endif; ?>
                        <div class="cv-benefit-body">
                            <div class="cv-tag-row"><span class="cv-kicker"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$reward['reward_type']))) ?></span><span class="cv-status"><?= coveted_e(ucfirst((string)$reward['status'])) ?></span></div>
                            <h2><?= coveted_e($reward['title']) ?></h2>
                            <?php if ($reward['description']): ?><p><?= coveted_e(mb_strimwidth((string)$reward['description'], 0, 260, '…')) ?></p><?php endif; ?>
                            <?php if ($reward['value_text']): ?><p><strong><?= coveted_e($reward['value_text']) ?></strong></p><?php endif; ?>

                            <?php if ($mediaItems): ?>
                                <div class="cv-media-list">
                                    <?php foreach ($mediaItems as $item): ?>
                                        <?php $mediaUrl = coveted_safe_url($item['media_url'] ?? null, false); if ($mediaUrl === null) { continue; } ?>
                                        <?php if ($item['media_type'] === 'audio'): ?>
                                            <button type="button" class="cv-media-action" data-play-audio data-src="<?= coveted_e($mediaUrl) ?>" data-title="<?= coveted_e($item['title'] ?: $reward['title']) ?>" data-artist="<?= coveted_e($artist['artist_name']) ?>" data-artwork="<?= coveted_e($cover ?? '') ?>">▶ <?= coveted_e($item['title'] ?: 'Play audio') ?></button>
                                        <?php elseif ($item['media_type'] === 'video'): ?>
                                            <a class="cv-media-action" href="<?= coveted_e($mediaUrl) ?>" target="_blank" rel="noopener noreferrer">Watch · <?= coveted_e($item['title'] ?: 'Video') ?></a>
                                        <?php else: ?>
                                            <a class="cv-media-action" href="<?= coveted_e($mediaUrl) ?>" target="_blank" rel="noopener noreferrer">Open · <?= coveted_e($item['title'] ?: ucfirst((string)$item['media_type'])) ?></a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($canManage): ?>
                                <form method="post" class="cv-inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="reward_status">
                                    <input type="hidden" name="artist_ref" value="<?= coveted_e($artist['public_id']) ?>">
                                    <input type="hidden" name="reward_ref" value="<?= coveted_e($reward['public_id']) ?>">
                                    <input type="hidden" name="view" value="rewards">
                                    <select name="status"><?php foreach (['draft','active','paused','archived'] as $status): ?><option value="<?= coveted_e($status) ?>" <?= $reward['status'] === $status ? 'selected' : '' ?>><?= coveted_e(ucfirst($status)) ?></option><?php endforeach; ?></select>
                                    <button class="cv-button cv-button-soft" type="submit">Status</button>
                                </form>

                                <details class="cv-form-details">
                                    <summary>Edit reward media</summary>
                                    <form method="post" class="cv-form">
                                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                        <input type="hidden" name="action" value="replace_reward_media">
                                        <input type="hidden" name="artist_ref" value="<?= coveted_e($artist['public_id']) ?>">
                                        <input type="hidden" name="reward_ref" value="<?= coveted_e($reward['public_id']) ?>">
                                        <input type="hidden" name="view" value="rewards">
                                        <?php $rows = array_merge($mediaItems, [['media_type' => 'audio', 'title' => '', 'media_url' => '', 'mime_type' => '']]); ?>
                                        <?php foreach ($rows as $index => $media): ?>
                                            <div class="cv-form-row">
                                                <label>Type<select name="media_type[]"><?php foreach (['audio','video','image','file'] as $type): ?><option value="<?= coveted_e($type) ?>" <?= ($media['media_type'] ?? '') === $type ? 'selected' : '' ?>><?= coveted_e(ucfirst($type)) ?></option><?php endforeach; ?></select></label>
                                                <label>Title<input name="media_title[]" maxlength="190" value="<?= coveted_e($media['title'] ?? '') ?>"></label>
                                            </div>
                                            <label>Media URL<input type="url" name="media_url[]" maxlength="1000" value="<?= coveted_e($media['media_url'] ?? '') ?>" placeholder="https://…"></label>
                                            <input type="hidden" name="mime_type[]" value="<?= coveted_e($media['mime_type'] ?? '') ?>">
                                        <?php endforeach; ?>
                                        <p class="cv-form-help">Existing rows stay intact unless you edit or clear them. The final blank row adds one more media item.</p>
                                        <button class="cv-button cv-button-soft" type="submit">Save Media</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <div class="cv-section-head"><div><span class="cv-eyebrow">CAMPAIGNS</span><h2>Distribution setup</h2></div><span class="cv-status"><?= count($campaigns) ?> campaign<?= count($campaigns) === 1 ? '' : 's' ?></span></div>
            <div class="cv-stack">
                <?php if (!$campaigns): ?><div class="cv-card cv-empty"><h3>No artist campaigns yet.</h3><p>Campaigns connect an artist reward to a future distribution action.</p></div><?php endif; ?>
                <?php foreach ($campaigns as $campaign): ?>
                    <article class="cv-card cv-admin-row">
                        <div><div class="cv-tag-row"><span class="cv-kicker"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$campaign['trigger_key']))) ?></span><span class="cv-status"><?= coveted_e(ucfirst((string)$campaign['status'])) ?></span></div><h3><?= coveted_e($campaign['title']) ?></h3><p><?= coveted_e($campaign['reward_title']) ?> · <?= $campaign['quantity_limit'] !== null ? (int)$campaign['quantity_limit'] . ' max' : 'Unlimited quantity' ?></p></div>
                        <?php if ($canManage): ?>
                            <form method="post" class="cv-inline-form">
                                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                <input type="hidden" name="action" value="campaign_status">
                                <input type="hidden" name="artist_ref" value="<?= coveted_e($artist['public_id']) ?>">
                                <input type="hidden" name="campaign_ref" value="<?= coveted_e($campaign['public_id']) ?>">
                                <input type="hidden" name="view" value="rewards">
                                <select name="status"><?php foreach (['draft','active','paused','archived'] as $status): ?><option value="<?= coveted_e($status) ?>" <?= $campaign['status'] === $status ? 'selected' : '' ?>><?= coveted_e(ucfirst($status)) ?></option><?php endforeach; ?></select>
                                <button class="cv-button cv-button-soft" type="submit">Status</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
<?php coveted_page_end(); ?>