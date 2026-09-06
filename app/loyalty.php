<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const COVETED_LOYALTY_ATTENDANCE_POINTS = 100;
const COVETED_LOYALTY_HOST_POINTS = 50;
const COVETED_LOYALTY_RETURN_POINTS = 40;
const COVETED_LOYALTY_RECONNECT_DAYS = 90;
const COVETED_LOYALTY_LOCK = 'coveted:group-loyalty:v1';

/** @return array<int,array<string,mixed>> */
function coveted_loyalty_tiers(): array
{
    return [
        ['key' => 'new_member', 'label' => 'New Member', 'min_events' => 0, 'min_points' => 0, 'min_hosts' => 0],
        ['key' => 'active_member', 'label' => 'Active Member', 'min_events' => 1, 'min_points' => 100, 'min_hosts' => 0],
        ['key' => 'regular', 'label' => 'Regular', 'min_events' => 3, 'min_points' => 300, 'min_hosts' => 0],
        ['key' => 'core_member', 'label' => 'Core Member', 'min_events' => 5, 'min_points' => 550, 'min_hosts' => 0],
        ['key' => 'community_contributor', 'label' => 'Community Contributor', 'min_events' => 10, 'min_points' => 1100, 'min_hosts' => 1],
    ];
}

/** @return array<string,mixed> */
function coveted_loyalty_status(int $attendanceCount, int $groupPoints, int $hostCount, ?string $lastAttendedAt = null): array
{
    $attendanceCount = max(0, $attendanceCount);
    $groupPoints = max(0, $groupPoints);
    $hostCount = max(0, $hostCount);
    $tiers = coveted_loyalty_tiers();
    $earned = $tiers[0];

    foreach ($tiers as $tier) {
        if (
            $attendanceCount >= (int)$tier['min_events']
            && $groupPoints >= (int)$tier['min_points']
            && $hostCount >= (int)$tier['min_hosts']
        ) {
            $earned = $tier;
        }
    }

    $next = null;
    foreach ($tiers as $tier) {
        if ((string)$tier['key'] === (string)$earned['key']) {
            continue;
        }
        if ((int)$tier['min_events'] >= (int)$earned['min_events'] && (int)$tier['min_points'] >= (int)$earned['min_points']) {
            if ((int)$tier['min_events'] > $attendanceCount || (int)$tier['min_points'] > $groupPoints || (int)$tier['min_hosts'] > $hostCount) {
                $next = $tier;
                break;
            }
        }
    }

    $activity = 'current';
    $daysSinceAttendance = null;
    if ($attendanceCount < 1 || trim((string)$lastAttendedAt) === '') {
        $activity = 'new';
    } else {
        try {
            $last = coveted_utc_datetime((string)$lastAttendedAt);
            $daysSinceAttendance = max(0, (int)$last->diff(new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('%a'));
            if ($attendanceCount >= 2 && $daysSinceAttendance >= COVETED_LOYALTY_RECONNECT_DAYS) {
                $activity = 'reconnect';
            }
        } catch (Throwable) {
            $daysSinceAttendance = null;
        }
    }

    $progress = null;
    if ($next !== null) {
        $eventTarget = max(1, (int)$next['min_events']);
        $pointTarget = max(1, (int)$next['min_points']);
        $hostTarget = max(0, (int)$next['min_hosts']);
        $eventProgress = min(1.0, $attendanceCount / $eventTarget);
        $pointProgress = min(1.0, $groupPoints / $pointTarget);
        $hostProgress = $hostTarget > 0 ? min(1.0, $hostCount / $hostTarget) : 1.0;
        $progress = (int)round(min($eventProgress, $pointProgress, $hostProgress) * 100);
    }

    return [
        'key' => (string)$earned['key'],
        'label' => (string)$earned['label'],
        'activity_state' => $activity,
        'days_since_attendance' => $daysSinceAttendance,
        'next' => $next,
        'next_progress_percent' => $progress,
        'events_needed' => $next !== null ? max(0, (int)$next['min_events'] - $attendanceCount) : 0,
        'points_needed' => $next !== null ? max(0, (int)$next['min_points'] - $groupPoints) : 0,
        'hosts_needed' => $next !== null ? max(0, (int)$next['min_hosts'] - $hostCount) : 0,
    ];
}

function coveted_loyalty_is_duplicate(PDOException $e): bool
{
    return (int)($e->errorInfo[1] ?? 0) === 1062;
}

function coveted_loyalty_insert_points(
    PDO $pdo,
    int $userId,
    ?int $groupId,
    ?int $eventId,
    string $sourceType,
    string $sourceRef,
    int $globalPoints,
    int $groupPoints,
    string $description,
    string $occurredAt,
    array $metadata = []
): bool {
    if ($userId < 1 || ($globalPoints === 0 && $groupPoints === 0)) {
        throw new InvalidArgumentException('Invalid loyalty point entry.');
    }
    $sourceType = trim($sourceType);
    $sourceRef = trim($sourceRef);
    if ($sourceType === '' || strlen($sourceType) > 64 || $sourceRef === '' || strlen($sourceRef) > 128) {
        throw new InvalidArgumentException('Invalid loyalty source.');
    }
    if (abs($globalPoints) > 1000000 || abs($groupPoints) > 1000000) {
        throw new InvalidArgumentException('Loyalty point entry is outside the allowed range.');
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO loyalty_point_ledger
                (public_id,user_id,group_id,event_id,source_type,source_ref,global_points,group_points,description,occurred_at,metadata_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            coveted_uuid('lpt'),
            $userId,
            $groupId,
            $eventId,
            $sourceType,
            $sourceRef,
            $globalPoints,
            $groupPoints,
            mb_substr(trim($description), 0, 255),
            coveted_utc_datetime($occurredAt)->format('Y-m-d H:i:s'),
            $metadata ? coveted_json($metadata) : null,
        ]);
        return true;
    } catch (PDOException $e) {
        if (coveted_loyalty_is_duplicate($e)) {
            return false;
        }
        throw $e;
    }
}

