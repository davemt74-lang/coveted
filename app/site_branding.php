<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function coveted_site_branding_dir(): string
{
    return dirname(__DIR__) . '/uploads/branding';
}

/** @return array<string,string>|null */
function coveted_site_logo_asset(): ?array
{
    $directory = coveted_site_branding_dir();
    $types = [
        'site-logo.png' => 'image/png',
        'site-logo.webp' => 'image/webp',
        'site-logo.jpg' => 'image/jpeg',
    ];

    foreach ($types as $filename => $mime) {
        $path = $directory . '/' . $filename;
        if (!is_file($path)) {
            continue;
        }

        $image = @getimagesize($path);
        if (!is_array($image) || strtolower((string)($image['mime'] ?? '')) !== $mime) {
            continue;
        }

        return [
            'filename' => $filename,
            'path' => $path,
            'public_path' => '/uploads/branding/' . rawurlencode($filename),
            'mime_type' => $mime,
            'width' => (string)(int)$image[0],
            'height' => (string)(int)$image[1],
            'version' => (string)((int)(filemtime($path) ?: time())),
        ];
    }

    return null;
}

/** @return array{tmp_name:string,extension:string,mime_type:string,width:int,height:int,bytes:int} */
function coveted_site_logo_validate_upload(array $file): array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The logo image is too large.',
            UPLOAD_ERR_PARTIAL => 'The logo upload did not finish. Try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a logo image to upload.',
            default => 'Unable to upload that logo image.',
        });
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $bytes = (int)($file['size'] ?? 0);
    if ($tmp === '' || $bytes < 1 || $bytes > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('The logo image must be between 1 byte and 5 MB.');
    }

    $image = @getimagesize($tmp);
    if (!is_array($image)) {
        throw new InvalidArgumentException('Upload a valid PNG, WebP or JPEG image.');
    }

    $mime = strtolower((string)($image['mime'] ?? ''));
    $type = (int)($image[2] ?? 0);
    $extension = match (true) {
        $type === IMAGETYPE_PNG && $mime === 'image/png' => 'png',
        defined('IMAGETYPE_WEBP') && $type === IMAGETYPE_WEBP && $mime === 'image/webp' => 'webp',
        $type === IMAGETYPE_JPEG && $mime === 'image/jpeg' => 'jpg',
        default => '',
    };
    if ($extension === '') {
        throw new InvalidArgumentException('Logo artwork must be PNG, WebP or JPEG. SVG is not accepted for uploaded branding.');
    }

    $width = (int)$image[0];
    $height = (int)$image[1];
    if ($width < 32 || $height < 16 || $width > 5000 || $height > 5000) {
        throw new InvalidArgumentException('Logo dimensions must be between 32×16 and 5000×5000 pixels.');
    }

    return [
        'tmp_name' => $tmp,
        'extension' => $extension,
        'mime_type' => $mime,
        'width' => $width,
        'height' => $height,
        'bytes' => $bytes,
    ];
}

/** @return array<string,string> */
function coveted_site_logo_store(array $admin, array $file): array
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    $validated = coveted_site_logo_validate_upload($file);
    $directory = coveted_site_branding_dir();
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the site branding upload directory.');
    }

    $filename = 'site-logo.' . $validated['extension'];
    $destination = $directory . '/' . $filename;
    $staging = $directory . '/.' . $filename . '.' . bin2hex(random_bytes(6)) . '.tmp';

    $moved = is_uploaded_file($validated['tmp_name'])
        ? move_uploaded_file($validated['tmp_name'], $staging)
        : ((coveted_config('app')['environment'] ?? 'production') === 'testing'
            && @rename($validated['tmp_name'], $staging));
    if (!$moved) {
        throw new RuntimeException('Unable to store the uploaded site logo.');
    }
    @chmod($staging, 0644);

    if (!@rename($staging, $destination)) {
        @unlink($staging);
        throw new RuntimeException('Unable to activate the uploaded site logo.');
    }

    foreach (['png', 'webp', 'jpg'] as $extension) {
        $other = $directory . '/site-logo.' . $extension;
        if ($other !== $destination && is_file($other)) {
            @unlink($other);
        }
    }

    clearstatcache(true, $destination);
    $asset = coveted_site_logo_asset();
    if ($asset === null) {
        @unlink($destination);
        throw new RuntimeException('The uploaded site logo could not be read back.');
    }

    coveted_audit(
        'admin.site_logo_uploaded',
        'site_branding',
        'site_logo',
        [
            'filename' => $filename,
            'mime_type' => $validated['mime_type'],
            'width' => $validated['width'],
            'height' => $validated['height'],
            'bytes' => $validated['bytes'],
        ],
        (int)$admin['id']
    );

    return $asset;
}

function coveted_site_logo_delete(array $admin): void
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    $directory = coveted_site_branding_dir();
    foreach (['png', 'webp', 'jpg'] as $extension) {
        $path = $directory . '/site-logo.' . $extension;
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('Unable to remove the active site logo.');
        }
    }

    coveted_audit('admin.site_logo_removed', 'site_branding', 'site_logo', [], (int)$admin['id']);
}

/**
 * Add branding completeness to an already-generated Admin Agent snapshot.
 * Keeping this outside the core brain means branding remains a small optional
 * capability rather than a dependency of the operational snapshot.
 *
 * @return array<string,mixed>
 */
function coveted_site_branding_enrich_agent_snapshot(array $snapshot): array
{
    $logo = coveted_site_logo_asset();
    $readiness = (array)($snapshot['readiness'] ?? []);
    $checks = array_values((array)($readiness['checks'] ?? []));
    $checks[] = ['key' => 'branding', 'label' => 'Site logo uploaded', 'done' => $logo !== null];

    $ready = count(array_filter($checks, static fn(array $check): bool => !empty($check['done'])));
    $total = count($checks);
    $snapshot['readiness'] = [
        'ready' => $ready,
        'total' => $total,
        'percent' => $total > 0 ? (int)round(($ready / $total) * 100) : 100,
        'checks' => $checks,
    ];
    $snapshot['branding'] = ['logo_uploaded' => $logo !== null];

    if ($logo === null) {
        $opportunities = array_values((array)($snapshot['opportunities'] ?? []));
        $opportunities[] = [
            'priority' => 2,
            'key' => 'site-logo',
            'category' => 'Branding',
            'title' => 'Upload the Coveted site logo',
            'detail' => 'Replace the text-only brand treatment with your uploaded logo across the public header and Admin shell.',
            'href' => '/admin/branding.php',
            'evidence' => 'No active site logo image is installed.',
        ];
        usort($opportunities, static function (array $a, array $b): int {
            $priority = ((int)$a['priority']) <=> ((int)$b['priority']);
            return $priority !== 0 ? $priority : strcmp((string)$a['key'], (string)$b['key']);
        });
        $snapshot['opportunities'] = $opportunities;
    }

    return $snapshot;
}
