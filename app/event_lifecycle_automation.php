<?php
declare(strict_types=1);

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/rewards.php';

const COVETED_EVENT_AUTOMATION_LOCK = 'coveted:event-lifecycle:v1';

function coveted_event_automation_copy(string $value, int $limit = 190): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($value === '') {
        return 'Coveted event update';
    }

    return mb_strlen($value) <= $limit
        ? $value
        : rtrim(mb_substr($value, 0, max(1, $limit - 1))) . '…';
}

function coveted_event_automation_try_lock(PDO $pdo): bool
{
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $stmt->execute([COVETED_EVENT_AUTOMATION_LOCK]);
    return (int)$stmt->fetchColumn() === 1;
}

function coveted_event_automation_unlock(PDO $pdo): void
{
    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([COVETED_EVENT_AUTOMATION_LOCK]);
    } catch (Throwable $e) {
        error_log('Coveted event automation lock release failed: ' . $e->getMessage());
    }
}

function coveted_event_automation_expected_reward_skip(string $message): bool
{
    return in_array($message, [
        'Campaign distribution limit has been reached.',
        'Member campaign limit has been reached.',
    ], true);
}

function coveted_event_automation_notify(
    int $userId,
    string $type,
    string $title,
    string $body,
    string $actionUrl,
    string $dedupeKey,
    string $priority = 'normal',
    array $payload = []
): bool {
    $canonicalDedupe = strlen($dedupeKey) > 190 ? hash('sha256', $dedupeKey) : $dedupeKey;
    $existing = coveted_db()->prepare('SELECT 1 FROM notifications WHERE user_id = ? AND dedupe_key = ? LIMIT 1');
    $existing->execute([$userId, $canonicalDedupe]);
    if ($existing->fetchColumn()) {
        return false;
    }

    coveted_notification_create(
        $userId,
        $type,
        coveted_event_automation_copy($title),
        mb_substr(trim($body), 0, 2000),
        $actionUrl,
        $payload,
        $priority,
        $dedupeKey,
        null
    );

    return true;
}