function coveted_loyalty_insert_milestone(
    PDO $pdo,
    int $userId,
    int $groupId,
    string $milestoneKey,
    int $milestoneValue,
    string $achievedAt,
    ?string $sourceType = null,
    ?string $sourceRef = null,
    array $metadata = []
): bool {
    $milestoneKey = trim($milestoneKey);
    if ($userId < 1 || $groupId < 1 || $milestoneKey === '' || strlen($milestoneKey) > 64) {
        throw new InvalidArgumentException('Invalid loyalty milestone.');
    }
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO loyalty_milestones
                (public_id,user_id,group_id,milestone_key,milestone_value,source_type,source_ref,achieved_at,metadata_json)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            coveted_uuid('lms'),
            $userId,
            $groupId,
            $milestoneKey,
            max(1, $milestoneValue),
            $sourceType,
            $sourceRef,
            coveted_utc_datetime($achievedAt)->format('Y-m-d H:i:s'),
            $metadata ? coveted_json($metadata) : null,
        ]);
        return true;
    } catch (PDOException $e) {
        if (coveted_loyalty_is_duplicate($e)) {
            return false;
        }
        throw $e;
    }
}

/** @return array{inserted:int,more:bool,failures:int} */
function coveted_loyalty_reconcile_attendance(PDO $pdo, int $limit): array
{
    $stmt = $pdo->query(
        "SELECT ea.user_id, e.id AS event_id, e.public_id AS event_ref, e.group_id, e.title,
                COALESCE(e.ends_at,e.starts_at) AS occurred_at, ea.status AS attendance_status
         FROM event_attendance ea
         JOIN events e ON e.id=ea.event_id
         JOIN users u ON u.id=ea.user_id AND u.status='active'
         WHERE e.status='completed'
           AND ea.status IN ('checked_in','attended','left_early')
           AND NOT EXISTS (
               SELECT 1 FROM loyalty_point_ledger lp
               WHERE lp.user_id=ea.user_id
                 AND lp.source_type='verified_attendance'
                 AND lp.source_ref=e.public_id
           )
         ORDER BY COALESCE(e.ends_at,e.starts_at), ea.id
         LIMIT " . ($limit + 1)
    );
    $rows = $stmt->fetchAll();
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $inserted = 0;
    $failures = 0;
    foreach ($rows as $row) {
        try {
            if (coveted_loyalty_insert_points(
                $pdo,
                (int)$row['user_id'],
                (int)$row['group_id'],
                (int)$row['event_id'],
                'verified_attendance',
                (string)$row['event_ref'],
                COVETED_LOYALTY_ATTENDANCE_POINTS,
                COVETED_LOYALTY_ATTENDANCE_POINTS,
                'Verified event attendance',
                (string)$row['occurred_at'],
                ['attendance_status' => (string)$row['attendance_status']]
            )) {
                $inserted++;
            }
        } catch (Throwable $e) {
            $failures++;
            error_log('Loyalty attendance reconciliation failed: ' . $e->getMessage());
        }
    }
    return ['inserted' => $inserted, 'more' => $more, 'failures' => $failures];
}

