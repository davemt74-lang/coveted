<?php
declare(strict_types=1);

require_once __DIR__ . '/businesses.php';

/** @return array<int,string> */
function coveted_venue_relationship_statuses(): array
{
    return ['new', 'event_venue', 'partner', 'preferred_partner', 'home_venue'];
}

function coveted_venue_relationship_key(int $groupId, int $locationId): string
{
    return $groupId . ':' . $locationId;
}

/** @return array<string,mixed> */
function coveted_venue_relationship_resolve(
    array $actor,
    int $businessId,
    string $groupRef,
    string $locationRef
): array {
    if (!coveted_business_actor_can_view($actor, $businessId)) {
        throw new InvalidArgumentException('Business Admin access is required.');
    }

    $groupRef = trim($groupRef);
    $locationRef = trim($locationRef);
    if ($groupRef === '' || $locationRef === '' || strlen($groupRef) > 64 || strlen($locationRef) > 64) {
        throw new InvalidArgumentException('Venue relationship is not available.');
    }

    $stmt = coveted_db()->prepare(
        "SELECT DISTINCT
            g.id AS group_id,
            g.public_id AS group_public_id,
            g.name AS group_name,
            g.status AS group_status,
            l.id AS location_id,
            l.public_id AS location_public_id,
            l.name AS location_name,
            l.city,
            l.region,
            l.status AS location_status
         FROM social_groups g
         JOIN events e
           ON e.group_id = g.id
          AND e.status IN ('published','closed','completed')
         JOIN event_locations el ON el.event_id = e.id
         JOIN locations l ON l.id = el.location_id
         WHERE l.business_id = ?
           AND (g.public_id = ? OR CAST(g.id AS CHAR) = ?)
           AND (l.public_id = ? OR CAST(l.id AS CHAR) = ?)
         LIMIT 1"
    );
    $stmt->execute([$businessId, $groupRef, $groupRef, $locationRef, $locationRef]);
    $relationship = $stmt->fetch();

    if (!$relationship) {
        throw new InvalidArgumentException('Venue relationship is not available.');
    }

    return $relationship;
}

