<?php
declare(strict_types=1);

require_once __DIR__ . '/app/member_media.php';

$user = coveted_require_user();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        $action = trim((string)($_POST['action'] ?? ''));
        $issuanceRef = trim((string)($_POST['issuance'] ?? ''));
        $sortOrder = filter_var(
            $_POST['media'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 100]]
        );

        if ($action !== 'open' || $sortOrder === false) {
            throw new InvalidArgumentException('That video is not available.');
        }

        // Resolve entitlement before recording the intentional media use.
        coveted_member_video_context($user, $issuanceRef, (int)$sortOrder);
        coveted_member_video_mark_viewed($user, $issuanceRef);

        coveted_redirect(
            '/media.php?issuance=' . rawurlencode($issuanceRef)
            . '&media=' . rawurlencode((string)$sortOrder)
        );
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted member video open error: ' . $e->getMessage());
        $error = 'That video is not available right now.';
    }
}

$issuanceRef = trim((string)($_GET['issuance'] ?? ''));
$sortOrder = filter_input(
    INPUT_GET,
    'media',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0, 'max_range' => 100]]
);
$video = null;

if ($error === '' && $issuanceRef !== '') {
    try {
        if ($sortOrder === false || $sortOrder === null) {
            throw new InvalidArgumentException('That video is not available.');
        }
        $video = coveted_member_video_context($user, $issuanceRef, (int)$sortOrder);
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted member video load error: ' . $e->getMessage());
        $error = 'That video is not available right now.';
    }
} elseif ($error === '') {
    $error = 'That video is not available.';
}

coveted_page_start($video ? (string)($video['media_title'] ?: $video['reward_title']) : 'Video', 'Benefits');
?>
<section class="cv-page-heading">
    <span class="cv-eyebrow">COVETED VIDEO</span>
    <h1><?= $video ? coveted_e($video['media_title'] ?: $video['reward_title']) : 'Video unavailable' ?></h1>
    <?php if ($video && $video['artist_name']): ?><p><?= coveted_e($video['artist_name']) ?></p><?php endif; ?>
</section>

<?php if ($error !== ''): ?>
    <div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div>
    <div class="cv-action-row">
        <a class="cv-button cv-button-soft" href="/benefits.php">Back to Benefits</a>
    </div>
<?php elseif ($video): ?>
    <section class="cv-two-column">
        <article class="cv-card cv-benefit-card">
            <video
                class="cv-benefit-cover"
                controls
                playsinline
                preload="metadata"
                <?php if ($video['cover_url']): ?>poster="<?= coveted_e($video['cover_url']) ?>"<?php endif; ?>
            >
                <source
                    src="<?= coveted_e($video['media_url']) ?>"
                    <?php if ($video['mime_type']): ?>type="<?= coveted_e($video['mime_type']) ?>"<?php endif; ?>
                >
                Your browser does not support this video.
            </video>
        </article>

        <aside class="cv-card cv-copy-card">
            <span class="cv-kicker">MEMBER MEDIA</span>
            <h2><?= coveted_e($video['reward_title']) ?></h2>
            <?php if ($video['reward_description']): ?>
                <p><?= coveted_e(mb_strimwidth((string)$video['reward_description'], 0, 420, '…')) ?></p>
            <?php endif; ?>
            <div class="cv-meta-row">
                <?php if ($video['event_title']): ?><span>Unlocked at <?= coveted_e($video['event_title']) ?></span><?php endif; ?>
                <?php if ($video['artist_name']): ?><span><?= coveted_e($video['artist_name']) ?></span><?php endif; ?>
            </div>
            <div class="cv-action-row">
                <?php if ($video['event_public_id']): ?>
                    <a class="cv-button cv-button-soft" href="/event.php?event=<?= coveted_e(rawurlencode((string)$video['event_public_id'])) ?>">Event Memory</a>
                <?php endif; ?>
                <a class="cv-button cv-button-soft" href="/benefits.php">All Benefits</a>
            </div>
        </aside>
    </section>
<?php endif; ?>
<?php coveted_page_end(); ?>
