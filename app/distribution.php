<?php
declare(strict_types=1);

require_once __DIR__ . '/campaigns.php';
require_once __DIR__ . '/notifications.php';

function coveted_distribution_lock_name(string $scope): string
{
    return 'covdist:' . substr(hash('sha256', $scope), 0, 50);
}

function coveted_distribution_acquire_lock(PDO $pdo, string $scope): string
{
    $name = coveted_distribution_lock_name($scope);
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $stmt->execute([$name]);

    if ((int)$stmt->fetchColumn() !== 1) {
        throw new RuntimeException('Another distribution is already running. Try again shortly.');
    }

    return $name;
}

function coveted_distribution_release_lock(PDO $pdo, string $name): void
{
    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$name]);
    } catch (Throwable $e) {
        error_log('Coveted distribution lock release failed: ' . $e->getMessage());
    }
}

function coveted_distribution_events(int $limit = 100): array
{
    $limit = max(1, min($limit, 250));

    return coveted_db()->query(
        "SELECT
            e.id,
            e.public_id,
            e.title,
            e.status,
            e.starts_at,
            e.timezone,
            g.name AS group_name,
            COUNT(cel.campaign_id) AS campaign_count
         FROM events e
         JOIN social_groups g ON g.id = e.group_id
         JOIN campaign_event_links cel ON cel.event_id = e.id
         GROUP BY e.id, e.public_id, e.title, e.status, e.starts_at, e.timezone, g.name
         ORDER BY e.starts_at DESC, e.id DESC
         LIMIT {$limit}"
    )->fetchAll();
}