/** @return array<int,array<string,mixed>> */
function coveted_event_automation_published_invites(int $limit): array
{
    return coveted_db()->query(
        "SELECT ei.id AS invitation_id, ei.user_id, ei.status AS invitation_status,
                e.id AS event_id, e.public_id AS event_public_id, e.title, e.starts_at,
                e.event_type, g.name AS group_name
         FROM event_invitations ei
         JOIN events e ON e.id = ei.event_id
         JOIN social_groups g ON g.id = e.group_id
         JOIN users u ON u.id = ei.user_id AND u.status = 'active'
         WHERE e.status = 'published'
           AND e.starts_at > NOW()
           AND e.updated_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
           AND ei.status IN ('pending','accepted')
           AND NOT EXISTS (
               SELECT 1 FROM notifications n
               WHERE n.user_id = ei.user_id
                 AND n.dedupe_key = CONCAT('event-published:', e.id, ':', ei.user_id)
           )
         ORDER BY e.starts_at ASC, ei.id ASC
         LIMIT {$limit}"
    )->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function coveted_event_automation_pending_rsvp_reminders(int $limit): array
{
    return coveted_db()->query(
        "SELECT ei.user_id, e.id AS event_id, e.public_id AS event_public_id,
                e.title, e.starts_at, g.name AS group_name
         FROM event_invitations ei
         JOIN events e ON e.id = ei.event_id
         JOIN social_groups g ON g.id = e.group_id
         JOIN users u ON u.id = ei.user_id AND u.status = 'active'
         LEFT JOIN event_rsvps er ON er.event_id = e.id AND er.user_id = ei.user_id
         WHERE e.status = 'published'
           AND e.starts_at > NOW()
           AND e.starts_at <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
           AND ei.status = 'pending'
           AND (er.response IS NULL OR er.response NOT IN ('attending','waitlist'))
           AND NOT EXISTS (
               SELECT 1 FROM notifications n
               WHERE n.user_id = ei.user_id
                 AND n.dedupe_key = CONCAT('event-rsvp-24h:', e.id, ':', ei.user_id)
           )
           AND NOT EXISTS (
               SELECT 1 FROM notifications recent
               WHERE recent.user_id = ei.user_id
                 AND recent.dedupe_key = CONCAT('event-published:', e.id, ':', ei.user_id)
                 AND recent.created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
           )
         ORDER BY e.starts_at ASC, ei.id ASC
         LIMIT {$limit}"
    )->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function coveted_event_automation_attendee_reminders(int $limit): array
{
    return coveted_db()->query(
        "SELECT er.user_id, er.guest_count, e.id AS event_id, e.public_id AS event_public_id,
                e.title, e.starts_at, e.location_visibility, g.name AS group_name
         FROM event_rsvps er
         JOIN events e ON e.id = er.event_id
         JOIN social_groups g ON g.id = e.group_id
         JOIN users u ON u.id = er.user_id AND u.status = 'active'
         WHERE er.response = 'attending'
           AND e.status = 'published'
           AND e.starts_at > NOW()
           AND e.starts_at <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
           AND NOT EXISTS (
               SELECT 1 FROM notifications n
               WHERE n.user_id = er.user_id
                 AND n.dedupe_key = CONCAT(
                     'event-attendee-',
                     IF(e.starts_at <= DATE_ADD(NOW(), INTERVAL 3 HOUR), '3h', '24h'),
                     ':', e.id, ':', er.user_id
                 )
           )
           AND NOT EXISTS (
               SELECT 1 FROM notifications recent
               WHERE recent.user_id = er.user_id
                 AND recent.dedupe_key = CONCAT('event-published:', e.id, ':', er.user_id)
                 AND recent.created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
           )
         ORDER BY e.starts_at ASC, er.event_id ASC, er.user_id ASC
         LIMIT {$limit}"
    )->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function coveted_event_automation_due_reveals(int $limit): array
{
    return coveted_db()->query(
        "SELECT emr.id AS reveal_id, emr.reveal_type, emr.title AS reveal_title,
                er.user_id, e.id AS event_id, e.public_id AS event_public_id,
                e.title AS event_title, e.starts_at
         FROM event_mystery_reveals emr
         JOIN events e ON e.id = emr.event_id
         JOIN event_rsvps er ON er.event_id = e.id AND er.response = 'attending'
         JOIN users u ON u.id = er.user_id AND u.status = 'active'
         WHERE emr.reveal_at <= NOW()
           AND e.status IN ('published','closed')
           AND e.starts_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
           AND NOT EXISTS (
               SELECT 1 FROM notifications n
               WHERE n.user_id = er.user_id
                 AND n.dedupe_key = CONCAT('event-reveal:', emr.id, ':', er.user_id)
           )
         ORDER BY emr.reveal_at ASC, emr.id ASC, er.user_id ASC
         LIMIT {$limit}"
    )->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function coveted_event_automation_reward_targets(string $triggerKey, int $limit): array
{
    if (!in_array($triggerKey, ['attendance', 'completion'], true)) {
        throw new InvalidArgumentException('Unsupported automated reward trigger.');
    }

    $eventStatus = $triggerKey === 'completion'
        ? "e.status = 'completed' AND e.updated_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)"
        : "e.status IN ('published','closed','completed') AND ea.updated_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)";
    $triggerSql = $triggerKey === 'completion' ? "'completion'" : "'attendance'";

    return coveted_db()->query(
        "SELECT c.id AS campaign_id, c.public_id AS campaign_public_id,
                ea.user_id, e.id AS event_id, e.public_id AS event_public_id,
                e.title AS event_title
         FROM campaign_event_links cel
         JOIN campaigns c ON c.id = cel.campaign_id
         JOIN reward_templates rt ON rt.id = c.reward_template_id
         JOIN events e ON e.id = cel.event_id
         JOIN event_attendance ea ON ea.event_id = e.id
         JOIN users u ON u.id = ea.user_id AND u.status = 'active'
         WHERE c.status = 'active'
           AND rt.status = 'active'
           AND c.trigger_key = {$triggerSql}
           AND {$eventStatus}
           AND ea.status IN ('checked_in','attended','left_early')
           AND (c.starts_at IS NULL OR c.starts_at <= NOW())
           AND (c.ends_at IS NULL OR c.ends_at > NOW())
           AND (rt.starts_at IS NULL OR rt.starts_at <= NOW())
           AND (rt.expires_at IS NULL OR rt.expires_at > NOW())
           AND (
               c.quantity_limit IS NULL OR
               (SELECT COUNT(*) FROM reward_issuances qri
                WHERE qri.campaign_id = c.id AND qri.status <> 'cancelled') < c.quantity_limit
           )
           AND (
               c.per_user_limit IS NULL OR
               (SELECT COUNT(*) FROM reward_issuances uri
                WHERE uri.campaign_id = c.id
                  AND uri.user_id = ea.user_id
                  AND uri.status <> 'cancelled') < c.per_user_limit
           )
           AND NOT EXISTS (
               SELECT 1 FROM reward_issuances ri
               WHERE ri.campaign_id = c.id
                 AND ri.user_id = ea.user_id
                 AND ri.event_id = e.id
                 AND ri.status <> 'cancelled'
           )
         ORDER BY e.id ASC, c.id ASC, ea.user_id ASC
         LIMIT {$limit}"
    )->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function coveted_event_automation_completed_attendees(int $limit): array
{
    return coveted_db()->query(
        "SELECT ea.user_id, e.id AS event_id, e.public_id AS event_public_id,
                e.title, e.starts_at, g.name AS group_name
         FROM event_attendance ea
         JOIN events e ON e.id = ea.event_id
         JOIN social_groups g ON g.id = e.group_id
         JOIN users u ON u.id = ea.user_id AND u.status = 'active'
         WHERE e.status = 'completed'
           AND e.updated_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
           AND ea.status IN ('checked_in','attended','left_early')
           AND NOT EXISTS (
               SELECT 1 FROM notifications n
               WHERE n.user_id = ea.user_id
                 AND n.dedupe_key = CONCAT('event-post:', e.id, ':', ea.user_id)
           )
         ORDER BY e.updated_at DESC, ea.id ASC
         LIMIT {$limit}"
    )->fetchAll();
}

function coveted_event_automation_has_backlog(): bool
{
    if (coveted_event_automation_published_invites(1)) {
        return true;
    }
    if (coveted_event_automation_pending_rsvp_reminders(1)) {
        return true;
    }
    if (coveted_event_automation_attendee_reminders(1)) {
        return true;
    }
    if (coveted_event_automation_due_reveals(1)) {
        return true;
    }
    if (coveted_event_automation_reward_targets('attendance', 1)) {
        return true;
    }
    if (coveted_event_automation_reward_targets('completion', 1)) {
        return true;
    }

    return (bool)coveted_event_automation_completed_attendees(1);
}

/**
 * Bounded, idempotent event lifecycle automation pass.
 * Repeated scheduler runs are safe because notifications use canonical dedupe
 * keys and rewards use canonical issuance idempotency keys. A named DB lock
 * prevents two scheduler processes from running this pass concurrently.
 *
 * @return array<string,int|bool>
 */
function coveted_event_lifecycle_automation_reconcile(int $limit = 250): array
{
    $limit = max(1, min($limit, 1000));
    $summary = [
        'publish_notifications' => 0,
        'rsvp_reminders' => 0,
        'attendee_reminders_24h' => 0,
        'attendee_reminders_3h' => 0,
        'mystery_reveal_notifications' => 0,
        'attendance_rewards' => 0,
        'completion_rewards' => 0,
        'reward_limit_skips' => 0,
        'post_event_notifications' => 0,
        'failures' => 0,
        'more_work_possible' => false,
        'skipped_locked' => false,
    ];

    $pdo = coveted_db();
    if (!coveted_event_automation_try_lock($pdo)) {
        $summary['skipped_locked'] = true;
        return $summary;
    }

    try {
        $buckets = [];

        $rows = coveted_event_automation_published_invites($limit);
        $buckets[] = count($rows);
        foreach ($rows as $row) {
            try {
                $created = coveted_event_automation_notify(
                    (int)$row['user_id'],
                    'event.published',
                    'Invitation · ' . (string)$row['title'],
                    'A Coveted gathering is ready for you. Review the event and RSVP when you are ready.',
                    '/event.php?event=' . rawurlencode((string)$row['event_public_id']),
                    'event-published:' . (int)$row['event_id'] . ':' . (int)$row['user_id'],
                    'normal',
                    ['event_id' => (string)$row['event_public_id']]
                );
                $summary['publish_notifications'] += $created ? 1 : 0;
            } catch (Throwable $e) {
                $summary['failures']++;
                error_log('Coveted event publication automation failed: ' . $e->getMessage());
            }
        }

        $rows = coveted_event_automation_pending_rsvp_reminders($limit);
        $buckets[] = count($rows);
        foreach ($rows as $row) {
            try {
                $created = coveted_event_automation_notify(
                    (int)$row['user_id'],
                    'event.rsvp_reminder',
                    'RSVP reminder · ' . (string)$row['title'],
                    'This gathering is within 24 hours and still needs your response.',
                    '/invitations.php?view=waiting',
                    'event-rsvp-24h:' . (int)$row['event_id'] . ':' . (int)$row['user_id'],
                    'high',
                    ['event_id' => (string)$row['event_public_id']]
                );
                $summary['rsvp_reminders'] += $created ? 1 : 0;
            } catch (Throwable $e) {
                $summary['failures']++;
                error_log('Coveted RSVP reminder automation failed: ' . $e->getMessage());
            }
        }

        $rows = coveted_event_automation_attendee_reminders($limit);
        $buckets[] = count($rows);
        foreach ($rows as $row) {
            try {
                $seconds = strtotime((string)$row['starts_at']) - time();
                $withinThreeHours = $seconds <= 3 * 3600;
                $milestone = $withinThreeHours ? '3h' : '24h';
                $created = coveted_event_automation_notify(
                    (int)$row['user_id'],
                    $withinThreeHours ? 'event.starting_soon' : 'event.reminder',
                    ($withinThreeHours ? 'Starting soon · ' : 'Tomorrow · ') . (string)$row['title'],
                    $withinThreeHours
                        ? 'Your Event Pass and the latest event details are ready in My Events.'
                        : 'Your gathering is coming up. Review your Event Pass and any available reveals before you go.',
                    '/my-events.php',
                    'event-attendee-' . $milestone . ':' . (int)$row['event_id'] . ':' . (int)$row['user_id'],
                    $withinThreeHours ? 'high' : 'normal',
                    ['event_id' => (string)$row['event_public_id'], 'milestone' => $milestone]
                );
                if ($created) {
                    $summary[$withinThreeHours ? 'attendee_reminders_3h' : 'attendee_reminders_24h']++;
                }
            } catch (Throwable $e) {
                $summary['failures']++;
                error_log('Coveted attendee reminder automation failed: ' . $e->getMessage());
            }
        }

        $rows = coveted_event_automation_due_reveals($limit);
        $buckets[] = count($rows);
        foreach ($rows as $row) {
            try {
                $label = trim((string)($row['reveal_title'] ?? ''));
                $created = coveted_event_automation_notify(
                    (int)$row['user_id'],
                    'event.mystery_reveal',
                    $label !== '' ? 'Revealed · ' . $label : 'A mystery detail was revealed',
                    'A new detail for ' . (string)$row['event_title'] . ' is now available. Open the event to see it.',
                    '/event.php?event=' . rawurlencode((string)$row['event_public_id']),
                    'event-reveal:' . (int)$row['reveal_id'] . ':' . (int)$row['user_id'],
                    (string)$row['reveal_type'] === 'location' ? 'high' : 'normal',
                    ['event_id' => (string)$row['event_public_id'], 'reveal_type' => (string)$row['reveal_type']]
                );
                $summary['mystery_reveal_notifications'] += $created ? 1 : 0;
            } catch (Throwable $e) {
                $summary['failures']++;
                error_log('Coveted mystery reveal automation failed: ' . $e->getMessage());
            }
        }

        foreach (['attendance' => 'attendance_rewards', 'completion' => 'completion_rewards'] as $trigger => $summaryKey) {
            $rows = coveted_event_automation_reward_targets($trigger, $limit);
            $buckets[] = count($rows);
            foreach ($rows as $row) {
                try {
                    $key = 'event-' . $trigger . ':' . (int)$row['event_id'] . ':' . (int)$row['campaign_id'] . ':' . (int)$row['user_id'];
                    coveted_reward_issue(
                        (int)$row['campaign_id'],
                        (int)$row['user_id'],
                        (int)$row['event_id'],
                        ['automation' => 'event_lifecycle', 'trigger' => $trigger],
                        $key
                    );
                    $summary[$summaryKey]++;
                } catch (InvalidArgumentException $e) {
                    if (coveted_event_automation_expected_reward_skip($e->getMessage())) {
                        $summary['reward_limit_skips']++;
                        continue;
                    }
                    $summary['failures']++;
                    error_log('Coveted automated reward skipped unexpectedly: ' . $e->getMessage());
                } catch (Throwable $e) {
                    $summary['failures']++;
                    error_log('Coveted automated reward failed: ' . $e->getMessage());
                }
            }
        }

        $rows = coveted_event_automation_completed_attendees($limit);
        $buckets[] = count($rows);
        foreach ($rows as $row) {
            try {
                $created = coveted_event_automation_notify(
                    (int)$row['user_id'],
                    'event.post_event_open',
                    'After the event · ' . (string)$row['title'],
                    'Your event memory is open. Review benefits, private feedback and Mutual Reconnect when you are ready.',
                    '/event.php?event=' . rawurlencode((string)$row['event_public_id']),
                    'event-post:' . (int)$row['event_id'] . ':' . (int)$row['user_id'],
                    'normal',
                    ['event_id' => (string)$row['event_public_id']]
                );
                $summary['post_event_notifications'] += $created ? 1 : 0;
            } catch (Throwable $e) {
                $summary['failures']++;
                error_log('Coveted post-event automation failed: ' . $e->getMessage());
            }
        }

        $summary['more_work_possible'] = max($buckets ?: [0]) >= $limit
            && coveted_event_automation_has_backlog();

        $changed = array_sum([
            $summary['publish_notifications'],
            $summary['rsvp_reminders'],
            $summary['attendee_reminders_24h'],
            $summary['attendee_reminders_3h'],
            $summary['mystery_reveal_notifications'],
            $summary['attendance_rewards'],
            $summary['completion_rewards'],
            $summary['post_event_notifications'],
        ]);
        if ($changed > 0 || $summary['failures'] > 0 || $summary['reward_limit_skips'] > 0) {
            coveted_audit(
                'event_lifecycle.automated',
                'platform',
                null,
                [
                    'publish_notifications' => $summary['publish_notifications'],
                    'rsvp_reminders' => $summary['rsvp_reminders'],
                    'attendee_reminders_24h' => $summary['attendee_reminders_24h'],
                    'attendee_reminders_3h' => $summary['attendee_reminders_3h'],
                    'mystery_reveal_notifications' => $summary['mystery_reveal_notifications'],
                    'attendance_rewards' => $summary['attendance_rewards'],
                    'completion_rewards' => $summary['completion_rewards'],
                    'reward_limit_skips' => $summary['reward_limit_skips'],
                    'post_event_notifications' => $summary['post_event_notifications'],
                    'failures' => $summary['failures'],
                ],
                0
            );
        }

        return $summary;
    } finally {
        coveted_event_automation_unlock($pdo);
    }
}

/** @return array<string,mixed> */
function coveted_event_lifecycle_automation_exceptions(int $limit = 50): array
{
    $limit = max(1, min($limit, 100));
    $pdo = coveted_db();

    $pendingRsvp = $pdo->query(
        "SELECT e.public_id, e.title, e.starts_at, g.name AS group_name, COUNT(*) AS pending_count
         FROM event_invitations ei
         JOIN events e ON e.id = ei.event_id
         JOIN social_groups g ON g.id = e.group_id
         LEFT JOIN event_rsvps er ON er.event_id = e.id AND er.user_id = ei.user_id
         WHERE e.status = 'published'
           AND e.starts_at > NOW()
           AND e.starts_at <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
           AND ei.status = 'pending'
           AND (er.response IS NULL OR er.response NOT IN ('attending','waitlist'))
         GROUP BY e.id, e.public_id, e.title, e.starts_at, g.name
         ORDER BY e.starts_at ASC
         LIMIT {$limit}"
    )->fetchAll();

    $unrevealedMystery = $pdo->query(
        "SELECT e.public_id, e.title, e.starts_at, g.name AS group_name,
                COUNT(*) AS missing_notifications
         FROM event_mystery_reveals emr
         JOIN events e ON e.id = emr.event_id
         JOIN social_groups g ON g.id = e.group_id
         JOIN event_rsvps er ON er.event_id = e.id AND er.response = 'attending'
         LEFT JOIN notifications n
           ON n.user_id = er.user_id
          AND n.dedupe_key = CONCAT('event-reveal:', emr.id, ':', er.user_id)
         WHERE e.event_type = 'mystery'
           AND e.status IN ('published','closed')
           AND e.starts_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
           AND emr.reveal_at <= NOW()
           AND n.id IS NULL
         GROUP BY e.id, e.public_id, e.title, e.starts_at, g.name
         ORDER BY e.starts_at ASC
         LIMIT {$limit}"
    )->fetchAll();

    $rewardGaps = $pdo->query(
        "SELECT e.public_id, e.title, e.status, g.name AS group_name,
                COUNT(*) AS missing_issuances
         FROM campaign_event_links cel
         JOIN campaigns c ON c.id = cel.campaign_id
         JOIN reward_templates rt ON rt.id = c.reward_template_id
         JOIN events e ON e.id = cel.event_id
         JOIN social_groups g ON g.id = e.group_id
         JOIN event_attendance ea ON ea.event_id = e.id AND ea.status IN ('checked_in','attended','left_early')
         WHERE c.status = 'active'
           AND rt.status = 'active'
           AND c.trigger_key IN ('attendance','completion')
           AND (
               (c.trigger_key = 'attendance' AND ea.updated_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR))
               OR
               (c.trigger_key = 'completion' AND e.status = 'completed' AND e.updated_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR))
           )
           AND (c.starts_at IS NULL OR c.starts_at <= NOW())
           AND (c.ends_at IS NULL OR c.ends_at > NOW())
           AND (rt.starts_at IS NULL OR rt.starts_at <= NOW())
           AND (rt.expires_at IS NULL OR rt.expires_at > NOW())
           AND (
               c.quantity_limit IS NULL OR
               (SELECT COUNT(*) FROM reward_issuances qri
                WHERE qri.campaign_id = c.id AND qri.status <> 'cancelled') < c.quantity_limit
           )
           AND (
               c.per_user_limit IS NULL OR
               (SELECT COUNT(*) FROM reward_issuances uri
                WHERE uri.campaign_id = c.id
                  AND uri.user_id = ea.user_id
                  AND uri.status <> 'cancelled') < c.per_user_limit
           )
           AND NOT EXISTS (
               SELECT 1 FROM reward_issuances ri
               WHERE ri.campaign_id = c.id
                 AND ri.user_id = ea.user_id
                 AND ri.event_id = e.id
                 AND ri.status <> 'cancelled'
           )
         GROUP BY e.id, e.public_id, e.title, e.status, g.name
         ORDER BY e.starts_at DESC
         LIMIT {$limit}"
    )->fetchAll();

    $runs = $pdo->query(
        "SELECT id, metadata_json, created_at
         FROM audit_events
         WHERE event_type = 'event_lifecycle.automated'
           AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         ORDER BY created_at DESC, id DESC
         LIMIT 50"
    )->fetchAll();

    $recentFailures = [];
    $lastRunAt = null;
    $lastRunFailures = 0;
    foreach ($runs as $index => $run) {
        $metadata = [];
        if (!empty($run['metadata_json'])) {
            try {
                $decoded = json_decode((string)$run['metadata_json'], true, 64, JSON_THROW_ON_ERROR);
                $metadata = is_array($decoded) ? $decoded : [];
            } catch (Throwable) {
                $metadata = [];
            }
        }
        $failures = (int)($metadata['failures'] ?? 0);
        if ($index === 0) {
            $lastRunAt = (string)$run['created_at'];
            $lastRunFailures = $failures;
        }
        if ($failures > 0) {
            $recentFailures[] = [
                'created_at' => (string)$run['created_at'],
                'failures' => $failures,
            ];
        }
    }

    return [
        'pending_rsvp' => $pendingRsvp,
        'unrevealed_mystery' => $unrevealedMystery,
        'reward_gaps' => $rewardGaps,
        'recent_failure_runs' => array_slice($recentFailures, 0, $limit),
        'last_run_at' => $lastRunAt,
        'last_run_failures' => $lastRunFailures,
        'total' => count($pendingRsvp) + count($unrevealedMystery) + count($rewardGaps) + count($recentFailures),
    ];
}
