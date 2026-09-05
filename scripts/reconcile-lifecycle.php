<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/lifecycle.php';

$limit = isset($argv[1]) && ctype_digit((string)$argv[1]) ? (int)$argv[1] : 250;
$maxBatches = isset($argv[2]) && ctype_digit((string)$argv[2]) ? (int)$argv[2] : 10;

try {
    $summary = coveted_lifecycle_reconcile($limit, $maxBatches);
    fwrite(
        STDOUT,
        sprintf(
            "Coveted lifecycle: %d group invitations expired, %d event invitations expired, %d Guest Passes released, %d Guest Passes expired across %d batch%s.\n",
            (int)$summary['group_invitations_expired'],
            (int)$summary['event_invitations_expired'],
            (int)$summary['guest_passes_released'],
            (int)$summary['guest_passes_expired'],
            (int)$summary['batches'],
            (int)$summary['batches'] === 1 ? '' : 'es'
        )
    );

    if (!empty($summary['more_work_possible'])) {
        fwrite(STDERR, "Coveted lifecycle backlog remains after the configured batch limit.\n");
        exit(2);
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Coveted lifecycle reconciliation failed: ' . $e->getMessage() . "\n");
    exit(1);
}