/** @return array<int,array<string,mixed>> */
function coveted_venue_relationships_for_business(array $actor, int $businessId): array
{
    if (!coveted_business_actor_can_view($actor, $businessId)) {
        throw new InvalidArgumentException('Business Admin access is required.');
    }

    $pdo = coveted_db();
    $stmt = $pdo->prepare(
        "SELECT
            g.id AS group_id,
            g.public_id AS group_public_id,
            g.name AS group_name,
            g.status AS group_status,
            l.id AS location_id,
            l.public_id AS location_public_id,
            l.name AS location_name,
            l.city,
            l.region,
            l.status AS location_status,
            vr.relationship_status AS stored_status,
            vr.partner_since,
            COALESCE(vr.benefits_enabled, 0) AS benefits_enabled,
            COALESCE(vr.mystery_events_enabled, 0) AS mystery_events_enabled,
            vr.notes,
            COUNT(DISTINCT e.id) AS total_events,
            COUNT(DISTINCT CASE WHEN e.status = 'completed' THEN e.id END) AS completed_events,
            COUNT(DISTINCT CASE
                WHEN e.status IN ('published','closed')
                 AND COALESCE(e.ends_at, e.starts_at) >= NOW()
                THEN e.id END) AS upcoming_events,
            MIN(CASE WHEN e.status = 'completed' THEN e.starts_at END) AS first_completed_event_at,
            MAX(CASE WHEN e.status = 'completed' THEN e.starts_at END) AS last_completed_event_at,
            MAX(e.starts_at) AS latest_event_at
         FROM event_locations el
         JOIN events e
           ON e.id = el.event_id
          AND e.status IN ('published','closed','completed')
         JOIN social_groups g ON g.id = e.group_id
         JOIN locations l ON l.id = el.location_id
         LEFT JOIN venue_relationships vr
           ON vr.group_id = g.id
          AND vr.location_id = l.id
         WHERE l.business_id = ?
         GROUP BY
            g.id, g.public_id, g.name, g.status,
            l.id, l.public_id, l.name, l.city, l.region, l.status,
            vr.relationship_status, vr.partner_since, vr.benefits_enabled,
            vr.mystery_events_enabled, vr.notes
         ORDER BY
            COALESCE(MAX(CASE WHEN e.status = 'completed' THEN e.starts_at END), MAX(e.starts_at)) DESC,
            g.name,
            l.name"
    );
    $stmt->execute([$businessId]);
    $relationships = $stmt->fetchAll();
    if (!$relationships) {
        return [];
    }

    $attendanceStmt = $pdo->prepare(
        "SELECT
            e.group_id,
            el.location_id,
            COUNT(*) AS verified_visits,
            COUNT(DISTINCT ea.user_id) AS unique_attendees
         FROM event_attendance ea
         JOIN events e ON e.id = ea.event_id AND e.status = 'completed'
         JOIN event_locations el ON el.event_id = e.id
         JOIN locations l ON l.id = el.location_id
         WHERE l.business_id = ?
           AND ea.status IN ('checked_in','attended','left_early')
         GROUP BY e.group_id, el.location_id"
    );
    $attendanceStmt->execute([$businessId]);
    $attendance = [];
    foreach ($attendanceStmt->fetchAll() as $row) {
        $attendance[coveted_venue_relationship_key((int)$row['group_id'], (int)$row['location_id'])] = $row;
    }

    $repeatStmt = $pdo->prepare(
        "SELECT group_id, location_id, COUNT(*) AS repeat_attendees
         FROM (
            SELECT e.group_id, el.location_id, ea.user_id
            FROM event_attendance ea
            JOIN events e ON e.id = ea.event_id AND e.status = 'completed'
            JOIN event_locations el ON el.event_id = e.id
            JOIN locations l ON l.id = el.location_id
            WHERE l.business_id = ?
              AND ea.status IN ('checked_in','attended','left_early')
            GROUP BY e.group_id, el.location_id, ea.user_id
            HAVING COUNT(DISTINCT e.id) >= 2
         ) repeaters
         GROUP BY group_id, location_id"
    );
    $repeatStmt->execute([$businessId]);
    $repeat = [];
    foreach ($repeatStmt->fetchAll() as $row) {
        $repeat[coveted_venue_relationship_key((int)$row['group_id'], (int)$row['location_id'])] = (int)$row['repeat_attendees'];
    }

    $issuanceStmt = $pdo->prepare(
        "SELECT
            e.group_id,
            el.location_id,
            COUNT(*) AS business_benefits_issued,
            COUNT(DISTINCT ri.user_id) AS members_reached
         FROM reward_issuances ri
         JOIN reward_templates rt ON rt.id = ri.reward_template_id
         JOIN events e
           ON e.id = ri.event_id
          AND e.status IN ('published','closed','completed')
         JOIN event_locations el ON el.event_id = e.id
         JOIN locations l ON l.id = el.location_id
         WHERE rt.business_id = ?
           AND l.business_id = ?
           AND ri.status <> 'cancelled'
         GROUP BY e.group_id, el.location_id"
    );
    $issuanceStmt->execute([$businessId, $businessId]);
    $issuances = [];
    foreach ($issuanceStmt->fetchAll() as $row) {
        $issuances[coveted_venue_relationship_key((int)$row['group_id'], (int)$row['location_id'])] = $row;
    }

    $claimStmt = $pdo->prepare(
        "SELECT
            e.group_id,
            rc.location_id,
            COUNT(*) AS claims,
            COUNT(DISTINCT ri.user_id) AS claiming_members,
            SUM(rc.status = 'refunded') AS refunds,
            SUM(c.trigger_key IN ('return_visit','guest_return')) AS return_claims,
            SUM(c.trigger_key = 'guest_return') AS guest_return_claims
         FROM reward_claims rc
         JOIN reward_issuances ri ON ri.id = rc.reward_issuance_id
         JOIN reward_templates rt ON rt.id = ri.reward_template_id
         JOIN campaigns c ON c.id = ri.campaign_id
         JOIN events e
           ON e.id = ri.event_id
          AND e.status IN ('published','closed','completed')
         JOIN locations l ON l.id = rc.location_id
         WHERE rt.business_id = ?
           AND l.business_id = ?
         GROUP BY e.group_id, rc.location_id"
    );
    $claimStmt->execute([$businessId, $businessId]);
    $claims = [];
    foreach ($claimStmt->fetchAll() as $row) {
        $claims[coveted_venue_relationship_key((int)$row['group_id'], (int)$row['location_id'])] = $row;
    }

    foreach ($relationships as &$relationship) {
        $key = coveted_venue_relationship_key((int)$relationship['group_id'], (int)$relationship['location_id']);
        $completedEvents = (int)$relationship['completed_events'];
        $relationship['relationship_status'] = $relationship['stored_status'] !== null
            ? (string)$relationship['stored_status']
            : ($completedEvents > 0 ? 'event_venue' : 'new');
        $relationship['verified_visits'] = (int)($attendance[$key]['verified_visits'] ?? 0);
        $relationship['unique_attendees'] = (int)($attendance[$key]['unique_attendees'] ?? 0);
        $relationship['repeat_attendees'] = (int)($repeat[$key] ?? 0);
        $relationship['business_benefits_issued'] = (int)($issuances[$key]['business_benefits_issued'] ?? 0);
        $relationship['members_reached'] = (int)($issuances[$key]['members_reached'] ?? 0);
        $relationship['claims'] = (int)($claims[$key]['claims'] ?? 0);
        $relationship['claiming_members'] = (int)($claims[$key]['claiming_members'] ?? 0);
        $relationship['refunds'] = (int)($claims[$key]['refunds'] ?? 0);
        $relationship['return_claims'] = (int)($claims[$key]['return_claims'] ?? 0);
        $relationship['guest_return_claims'] = (int)($claims[$key]['guest_return_claims'] ?? 0);
    }
    unset($relationship);

    return $relationships;
}

