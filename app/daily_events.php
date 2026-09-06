<?php
declare(strict_types=1);

require_once __DIR__ . '/events.php';
require_once __DIR__ . '/businesses.php';
require_once __DIR__ . '/rewards.php';

const COVETED_DAILY_EVENT_LOCK = 'coveted:daily-events:v1';
const COVETED_DAILY_EVENT_CHECKIN_EARLY_MINUTES = 60;
const COVETED_DAILY_EVENT_CHECKIN_LATE_MINUTES = 120;

/** @return array<int,array<string,mixed>> */
function coveted_daily_event_relationship_options(): array
{
    return coveted_db()->query(
        "SELECT vr.id AS relationship_id, vr.relationship_status, vr.benefits_enabled,
                g.id AS group_id, g.public_id AS group_ref, g.name AS group_name, g.city AS group_city,
                b.id AS business_id, b.public_id AS business_ref, b.name AS business_name,
                l.id AS location_id, l.public_id AS location_ref, l.name AS location_name,
                l.city AS location_city, l.region AS location_region, l.timezone, l.capacity
         FROM venue_relationships vr
         JOIN social_groups g ON g.id=vr.group_id AND g.status='active'
         JOIN locations l ON l.id=vr.location_id AND l.status='active'
         JOIN businesses b ON b.id=l.business_id AND b.status='active'
         WHERE vr.benefits_enabled=1
           AND vr.relationship_status IN ('event_venue','partner','preferred_partner','home_venue')
         ORDER BY g.name,b.name,l.name,vr.id"
    )->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function coveted_daily_event_reward_campaign_options(): array
{
    return coveted_db()->query(
        "SELECT c.id AS campaign_id, c.public_id AS campaign_ref, c.title AS campaign_title,
                c.business_id, c.location_id, c.quantity_limit, c.per_user_limit,
                c.starts_at, c.ends_at,
                rt.public_id AS reward_ref, rt.title AS reward_title, rt.reward_type,
                rt.value_amount, rt.value_text, rt.claim_mode, rt.expires_at,
                b.name AS business_name, l.name AS location_name
         FROM campaigns c
         JOIN reward_templates rt ON rt.id=c.reward_template_id
         JOIN businesses b ON b.id=c.business_id AND b.status='active'
         JOIN locations l ON l.id=c.location_id AND l.status='active'
         WHERE c.owner_type='business'
           AND c.status='active'
           AND c.trigger_key='manual'
           AND rt.owner_type='business'
           AND rt.business_id=c.business_id
           AND rt.status='active'
           AND rt.claim_mode='location_code'
           AND NOT EXISTS (SELECT 1 FROM campaign_event_links cel WHERE cel.campaign_id=c.id)
         ORDER BY b.name,l.name,c.title,c.id"
    )->fetchAll();
}

function coveted_daily_event_parse_local_datetime(string $value, DateTimeZone $timezone, string $label): DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException($label . ' is required.');
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if (
        !$date
        || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
        || $date->format('Y-m-d\\TH:i') !== $value
    ) {
        throw new InvalidArgumentException('Enter a valid ' . strtolower($label) . '.');
    }

    return $date->setTimezone(new DateTimeZone('UTC'));
}

