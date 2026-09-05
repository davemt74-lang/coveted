<?php
declare(strict_types=1);

require_once __DIR__ . '/push.php';

function coveted_pwa_asset_definitions(): array
{
    return [
        'icon_192' => [
            'label' => 'App icon · 192×192',
            'filename' => 'icon-192.png',
            'width' => 192,
            'height' => 192,
            'max_bytes' => 2 * 1024 * 1024,
        ],
        'icon_512' => [
            'label' => 'App icon · 512×512',
            'filename' => 'icon-512.png',
            'width' => 512,
            'height' => 512,
            'max_bytes' => 4 * 1024 * 1024,
        ],
        'icon_maskable' => [
            'label' => 'Maskable icon · 512×512',
            'filename' => 'icon-maskable-512.png',
            'width' => 512,
            'height' => 512,
            'max_bytes' => 4 * 1024 * 1024,
        ],
        'apple_touch_icon' => [
            'label' => 'Apple touch icon · 180×180',
            'filename' => 'apple-touch-icon.png',
            'width' => 180,
            'height' => 180,
            'max_bytes' => 2 * 1024 * 1024,
        ],
        'splash_portrait' => [
            'label' => 'Splash · portrait',
            'filename' => 'splash-portrait.png',
            'min_width' => 750,
            'min_height' => 1200,
            'orientation' => 'portrait',
            'max_bytes' => 8 * 1024 * 1024,
        ],
        'splash_landscape' => [
            'label' => 'Splash · landscape',
            'filename' => 'splash-landscape.png',
            'min_width' => 1200,
            'min_height' => 750,
            'orientation' => 'landscape',
            'max_bytes' => 8 * 1024 * 1024,
        ],
    ];
}

function coveted_pwa_upload_dir(): string
{
    return dirname(__DIR__) . '/uploads/pwa';
}

function coveted_pwa_public_path(string $filename): string
{
    return '/uploads/pwa/' . rawurlencode($filename);
}

function coveted_pwa_asset_lock_name(string $assetKey): string
{
    return 'covpwa:' . substr(hash('sha256', $assetKey), 0, 54);
}

function coveted_pwa_acquire_asset_lock(PDO $pdo, string $assetKey): string
{
    $name = coveted_pwa_asset_lock_name($assetKey);
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $stmt->execute([$name]);

    if ((int)$stmt->fetchColumn() !== 1) {
        throw new RuntimeException('That PWA artwork is already being changed. Try again shortly.');
    }

    return $name;
}

function coveted_pwa_release_asset_lock(PDO $pdo, string $name): void
{
    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$name]);
    } catch (Throwable $e) {
        error_log('Coveted PWA asset lock release failed: ' . $e->getMessage());
    }
}

function coveted_pwa_asset_from_file(string $assetKey, array $definition): ?array
{
    $filename = (string)$definition['filename'];
    $path = coveted_pwa_upload_dir() . '/' . $filename;
    if (!is_file($path)) {
        return null;
    }

    $image = @getimagesize($path);
    if (!is_array($image)) {
        return null;
    }

    $checksum = hash_file('sha256', $path);
    if ($checksum === false) {
        return null;
    }

    return [
        'asset_key' => $assetKey,
        'filename' => $filename,
        'public_path' => coveted_pwa_public_path($filename),
        'mime_type' => (string)($image['mime'] ?? 'image/png'),
        'width' => (int)$image[0],
        'height' => (int)$image[1],
        'file_bytes' => (int)(filesize($path) ?: 0),
        'checksum_sha256' => $checksum,
        'updated_at' => date('Y-m-d H:i:s', (int)(filemtime($path) ?: time())),
    ];
}

function coveted_pwa_assets(): array
{
    $assets = [];
    foreach (coveted_pwa_asset_definitions() as $assetKey => $definition) {
        $asset = coveted_pwa_asset_from_file($assetKey, $definition);
        if ($asset !== null) {
            $assets[$assetKey] = $asset;
        }
    }
    return $assets;
}

function coveted_pwa_asset(string $assetKey): ?array
{
    $definitions = coveted_pwa_asset_definitions();
    return isset($definitions[$assetKey])
        ? coveted_pwa_asset_from_file($assetKey, $definitions[$assetKey])
        : null;
}

function coveted_pwa_validate_uploaded_image(array $file, array $definition): array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded image is too large.',
            UPLOAD_ERR_PARTIAL => 'The upload did not finish. Try again.',
            UPLOAD_ERR_NO_FILE => 'Choose an image to upload.',
            default => 'Unable to upload that image.',
        });
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($tmp === '' || $size < 1 || $size > (int)$definition['max_bytes']) {
        throw new InvalidArgumentException('The uploaded image is empty or too large.');
    }

    $image = @getimagesize($tmp);
    if (!is_array($image)) {
        throw new InvalidArgumentException('Upload a valid PNG image.');
    }

    [$width, $height, $type] = $image;
    if ((int)$type !== IMAGETYPE_PNG || strtolower((string)($image['mime'] ?? '')) !== 'image/png') {
        throw new InvalidArgumentException('PWA artwork must be a PNG image.');
    }

    if (isset($definition['width'], $definition['height'])) {
        if ($width !== (int)$definition['width'] || $height !== (int)$definition['height']) {
            throw new InvalidArgumentException(
                'This asset must be exactly ' . (int)$definition['width'] . '×' . (int)$definition['height'] . ' pixels.'
            );
        }
    } else {
        if ($width < (int)$definition['min_width'] || $height < (int)$definition['min_height']) {
            throw new InvalidArgumentException('Splash artwork is too small for this slot.');
        }
        if ($definition['orientation'] === 'portrait' && $height <= $width) {
            throw new InvalidArgumentException('Upload portrait artwork for the portrait splash slot.');
        }
        if ($definition['orientation'] === 'landscape' && $width <= $height) {
            throw new InvalidArgumentException('Upload landscape artwork for the landscape splash slot.');
        }
    }

    $checksum = hash_file('sha256', $tmp);
    if ($checksum === false) {
        throw new RuntimeException('Unable to checksum uploaded PWA artwork.');
    }

    return [
        'tmp_name' => $tmp,
        'width' => $width,
        'height' => $height,
        'bytes' => $size,
        'mime_type' => 'image/png',
        'checksum' => $checksum,
    ];
}