function coveted_distribution_event_campaigns(int $eventId): array
{
    if ($eventId < 1) {
        return [];
    }

    $stmt = coveted_db()->prepare(
        "SELECT
            c.*,
            rt.title AS reward_title,
            rt.reward_type,
            rt.claim_mode,
            rt.status AS reward_status,
            e.title AS event_title,
            e.status AS event_status,
            e.starts_at AS event_starts_at,
            e.timezone AS event_timezone
         FROM campaign_event_links cel
         JOIN campaigns c ON c.id = cel.campaign_id
         JOIN reward_templates rt ON rt.id = c.reward_template_id
         JOIN events e ON e.id = cel.event_id
         WHERE cel.event_id = ?
         ORDER BY c.created_at DESC, c.id DESC"
    );
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

function coveted_distribution_manual_campaigns(int $limit = 250): array
{
    $limit = max(1, min($limit, 500));

    return coveted_db()->query(
        "SELECT
            c.*,
            rt.title AS reward_title,
            rt.reward_type,
            rt.claim_mode,
            rt.status AS reward_status
         FROM campaigns c
         JOIN reward_templates rt ON rt.id = c.reward_template_id
         WHERE c.trigger_key = 'manual'
           AND c.status = 'active'
           AND rt.status = 'active'
           AND (c.starts_at IS NULL OR c.starts_at <= NOW())
           AND (c.ends_at IS NULL OR c.ends_at > NOW())
           AND (rt.starts_at IS NULL OR rt.starts_at <= NOW())
           AND (rt.expires_at IS NULL OR rt.expires_at > NOW())
         ORDER BY c.created_at DESC, c.id DESC
         LIMIT {$limit}"
    )->fetchAll();
}

function coveted_distribution_eligible_user_ids(array $campaign, int $eventId): array
{
    $trigger = (string)$campaign['trigger_key'];
    $pdo = coveted_db();

    if ($trigger === 'attendance') {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT ea.user_id
             FROM event_attendance ea
             JOIN users u ON u.id = ea.user_id AND u.status = 'active'
             WHERE ea.event_id = ?
               AND ea.status IN ('checked_in','attended','left_early')
             ORDER BY ea.user_id"
        );
        $stmt->execute([$eventId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
    }

    if ($trigger === 'completion') {
        $event = $pdo->prepare('SELECT status FROM events WHERE id = ? LIMIT 1');
        $event->execute([$eventId]);
        if ($event->fetchColumn() !== 'completed') {
            return [];
        }

        $stmt = $pdo->prepare(
            "SELECT DISTINCT ea.user_id
             FROM event_attendance ea
             JOIN users u ON u.id = ea.user_id AND u.status = 'active'
             WHERE ea.event_id = ?
               AND ea.status IN ('attended','left_early')
             ORDER BY ea.user_id"
        );
        $stmt->execute([$eventId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
    }

    $stmt = $pdo->prepare(
        "SELECT DISTINCT user_id
         FROM (
             SELECT ea.user_id
             FROM event_attendance ea
             WHERE ea.event_id = ? AND ea.status IN ('checked_in','attended','left_early')

             UNION

             SELECT er.user_id
             FROM event_rsvps er
             WHERE er.event_id = ? AND er.response = 'attending'

             UNION

             SELECT ei.user_id
             FROM event_invitations ei
             WHERE ei.event_id = ? AND ei.status = 'accepted'
         ) eligible
         JOIN users u ON u.id = eligible.user_id AND u.status = 'active'
         ORDER BY user_id"
    );
    $stmt->execute([$eventId, $eventId, $eventId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
}

function coveted_distribution_preview(array $admin, string $campaignRef, int $eventId): array
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    $campaign = coveted_campaign_by_ref($campaignRef);
    if (!$campaign) {
        throw new InvalidArgumentException('Campaign not found.');
    }

    $linked = coveted_db()->prepare(
        "SELECT e.title AS event_title, e.status AS event_status, e.starts_at, e.timezone,
                rt.title AS reward_title, rt.reward_type, rt.claim_mode, rt.status AS reward_status
         FROM campaign_event_links cel
         JOIN events e ON e.id = cel.event_id
         JOIN campaigns c ON c.id = cel.campaign_id
         JOIN reward_templates rt ON rt.id = c.reward_template_id
         WHERE cel.campaign_id = ? AND cel.event_id = ?
         LIMIT 1"
    );
    $linked->execute([(int)$campaign['id'], $eventId]);
    $context = $linked->fetch();
    if (!$context) {
        throw new InvalidArgumentException('Campaign is not linked to that event.');
    }

    $eligibleIds = coveted_distribution_eligible_user_ids($campaign, $eventId);
    $alreadyIssued = 0;
    if ($eligibleIds) {
        $placeholders = implode(',', array_fill(0, count($eligibleIds), '?'));
        $stmt = coveted_db()->prepare(
            "SELECT COUNT(DISTINCT user_id)
             FROM reward_issuances
             WHERE campaign_id = ?
               AND event_id = ?
               AND status <> 'cancelled'
               AND user_id IN ({$placeholders})"
        );
        $stmt->execute([(int)$campaign['id'], $eventId, ...$eligibleIds]);
        $alreadyIssued = (int)$stmt->fetchColumn();
    }

    return [
        'campaign' => $campaign,
        'context' => $context,
        'eligible_user_ids' => $eligibleIds,
        'eligible_count' => count($eligibleIds),
        'already_issued_count' => $alreadyIssued,
        'remaining_count' => max(0, count($eligibleIds) - $alreadyIssued),
    ];
}

function coveted_distribution_notify_issuance(
    array $admin,
    array $campaign,
    array $issuance,
    string $rewardTitle,
    ?int $eventId = null
): array {
    $issuanceRef = (string)($issuance['public_id'] ?? $issuance['id'] ?? '');
    if ($issuanceRef === '') {
        throw new RuntimeException('Reward issuance identity is unavailable.');
    }

    $payload = [
        'reward_issuance_id' => $issuanceRef,
        'campaign_id' => (string)$campaign['public_id'],
    ];
    if ($eventId !== null) {
        $payload['event_id'] = $eventId;
    }

    return coveted_notification_create(
        (int)$issuance['user_id'],
        'reward.delivered',
        'A Coveted gift is waiting for you.',
        $rewardTitle,
        '/benefits.php?box=inbox',
        $payload,
        'high',
        'reward-delivered:' . $issuanceRef,
        (int)$admin['id']
    );
}

function coveted_distribution_run_event_campaign(
    array $admin,
    string $campaignRef,
    int $eventId,
    string $note = ''
): array {
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    $note = trim($note);
    if (mb_strlen($note) > 1000) {
        throw new InvalidArgumentException('Distribution note is too long.');
    }

    $pdo = coveted_db();
    $lockName = coveted_distribution_acquire_lock($pdo, 'event:' . $eventId . ':campaign:' . $campaignRef);

    try {
        $preview = coveted_distribution_preview($admin, $campaignRef, $eventId);
        $campaign = $preview['campaign'];
        $context = $preview['context'];

        if ($campaign['status'] !== 'active' || $context['reward_status'] !== 'active') {
            throw new InvalidArgumentException('Campaign and reward must both be active before distribution.');
        }

        $issued = 0;
        $alreadyIssued = 0;
        $skipped = 0;
        $errors = [];
        $existingStmt = $pdo->prepare(
            "SELECT * FROM reward_issuances
             WHERE campaign_id = ? AND event_id = ? AND user_id = ? AND status <> 'cancelled'
             ORDER BY id DESC LIMIT 1"
        );

        foreach ($preview['eligible_user_ids'] as $userId) {
            $existingStmt->execute([(int)$campaign['id'], $eventId, $userId]);
            $existing = $existingStmt->fetch();
            if ($existing) {
                coveted_distribution_notify_issuance(
                    $admin,
                    $campaign,
                    $existing,
                    (string)$context['reward_title'],
                    $eventId
                );
                $alreadyIssued++;
                continue;
            }

            $idempotencyKey = implode(':', [
                'system-distribution',
                'event', $eventId,
                'campaign', (int)$campaign['id'],
                'user', $userId,
            ]);

            try {
                coveted_campaign_assert_event_trigger_eligible($eventId, (string)$campaign['trigger_key'], $userId);
                $issuance = coveted_reward_issue(
                    (int)$campaign['id'],
                    $userId,
                    $eventId,
                    [
                        'trigger_key' => (string)$campaign['trigger_key'],
                        'distribution_mode' => 'system_admin',
                        'distributed_by_user_id' => (int)$admin['id'],
                        'distribution_note' => $note !== '' ? $note : null,
                    ],
                    $idempotencyKey
                );

                coveted_distribution_notify_issuance(
                    $admin,
                    $campaign,
                    $issuance,
                    (string)$context['reward_title'],
                    $eventId
                );
                $issued++;
            } catch (InvalidArgumentException $e) {
                $skipped++;
                $errors[] = ['user_id' => $userId, 'reason' => $e->getMessage()];
            }
        }

        $summary = [
            'campaign_id' => (string)$campaign['public_id'],
            'event_id' => $eventId,
            'eligible_count' => (int)$preview['eligible_count'],
            'issued_count' => $issued,
            'already_issued_count' => $alreadyIssued,
            'skipped_count' => $skipped,
            'note' => $note !== '' ? $note : null,
            'errors' => array_slice($errors, 0, 25),
        ];

        coveted_audit(
            'campaign.system_distributed',
            'campaign',
            (string)$campaign['public_id'],
            $summary,
            (int)$admin['id']
        );

        return $summary;
    } finally {
        coveted_distribution_release_lock($pdo, $lockName);
    }
}

function coveted_distribution_run_manual_campaign(
    array $admin,
    string $campaignRef,
    int $userId,
    string $requestKey,
    string $note = ''
): array {
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    $requestKey = trim($requestKey);
    $note = trim($note);
    if ($requestKey === '' || strlen($requestKey) > 190) {
        throw new InvalidArgumentException('Manual distribution request is invalid.');
    }
    if (mb_strlen($note) > 1000) {
        throw new InvalidArgumentException('Distribution note is too long.');
    }

    $campaign = coveted_campaign_by_ref($campaignRef);
    if (!$campaign || $campaign['trigger_key'] !== 'manual') {
        throw new InvalidArgumentException('Choose an active manual campaign.');
    }

    $reward = coveted_reward_template_by_ref((string)$campaign['reward_template_id']);
    if (!$reward) {
        throw new RuntimeException('Campaign reward is unavailable.');
    }

    $pdo = coveted_db();
    $lockName = coveted_distribution_acquire_lock(
        $pdo,
        'manual:' . (int)$campaign['id'] . ':user:' . $userId . ':request:' . $requestKey
    );

    try {
        $idempotencyKey = 'system-manual:' . (int)$campaign['id'] . ':user:' . $userId . ':request:' . hash('sha256', $requestKey);
        $existing = coveted_reward_existing_idempotent($pdo, $idempotencyKey);
        if ($existing) {
            coveted_distribution_notify_issuance(
                $admin,
                $campaign,
                $existing,
                (string)$reward['title']
            );
            return [
                'campaign_id' => (string)$campaign['public_id'],
                'user_id' => $userId,
                'issuance_id' => (string)($existing['public_id'] ?? $existing['id']),
                'already_issued' => true,
            ];
        }

        $user = $pdo->prepare('SELECT status FROM users WHERE id = ? LIMIT 1');
        $user->execute([$userId]);
        if ($user->fetchColumn() !== 'active') {
            throw new InvalidArgumentException('Choose an active member.');
        }

        $issuance = coveted_reward_issue(
            (int)$campaign['id'],
            $userId,
            null,
            [
                'trigger_key' => 'manual',
                'distribution_mode' => 'system_admin',
                'distributed_by_user_id' => (int)$admin['id'],
                'distribution_note' => $note !== '' ? $note : null,
            ],
            $idempotencyKey
        );

        coveted_distribution_notify_issuance(
            $admin,
            $campaign,
            $issuance,
            (string)$reward['title']
        );

        $summary = [
            'campaign_id' => (string)$campaign['public_id'],
            'user_id' => $userId,
            'issuance_id' => (string)($issuance['public_id'] ?? $issuance['id']),
            'already_issued' => false,
            'note' => $note !== '' ? $note : null,
        ];

        coveted_audit(
            'campaign.system_manual_distributed',
            'campaign',
            (string)$campaign['public_id'],
            $summary,
            (int)$admin['id']
        );

        return $summary;
    } finally {
        coveted_distribution_release_lock($pdo, $lockName);
    }
}

function coveted_distribution_recent_runs(int $limit = 50): array
{
    $limit = max(1, min($limit, 100));
    return coveted_db()->query(
        "SELECT ae.*, u.display_name AS actor_name
         FROM audit_events ae
         LEFT JOIN users u ON u.id = ae.actor_user_id
         WHERE ae.event_type IN ('campaign.system_distributed','campaign.system_manual_distributed')
         ORDER BY ae.created_at DESC, ae.id DESC
         LIMIT {$limit}"
    )->fetchAll();
}
