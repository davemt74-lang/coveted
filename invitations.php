<?php
declare(strict_types=1);

require_once __DIR__ . '/app/invitation_eligibility.php';
require_once __DIR__ . '/app/member_pages_v2.php';

$user = coveted_require_user();
$pdo = coveted_db();
$sampleMode = coveted_member_sample_mode($user, $pdo);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        if ($sampleMode) {
            throw new InvalidArgumentException('Sample invitations are preview-only. Turn Sample Data off to manage live invitations.');
        }

        $response = coveted_event_respond_invitation(
            $user,
            (string)($_POST['invite_id'] ?? ''),
            (string)($_POST['decision'] ?? ''),
            (int)($_POST['guest_count'] ?? 0)
        );

        $message = match ($response) {
            'attending' => 'You’re in.',
            'waitlist' => 'You’re on the waitlist.',
            default => 'Invitation updated.',
        };
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted invitation response error: ' . $e->getMessage());
        $error = 'Unable to update that invitation right now.';
    }
}

$invitations = coveted_member_v2_invitations($user, $pdo);
$now = time();
$buckets = ['waiting' => [], 'accepted' => [], 'maybe' => [], 'past' => []];
foreach ($invitations as $invite) {
    $bucket = coveted_member_v2_invitation_bucket($invite, $now);
    $buckets[$bucket][] = $invite;
}

foreach ($buckets as $key => &$rows) {
    usort(
        $rows,
        static fn(array $a, array $b): int => $key === 'past'
            ? strcmp((string)$b['starts_at'], (string)$a['starts_at'])
            : strcmp((string)$a['starts_at'], (string)$b['starts_at'])
    );
}
unset($rows);

$view = strtolower(trim((string)($_GET['view'] ?? 'waiting')));
$view = match ($view) {
    'pending' => 'waiting',
    'history' => 'past',
    'declined' => 'maybe',
    default => $view,
};
if (!array_key_exists($view, $buckets)) {
    $view = 'waiting';
}

$featured = $buckets['waiting'][0] ?? null;
$visibleInvitations = $view === 'waiting' && $featured !== null
    ? array_slice($buckets['waiting'], 1)
    : $buckets[$view];
$showInvitationList = $view !== 'waiting' || $featured === null || !empty($visibleInvitations);