/** @return array{inserted:int,more:bool,failures:int} */
function coveted_loyalty_reconcile_hosts(PDO $pdo, int $limit): array
{
    $stmt = $pdo->query(
        "SELECT eh.user_id, e.id AS event_id, e.public_id AS event_ref, e.group_id,
                eh.host_role, COALESCE(e.ends_at,e.starts_at) AS occurred_at
         FROM event_hosts eh
         JOIN events e ON e.id=eh.event_id
         JOIN users u ON u.id=eh.user_id AND u.status='active'
         WHERE e.status='completed'
           AND eh.host_role IN ('lead','cohost')
           AND NOT EXISTS (
               SELECT 1 FROM loyalty_point_ledger lp
               WHERE lp.user_id=eh.user_id
                 AND lp.source_type='host_contribution'
                 AND lp.source_ref=e.public_id
           )
         ORDER BY COALESCE(e.ends_at,e.starts_at), e.id, eh.user_id
         LIMIT " . ($limit + 1)
    );
    $rows = $stmt->fetchAll();
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $inserted = 0;
    $failures = 0;
    foreach ($rows as $row) {
        try {
            if (coveted_loyalty_insert_points(
                $pdo,
                (int)$row['user_id'],
                (int)$row['group_id'],
                (int)$row['event_id'],
                'host_contribution',
                (string)$row['event_ref'],
                COVETED_LOYALTY_HOST_POINTS,
                COVETED_LOYALTY_HOST_POINTS,
                'Event host contribution',
                (string)$row['occurred_at'],
                ['host_role' => (string)$row['host_role']]
            )) {
                $inserted++;
            }
        } catch (Throwable $e) {
            $failures++;
            error_log('Loyalty host reconciliation failed: ' . $e->getMessage());
        }
    }
    return ['inserted' => $inserted, 'more' => $more, 'failures' => $failures];
}

/** @return array{inserted:int,more:bool,failures:int} */
function coveted_loyalty_reconcile_returns(PDO $pdo, int $limit): array
{
    $stmt = $pdo->query(
        "SELECT followup.user_id, followup.public_id AS issuance_ref, followup.event_id,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(followup.metadata_json,'$.origin_group_id')) AS UNSIGNED) AS group_id,
                followup.created_at AS occurred_at, c.trigger_key,
                JSON_UNQUOTE(JSON_EXTRACT(followup.metadata_json,'$.source_reward_issuance_id')) AS source_reward_ref
         FROM reward_issuances followup
         JOIN campaigns c ON c.id=followup.campaign_id
         JOIN users u ON u.id=followup.user_id AND u.status='active'
         JOIN social_groups g
           ON g.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(followup.metadata_json,'$.origin_group_id')) AS UNSIGNED)
         WHERE followup.status<>'cancelled'
           AND c.trigger_key IN ('return_visit','guest_return')
           AND JSON_UNQUOTE(JSON_EXTRACT(followup.metadata_json,'$.source_reward_issuance_id')) IS NOT NULL
           AND NOT EXISTS (
               SELECT 1 FROM loyalty_point_ledger lp
               WHERE lp.user_id=followup.user_id
                 AND lp.source_type='verified_return_visit'
                 AND lp.source_ref=followup.public_id
           )
         ORDER BY followup.created_at, followup.id
         LIMIT " . ($limit + 1)
    );
    $rows = $stmt->fetchAll();
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $inserted = 0;
    $failures = 0;
    foreach ($rows as $row) {
        try {
            if (coveted_loyalty_insert_points(
                $pdo,
                (int)$row['user_id'],
                (int)$row['group_id'],
                $row['event_id'] !== null ? (int)$row['event_id'] : null,
                'verified_return_visit',
                (string)$row['issuance_ref'],
                COVETED_LOYALTY_RETURN_POINTS,
                COVETED_LOYALTY_RETURN_POINTS,
                'Verified partner return visit',
                (string)$row['occurred_at'],
                [
                    'return_kind' => (string)$row['trigger_key'],
                    'source_reward_issuance_id' => (string)$row['source_reward_ref'],
                ]
            )) {
                $inserted++;
            }
        } catch (Throwable $e) {
            $failures++;
            error_log('Loyalty return reconciliation failed: ' . $e->getMessage());
        }
    }
    return ['inserted' => $inserted, 'more' => $more, 'failures' => $failures];
}

