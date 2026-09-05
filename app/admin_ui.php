<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_onboarding.php';

/**
 * Read-only counts for the System Admin shell.
 *
 * @return array{users:int,groups:int,events:int,businesses:int,artists:int,pending_requests:int}
 */
function coveted_admin_ui_safe_count(PDO $pdo, string $sql, string $label): int
{
    try {
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        error_log('Coveted Admin count unavailable [' . $label . ']: ' . $e->getMessage());
        return 0;
    }
}

function coveted_admin_ui_counts(?PDO $pdo = null): array
{
    $pdo ??= coveted_db();

    return [
        'users' => coveted_admin_ui_safe_count($pdo, "SELECT COUNT(*) FROM users WHERE status <> 'deleted'", 'users'),
        'groups' => coveted_admin_ui_safe_count($pdo, "SELECT COUNT(*) FROM social_groups WHERE status <> 'archived'", 'groups'),
        'events' => coveted_admin_ui_safe_count($pdo, "SELECT COUNT(*) FROM events WHERE status <> 'cancelled'", 'events'),
        'businesses' => coveted_admin_ui_safe_count($pdo, "SELECT COUNT(*) FROM businesses WHERE status <> 'archived'", 'businesses'),
        'artists' => coveted_admin_ui_safe_count($pdo, "SELECT COUNT(*) FROM artist_profiles WHERE status <> 'archived'", 'artists'),
        'pending_requests' => coveted_admin_ui_safe_count($pdo, "SELECT COUNT(*) FROM role_requests WHERE status = 'pending'", 'pending_requests'),
    ];
}

function coveted_admin_ui_initials(string $name): string
{
    return coveted_shell_initials($name);
}

function coveted_admin_nav_link(string $active, string $key, string $href, string $label, ?int $count = null): void
{
    ?>
    <a class="<?= $active === $key ? 'is-active' : '' ?>" href="<?= coveted_e($href) ?>">
        <span class="cv-admin-nav-text"><?= coveted_e($label) ?></span>
        <?php if ($count !== null): ?><span class="cv-admin-nav-count"><?= $count ?></span><?php endif; ?>
    </a>
    <?php
}

/**
 * Dedicated System Admin application shell. Admin pages never inherit the
 * member sidebar; switching back to the member app is always explicit.
 */
