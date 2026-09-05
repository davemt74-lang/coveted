<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/push.php';
require_once dirname(__DIR__) . '/app/notification_events.php';

$limit = isset($argv[1]) && ctype_digit((string)$argv[1]) ? (int)$argv[1] : 0;

try {
    $projection = coveted_notification_reconcile(null, $limit > 0 ? $limit : 200);
    $summary = coveted_push_dispatch_pending($limit);
    fwrite(
        STDOUT,
        sprintf(
            "Coveted notifications: %d event invites, %d waitlist promotions, %d refunds, %d mystery reveals projected.\n",
            (int)$projection['event_invitations'],
            (int)$projection['waitlist_promotions'],
            (int)$projection['reward_refunds'],
            (int)$projection['mystery_reveals']
        )
    );
    fwrite(
        STDOUT,
        sprintf(
            "Coveted Web Push: %d claimed, %d sent, %d failed, %d expired.\n",
            (int)$summary['claimed'],
            (int)$summary['sent'],
            (int)$summary['failed'],
            (int)$summary['expired']
        )
    );
    exit($summary['failed'] > 0 ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Coveted Web Push failed: ' . $e->getMessage() . "\n");
    exit(1);
}