/** @return array{occurred_at:string,source_ref:string}|null */
function coveted_loyalty_nth_attendance(PDO $pdo, int $userId, int $groupId, int $ordinal): ?array
{
    $offset = max(0, $ordinal - 1);
    $stmt = $pdo->prepare(
        "SELECT occurred_at,source_ref
         FROM loyalty_point_ledger
         WHERE user_id=? AND group_id=? AND source_type='verified_attendance'
         ORDER BY occurred_at ASC,id ASC
         LIMIT 1 OFFSET {$offset}"
    );
    $stmt->execute([$userId, $groupId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** @return array{inserted:int,more:bool,failures:int} */
function coveted_loyalty_reconcile_milestones(PDO $pdo, int $limit): array
{
    $limit = max(1, min($limit, 1000));
    $inserted = 0;
    $failures = 0;
    $more = false;
    $eventMilestones = [1 => 'first_event', 3 => 'event_3', 5 => 'event_5', 10 => 'event_10', 25 => 'event_25'];

    $attendance = $pdo->query(
        "SELECT a.user_id,a.group_id,a.event_count,a.last_at
         FROM (
             SELECT user_id,group_id,COUNT(*) AS event_count,MAX(occurred_at) AS last_at
             FROM loyalty_point_ledger
             WHERE source_type='verified_attendance' AND group_id IS NOT NULL
             GROUP BY user_id,group_id
         ) a
         WHERE (a.event_count>=1 AND NOT EXISTS (
                    SELECT 1 FROM loyalty_milestones lm
                    WHERE lm.user_id=a.user_id AND lm.group_id=a.group_id AND lm.milestone_key='first_event'))
            OR (a.event_count>=3 AND NOT EXISTS (
                    SELECT 1 FROM loyalty_milestones lm
                    WHERE lm.user_id=a.user_id AND lm.group_id=a.group_id AND lm.milestone_key='event_3'))
            OR (a.event_count>=5 AND NOT EXISTS (
                    SELECT 1 FROM loyalty_milestones lm
                    WHERE lm.user_id=a.user_id AND lm.group_id=a.group_id AND lm.milestone_key='event_5'))
            OR (a.event_count>=10 AND NOT EXISTS (
                    SELECT 1 FROM loyalty_milestones lm
                    WHERE lm.user_id=a.user_id AND lm.group_id=a.group_id AND lm.milestone_key='event_10'))
            OR (a.event_count>=25 AND NOT EXISTS (
                    SELECT 1 FROM loyalty_milestones lm
                    WHERE lm.user_id=a.user_id AND lm.group_id=a.group_id AND lm.milestone_key='event_25'))
         ORDER BY a.last_at,a.user_id,a.group_id
         LIMIT " . ($limit + 1)
    )->fetchAll();
    if (count($attendance) > $limit) $more = true;
    foreach (array_slice($attendance, 0, $limit) as $row) {
        $count = (int)$row['event_count'];
        foreach ($eventMilestones as $threshold => $key) {
            if ($count < $threshold) continue;
            try {
                $nth = coveted_loyalty_nth_attendance($pdo, (int)$row['user_id'], (int)$row['group_id'], $threshold);
                if ($nth === null) continue;
                if (coveted_loyalty_insert_milestone(
                    $pdo,
                    (int)$row['user_id'],
                    (int)$row['group_id'],
                    $key,
                    $threshold,
                    (string)$nth['occurred_at'],
                    'verified_attendance',
                    (string)$nth['source_ref'],
                    ['verified_event_count_at_reconcile' => $count, 'threshold' => $threshold]
                )) $inserted++;
            } catch (Throwable $e) {
                $failures++;
                error_log('Loyalty event milestone reconciliation failed: ' . $e->getMessage());
            }
        }
    }

    foreach ([
        ['source' => 'verified_return_visit', 'key' => 'first_return', 'value' => 1],
        ['source' => 'host_contribution', 'key' => 'first_host', 'value' => 1],
    ] as $definition) {
        $stmt = $pdo->prepare(
            "SELECT lp.user_id,lp.group_id,MIN(lp.occurred_at) AS achieved_at
             FROM loyalty_point_ledger lp
             WHERE lp.source_type=? AND lp.group_id IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM loyalty_milestones lm
                   WHERE lm.user_id=lp.user_id AND lm.group_id=lp.group_id AND lm.milestone_key=?
               )
             GROUP BY lp.user_id,lp.group_id
             ORDER BY achieved_at,lp.user_id,lp.group_id
             LIMIT " . ($limit + 1)
        );
        $stmt->execute([(string)$definition['source'], (string)$definition['key']]);
        $rows = $stmt->fetchAll();
        if (count($rows) > $limit) $more = true;
        foreach (array_slice($rows, 0, $limit) as $row) {
            try {
                if (coveted_loyalty_insert_milestone(
                    $pdo,
                    (int)$row['user_id'],
                    (int)$row['group_id'],
                    (string)$definition['key'],
                    (int)$definition['value'],
                    (string)$row['achieved_at'],
                    (string)$definition['source'],
                    'first'
                )) $inserted++;
            } catch (Throwable $e) {
                $failures++;
                error_log('Loyalty relationship milestone reconciliation failed: ' . $e->getMessage());
            }
        }
    }

    $anniversary = $pdo->query(
        "SELECT gm.user_id,gm.group_id,DATE_ADD(gm.joined_at,INTERVAL 1 YEAR) AS achieved_at
         FROM group_memberships gm
         JOIN users u ON u.id=gm.user_id AND u.status='active'
         WHERE gm.membership_status IN ('active','away')
           AND gm.joined_at IS NOT NULL
           AND gm.joined_at <= DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 YEAR)
           AND NOT EXISTS (
               SELECT 1 FROM loyalty_milestones lm
               WHERE lm.user_id=gm.user_id AND lm.group_id=gm.group_id AND lm.milestone_key='membership_year_1'
           )
         ORDER BY achieved_at,gm.user_id,gm.group_id
         LIMIT " . ($limit + 1)
    )->fetchAll();
    if (count($anniversary) > $limit) $more = true;
    foreach (array_slice($anniversary, 0, $limit) as $row) {
        try {
            if (coveted_loyalty_insert_milestone(
                $pdo,
                (int)$row['user_id'],
                (int)$row['group_id'],
                'membership_year_1',
                1,
                (string)$row['achieved_at'],
                'group_membership',
                'year:1'
            )) $inserted++;
        } catch (Throwable $e) {
            $failures++;
            error_log('Loyalty anniversary milestone reconciliation failed: ' . $e->getMessage());
        }
    }

    return ['inserted' => $inserted, 'more' => $more, 'failures' => $failures];
}

/** @return array<string,mixed> */
function coveted_loyalty_reconcile(int $limit = 250): array
{
    $limit = max(10, min($limit, 1000));
    $pdo = coveted_db();
    $lock = $pdo->prepare('SELECT GET_LOCK(?,0)');
    $lock->execute([COVETED_LOYALTY_LOCK]);
    if ((int)$lock->fetchColumn() !== 1) {
        return [
            'attendance_points' => 0,
            'host_points' => 0,
            'return_points' => 0,
            'milestones' => 0,
            'failures' => 0,
            'more_work_possible' => false,
            'skipped_locked' => true,
        ];
    }

    try {
        $attendance = coveted_loyalty_reconcile_attendance($pdo, $limit);
        $hosts = coveted_loyalty_reconcile_hosts($pdo, $limit);
        $returns = coveted_loyalty_reconcile_returns($pdo, $limit);
        $milestones = coveted_loyalty_reconcile_milestones($pdo, $limit);
        $failures = (int)$attendance['failures'] + (int)$hosts['failures'] + (int)$returns['failures'] + (int)$milestones['failures'];
        $more = (bool)$attendance['more'] || (bool)$hosts['more'] || (bool)$returns['more'] || (bool)$milestones['more'];
        $summary = [
            'attendance_points' => (int)$attendance['inserted'],
            'host_points' => (int)$hosts['inserted'],
            'return_points' => (int)$returns['inserted'],
            'milestones' => (int)$milestones['inserted'],
            'failures' => $failures,
            'more_work_possible' => $more,
            'skipped_locked' => false,
        ];
        $changed = (int)$summary['attendance_points'] + (int)$summary['host_points'] + (int)$summary['return_points'] + (int)$summary['milestones'];
        if ($changed > 0 || $failures > 0) {
            coveted_audit('loyalty.reconciled', 'loyalty', 'v1', $summary, null);
        }
        return $summary;
    } finally {
        try {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([COVETED_LOYALTY_LOCK]);
        } catch (Throwable $e) {
            error_log('Loyalty reconciliation lock release failed: ' . $e->getMessage());
        }
    }
}

/** @return array<string,mixed> */
function coveted_loyalty_member_snapshot(int $userId): array
{
    if ($userId < 1) throw new InvalidArgumentException('Choose a valid member.');
    $pdo = coveted_db();
    $lifetime = $pdo->prepare(
        "SELECT COALESCE(SUM(global_points),0) AS lifetime_points,
                COUNT(*) AS point_events,
                COUNT(DISTINCT group_id) AS groups_with_points,
                MAX(occurred_at) AS last_point_at
         FROM loyalty_point_ledger WHERE user_id=?"
    );
    $lifetime->execute([$userId]);
    $lifetimeRow = $lifetime->fetch() ?: [];

    $groups = $pdo->prepare(
        "SELECT g.id AS group_id,g.public_id AS group_ref,g.name AS group_name,g.city,
                gm.group_role,gm.membership_status,gm.joined_at,
                COALESCE(SUM(lp.group_points),0) AS group_points,
                COUNT(DISTINCT CASE WHEN lp.source_type='verified_attendance' THEN lp.source_ref END) AS attendance_count,
                COUNT(DISTINCT CASE WHEN lp.source_type='host_contribution' THEN lp.source_ref END) AS host_count,
                COUNT(DISTINCT CASE WHEN lp.source_type='verified_return_visit' THEN lp.source_ref END) AS return_count,
                MAX(CASE WHEN lp.source_type='verified_attendance' THEN lp.occurred_at END) AS last_attended_at,
                MAX(lp.occurred_at) AS last_activity_at
         FROM group_memberships gm
         JOIN social_groups g ON g.id=gm.group_id
         LEFT JOIN loyalty_point_ledger lp ON lp.user_id=gm.user_id AND lp.group_id=gm.group_id
         WHERE gm.user_id=? AND gm.membership_status IN ('active','away')
         GROUP BY g.id,g.public_id,g.name,g.city,gm.group_role,gm.membership_status,gm.joined_at
         ORDER BY FIELD(gm.membership_status,'active','away'),g.name"
    );
    $groups->execute([$userId]);
    $groupRows = $groups->fetchAll();
    foreach ($groupRows as &$group) {
        $group['status'] = coveted_loyalty_status(
            (int)$group['attendance_count'],
            (int)$group['group_points'],
            (int)$group['host_count'],
            $group['last_attended_at'] !== null ? (string)$group['last_attended_at'] : null
        );
    }
    unset($group);

    $milestones = $pdo->prepare(
        "SELECT lm.public_id,lm.group_id,lm.milestone_key,lm.milestone_value,lm.achieved_at,g.name AS group_name
         FROM loyalty_milestones lm
         JOIN social_groups g ON g.id=lm.group_id
         WHERE lm.user_id=?
         ORDER BY lm.achieved_at DESC,lm.id DESC
         LIMIT 50"
    );
    $milestones->execute([$userId]);

    $recent = $pdo->prepare(
        "SELECT lp.public_id,lp.source_type,lp.global_points,lp.group_points,lp.description,lp.occurred_at,
                g.name AS group_name,e.title AS event_title
         FROM loyalty_point_ledger lp
         LEFT JOIN social_groups g ON g.id=lp.group_id
         LEFT JOIN events e ON e.id=lp.event_id
         WHERE lp.user_id=?
         ORDER BY lp.occurred_at DESC,lp.id DESC
         LIMIT 40"
    );
    $recent->execute([$userId]);

    return [
        'lifetime_points' => (int)($lifetimeRow['lifetime_points'] ?? 0),
        'point_events' => (int)($lifetimeRow['point_events'] ?? 0),
        'groups_with_points' => (int)($lifetimeRow['groups_with_points'] ?? 0),
        'last_point_at' => $lifetimeRow['last_point_at'] ?? null,
        'groups' => $groupRows,
        'milestones' => $milestones->fetchAll(),
        'recent_points' => $recent->fetchAll(),
        'privacy' => 'Your Coveted Points are private. Other members do not see your balance or a leaderboard.',
        'travel_note' => 'Lifetime Coveted Points are group-independent so future Coveted cities, travel access and partner experiences can recognize long-term participation without rebuilding the ledger.',
    ];
}

/** @return array<string,mixed> */
function coveted_loyalty_admin_snapshot(): array
{
    $pdo = coveted_db();
    $summary = $pdo->query(
        "SELECT COALESCE(SUM(global_points),0) AS lifetime_points_issued,
                COUNT(DISTINCT user_id) AS members_with_points,
                COUNT(*) AS ledger_entries,
                COALESCE(SUM(CASE WHEN occurred_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) THEN global_points ELSE 0 END),0) AS points_30d
         FROM loyalty_point_ledger"
    )->fetch() ?: [];

    $memberships = $pdo->query(
        "SELECT gm.user_id,gm.group_id,g.public_id AS group_ref,g.name AS group_name,
                COALESCE(SUM(lp.group_points),0) AS group_points,
                COUNT(DISTINCT CASE WHEN lp.source_type='verified_attendance' THEN lp.source_ref END) AS attendance_count,
                COUNT(DISTINCT CASE WHEN lp.source_type='host_contribution' THEN lp.source_ref END) AS host_count,
                COUNT(DISTINCT CASE WHEN lp.source_type='verified_return_visit' THEN lp.source_ref END) AS return_count,
                MAX(CASE WHEN lp.source_type='verified_attendance' THEN lp.occurred_at END) AS last_attended_at
         FROM group_memberships gm
         JOIN users u ON u.id=gm.user_id AND u.status='active'
         JOIN social_groups g ON g.id=gm.group_id AND g.status='active'
         LEFT JOIN loyalty_point_ledger lp ON lp.user_id=gm.user_id AND lp.group_id=gm.group_id
         WHERE gm.membership_status='active'
         GROUP BY gm.user_id,gm.group_id,g.public_id,g.name
         ORDER BY gm.group_id,gm.user_id
         LIMIT 10000"
    )->fetchAll();

    $tierDistribution = [];
    foreach (coveted_loyalty_tiers() as $tier) $tierDistribution[(string)$tier['key']] = 0;
    $reconnect = 0;
    $near = ['event_3' => 0, 'event_5' => 0, 'event_10' => 0, 'event_25' => 0];
    $groupAgg = [];
    foreach ($memberships as $row) {
        $status = coveted_loyalty_status(
            (int)$row['attendance_count'],
            (int)$row['group_points'],
            (int)$row['host_count'],
            $row['last_attended_at'] !== null ? (string)$row['last_attended_at'] : null
        );
        $tierDistribution[(string)$status['key']] = ($tierDistribution[(string)$status['key']] ?? 0) + 1;
        if ((string)$status['activity_state'] === 'reconnect') $reconnect++;
        $count = (int)$row['attendance_count'];
        if ($count === 2) $near['event_3']++;
        if ($count === 4) $near['event_5']++;
        if ($count === 9) $near['event_10']++;
        if ($count === 24) $near['event_25']++;
        $groupRef = (string)$row['group_ref'];
        if (!isset($groupAgg[$groupRef])) {
            $groupAgg[$groupRef] = ['group_ref' => $groupRef, 'group_name' => (string)$row['group_name'], 'active_members' => 0, 'group_points' => 0, 'verified_events' => 0, 'returns' => 0];
        }
        $groupAgg[$groupRef]['active_members']++;
        $groupAgg[$groupRef]['group_points'] += (int)$row['group_points'];
        $groupAgg[$groupRef]['verified_events'] += (int)$row['attendance_count'];
        $groupAgg[$groupRef]['returns'] += (int)$row['return_count'];
    }

    $retention = $pdo->query(
        "SELECT COUNT(*) AS eligible_relationships,
                COALESCE(SUM(event_count>=2),0) AS second_event_relationships
         FROM (
             SELECT user_id,group_id,COUNT(*) AS event_count,MIN(occurred_at) AS first_attended_at
             FROM loyalty_point_ledger
             WHERE source_type='verified_attendance' AND group_id IS NOT NULL
             GROUP BY user_id,group_id
             HAVING first_attended_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)
         ) x"
    )->fetch() ?: [];
    $eligible = (int)($retention['eligible_relationships'] ?? 0);
    $second = (int)($retention['second_event_relationships'] ?? 0);

    $travelReady = (int)$pdo->query(
        "SELECT COUNT(*) FROM (
             SELECT user_id FROM loyalty_point_ledger
             WHERE group_id IS NOT NULL AND global_points>0
             GROUP BY user_id HAVING COUNT(DISTINCT group_id)>=2
         ) t"
    )->fetchColumn();

    $milestones30d = (int)$pdo->query(
        "SELECT COUNT(*) FROM loyalty_milestones WHERE achieved_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)"
    )->fetchColumn();

    usort($groupAgg, static fn(array $a, array $b): int => $b['group_points'] <=> $a['group_points']);

    return [
        'summary' => [
            'active_memberships' => count($memberships),
            'members_with_points' => (int)($summary['members_with_points'] ?? 0),
            'lifetime_points_issued' => (int)($summary['lifetime_points_issued'] ?? 0),
            'points_30d' => (int)($summary['points_30d'] ?? 0),
            'ledger_entries' => (int)($summary['ledger_entries'] ?? 0),
            'reconnect_relationships' => $reconnect,
            'travel_ready_members' => $travelReady,
            'milestones_30d' => $milestones30d,
            'second_event_eligible' => $eligible,
            'second_event_relationships' => $second,
            'second_event_rate' => $eligible > 0 ? round(($second / $eligible) * 100, 1) : null,
        ],
        'tier_distribution' => $tierDistribution,
        'near_milestones' => $near,
        'groups' => array_slice(array_values($groupAgg), 0, 50),
        'privacy' => 'Admin loyalty analytics are aggregate. Member point balances are private and there is no public leaderboard.',
        'points_policy' => [
            'verified_attendance' => COVETED_LOYALTY_ATTENDANCE_POINTS,
            'lead_or_cohost' => COVETED_LOYALTY_HOST_POINTS,
            'verified_return_visit' => COVETED_LOYALTY_RETURN_POINTS,
            'benefit_claim' => 0,
        ],
    ];
}