/** @return array<int,array<string,mixed>> */
function coveted_venue_relationship_events(
    array $actor,
    int $businessId,
    string $groupRef,
    string $locationRef,
    int $limit = 50
): array {
    $resolved = coveted_venue_relationship_resolve($actor, $businessId, $groupRef, $locationRef);
    $limit = max(1, min(100, $limit));

    $stmt = coveted_db()->prepare(
        "SELECT
            e.public_id,
            e.title,
            e.event_type,
            e.status,
            e.timezone,
            e.starts_at,
            e.ends_at,
            (SELECT COUNT(*)
             FROM event_attendance ea
             WHERE ea.event_id = e.id
               AND ea.status IN ('checked_in','attended','left_early')) AS verified_attendance,
            (SELECT COUNT(*)
             FROM reward_issuances ri
             JOIN reward_templates rt ON rt.id = ri.reward_template_id
             WHERE ri.event_id = e.id
               AND rt.business_id = ?
               AND ri.status <> 'cancelled') AS business_benefits_issued,
            (SELECT COUNT(*)
             FROM reward_claims rc
             JOIN reward_issuances ri ON ri.id = rc.reward_issuance_id
             JOIN reward_templates rt ON rt.id = ri.reward_template_id
             WHERE ri.event_id = e.id
               AND rt.business_id = ?
               AND rc.location_id = ?) AS claims,
            (SELECT COUNT(*)
             FROM reward_claims rc
             JOIN reward_issuances ri ON ri.id = rc.reward_issuance_id
             JOIN reward_templates rt ON rt.id = ri.reward_template_id
             WHERE ri.event_id = e.id
               AND rt.business_id = ?
               AND rc.location_id = ?
               AND rc.status = 'refunded') AS refunds
         FROM events e
         JOIN event_locations el ON el.event_id = e.id
         WHERE e.group_id = ?
           AND el.location_id = ?
           AND e.status IN ('published','closed','completed')
         ORDER BY e.starts_at DESC, e.id DESC
         LIMIT {$limit}"
    );
    $stmt->execute([
        $businessId,
        $businessId,
        (int)$resolved['location_id'],
        $businessId,
        (int)$resolved['location_id'],
        (int)$resolved['group_id'],
        (int)$resolved['location_id'],
    ]);

    return $stmt->fetchAll();
}