coveted_page_start('Invitations', 'Invitations');
?>
<div class="cv-member-page-v2 cv-invitations-v2">
    <section class="cv-member-page-intro">
        <div>
            <span class="cv-eyebrow">INVITATIONS</span>
            <h1>Your invitations.</h1>
            <p>Know where you’re headed, who invited you and what needs a response. Then put the phone away and go.</p>
        </div>
        <?php if ($sampleMode): ?>
            <a class="cv-member-preview-pill" href="/admin/sample-data.php">Sample data · ON</a>
        <?php endif; ?>
    </section>

    <?php if ($message !== ''): ?><div class="cv-alert"><?= coveted_e($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>

    <nav class="cv-member-segmented-tabs" aria-label="Invitation views">
        <?php foreach ([
            'waiting' => 'Waiting',
            'accepted' => 'Accepted',
            'maybe' => 'Maybe later',
            'past' => 'Past',
        ] as $tabKey => $tabLabel): ?>
            <a class="<?= $view === $tabKey ? 'is-active' : '' ?>" href="/invitations.php?view=<?= coveted_e($tabKey) ?>">
                <span><?= coveted_e($tabLabel) ?></span>
                <?php if (count($buckets[$tabKey]) > 0): ?><small><?= count($buckets[$tabKey]) ?></small><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($featured && $view === 'waiting'): ?>
        <?php
        $featuredImage = trim((string)($featured['image'] ?? ''));
        $featuredCanRespond = !$sampleMode
            && $featured['status'] === 'pending'
            && $featured['event_status'] === 'published'
            && coveted_utc_datetime((string)$featured['starts_at'])->getTimestamp() > $now;
        ?>
        <section class="cv-invite-feature" aria-label="Featured invitation">
            <div class="cv-invite-feature-media <?= $featuredImage === '' ? 'is-empty' : '' ?>">
                <?php if ($featuredImage !== ''): ?><img src="<?= coveted_e($featuredImage) ?>" alt="" loading="eager" decoding="async"><?php endif; ?>
                <div class="cv-invite-feature-date">
                    <strong><?= coveted_e(coveted_event_format($featured, 'M')) ?></strong>
                    <span><?= coveted_e(coveted_event_format($featured, 'j')) ?></span>
                </div>
            </div>
            <div class="cv-invite-feature-copy">
                <span class="cv-member-overline">WAITING FOR YOU</span>
                <h2><?= coveted_e((string)$featured['title']) ?></h2>
                <p class="cv-invite-feature-lede"><?= coveted_e((string)($featured['description'] ?? '')) ?></p>
                <dl class="cv-member-detail-list">
                    <div><dt>When</dt><dd><?= coveted_e(coveted_event_format($featured, 'l, F j · g:i A')) ?></dd></div>
                    <div><dt>Group</dt><dd><?= coveted_e((string)$featured['group_name']) ?></dd></div>
                    <div><dt>Place</dt><dd><?= $featured['location_visibility'] === 'immediate' && !empty($featured['location_name']) ? coveted_e((string)$featured['location_name']) : 'Revealed later' ?></dd></div>
                </dl>

                <?php if ($featuredCanRespond): ?>
                    <form class="cv-member-rsvp-actions" method="post">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="invite_id" value="<?= coveted_e((string)$featured['public_id']) ?>">
                        <?php if ((bool)$featured['plus_one_allowed']): ?>
                            <select name="guest_count" aria-label="Guests">
                                <option value="0">Just me</option>
                                <option value="1">Me + one guest</option>
                            </select>
                        <?php else: ?>
                            <input type="hidden" name="guest_count" value="0">
                        <?php endif; ?>
                        <button class="cv-button cv-button-primary" name="decision" value="accepted" type="submit">I’m in</button>
                        <button class="cv-button cv-button-soft" name="decision" value="declined" type="submit">Not this time</button>
                    </form>
                <?php elseif ($sampleMode): ?>
                    <div class="cv-member-preview-note">Preview only · live RSVP actions stay disabled in Sample Data mode.</div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($showInvitationList): ?>
        <section class="cv-member-section-head">
            <div>
                <span class="cv-member-overline"><?= coveted_e(strtoupper($view === 'maybe' ? 'MAYBE LATER' : $view)) ?></span>
                <h2><?= match ($view) {
                    'accepted' => 'You said yes.',
                    'maybe' => 'Not this time.',
                    'past' => 'Where you’ve been invited.',
                    default => $featured ? 'More invitations.' : 'Waiting on your answer.',
                } ?></h2>
            </div>
        </section>

        <?php if (!$visibleInvitations): ?>
            <div class="cv-member-empty-v2">
                <span><?= $view === 'waiting' ? 'All caught up' : 'Nothing here yet' ?></span>
                <h2><?= match ($view) {
                    'accepted' => 'No accepted invitations yet.',
                    'maybe' => 'No invitations set aside.',
                    'past' => 'Your invitation history will live here.',
                    default => 'Nothing needs your response.',
                } ?></h2>
                <p>Coveted keeps this list intentionally small so the invitation stays about the gathering, not the feed.</p>
            </div>
        <?php else: ?>
            <div class="cv-invite-card-grid">
                <?php foreach ($visibleInvitations as $invite): ?>
                    <?php
                    $image = trim((string)($invite['image'] ?? ''));
                    $future = coveted_utc_datetime((string)$invite['starts_at'])->getTimestamp() > $now;
                    $canRespond = !$sampleMode
                        && $invite['status'] === 'pending'
                        && $invite['event_status'] === 'published'
                        && $future;
                    ?>
                    <article class="cv-invite-card">
                        <div class="cv-invite-card-media <?= $image === '' ? 'is-empty' : '' ?>">
                            <?php if ($image !== ''): ?><img src="<?= coveted_e($image) ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                            <div class="cv-invite-card-date">
                                <strong><?= coveted_e(coveted_event_format($invite, 'M')) ?></strong>
                                <span><?= coveted_e(coveted_event_format($invite, 'j')) ?></span>
                            </div>
                        </div>
                        <div class="cv-invite-card-copy">
                            <span class="cv-member-overline"><?= coveted_e((string)$invite['group_name']) ?></span>
                            <h3><?= coveted_e((string)$invite['title']) ?></h3>
                            <p><?= coveted_e(coveted_event_format($invite, 'D, M j · g:i A')) ?></p>
                            <div class="cv-member-card-meta">
                                <?php if ($invite['location_visibility'] === 'immediate' && !empty($invite['location_name'])): ?>
                                    <span><?= coveted_e((string)$invite['location_name']) ?></span>
                                <?php else: ?>
                                    <span>Location revealed later</span>
                                <?php endif; ?>
                                <?php if ((int)($invite['guest_count'] ?? 0) === 1 && $invite['rsvp_response'] === 'attending'): ?><span>+1 included</span><?php endif; ?>
                            </div>

                            <?php if ($canRespond): ?>
                                <form class="cv-invite-card-actions" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                    <input type="hidden" name="invite_id" value="<?= coveted_e((string)$invite['public_id']) ?>">
                                    <input type="hidden" name="guest_count" value="0">
                                    <button class="cv-button cv-button-primary" name="decision" value="accepted" type="submit">Accept</button>
                                    <button class="cv-button cv-button-soft" name="decision" value="declined" type="submit">Maybe later</button>
                                </form>
                            <?php elseif ($sampleMode): ?>
                                <span class="cv-member-preview-chip">Preview</span>
                            <?php else: ?>
                                <span class="cv-member-status-chip"><?= coveted_e(match ($view) {
                                    'accepted' => 'Accepted',
                                    'maybe' => 'Passed',
                                    'past' => 'Past',
                                    default => 'Waiting',
                                }) ?></span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php coveted_page_end(); ?>