/** @return array<string,mixed> */
function coveted_daily_event_relationship_locked(PDO $pdo, int $relationshipId): array
{
    $stmt = $pdo->prepare(
        "SELECT vr.id AS relationship_id, vr.relationship_status, vr.benefits_enabled,
                g.id AS group_id, g.public_id AS group_ref, g.name AS group_name, g.status AS group_status,
                b.id AS business_id, b.public_id AS business_ref, b.name AS business_name, b.status AS business_status,
                l.id AS location_id, l.public_id AS location_ref, l.name AS location_name,
                l.city AS location_city, l.region AS location_region, l.timezone, l.capacity AS location_capacity,
                l.status AS location_status
         FROM venue_relationships vr
         JOIN social_groups g ON g.id=vr.group_id
         JOIN locations l ON l.id=vr.location_id
         JOIN businesses b ON b.id=l.business_id
         WHERE vr.id=?
         LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([$relationshipId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new InvalidArgumentException('Partner relationship not found.');
    }
    if (
        $row['group_status'] !== 'active'
        || $row['business_status'] !== 'active'
        || $row['location_status'] !== 'active'
        || (int)$row['benefits_enabled'] !== 1
        || !in_array((string)$row['relationship_status'], ['event_venue','partner','preferred_partner','home_venue'], true)
    ) {
        throw new InvalidArgumentException('Daily Events require an active benefit-enabled group and partner location relationship.');
    }

    $codes = $pdo->prepare(
        "SELECT COUNT(*)
         FROM business_claim_codes
         WHERE business_id=? AND status='active'
           AND (location_id=? OR (code_type='employee' AND location_id IS NULL))"
    );
    $codes->execute([(int)$row['business_id'], (int)$row['location_id']]);
    if ((int)$codes->fetchColumn() < 1) {
        throw new InvalidArgumentException('This partner location needs an active location or employee claim code before it can host a Daily Event.');
    }

    return $row;
}

/** @return array<string,mixed> */
function coveted_daily_event_campaign_locked(
    PDO $pdo,
    int $campaignId,
    int $businessId,
    int $locationId,
    int $capacity,
    DateTimeImmutable $eventStarts,
    DateTimeImmutable $eventEnds
): array {
    $stmt = $pdo->prepare(
        "SELECT c.*, rt.status AS reward_status, rt.owner_type AS reward_owner_type,
                rt.business_id AS reward_business_id, rt.claim_mode, rt.starts_at AS reward_starts_at,
                rt.expires_at AS reward_expires_at, rt.title AS reward_title
         FROM campaigns c
         JOIN reward_templates rt ON rt.id=c.reward_template_id
         WHERE c.id=?
         LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([$campaignId]);
    $campaign = $stmt->fetch();
    if (!$campaign) {
        throw new InvalidArgumentException('Reward campaign not found.');
    }
    if (
        $campaign['owner_type'] !== 'business'
        || (int)$campaign['business_id'] !== $businessId
        || (int)$campaign['location_id'] !== $locationId
        || $campaign['trigger_key'] !== 'manual'
        || $campaign['status'] !== 'active'
        || $campaign['reward_status'] !== 'active'
        || $campaign['reward_owner_type'] !== 'business'
        || (int)$campaign['reward_business_id'] !== $businessId
        || $campaign['claim_mode'] !== 'location_code'
    ) {
        throw new InvalidArgumentException('Daily Event group rewards must use an active, dedicated Business location-code campaign with a manual trigger at the same partner location.');
    }

    $linked = $pdo->prepare('SELECT 1 FROM campaign_event_links WHERE campaign_id=? LIMIT 1 FOR UPDATE');
    $linked->execute([$campaignId]);
    if ($linked->fetchColumn()) {
        throw new InvalidArgumentException('Choose a reward campaign that is not already linked to another event.');
    }

    if ($campaign['quantity_limit'] !== null && (int)$campaign['quantity_limit'] < $capacity) {
        throw new InvalidArgumentException('The group reward pool must cover the Daily Event capacity so every verified attendee can receive the unlocked reward.');
    }
    if ($campaign['per_user_limit'] !== null && (int)$campaign['per_user_limit'] < 1) {
        throw new InvalidArgumentException('The reward campaign must allow at least one issuance per member.');
    }

    $eventStartTs = $eventStarts->getTimestamp();
    $eventEndTs = $eventEnds->getTimestamp();
    if (!empty($campaign['starts_at']) && strtotime((string)$campaign['starts_at']) > $eventStartTs) {
        throw new InvalidArgumentException('The reward campaign must be active by the Daily Event start time.');
    }
    if (!empty($campaign['ends_at']) && strtotime((string)$campaign['ends_at']) <= $eventEndTs + (COVETED_DAILY_EVENT_CHECKIN_LATE_MINUTES * 60)) {
        throw new InvalidArgumentException('The reward campaign must remain active through the Daily Event check-in window.');
    }
    if (!empty($campaign['reward_starts_at']) && strtotime((string)$campaign['reward_starts_at']) > $eventStartTs) {
        throw new InvalidArgumentException('The reward itself must be active by the Daily Event start time.');
    }
    if (!empty($campaign['reward_expires_at']) && strtotime((string)$campaign['reward_expires_at']) <= $eventEndTs) {
        throw new InvalidArgumentException('The reward cannot expire before the Daily Event ends.');
    }

    return $campaign;
}

/** @return array{id:int,public_id:string,event_id:int,event_ref:string} */
function coveted_daily_event_create(array $actor, array $data): array
{
    coveted_event_require_system_admin($actor);

    $relationshipId = (int)($data['relationship_id'] ?? 0);
    $campaignId = (int)($data['reward_campaign_id'] ?? 0);
    $threshold = (int)($data['attendance_threshold'] ?? 0);
    $requestedCapacity = ($data['capacity'] ?? '') === '' ? null : (int)$data['capacity'];
    $title = trim((string)($data['title'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $status = strtolower(trim((string)($data['status'] ?? 'draft')));

    if ($relationshipId < 1 || $campaignId < 1) {
        throw new InvalidArgumentException('Choose a partner relationship and reward campaign.');
    }
    if ($title === '' || mb_strlen($title) > 190) {
        throw new InvalidArgumentException('Enter a Daily Event title.');
    }
    if (mb_strlen($description) > 5000) {
        throw new InvalidArgumentException('Daily Event description is too long.');
    }
    if (!in_array($status, ['draft','published'], true)) {
        throw new InvalidArgumentException('New Daily Events must start as draft or published.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $relationship = coveted_daily_event_relationship_locked($pdo, $relationshipId);
        $timezone = coveted_require_timezone((string)$relationship['timezone']);
        $startsAt = coveted_daily_event_parse_local_datetime((string)($data['starts_local'] ?? ''), $timezone, 'Start time');
        $endsAt = coveted_daily_event_parse_local_datetime((string)($data['ends_local'] ?? ''), $timezone, 'End time');
        if ($endsAt <= $startsAt) {
            throw new InvalidArgumentException('Daily Event end time must be after its start time.');
        }
        if ($status === 'published' && $startsAt->getTimestamp() <= time()) {
            throw new InvalidArgumentException('Published Daily Events must start in the future.');
        }

        $capacity = $requestedCapacity ?? ($relationship['location_capacity'] !== null ? (int)$relationship['location_capacity'] : 0);
        if ($capacity < 1) {
            throw new InvalidArgumentException('Daily Events require a capacity. Enter one or set the partner location capacity first.');
        }
        if ($threshold < 1 || $threshold > $capacity) {
            throw new InvalidArgumentException('Group attendance threshold must be between 1 and the Daily Event capacity.');
        }

        $campaign = coveted_daily_event_campaign_locked(
            $pdo,
            $campaignId,
            (int)$relationship['business_id'],
            (int)$relationship['location_id'],
            $capacity,
            $startsAt,
            $endsAt
        );

        $eventRef = coveted_uuid('evt');
        $pdo->prepare(
            "INSERT INTO events
                (public_id,group_id,title,description,event_type,audience,timezone,starts_at,ends_at,
                 capacity,plus_one_allowed,location_visibility,status,created_by)
             VALUES (?, ?, ?, ?, 'regular', 'group', ?, ?, ?, ?, 0, 'immediate', ?, ?)"
        )->execute([
            $eventRef,
            (int)$relationship['group_id'],
            $title,
            $description !== '' ? $description : null,
            $timezone->getName(),
            $startsAt->format('Y-m-d H:i:s'),
            $endsAt->format('Y-m-d H:i:s'),
            $capacity,
            $status,
            (int)$actor['id'],
        ]);
        $eventId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO event_locations (event_id,location_id,private_location_label,reveal_notes) VALUES (?, ?, NULL, NULL)'
        )->execute([$eventId, (int)$relationship['location_id']]);
        $pdo->prepare('INSERT INTO campaign_event_links (campaign_id,event_id) VALUES (?, ?)')
            ->execute([$campaignId, $eventId]);

        $publicId = coveted_uuid('daily');
        $pdo->prepare(
            "INSERT INTO daily_event_opportunities
                (public_id,event_id,business_id,location_id,reward_campaign_id,attendance_threshold,status,created_by)
             VALUES (?, ?, ?, ?, ?, ?, 'active', ?)"
        )->execute([
            $publicId,
            $eventId,
            (int)$relationship['business_id'],
            (int)$relationship['location_id'],
            $campaignId,
            $threshold,
            (int)$actor['id'],
        ]);
        $dailyId = (int)$pdo->lastInsertId();

        coveted_audit(
            'event.created',
            'event',
            $eventRef,
            [
                'group_id' => (int)$relationship['group_id'],
                'status' => $status,
                'daily_event_opportunity_id' => $publicId,
                'business_id' => (string)$relationship['business_ref'],
                'location_id' => (string)$relationship['location_ref'],
            ],
            (int)$actor['id']
        );
        coveted_audit(
            'daily_event.created',
            'daily_event_opportunity',
            $publicId,
            [
                'event_id' => $eventRef,
                'group_id' => (string)$relationship['group_ref'],
                'business_id' => (string)$relationship['business_ref'],
                'location_id' => (string)$relationship['location_ref'],
                'reward_campaign_id' => (string)$campaign['public_id'],
                'attendance_threshold' => $threshold,
                'capacity' => $capacity,
            ],
            (int)$actor['id']
        );

        $pdo->commit();
        return ['id' => $dailyId, 'public_id' => $publicId, 'event_id' => $eventId, 'event_ref' => $eventRef];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_daily_event_set_status(array $actor, string $dailyRef, string $status): void
{
    coveted_event_require_system_admin($actor);
    $status = strtolower(trim($status));
    if (!in_array($status, ['active','paused','archived'], true)) {
        throw new InvalidArgumentException('Invalid Daily Event opportunity status.');
    }
    $dailyRef = trim($dailyRef);
    if ($dailyRef === '' || strlen($dailyRef) > 64) {
        throw new InvalidArgumentException('Daily Event not found.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT id,public_id,status FROM daily_event_opportunities WHERE public_id=? OR CAST(id AS CHAR)=? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$dailyRef, $dailyRef]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Daily Event not found.');
        }
        if ($row['status'] === 'archived' && $status !== 'archived') {
            throw new InvalidArgumentException('Archived Daily Events cannot be reopened.');
        }
        $pdo->prepare('UPDATE daily_event_opportunities SET status=?,updated_at=NOW() WHERE id=?')
            ->execute([$status, (int)$row['id']]);
        coveted_audit('daily_event.status_changed', 'daily_event_opportunity', (string)$row['public_id'], ['status' => $status], (int)$actor['id']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** @return array<int,array<string,mixed>> */
function coveted_daily_event_admin_rows(): array
{
    return coveted_db()->query(
        "SELECT deo.*, e.public_id AS event_ref,e.title,e.status AS event_status,e.starts_at,e.ends_at,e.capacity,e.timezone,
                g.public_id AS group_ref,g.name AS group_name,
                b.public_id AS business_ref,b.name AS business_name,
                l.public_id AS location_ref,l.name AS location_name,l.city AS location_city,l.region AS location_region,
                c.public_id AS campaign_ref,c.title AS campaign_title,
                rt.public_id AS reward_ref,rt.title AS reward_title,rt.value_text,rt.value_amount,
                (SELECT COUNT(*) FROM event_rsvps er WHERE er.event_id=e.id AND er.response='attending') AS attending_rsvps,
                (SELECT COUNT(*) FROM event_attendance ea WHERE ea.event_id=e.id AND ea.status IN ('checked_in','attended','left_early')) AS verified_attendance,
                (SELECT COUNT(*) FROM reward_issuances ri WHERE ri.campaign_id=c.id AND ri.event_id=e.id AND ri.status<>'cancelled') AS rewards_issued
         FROM daily_event_opportunities deo
         JOIN events e ON e.id=deo.event_id
         JOIN social_groups g ON g.id=e.group_id
         JOIN businesses b ON b.id=deo.business_id
         JOIN locations l ON l.id=deo.location_id
         JOIN campaigns c ON c.id=deo.reward_campaign_id
         JOIN reward_templates rt ON rt.id=c.reward_template_id
         ORDER BY e.starts_at DESC,deo.id DESC"
    )->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function coveted_daily_event_member_feed(array $member, int $daysAhead = 14): array
{
    $daysAhead = max(1, min($daysAhead, 60));
    $stmt = coveted_db()->prepare(
        "SELECT deo.public_id,deo.attendance_threshold,deo.reward_unlocked_at,deo.attendance_count_at_unlock,
                e.id AS event_id,e.public_id AS event_ref,e.title,e.description,e.status AS event_status,e.starts_at,e.ends_at,
                e.capacity,e.timezone,
                g.public_id AS group_ref,g.name AS group_name,
                b.public_id AS business_ref,b.name AS business_name,
                l.id AS location_id,l.public_id AS location_ref,l.name AS location_name,
                l.address1,l.address2,l.city,l.region,l.postal_code,
                c.public_id AS campaign_ref,rt.public_id AS reward_ref,rt.title AS reward_title,
                rt.reward_type,rt.value_text,rt.value_amount,
                er.response AS rsvp_response,ea.status AS attendance_status,ea.checked_in_at,
                EXISTS(
                    SELECT 1 FROM reward_issuances ri
                    WHERE ri.campaign_id=deo.reward_campaign_id AND ri.event_id=e.id
                      AND ri.user_id=? AND ri.status<>'cancelled'
                ) AS member_reward_issued,
                (SELECT COUNT(*) FROM event_rsvps x WHERE x.event_id=e.id AND x.response='attending') AS attending_rsvps,
                (SELECT COUNT(*) FROM event_attendance x WHERE x.event_id=e.id AND x.status IN ('checked_in','attended','left_early')) AS verified_attendance
         FROM daily_event_opportunities deo
         JOIN events e ON e.id=deo.event_id
         JOIN social_groups g ON g.id=e.group_id AND g.status='active'
         JOIN group_memberships gm ON gm.group_id=e.group_id AND gm.user_id=? AND gm.membership_status='active'
         JOIN businesses b ON b.id=deo.business_id AND b.status='active'
         JOIN locations l ON l.id=deo.location_id AND l.status='active'
         JOIN campaigns c ON c.id=deo.reward_campaign_id
         JOIN reward_templates rt ON rt.id=c.reward_template_id
         LEFT JOIN event_rsvps er ON er.event_id=e.id AND er.user_id=?
         LEFT JOIN event_attendance ea ON ea.event_id=e.id AND ea.user_id=?
         WHERE deo.status='active'
           AND e.status IN ('published','closed','completed')
           AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)
           AND e.starts_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$daysAhead} DAY)
         ORDER BY e.starts_at ASC,e.id ASC"
    );
    $userId = (int)$member['id'];
    $stmt->execute([$userId,$userId,$userId,$userId]);
    $rows = $stmt->fetchAll();

    $now = time();
    foreach ($rows as &$row) {
        $start = strtotime((string)$row['starts_at']);
        $end = !empty($row['ends_at']) ? strtotime((string)$row['ends_at']) : $start + 6 * 3600;
        $row['checkin_open'] = $row['event_status'] !== 'completed'
            && ($row['rsvp_response'] ?? '') === 'attending'
            && $now >= $start - COVETED_DAILY_EVENT_CHECKIN_EARLY_MINUTES * 60
            && $now <= $end + COVETED_DAILY_EVENT_CHECKIN_LATE_MINUTES * 60
            && !in_array((string)($row['attendance_status'] ?? ''), ['checked_in','attended','left_early'], true);
        $row['reward_unlocked'] = !empty($row['reward_unlocked_at'])
            || (int)$row['verified_attendance'] >= (int)$row['attendance_threshold'];
        $row['threshold_remaining'] = max(0, (int)$row['attendance_threshold'] - (int)$row['verified_attendance']);
    }
    unset($row);
    return $rows;
}

/** @return array<int,array{key:string,limit:int}> */
function coveted_daily_event_checkin_attempt_entries(int $userId, int $eventId): array
{
    return [
        ['key' => hash('sha256', 'daily-checkin-member-event|' . $userId . '|' . $eventId), 'limit' => 8],
        ['key' => hash('sha256', 'daily-checkin-member-ip|' . $userId . '|' . coveted_client_ip()), 'limit' => 40],
    ];
}

function coveted_daily_event_assert_checkin_allowed(int $userId, int $eventId): void
{
    $entries = coveted_daily_event_checkin_attempt_entries($userId, $eventId);
    $keys = array_column($entries, 'key');
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = coveted_db()->prepare("SELECT blocked_until FROM claim_attempts WHERE attempt_key IN ({$placeholders})");
    $stmt->execute($keys);
    foreach ($stmt->fetchAll() as $row) {
        if (!empty($row['blocked_until']) && strtotime((string)$row['blocked_until']) > time()) {
            throw new InvalidArgumentException('Too many incorrect check-in code attempts. Try again later.');
        }
    }
}

function coveted_daily_event_record_checkin_failure(int $userId, int $eventId): void
{
    $entries = coveted_daily_event_checkin_attempt_entries($userId, $eventId);
    usort($entries, static fn(array $a,array $b): int => strcmp($a['key'],$b['key']));
    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        foreach ($entries as $entry) {
            $pdo->prepare(
                'INSERT INTO claim_attempts (attempt_key,failures,window_started_at,updated_at) VALUES (?,0,NOW(),NOW()) ON DUPLICATE KEY UPDATE updated_at=updated_at'
            )->execute([$entry['key']]);
            $stmt = $pdo->prepare('SELECT id,failures,window_started_at FROM claim_attempts WHERE attempt_key=? LIMIT 1 FOR UPDATE');
            $stmt->execute([$entry['key']]);
            $row = $stmt->fetch();
            if (!$row) throw new RuntimeException('Unable to update Daily Event check-in throttle.');
            $fresh = strtotime((string)$row['window_started_at']) >= time() - 900;
            $failures = ($fresh ? (int)$row['failures'] : 0) + 1;
            $blockedUntil = $failures >= (int)$entry['limit'] ? date('Y-m-d H:i:s', time()+900) : null;
            $windowStartedAt = $fresh ? (string)$row['window_started_at'] : date('Y-m-d H:i:s');
            $pdo->prepare('UPDATE claim_attempts SET failures=?,window_started_at=?,blocked_until=?,updated_at=NOW() WHERE id=?')
                ->execute([$failures,$windowStartedAt,$blockedUntil,(int)$row['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function coveted_daily_event_clear_checkin_attempts(int $userId, int $eventId): void
{
    $keys = array_column(coveted_daily_event_checkin_attempt_entries($userId,$eventId), 'key');
    $placeholders = implode(',', array_fill(0,count($keys),'?'));
    coveted_db()->prepare("DELETE FROM claim_attempts WHERE attempt_key IN ({$placeholders})")->execute($keys);
}

/** @return array<string,mixed> */
function coveted_daily_event_member_checkin(array $member, string $dailyRef, string $claimCode): array
{
    $dailyRef = trim($dailyRef);
    if ($dailyRef === '' || strlen($dailyRef) > 64) {
        throw new InvalidArgumentException('Daily Event not found.');
    }
    $claimCode = coveted_claim_code_validate($claimCode);
    $userId = (int)$member['id'];
    $pdo = coveted_db();

    $lookup = $pdo->prepare(
        "SELECT deo.id AS daily_id,deo.public_id,deo.status AS daily_status,deo.attendance_threshold,
                e.id AS event_id,e.public_id AS event_ref,e.group_id,e.status AS event_status,e.starts_at,e.ends_at,
                b.id AS business_id,b.status AS business_status,
                l.id AS location_id,l.business_id AS location_business_id,l.status AS location_status
         FROM daily_event_opportunities deo
         JOIN events e ON e.id=deo.event_id
         JOIN businesses b ON b.id=deo.business_id
         JOIN locations l ON l.id=deo.location_id
         WHERE deo.public_id=? OR CAST(deo.id AS CHAR)=?
         LIMIT 1"
    );
    $lookup->execute([$dailyRef,$dailyRef]);
    $preview = $lookup->fetch();
    if (!$preview) throw new InvalidArgumentException('Daily Event not found.');

    coveted_daily_event_assert_checkin_allowed($userId, (int)$preview['event_id']);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT deo.id AS daily_id,deo.public_id,deo.status AS daily_status,deo.attendance_threshold,
                    e.id AS event_id,e.public_id AS event_ref,e.group_id,e.status AS event_status,e.starts_at,e.ends_at,
                    b.id AS business_id,b.status AS business_status,
                    l.id AS location_id,l.business_id AS location_business_id,l.status AS location_status
             FROM daily_event_opportunities deo
             JOIN events e ON e.id=deo.event_id
             JOIN businesses b ON b.id=deo.business_id
             JOIN locations l ON l.id=deo.location_id
             WHERE deo.id=? LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([(int)$preview['daily_id']]);
        $daily = $stmt->fetch();
        if (!$daily || $daily['daily_status'] !== 'active') {
            throw new InvalidArgumentException('This Daily Event is not accepting check-ins.');
        }
        if (!in_array((string)$daily['event_status'], ['published','closed'], true)) {
            throw new InvalidArgumentException('This Daily Event is not accepting check-ins.');
        }
        if ($daily['business_status'] !== 'active' || $daily['location_status'] !== 'active' || (int)$daily['location_business_id'] !== (int)$daily['business_id']) {
            throw new InvalidArgumentException('This partner location is not available for check-in.');
        }

        $memberEligible = $pdo->prepare(
            "SELECT 1 FROM users u
             JOIN group_memberships gm ON gm.user_id=u.id
             WHERE u.id=? AND u.status='active' AND gm.group_id=? AND gm.membership_status='active' LIMIT 1"
        );
        $memberEligible->execute([$userId,(int)$daily['group_id']]);
        if (!$memberEligible->fetchColumn()) {
            throw new InvalidArgumentException('Your group membership is not eligible for this Daily Event.');
        }
        $rsvp = $pdo->prepare("SELECT response FROM event_rsvps WHERE event_id=? AND user_id=? LIMIT 1 FOR UPDATE");
        $rsvp->execute([(int)$daily['event_id'],$userId]);
        if ($rsvp->fetchColumn() !== 'attending') {
            throw new InvalidArgumentException('RSVP as attending before checking in.');
        }

        $existing = $pdo->prepare('SELECT status,checked_in_at FROM event_attendance WHERE event_id=? AND user_id=? LIMIT 1 FOR UPDATE');
        $existing->execute([(int)$daily['event_id'],$userId]);
        $existingRow = $existing->fetch();
        if ($existingRow && in_array((string)$existingRow['status'], ['checked_in','attended','left_early'], true)) {
            $count = $pdo->prepare("SELECT COUNT(*) FROM event_attendance WHERE event_id=? AND status IN ('checked_in','attended','left_early')");
            $count->execute([(int)$daily['event_id']]);
            $attendanceCount = (int)$count->fetchColumn();
            $pdo->commit();
            coveted_daily_event_clear_checkin_attempts($userId,(int)$daily['event_id']);
            return [
                'already_checked_in' => true,
                'verified_attendance' => $attendanceCount,
                'attendance_threshold' => (int)$daily['attendance_threshold'],
                'reward_unlocked' => $attendanceCount >= (int)$daily['attendance_threshold'],
            ];
        }

        $start = strtotime((string)$daily['starts_at']);
        $end = !empty($daily['ends_at']) ? strtotime((string)$daily['ends_at']) : $start + 6*3600;
        $now = time();
        if ($now < $start - COVETED_DAILY_EVENT_CHECKIN_EARLY_MINUTES*60 || $now > $end + COVETED_DAILY_EVENT_CHECKIN_LATE_MINUTES*60) {
            throw new InvalidArgumentException('Daily Event check-in is not open right now.');
        }

        $location = [
            'id' => (int)$daily['location_id'],
            'business_id' => (int)$daily['business_id'],
        ];
        $verifiedCode = coveted_claim_code_verify_for_location($pdo,$location,$claimCode);
        if (!$verifiedCode) {
            $pdo->rollBack();
            coveted_daily_event_record_checkin_failure($userId,(int)$daily['event_id']);
            throw new InvalidArgumentException('That partner location check-in code is not valid.');
        }

        $pdo->prepare(
            "INSERT INTO event_attendance (event_id,user_id,status,checked_in_at,verified_by)
             VALUES (?,?,'checked_in',NOW(),NULL)
             ON DUPLICATE KEY UPDATE status='checked_in',checked_in_at=COALESCE(checked_in_at,NOW()),verified_by=NULL,updated_at=NOW()"
        )->execute([(int)$daily['event_id'],$userId]);

        $count = $pdo->prepare("SELECT COUNT(*) FROM event_attendance WHERE event_id=? AND status IN ('checked_in','attended','left_early')");
        $count->execute([(int)$daily['event_id']]);
        $attendanceCount = (int)$count->fetchColumn();

        coveted_audit(
            'daily_event.member_checked_in',
            'daily_event_opportunity',
            (string)$daily['public_id'],
            [
                'event_id' => (string)$daily['event_ref'],
                'location_id' => (int)$daily['location_id'],
                'claim_code_id' => (string)$verifiedCode['public_id'],
                'verification_method' => 'partner_location_code',
                'attendance_count' => $attendanceCount,
                'threshold' => (int)$daily['attendance_threshold'],
            ],
            $userId
        );
        $pdo->commit();
        coveted_daily_event_clear_checkin_attempts($userId,(int)$daily['event_id']);

        try {
            coveted_daily_event_reconcile(100, (int)$daily['event_id']);
        } catch (Throwable $e) {
            error_log('Immediate Daily Event reward reconciliation failed after valid check-in: ' . $e->getMessage());
        }

        return [
            'already_checked_in' => false,
            'verified_attendance' => $attendanceCount,
            'attendance_threshold' => (int)$daily['attendance_threshold'],
            'reward_unlocked' => $attendanceCount >= (int)$daily['attendance_threshold'],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** @return array{rows:array<int,array<string,mixed>>,more:bool} */
function coveted_daily_event_reward_targets(int $limit, ?int $eventId = null): array
{
    $limit = max(1,min($limit,1000));
    $sql = "SELECT deo.id AS daily_id,deo.public_id AS daily_ref,deo.attendance_threshold,
                   deo.reward_campaign_id,e.id AS event_id,e.public_id AS event_ref,ea.user_id,
                   (SELECT COUNT(*) FROM event_attendance x
                    WHERE x.event_id=e.id AND x.status IN ('checked_in','attended','left_early')) AS attendance_count,
                   CONCAT('daily-group-reward:',deo.id,':',ea.user_id) AS issuance_key
            FROM daily_event_opportunities deo
            JOIN events e ON e.id=deo.event_id
            JOIN campaigns c ON c.id=deo.reward_campaign_id
            JOIN reward_templates rt ON rt.id=c.reward_template_id
            JOIN event_attendance ea ON ea.event_id=e.id AND ea.status IN ('checked_in','attended','left_early')
            JOIN users u ON u.id=ea.user_id AND u.status='active'
            WHERE deo.status='active'
              AND e.status IN ('published','closed','completed')
              AND e.starts_at<=UTC_TIMESTAMP()
              AND c.status='active' AND c.trigger_key='manual'
              AND rt.status='active'
              AND (SELECT COUNT(*) FROM event_attendance x
                   WHERE x.event_id=e.id AND x.status IN ('checked_in','attended','left_early')) >= deo.attendance_threshold
              AND NOT EXISTS (
                  SELECT 1 FROM reward_issuances ri
                  WHERE ri.idempotency_key=CONCAT('daily-group-reward:',deo.id,':',ea.user_id)
                    AND ri.status<>'cancelled'
              )";
    $params = [];
    if ($eventId !== null && $eventId > 0) {
        $sql .= ' AND e.id=?';
        $params[] = $eventId;
    }
    $sql .= ' ORDER BY e.id,deo.id,ea.user_id LIMIT ' . ($limit+1);
    $stmt = coveted_db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $more = count($rows) > $limit;
    return ['rows' => array_slice($rows,0,$limit), 'more' => $more];
}

function coveted_daily_event_mark_unlocked(int $dailyId, int $attendanceCount): bool
{
    $stmt = coveted_db()->prepare(
        'UPDATE daily_event_opportunities SET reward_unlocked_at=COALESCE(reward_unlocked_at,NOW()), attendance_count_at_unlock=COALESCE(attendance_count_at_unlock,?), updated_at=NOW() WHERE id=? AND reward_unlocked_at IS NULL'
    );
    $stmt->execute([$attendanceCount,$dailyId]);
    return $stmt->rowCount() === 1;
}

/** @return array<string,int|bool> */
function coveted_daily_event_reconcile(int $limit = 250, ?int $eventId = null): array
{
    $limit = max(1,min($limit,1000));
    $summary = [
        'thresholds_unlocked' => 0,
        'rewards_issued' => 0,
        'reward_limit_skips' => 0,
        'failures' => 0,
        'more_work_possible' => false,
        'skipped_locked' => false,
    ];
    $pdo = coveted_db();
    $lock = $pdo->prepare('SELECT GET_LOCK(?,0)');
    $lock->execute([COVETED_DAILY_EVENT_LOCK]);
    if ((int)$lock->fetchColumn() !== 1) {
        $summary['skipped_locked'] = true;
        return $summary;
    }

    try {
        $targets = coveted_daily_event_reward_targets($limit,$eventId);
        $summary['more_work_possible'] = (bool)$targets['more'];
        $seenUnlocks = [];
        foreach ($targets['rows'] as $row) {
            $dailyId = (int)$row['daily_id'];
            if (!isset($seenUnlocks[$dailyId])) {
                $seenUnlocks[$dailyId] = true;
                if (coveted_daily_event_mark_unlocked($dailyId,(int)$row['attendance_count'])) {
                    $summary['thresholds_unlocked']++;
                    coveted_audit(
                        'daily_event.group_reward_unlocked',
                        'daily_event_opportunity',
                        (string)$row['daily_ref'],
                        [
                            'event_id' => (string)$row['event_ref'],
                            'attendance_count' => (int)$row['attendance_count'],
                            'threshold' => (int)$row['attendance_threshold'],
                        ],
                        null
                    );
                }
            }

            try {
                coveted_reward_issue(
                    (int)$row['reward_campaign_id'],
                    (int)$row['user_id'],
                    (int)$row['event_id'],
                    [
                        'source' => 'daily_event_group_attendance',
                        'daily_event_opportunity_id' => (string)$row['daily_ref'],
                        'attendance_threshold' => (int)$row['attendance_threshold'],
                        'verified_attendance_at_issue' => (int)$row['attendance_count'],
                    ],
                    (string)$row['issuance_key']
                );
                $summary['rewards_issued']++;
            } catch (InvalidArgumentException $e) {
                if (in_array($e->getMessage(), ['Campaign distribution limit has been reached.','Member campaign limit has been reached.'], true)) {
                    $summary['reward_limit_skips']++;
                    continue;
                }
                $summary['failures']++;
                error_log('Daily Event group reward issuance failed: ' . $e->getMessage());
            } catch (Throwable $e) {
                $summary['failures']++;
                error_log('Daily Event group reward issuance failed: ' . $e->getMessage());
            }
        }
        return $summary;
    } finally {
        try {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([COVETED_DAILY_EVENT_LOCK]);
        } catch (Throwable $e) {
            error_log('Daily Event reward lock release failed: ' . $e->getMessage());
        }
    }
}

/** @return array<int,array<string,mixed>> */
function coveted_daily_event_business_rows(array $actor, int $businessId): array
{
    if (!coveted_business_actor_can_view($actor,$businessId)) {
        throw new InvalidArgumentException('You cannot view Daily Events for that business.');
    }
    $stmt = coveted_db()->prepare(
        "SELECT deo.public_id,deo.attendance_threshold,deo.reward_unlocked_at,deo.status,
                e.public_id AS event_ref,e.title,e.status AS event_status,e.starts_at,e.ends_at,e.capacity,e.timezone,
                g.name AS group_name,l.public_id AS location_ref,l.name AS location_name,l.city,l.region,
                c.public_id AS campaign_ref,c.title AS campaign_title,rt.title AS reward_title,rt.value_text,rt.value_amount,
                (SELECT COUNT(*) FROM event_rsvps er WHERE er.event_id=e.id AND er.response='attending') AS attending_rsvps,
                (SELECT COUNT(*) FROM event_attendance ea WHERE ea.event_id=e.id AND ea.status IN ('checked_in','attended','left_early')) AS verified_attendance,
                (SELECT COUNT(*) FROM reward_issuances ri WHERE ri.campaign_id=c.id AND ri.event_id=e.id AND ri.status<>'cancelled') AS rewards_issued,
                (SELECT COUNT(*) FROM business_claim_codes cc
                 WHERE cc.business_id=deo.business_id AND cc.status='active'
                   AND (cc.location_id=deo.location_id OR (cc.code_type='employee' AND cc.location_id IS NULL))) AS active_checkin_codes
         FROM daily_event_opportunities deo
         JOIN events e ON e.id=deo.event_id
         JOIN social_groups g ON g.id=e.group_id
         JOIN locations l ON l.id=deo.location_id
         JOIN campaigns c ON c.id=deo.reward_campaign_id
         JOIN reward_templates rt ON rt.id=c.reward_template_id
         WHERE deo.business_id=? AND deo.status<>'archived'
         ORDER BY e.starts_at DESC,e.id DESC"
    );
    $stmt->execute([$businessId]);
    return $stmt->fetchAll();
}