function coveted_venue_relationship_update(
    array $actor,
    int $businessId,
    string $groupRef,
    string $locationRef,
    array $data
): bool {
    coveted_business_require_mutable($actor, $businessId);
    $resolved = coveted_venue_relationship_resolve($actor, $businessId, $groupRef, $locationRef);

    $status = strtolower(trim((string)($data['relationship_status'] ?? '')));
    if (!in_array($status, coveted_venue_relationship_statuses(), true)) {
        throw new InvalidArgumentException('Choose a valid venue relationship status.');
    }

    $benefitsEnabled = !empty($data['benefits_enabled']) ? 1 : 0;
    $mysteryEnabled = !empty($data['mystery_events_enabled']) ? 1 : 0;
    $notes = trim((string)($data['notes'] ?? ''));
    if (mb_strlen($notes) > 4000) {
        throw new InvalidArgumentException('Keep relationship notes under 4,000 characters.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $eventTimes = $pdo->prepare(
            "SELECT
                MIN(CASE WHEN e.status = 'completed' THEN e.starts_at END) AS first_event_at,
                MAX(CASE WHEN e.status = 'completed' THEN e.starts_at END) AS last_event_at,
                COUNT(*) AS linked_events,
                SUM(e.status = 'completed') AS completed_events
             FROM events e
             JOIN event_locations el ON el.event_id = e.id
             WHERE e.group_id = ?
               AND el.location_id = ?
               AND e.status IN ('published','closed','completed')"
        );
        $eventTimes->execute([(int)$resolved['group_id'], (int)$resolved['location_id']]);
        $eventState = $eventTimes->fetch();
        if (!$eventState || (int)$eventState['linked_events'] < 1) {
            throw new InvalidArgumentException('A venue relationship requires a real Coveted event connection.');
        }
        if ((int)$eventState['completed_events'] > 0 && $status === 'new') {
            throw new InvalidArgumentException('A venue with completed Coveted history cannot return to New.');
        }

        $existingStmt = $pdo->prepare(
            'SELECT * FROM venue_relationships WHERE group_id = ? AND location_id = ? LIMIT 1 FOR UPDATE'
        );
        $existingStmt->execute([(int)$resolved['group_id'], (int)$resolved['location_id']]);
        $existing = $existingStmt->fetch();

        $effectiveCurrentStatus = $existing
            ? (string)$existing['relationship_status']
            : ((int)$eventState['completed_events'] > 0 ? 'event_venue' : 'new');
        $currentBenefits = $existing ? (int)$existing['benefits_enabled'] : 0;
        $currentMystery = $existing ? (int)$existing['mystery_events_enabled'] : 0;
        $currentNotes = $existing ? trim((string)($existing['notes'] ?? '')) : '';

        $changed = $effectiveCurrentStatus !== $status
            || $currentBenefits !== $benefitsEnabled
            || $currentMystery !== $mysteryEnabled
            || $currentNotes !== $notes;

        $partnerStatuses = ['partner', 'preferred_partner', 'home_venue'];
        $partnerSince = $existing ? ($existing['partner_since'] ?? null) : null;
        if ($partnerSince === null && in_array($status, $partnerStatuses, true)) {
            $partnerSince = gmdate('Y-m-d H:i:s');
        }

        if ($existing) {
            $pdo->prepare(
                "UPDATE venue_relationships
                 SET relationship_status = ?,
                     partner_since = ?,
                     benefits_enabled = ?,
                     mystery_events_enabled = ?,
                     first_event_at = ?,
                     last_event_at = ?,
                     notes = ?
                 WHERE id = ?"
            )->execute([
                $status,
                $partnerSince,
                $benefitsEnabled,
                $mysteryEnabled,
                $eventState['first_event_at'],
                $eventState['last_event_at'],
                $notes !== '' ? $notes : null,
                (int)$existing['id'],
            ]);
        } elseif ($changed) {
            $pdo->prepare(
                "INSERT INTO venue_relationships
                    (group_id, location_id, relationship_status, partner_since,
                     benefits_enabled, mystery_events_enabled, first_event_at, last_event_at, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                (int)$resolved['group_id'],
                (int)$resolved['location_id'],
                $status,
                $partnerSince,
                $benefitsEnabled,
                $mysteryEnabled,
                $eventState['first_event_at'],
                $eventState['last_event_at'],
                $notes !== '' ? $notes : null,
            ]);
        }

        if ($changed) {
            coveted_audit(
                'venue_relationship.updated',
                'venue_relationship',
                (string)$resolved['group_public_id'] . ':' . (string)$resolved['location_public_id'],
                [
                    'business_id' => $businessId,
                    'group_id' => (int)$resolved['group_id'],
                    'location_id' => (int)$resolved['location_id'],
                    'previous_status' => $effectiveCurrentStatus,
                    'relationship_status' => $status,
                    'benefits_enabled' => (bool)$benefitsEnabled,
                    'mystery_events_enabled' => (bool)$mysteryEnabled,
                ],
                (int)$actor['id']
            );
        }

        $pdo->commit();
        return $changed;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
