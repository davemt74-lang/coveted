<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/lifecycle.php';
require_once dirname(__DIR__) . '/app/event_lifecycle_automation.php';
require_once dirname(__DIR__) . '/app/benefit_economy.php';

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

    $events = coveted_event_lifecycle_automation_reconcile($limit);
    if (!empty($events['skipped_locked'])) {
        fwrite(STDOUT, "Coveted event automation: another worker already holds the lifecycle lock; this pass was skipped.\n");
    } else {
        fwrite(
            STDOUT,
            sprintf(
                "Coveted event automation: %d publish notices, %d RSVP reminders, %d 24h reminders, %d 3h reminders, %d reveals, %d attendance rewards, %d completion rewards, %d campaign-limit skips, %d post-event notices, %d failures.\n",
                (int)$events['publish_notifications'],
                (int)$events['rsvp_reminders'],
                (int)$events['attendee_reminders_24h'],
                (int)$events['attendee_reminders_3h'],
                (int)$events['mystery_reveal_notifications'],
                (int)$events['attendance_rewards'],
                (int)$events['completion_rewards'],
                (int)$events['reward_limit_skips'],
                (int)$events['post_event_notifications'],
                (int)$events['failures']
            )
        );
    }

    $membership = coveted_membership_benefit_reconcile($limit);
    if (!empty($membership['skipped_locked'])) {
        fwrite(STDOUT, "Coveted membership benefits: another worker already holds the benefit lock; this pass was skipped.\n");
    } else {
        fwrite(
            STDOUT,
            sprintf(
                "Coveted membership benefits: %d issued, %d already issued, %d limit/state skips, %d failures.\n",
                (int)$membership['issued'],
                (int)$membership['already_issued'],
                (int)$membership['limit_skips'],
                (int)$membership['failures']
            )
        );
    }

    if (
        !empty($summary['more_work_possible'])
        || !empty($events['more_work_possible'])
        || !empty($membership['more_work_possible'])
    ) {
        fwrite(STDERR, "Coveted lifecycle backlog remains after the configured worker limit.\n");
        exit(2);
    }
    if ((int)$events['failures'] > 0 || (int)$membership['failures'] > 0) {
        fwrite(STDERR, "Coveted automation completed with one or more bounded item failures; review Admin operations and server logs.\n");
        exit(3);
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Coveted lifecycle reconciliation failed: ' . $e->getMessage() . "\n");
    exit(1);
}