/** @return array<string,mixed> */
function coveted_loyalty_agent_context(): array
{
    try {
        $snapshot = coveted_loyalty_admin_snapshot();
        $summary = (array)$snapshot['summary'];
        $insights = [];
        $near = (array)$snapshot['near_milestones'];
        if ((int)($near['event_5'] ?? 0) > 0) {
            $insights[] = [
                'key' => 'loyalty-near-fifth-event',
                'priority' => 2,
                'title' => 'Members are approaching their fifth verified event',
                'detail' => 'Consider whether a fifth-event recognition or Benefit Program belongs in the next planning cycle. This is analysis only; do not create or launch a program unless the System Admin explicitly asks.',
                'evidence' => (int)$near['event_5'] . ' active group relationship' . ((int)$near['event_5'] === 1 ? '' : 's') . ' currently at four verified events.',
            ];
        }
        if ((int)($summary['reconnect_relationships'] ?? 0) > 0) {
            $insights[] = [
                'key' => 'loyalty-reconnect',
                'priority' => 1,
                'title' => 'Previously active relationships are ready for reconnect planning',
                'detail' => 'These are aggregate relationships with at least two verified events and no verified attendance for 90+ days. Use Reconnect and event planning; do not expose member identities from Agent context.',
                'evidence' => (int)$summary['reconnect_relationships'] . ' active group relationship' . ((int)$summary['reconnect_relationships'] === 1 ? '' : 's') . ' in reconnect state.',
            ];
        }
        $rate = $summary['second_event_rate'] ?? null;
        if ($rate !== null && (int)($summary['second_event_eligible'] ?? 0) >= 5 && (float)$rate < 55.0) {
            $insights[] = [
                'key' => 'loyalty-second-event-retention',
                'priority' => 1,
                'title' => 'Second-event retention needs attention',
                'detail' => 'Review the event, venue, Benefit and follow-up mix for first-time attendees. The rate is observational and should not be treated as proof that any one program caused retention.',
                'evidence' => number_format((float)$rate, 1) . '% of matured first-event group relationships have a second verified event.',
            ];
        }
        if ((int)($summary['travel_ready_members'] ?? 0) > 0) {
            $insights[] = [
                'key' => 'loyalty-cross-group',
                'priority' => 3,
                'title' => 'Cross-group participation is emerging',
                'detail' => 'Lifetime Coveted Points already span groups, creating a measurable base for future cross-city and travel recognition. Do not infer travel intent from participation alone.',
                'evidence' => (int)$summary['travel_ready_members'] . ' member' . ((int)$summary['travel_ready_members'] === 1 ? '' : 's') . ' have verified point activity in at least two groups.',
            ];
        }

        return [
            'summary' => $summary,
            'tier_distribution' => (array)$snapshot['tier_distribution'],
            'near_milestones' => $near,
            'groups' => array_slice((array)$snapshot['groups'], 0, 12),
            'insights' => $insights,
            'privacy' => 'Aggregate loyalty intelligence only. No member names, emails, phones, individual point balances or person-level leaderboard data are included.',
            'action_policy' => 'Loyalty insights are analysis-only. The Agent may recommend planning or a Benefit Program concept, but it must not award points, alter a member balance, change tier thresholds, or create/launch economics from this context.',
            'admin_href' => '/admin/loyalty.php',
        ];
    } catch (Throwable $e) {
        error_log('Admin Agent loyalty context unavailable: ' . $e->getMessage());
        return ['unavailable' => true, 'admin_href' => '/admin/loyalty.php'];
    }
}