function coveted_pwa_store_uploaded_asset(array $actor, string $assetKey, array $file): array
{
    if (!coveted_is_system_admin($actor)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    $definitions = coveted_pwa_asset_definitions();
    if (!isset($definitions[$assetKey])) {
        throw new InvalidArgumentException('Unknown PWA asset slot.');
    }

    $definition = $definitions[$assetKey];
    $validated = coveted_pwa_validate_uploaded_image($file, $definition);
    $directory = coveted_pwa_upload_dir();

    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the PWA upload directory.');
    }

    $filename = (string)$definition['filename'];
    $destination = $directory . '/' . $filename;
    $token = bin2hex(random_bytes(6));
    $staging = $directory . '/.' . $filename . '.' . $token . '.tmp';
    $previous = $directory . '/.' . $filename . '.' . $token . '.previous';

    $moved = is_uploaded_file($validated['tmp_name'])
        ? move_uploaded_file($validated['tmp_name'], $staging)
        : ((coveted_config('app')['environment'] ?? 'production') === 'testing'
            && rename($validated['tmp_name'], $staging));

    if (!$moved) {
        throw new RuntimeException('Unable to store the uploaded PWA image.');
    }

    @chmod($staging, 0644);

    $pdo = coveted_db();
    $lockName = coveted_pwa_acquire_asset_lock($pdo, $assetKey);
    $hadPrevious = false;

    try {
        $pdo->beginTransaction();

        if (is_file($destination)) {
            if (!rename($destination, $previous)) {
                throw new RuntimeException('Unable to stage the existing PWA artwork for replacement.');
            }
            $hadPrevious = true;
        }

        if (!rename($staging, $destination)) {
            throw new RuntimeException('Unable to activate the uploaded PWA image.');
        }

        coveted_audit(
            'pwa.asset_uploaded',
            'pwa_asset',
            $assetKey,
            [
                'filename' => $filename,
                'width' => $validated['width'],
                'height' => $validated['height'],
                'bytes' => $validated['bytes'],
                'checksum_sha256' => $validated['checksum'],
            ],
            (int)$actor['id']
        );

        $pdo->commit();

        if ($hadPrevious && is_file($previous) && !@unlink($previous)) {
            error_log('Coveted could not remove replaced PWA artwork staging file: ' . $previous);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (is_file($destination)) {
            @unlink($destination);
        }
        if ($hadPrevious && is_file($previous) && !@rename($previous, $destination)) {
            error_log('Coveted could not restore prior PWA artwork after a failed replacement.');
        }
        if (is_file($staging)) {
            @unlink($staging);
        }
        throw $e;
    } finally {
        coveted_pwa_release_asset_lock($pdo, $lockName);
    }

    clearstatcache(true, $destination);
    $asset = coveted_pwa_asset($assetKey);
    if (!$asset) {
        throw new RuntimeException('Uploaded PWA artwork could not be read back.');
    }
    return $asset;
}

function coveted_pwa_delete_asset(array $actor, string $assetKey): void
{
    if (!coveted_is_system_admin($actor)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    $definitions = coveted_pwa_asset_definitions();
    if (!isset($definitions[$assetKey])) {
        throw new InvalidArgumentException('Unknown PWA asset slot.');
    }

    $directory = coveted_pwa_upload_dir();
    $path = $directory . '/' . (string)$definitions[$assetKey]['filename'];
    $staging = $directory . '/.' . (string)$definitions[$assetKey]['filename'] . '.' . bin2hex(random_bytes(6)) . '.delete';
    $pdo = coveted_db();
    $lockName = coveted_pwa_acquire_asset_lock($pdo, $assetKey);
    $moved = false;

    try {
        $pdo->beginTransaction();

        if (is_file($path)) {
            if (!rename($path, $staging)) {
                throw new RuntimeException('Unable to stage the PWA artwork for removal.');
            }
            $moved = true;
        }

        coveted_audit('pwa.asset_deleted', 'pwa_asset', $assetKey, [], (int)$actor['id']);
        $pdo->commit();

        if ($moved && is_file($staging) && !@unlink($staging)) {
            error_log('Coveted could not remove deleted PWA artwork staging file: ' . $staging);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($moved && is_file($staging) && !@rename($staging, $path)) {
            error_log('Coveted could not restore PWA artwork after a failed delete.');
        }
        throw $e;
    } finally {
        coveted_pwa_release_asset_lock($pdo, $lockName);
    }
}

function coveted_pwa_status(): array
{
    $definitions = coveted_pwa_asset_definitions();
    $assets = coveted_pwa_assets();
    $push = coveted_push_config_status();

    return [
        'assets_ready' => count($assets),
        'assets_total' => count($definitions),
        'manifest_ready' => isset($assets['icon_192'], $assets['icon_512']),
        'apple_ready' => isset($assets['apple_touch_icon']),
        'splash_ready' => isset($assets['splash_portrait'], $assets['splash_landscape']),
        'push_transport' => $push['configured'] ? 'configured' : ($push['enabled'] ? 'incomplete' : 'disabled'),
    ];
}