function coveted_admin_ui_start(
    array $admin,
    string $active,
    string $pageTitle,
    ?array $counts = null
): void {
    $pdo = coveted_db();
    $counts ??= coveted_admin_ui_counts($pdo);
    $onboarding = coveted_admin_onboarding_state($admin);

    $avatarUrl = coveted_shell_avatar_url((int)$admin['id']);
    $name = trim((string)($admin['display_name'] ?? 'Admin')) ?: 'Admin';
    $initials = coveted_admin_ui_initials($name);
    ?>
<div class="cv-admin-app" data-admin-shell="control-center-v4">
    <aside class="cv-admin-sidebar" aria-label="System Admin navigation">
        <a class="cv-admin-brand" href="/admin/">
            <span>COVETED</span>
            <small>ADMIN</small>
        </a>

        <nav class="cv-admin-primary-nav">
            <div class="cv-admin-nav-group">
                <span class="cv-admin-nav-label">OVERVIEW</span>
                <?php coveted_admin_nav_link($active, 'dashboard', '/admin/', 'Dashboard'); ?>
                <a class="<?= $active === 'onboarding' ? 'is-active' : '' ?>" href="/admin/onboarding.php">
                    <span class="cv-admin-nav-text">Setup</span>
                    <?php if (!$onboarding['is_complete']): ?><span class="cv-admin-nav-progress"><?= (int)$onboarding['completed'] ?>/<?= (int)$onboarding['total'] ?></span><?php endif; ?>
                </a>
            </div>

            <div class="cv-admin-nav-group">
                <span class="cv-admin-nav-label">PEOPLE</span>
                <?php coveted_admin_nav_link($active, 'users', '/admin/?view=users', 'Users', (int)$counts['users']); ?>
                <?php coveted_admin_nav_link($active, 'requests', '/admin/?view=requests', 'Role Requests', (int)$counts['pending_requests']); ?>
            </div>

            <div class="cv-admin-nav-group">
                <span class="cv-admin-nav-label">COMMUNITY</span>
                <?php coveted_admin_nav_link($active, 'businesses', '/admin/?view=businesses', 'Businesses', (int)$counts['businesses']); ?>
                <?php coveted_admin_nav_link($active, 'groups', '/admin/?view=groups', 'Groups', (int)$counts['groups']); ?>
                <?php coveted_admin_nav_link($active, 'events', '/admin/?view=events', 'Events', (int)$counts['events']); ?>
                <?php coveted_admin_nav_link($active, 'artists', '/admin/?view=artists', 'Artists', (int)$counts['artists']); ?>
            </div>

            <div class="cv-admin-nav-group">
                <span class="cv-admin-nav-label">VALUE</span>
                <?php coveted_admin_nav_link($active, 'benefits', '/admin/?view=benefits', 'Benefits'); ?>
                <?php coveted_admin_nav_link($active, 'distribution', '/admin/?view=distribution', 'Distribution'); ?>
            </div>

            <div class="cv-admin-nav-group">
                <span class="cv-admin-nav-label">PLATFORM</span>
                <?php coveted_admin_nav_link($active, 'operations', '/admin/operations.php', 'Operations'); ?>
                <?php coveted_admin_nav_link($active, 'landing', '/admin/landing.php', 'Landing Page'); ?>
                <?php coveted_admin_nav_link($active, 'settings', '/admin/?view=settings', 'Settings'); ?>
            </div>
        </nav>

        <div class="cv-admin-sidebar-footer">
            <a href="/">← Member View</a>
        </div>
    </aside>

    <div class="cv-admin-workspace">
        <header class="cv-admin-header">
            <div class="cv-admin-header-copy">
                <span class="cv-eyebrow">CONTROL CENTER</span>
                <strong><?= coveted_e($pageTitle) ?></strong>
            </div>

            <div class="cv-admin-header-actions">
                <details class="cv-admin-dropdown cv-admin-create-menu">
                    <summary class="cv-button cv-button-primary"><span aria-hidden="true">＋</span> Create</summary>
                    <div class="cv-admin-menu cv-admin-create-panel">
                        <span class="cv-admin-menu-label">CREATE</span>
                        <a href="/admin/?view=users#create-user"><strong>User</strong><small>Create an account and assign access</small></a>
                        <a href="/admin/?view=businesses#create-business"><strong>Business</strong><small>Add a venue or partner</small></a>
                        <a href="/admin/?view=groups#create-group"><strong>Group</strong><small>Start a private community</small></a>
                        <a href="/admin/?view=events#create-event"><strong>Event</strong><small>Plan a new gathering</small></a>
                        <a href="/admin/?view=artists#create-artist"><strong>Artist</strong><small>Create an artist identity</small></a>
                        <a href="/admin/?view=benefits"><strong>Benefit</strong><small>Open rewards and campaign tools</small></a>
                    </div>
                </details>

                <details class="cv-admin-dropdown cv-admin-account-menu">
                    <summary class="cv-admin-avatar-button" aria-label="Open account menu">
                        <?php if ($avatarUrl !== null): ?>
                            <img src="<?= coveted_e($avatarUrl) ?>" alt="">
                        <?php else: ?>
                            <span><?= coveted_e($initials) ?></span>
                        <?php endif; ?>
                    </summary>
                    <div class="cv-admin-menu cv-admin-account-panel">
                        <div class="cv-admin-account-summary">
                            <strong><?= coveted_e($name) ?></strong>
                            <small><?= coveted_e((string)$admin['email']) ?></small>
                            <span class="cv-admin-account-role">System Admin</span>
                        </div>
                        <a href="/profile.php"><strong>Profile</strong><small>Photo and account details</small></a>
                        <a href="/admin/onboarding.php"><strong>Admin Setup</strong><small>Review first-run setup</small></a>
                        <span class="cv-admin-menu-label">QUICK CREATE</span>
                        <a href="/admin/?view=users#create-user"><strong>Add User</strong><small>Create an account and assign access</small></a>
                        <a href="/admin/?view=businesses#create-business"><strong>Add Business</strong><small>Add a venue or partner</small></a>
                        <a href="/admin/?view=groups#create-group"><strong>Add Group</strong><small>Create a private community</small></a>
                        <a href="/admin/?view=events#create-event"><strong>Add Event</strong><small>Plan a gathering</small></a>
                        <a href="/"><strong>Member View</strong><small>Preview the attendee experience</small></a>
                        <form method="post" action="/auth.php?action=logout">
                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                            <button type="submit">Sign out</button>
                        </form>
                    </div>
                </details>
            </div>
        </header>

        <main class="cv-admin-content">
<?php
}

function coveted_admin_ui_end(): void
{
    ?>
        </main>
    </div>
</div>
<?php
}
