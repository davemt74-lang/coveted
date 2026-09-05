<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function coveted_event_by_ref(string $ref): ?array
{
    $ref = trim($ref);
    if ($ref === '' || strlen($ref) > 64) {
        return null;
    }

    $stmt = coveted_db()->prepare(
        "SELECT e.*, g.public_id AS group_public_id, g.name AS group_name, g.status AS group_status
         FROM events e
         JOIN social_groups g ON g.id = e.group_id
         WHERE e.public_id = ? OR CAST(e.id AS CHAR) = ?
         LIMIT 1"
    );
    $stmt->execute([$ref, $ref]);
    $event = $stmt->fetch();

    return $event ?: null;
}

function coveted_event_timezone(array $event): DateTimeZone
{
    return coveted_timezone((string)($event['timezone'] ?? ''));
}

function coveted_event_local_datetime(array $event, string $field = 'starts_at'): DateTimeImmutable
{
    $value = trim((string)($event[$field] ?? ''));
    if ($value === '') {
        throw new InvalidArgumentException('Event date/time is unavailable.');
    }

    return coveted_utc_datetime($value)->setTimezone(coveted_event_timezone($event));
}

function coveted_event_format(array $event, string $format, string $field = 'starts_at'): string
{
    return coveted_event_local_datetime($event, $field)->format($format);
}

function coveted_event_is_future(array $event): bool
{
    return coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() >= time();
}

function coveted_event_group_visible(array $event): bool
{
    if (($event['audience'] ?? 'group') !== 'group') {
        return false;
    }

    if (!in_array((string)($event['status'] ?? ''), ['published', 'closed', 'cancelled'], true)) {
        return false;
    }

    return !isset($event['group_status']) || $event['group_status'] === 'active';
}

function coveted_event_actor_has_host_approval(array $user): bool
{
    return coveted_is_system_admin($user)
        || in_array('attendee_host', (array)($user['roles'] ?? []), true);
}

function coveted_event_group_role(int $groupId, int $userId): ?string
{
    $stmt = coveted_db()->prepare(
        "SELECT group_role
         FROM group_memberships
         WHERE group_id = ? AND user_id = ? AND membership_status = 'active'
         LIMIT 1"
    );
    $stmt->execute([$groupId, $userId]);
    $role = $stmt->fetchColumn();

    return $role !== false ? (string)$role : null;
}

function coveted_event_assigned_host_role(int $eventId, int $userId): ?string
{
    $stmt = coveted_db()->prepare(
        'SELECT host_role FROM event_hosts WHERE event_id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$eventId, $userId]);
    $role = $stmt->fetchColumn();

    return $role !== false ? (string)$role : null;
}

function coveted_event_can_manage(array $event, array $user): bool
{
    if (coveted_is_system_admin($user)) {
        return true;
    }
    if (!coveted_event_actor_has_host_approval($user)) {
        return false;
    }

    $groupRole = coveted_event_group_role((int)$event['group_id'], (int)$user['id']);
    if (in_array($groupRole, ['host', 'group_admin'], true)) {
        return true;
    }

    return in_array(
        coveted_event_assigned_host_role((int)$event['id'], (int)$user['id']),
        ['lead', 'cohost'],
        true
    );
}

function coveted_event_can_checkin(array $event, array $user): bool
{
    if (coveted_event_can_manage($event, $user)) {
        return true;
    }

    return coveted_event_assigned_host_role((int)$event['id'], (int)$user['id']) === 'checkin';
}

function coveted_event_user_attended(int $eventId, int $userId): bool
{
    $stmt = coveted_db()->prepare(
        "SELECT 1
         FROM event_attendance
         WHERE event_id = ? AND user_id = ?
           AND status IN ('checked_in','attended','left_early')
         LIMIT 1"
    );
    $stmt->execute([$eventId, $userId]);

    return (bool)$stmt->fetchColumn();
}

function coveted_event_can_view(array $event, array $user): bool
{
    if (coveted_event_can_manage($event, $user)) {
        return true;
    }

    if (coveted_event_assigned_host_role((int)$event['id'], (int)$user['id']) !== null) {
        return true;
    }

    if (($event['status'] ?? '') === 'draft') {
        return false;
    }

    $eventId = (int)$event['id'];
    $userId = (int)$user['id'];

    if (($event['status'] ?? '') === 'completed') {
        return coveted_event_user_attended($eventId, $userId);
    }

    $invitation = coveted_db()->prepare(
        "SELECT 1
         FROM event_invitations
         WHERE event_id = ? AND user_id = ?
           AND status NOT IN ('expired','revoked')
         LIMIT 1"
    );
    $invitation->execute([$eventId, $userId]);
    if ($invitation->fetchColumn()) {
        return true;
    }

    if (!coveted_event_group_visible($event)) {
        return false;
    }

    return coveted_event_group_role((int)$event['group_id'], $userId) !== null;
}

/** @return array<int,array<string,mixed>> */
function coveted_events_for_user(array $user, int $limit = 100, ?int $groupId = null): array
{
    $limit = max(1, min($limit, 250));
    $userId = (int)$user['id'];
    $isSystemAdmin = coveted_is_system_admin($user) ? 1 : 0;
    $isHostApproved = coveted_event_actor_has_host_approval($user) ? 1 : 0;

    $sql = "SELECT
                e.id, e.public_id, e.group_id, e.title, e.description, e.event_type, e.audience,
                e.timezone, e.starts_at, e.ends_at, e.capacity, e.plus_one_allowed,
                e.location_visibility, e.status,
                g.name AS group_name, g.status AS group_status,
                er.response,
                ea.status AS attendance_status,
                ei.status AS invitation_status,
                COALESCE(l.name, el.private_location_label) AS location_name,
                l.city AS location_city,
                EXISTS (
                    SELECT 1 FROM event_mystery_reveals mr
                    WHERE mr.event_id = e.id AND mr.reveal_type = 'location' AND mr.reveal_at <= NOW()
                ) AS location_revealed,
                (
                    ? = 1
                    OR (
                        ? = 1
                        AND (
                            EXISTS (
                                SELECT 1 FROM group_memberships hgm
                                WHERE hgm.group_id = e.group_id AND hgm.user_id = ?
                                  AND hgm.membership_status = 'active'
                                  AND hgm.group_role IN ('host','group_admin')
                            )
                            OR EXISTS (
                                SELECT 1 FROM event_hosts ehm
                                WHERE ehm.event_id = e.id AND ehm.user_id = ?
                                  AND ehm.host_role IN ('lead','cohost')
                            )
                        )
                    )
                ) AS can_manage
            FROM events e
            JOIN social_groups g ON g.id = e.group_id
            LEFT JOIN event_rsvps er ON er.event_id = e.id AND er.user_id = ?
            LEFT JOIN event_attendance ea ON ea.event_id = e.id AND ea.user_id = ?
            LEFT JOIN event_invitations ei ON ei.event_id = e.id AND ei.user_id = ?
            LEFT JOIN event_locations el ON el.event_id = e.id
            LEFT JOIN locations l ON l.id = el.location_id
            WHERE (
                ? = 1
                OR EXISTS (
                    SELECT 1 FROM event_hosts ehv
                    WHERE ehv.event_id = e.id AND ehv.user_id = ?
                )
                OR (
                    ? = 1
                    AND EXISTS (
                        SELECT 1 FROM group_memberships mgm
                        WHERE mgm.group_id = e.group_id AND mgm.user_id = ?
                          AND mgm.membership_status = 'active'
                          AND mgm.group_role IN ('host','group_admin')
                    )
                )
                OR (
                    e.status <> 'draft'
                    AND (
                        (
                            e.status = 'completed'
                            AND EXISTS (
                                SELECT 1 FROM event_attendance eap
                                WHERE eap.event_id = e.id AND eap.user_id = ?
                                  AND eap.status IN ('checked_in','attended','left_early')
                            )
                        )
                        OR (
                            e.status <> 'completed'
                            AND (
                                EXISTS (
                                    SELECT 1 FROM event_invitations eiv
                                    WHERE eiv.event_id = e.id AND eiv.user_id = ?
                                      AND eiv.status NOT IN ('expired','revoked')
                                )
                                OR (
                                    e.audience = 'group'
                                    AND g.status = 'active'
                                    AND e.status IN ('published','closed','cancelled')
                                    AND EXISTS (
                                        SELECT 1 FROM group_memberships vgm
                                        WHERE vgm.group_id = e.group_id AND vgm.user_id = ?
                                          AND vgm.membership_status = 'active'
                                    )
                                )
                            )
                        )
                    )
                )
            )";

    $params = [
        $isSystemAdmin, $isHostApproved, $userId, $userId,
        $userId, $userId, $userId,
        $isSystemAdmin, $userId, $isHostApproved, $userId,
        $userId, $userId, $userId,
    ];

    if ($groupId !== null && $groupId > 0) {
        $sql .= ' AND e.group_id = ?';
        $params[] = $groupId;
    }

    $sql .= " ORDER BY e.starts_at DESC, e.id DESC LIMIT {$limit}";
    $stmt = coveted_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function coveted_event_assert_open_locked(array $event): void
{
    if (($event['group_status'] ?? 'active') !== 'active') {
        throw new InvalidArgumentException('This event is not accepting RSVPs.');
    }
    if (($event['status'] ?? '') !== 'published') {
        throw new InvalidArgumentException('This event is not accepting RSVPs.');
    }
    if (coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() <= time()) {
        throw new InvalidArgumentException('This event is no longer accepting RSVPs.');
    }
}

function coveted_event_user_eligible_locked(PDO $pdo, array $event, int $userId): bool
{
    $active = $pdo->prepare('SELECT 1 FROM users WHERE id = ? AND status = \'active\' LIMIT 1');
    $active->execute([$userId]);
    if (!$active->fetchColumn()) {
        return false;
    }

    $invite = $pdo->prepare(
        "SELECT 1
         FROM event_invitations
         WHERE event_id = ? AND user_id = ?
           AND status NOT IN ('expired','revoked')
         LIMIT 1"
    );
    $invite->execute([(int)$event['id'], $userId]);
    if ($invite->fetchColumn()) {
        return true;
    }

    if (($event['audience'] ?? '') !== 'group' || ($event['group_status'] ?? 'active') !== 'active') {
        return false;
    }

    $membership = $pdo->prepare(
        "SELECT 1
         FROM group_memberships
         WHERE group_id = ? AND user_id = ? AND membership_status = 'active'
         LIMIT 1"
    );
    $membership->execute([(int)$event['group_id'], $userId]);
    return (bool)$membership->fetchColumn();
}

function coveted_event_occupied_seats_locked(PDO $pdo, int $eventId, int $excludeUserId = 0): int
{
    $sql = "SELECT COALESCE(SUM(1 + guest_count), 0)
            FROM event_rsvps
            WHERE event_id = ? AND response = 'attending'";
    $params = [$eventId];

    if ($excludeUserId > 0) {
        $sql .= ' AND user_id <> ?';
        $params[] = $excludeUserId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/** @return int[] Promoted user IDs. */
function coveted_event_promote_waitlist_locked(PDO $pdo, array $event): array
{
    $capacity = $event['capacity'] !== null ? (int)$event['capacity'] : null;
    $available = $capacity === null
        ? PHP_INT_MAX
        : max(0, $capacity - coveted_event_occupied_seats_locked($pdo, (int)$event['id']));

    if ($available < 1) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT id, user_id, guest_count
         FROM event_rsvps
         WHERE event_id = ? AND response = 'waitlist'
         ORDER BY responded_at ASC, id ASC
         FOR UPDATE"
    );
    $stmt->execute([(int)$event['id']]);

    $promoted = [];
    $promote = $pdo->prepare(
        "UPDATE event_rsvps
         SET response = 'attending', updated_at = NOW()
         WHERE id = ? AND response = 'waitlist'"
    );
    $remove = $pdo->prepare(
        "UPDATE event_rsvps
         SET response = 'declined', guest_count = 0, updated_at = NOW()
         WHERE id = ? AND response = 'waitlist'"
    );

    foreach ($stmt->fetchAll() as $row) {
        if (!coveted_event_user_eligible_locked($pdo, $event, (int)$row['user_id'])) {
            $remove->execute([(int)$row['id']]);
            coveted_audit(
                'event.waitlist_ineligible_removed',
                'event',
                (string)$event['public_id'],
                ['user_id' => (int)$row['user_id']],
                0
            );
            continue;
        }

        $seats = 1 + (int)$row['guest_count'];
        if ($seats > $available) {
            continue;
        }

        $promote->execute([(int)$row['id']]);
        if ($promote->rowCount() !== 1) {
            continue;
        }

        $available -= $seats;
        $promoted[] = (int)$row['user_id'];
        coveted_audit(
            'event.waitlist_promoted',
            'event',
            (string)$event['public_id'],
            ['user_id' => (int)$row['user_id']],
            0
        );

        if ($available < 1) {
            break;
        }
    }

    return $promoted;
}

/**
 * Remove a former group member from future group-audience events and free any
 * reserved seats. Caller must already hold the group membership transaction.
 */
function coveted_event_revoke_group_member_future_access_locked(
    PDO $pdo,
    int $groupId,
    int $userId,
    int $actorId
): array {
    $events = $pdo->prepare(
        "SELECT e.*, g.status AS group_status
         FROM events e
         JOIN social_groups g ON g.id = e.group_id
         WHERE e.group_id = ?
           AND e.audience = 'group'
           AND e.starts_at > NOW()
           AND e.status IN ('published','closed')
         ORDER BY e.id
         FOR UPDATE"
    );
    $events->execute([$groupId]);

    $revokedInvitations = 0;
    $releasedRsvps = 0;
    $promotedUsers = [];

    foreach ($events->fetchAll() as $event) {
        $revoke = $pdo->prepare(
            "UPDATE event_invitations
             SET status = 'revoked'
             WHERE event_id = ? AND user_id = ? AND status IN ('pending','accepted','declined')"
        );
        $revoke->execute([(int)$event['id'], $userId]);
        $revokedInvitations += $revoke->rowCount();

        $rsvp = $pdo->prepare(
            'SELECT id, response FROM event_rsvps WHERE event_id = ? AND user_id = ? LIMIT 1 FOR UPDATE'
        );
        $rsvp->execute([(int)$event['id'], $userId]);
        $row = $rsvp->fetch();
        if (!$row || !in_array((string)$row['response'], ['attending','waitlist'], true)) {
            continue;
        }

        $wasAttending = $row['response'] === 'attending';
        $pdo->prepare(
            "UPDATE event_rsvps
             SET response = 'declined', guest_count = 0, responded_at = NOW(), updated_at = NOW()
             WHERE id = ?"
        )->execute([(int)$row['id']]);
        $releasedRsvps++;

        if ($wasAttending && $event['status'] === 'published') {
            array_push($promotedUsers, ...coveted_event_promote_waitlist_locked($pdo, $event));
        }

        coveted_audit(
            'event.member_access_revoked',
            'event',
            (string)$event['public_id'],
            ['user_id' => $userId, 'group_id' => $groupId],
            $actorId
        );
    }

    return [
        'revoked_invitations' => $revokedInvitations,
        'released_rsvps' => $releasedRsvps,
        'promoted_user_ids' => array_values(array_unique($promotedUsers)),
    ];
}

/** @return string attending, waitlist, or declined. */
function coveted_event_apply_rsvp_locked(
    PDO $pdo,
    array $event,
    int $userId,
    string $decision,
    int $guestCount = 0
): string {
    if (!in_array($decision, ['attending', 'declined'], true)) {
        throw new InvalidArgumentException('Invalid RSVP response.');
    }
    if ($guestCount < 0 || $guestCount > 1) {
        throw new InvalidArgumentException('Coveted currently supports at most one guest per RSVP.');
    }
    if ($guestCount > 0 && !(bool)$event['plus_one_allowed']) {
        throw new InvalidArgumentException('This event does not include a +1.');
    }
    if (!coveted_event_user_eligible_locked($pdo, $event, $userId)) {
        throw new InvalidArgumentException('You are not eligible to RSVP to this event.');
    }

    $existingStmt = $pdo->prepare(
        'SELECT id, response, guest_count FROM event_rsvps WHERE event_id = ? AND user_id = ? LIMIT 1 FOR UPDATE'
    );
    $existingStmt->execute([(int)$event['id'], $userId]);
    $existing = $existingStmt->fetch();
    $previousResponse = $existing['response'] ?? null;
    $previousGuestCount = $existing ? (int)$existing['guest_count'] : 0;

    $response = 'declined';
    if ($decision === 'attending') {
        $response = 'attending';
        $capacity = $event['capacity'] !== null ? (int)$event['capacity'] : null;
        if ($capacity !== null) {
            $occupied = coveted_event_occupied_seats_locked($pdo, (int)$event['id'], $userId);
            if ($occupied + 1 + $guestCount > $capacity) {
                $response = 'waitlist';
            }
        }
    } else {
        $guestCount = 0;
    }

    $pdo->prepare(
        "INSERT INTO event_rsvps (event_id, user_id, response, guest_count, responded_at)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            response = VALUES(response), guest_count = VALUES(guest_count),
            responded_at = NOW(), updated_at = NOW()"
    )->execute([(int)$event['id'], $userId, $response, $guestCount]);

    if ($previousResponse === 'attending' && ($response !== 'attending' || $guestCount < $previousGuestCount)) {
        coveted_event_promote_waitlist_locked($pdo, $event);
    }

    return $response;
}

function coveted_event_set_rsvp(array $user, string $eventRef, string $decision, int $guestCount = 0): string
{
    $eventRef = trim($eventRef);
    if ($eventRef === '' || strlen($eventRef) > 64) {
        throw new InvalidArgumentException('Event not found.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            "SELECT e.*, g.status AS group_status
             FROM events e
             JOIN social_groups g ON g.id = e.group_id
             WHERE e.public_id = ? OR CAST(e.id AS CHAR) = ?
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$eventRef, $eventRef]);
        $event = $stmt->fetch();
        if (!$event) {
            throw new InvalidArgumentException('Event not found.');
        }

        coveted_event_assert_open_locked($event);
        $response = coveted_event_apply_rsvp_locked($pdo, $event, (int)$user['id'], $decision, $guestCount);

        $inviteStatus = $decision === 'attending' ? 'accepted' : 'declined';
        $pdo->prepare(
            "UPDATE event_invitations SET status = ?
             WHERE event_id = ? AND user_id = ? AND status NOT IN ('expired','revoked')"
        )->execute([$inviteStatus, (int)$event['id'], (int)$user['id']]);

        coveted_audit(
            'event.rsvp_updated',
            'event',
            (string)$event['public_id'],
            ['response' => $response, 'guest_count' => $decision === 'attending' ? $guestCount : 0],
            (int)$user['id']
        );
        $pdo->commit();
        return $response;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_event_respond_invitation(
    array $user,
    string $invitePublicId,
    string $decision,
    int $guestCount = 0
): string {
    if (!in_array($decision, ['accepted', 'declined'], true)) {
        throw new InvalidArgumentException('Invalid invitation response.');
    }

    $invitePublicId = trim($invitePublicId);
    if ($invitePublicId === '' || strlen($invitePublicId) > 64) {
        throw new InvalidArgumentException('Invitation not found.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            "SELECT ei.id, ei.event_id, ei.status, e.public_id AS event_public_id,
                    e.group_id, e.audience, e.plus_one_allowed, e.status AS event_status,
                    e.starts_at, e.capacity, g.status AS group_status
             FROM event_invitations ei
             JOIN events e ON e.id = ei.event_id
             JOIN social_groups g ON g.id = e.group_id
             WHERE ei.public_id = ? AND ei.user_id = ?
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$invitePublicId, (int)$user['id']]);
        $invite = $stmt->fetch();

        if (!$invite || $invite['status'] !== 'pending') {
            throw new InvalidArgumentException('Invitation is no longer available.');
        }

        $event = [
            'id' => (int)$invite['event_id'],
            'public_id' => (string)$invite['event_public_id'],
            'group_id' => (int)$invite['group_id'],
            'audience' => (string)$invite['audience'],
            'plus_one_allowed' => (int)$invite['plus_one_allowed'],
            'status' => (string)$invite['event_status'],
            'starts_at' => (string)$invite['starts_at'],
            'capacity' => $invite['capacity'],
            'group_status' => (string)$invite['group_status'],
        ];
        coveted_event_assert_open_locked($event);

        $response = coveted_event_apply_rsvp_locked(
            $pdo,
            $event,
            (int)$user['id'],
            $decision === 'accepted' ? 'attending' : 'declined',
            $decision === 'accepted' ? $guestCount : 0
        );

        $pdo->prepare('UPDATE event_invitations SET status = ? WHERE id = ?')
            ->execute([$decision, (int)$invite['id']]);

        coveted_audit(
            'event.invitation_response',
            'event_invitation',
            $invitePublicId,
            [
                'decision' => $decision,
                'response' => $response,
                'guest_count' => $decision === 'accepted' ? $guestCount : 0,
                'event_id' => $invite['event_public_id'],
            ],
            (int)$user['id']
        );
        $pdo->commit();
        return $response;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_event_validate_input(array $data): array
{
    $title = trim((string)($data['title'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $eventType = strtolower(trim((string)($data['event_type'] ?? 'regular')));
    $audience = strtolower(trim((string)($data['audience'] ?? 'group')));
    $timezone = coveted_require_timezone((string)($data['timezone'] ?? ''));
    $startsAt = coveted_utc_datetime((string)($data['starts_at'] ?? ''));
    $endsRaw = trim((string)($data['ends_at'] ?? ''));
    $endsAt = $endsRaw !== '' ? coveted_utc_datetime($endsRaw) : null;
    $capacity = ($data['capacity'] ?? '') === '' ? null : (int)$data['capacity'];
    $plusOne = !empty($data['plus_one_allowed']) ? 1 : 0;
    $locationVisibility = strtolower(trim((string)($data['location_visibility'] ?? 'immediate')));

    if ($title === '' || mb_strlen($title) > 190) {
        throw new InvalidArgumentException('Enter an event title.');
    }
    if (mb_strlen($description) > 5000) {
        throw new InvalidArgumentException('Event description is too long.');
    }
    if (!in_array($eventType, ['regular','mystery','private_table','member_plus_one','session'], true)) {
        throw new InvalidArgumentException('Invalid event type.');
    }
    if (!in_array($audience, ['group','invitation_only'], true)) {
        throw new InvalidArgumentException('Invalid event audience.');
    }
    if ($capacity !== null && $capacity < 1) {
        throw new InvalidArgumentException('Event capacity must be at least one.');
    }
    if ($endsAt !== null && $endsAt <= $startsAt) {
        throw new InvalidArgumentException('Event end time must be after its start time.');
    }
    if (!in_array($locationVisibility, ['immediate','scheduled_reveal','host_only'], true)) {
        throw new InvalidArgumentException('Invalid location visibility.');
    }

    return [
        'title' => $title,
        'description' => $description !== '' ? $description : null,
        'event_type' => $eventType,
        'audience' => $audience,
        'timezone' => $timezone->getName(),
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
        'ends_at' => $endsAt?->format('Y-m-d H:i:s'),
        'capacity' => $capacity,
        'plus_one_allowed' => $plusOne,
        'location_visibility' => $locationVisibility,
    ];
}

function coveted_event_actor_can_host_group_locked(PDO $pdo, array $actor, int $groupId): bool
{
    if (!coveted_event_actor_has_host_approval($actor)) {
        return false;
    }
    if (coveted_is_system_admin($actor)) {
        return true;
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM group_memberships
         WHERE group_id = ? AND user_id = ? AND membership_status = 'active'
           AND group_role IN ('host','group_admin')
         LIMIT 1"
    );
    $stmt->execute([$groupId, (int)$actor['id']]);
    return (bool)$stmt->fetchColumn();
}

function coveted_event_create(array $actor, int $groupId, array $data): array
{
    $input = coveted_event_validate_input($data);
    $status = strtolower(trim((string)($data['status'] ?? 'draft')));
    if (!in_array($status, ['draft','published'], true)) {
        throw new InvalidArgumentException('New events must start as draft or published.');
    }
    if ($status === 'published' && coveted_utc_datetime($input['starts_at'])->getTimestamp() <= time()) {
        throw new InvalidArgumentException('Published events must start in the future.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $group = $pdo->prepare('SELECT status FROM social_groups WHERE id = ? LIMIT 1 FOR UPDATE');
        $group->execute([$groupId]);
        if ($group->fetchColumn() !== 'active') {
            throw new InvalidArgumentException('Only an active group can create events.');
        }
        if (!coveted_event_actor_can_host_group_locked($pdo, $actor, $groupId)) {
            throw new InvalidArgumentException('Host access is required to create an event.');
        }

        $publicId = coveted_uuid('evt');
        $pdo->prepare(
            "INSERT INTO events
                (public_id, group_id, title, description, event_type, audience, timezone,
                 starts_at, ends_at, capacity, plus_one_allowed, location_visibility, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $publicId, $groupId, $input['title'], $input['description'], $input['event_type'],
            $input['audience'], $input['timezone'], $input['starts_at'], $input['ends_at'],
            $input['capacity'], $input['plus_one_allowed'], $input['location_visibility'],
            $status, (int)$actor['id'],
        ]);
        $eventId = (int)$pdo->lastInsertId();

        coveted_audit('event.created', 'event', $publicId, ['group_id' => $groupId, 'status' => $status], (int)$actor['id']);
        $pdo->commit();
        return ['id' => $eventId, 'public_id' => $publicId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_event_set_status(array $actor, string $eventRef, string $status): void
{
    $status = strtolower(trim($status));
    $allowed = ['published','closed','completed','cancelled'];
    if (!in_array($status, $allowed, true)) {
        throw new InvalidArgumentException('Invalid event status.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT e.*, g.status AS group_status
             FROM events e JOIN social_groups g ON g.id = e.group_id
             WHERE e.public_id = ? OR CAST(e.id AS CHAR) = ?
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$eventRef, $eventRef]);
        $event = $stmt->fetch();
        if (!$event || !coveted_event_can_manage($event, $actor)) {
            throw new InvalidArgumentException('You cannot manage this event.');
        }

        $transitions = [
            'draft' => ['published','cancelled'],
            'published' => ['closed','completed','cancelled'],
            'closed' => ['published','completed','cancelled'],
            'completed' => [],
            'cancelled' => [],
        ];
        if (!in_array($status, $transitions[(string)$event['status']] ?? [], true)) {
            throw new InvalidArgumentException('That event status transition is not allowed.');
        }
        if ($status === 'published') {
            if ($event['group_status'] !== 'active') {
                throw new InvalidArgumentException('Only an active group can publish events.');
            }
            if (coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() <= time()) {
                throw new InvalidArgumentException('Published events must start in the future.');
            }
        }
        if ($status === 'completed' && coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() > time()) {
            throw new InvalidArgumentException('A future event cannot be completed.');
        }

        $pdo->prepare('UPDATE events SET status = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$status, (int)$event['id']]);
        coveted_audit('event.status_changed', 'event', (string)$event['public_id'], ['status' => $status], (int)$actor['id']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_event_assign_host(array $actor, string $eventRef, int $userId, string $hostRole): void
{
    $hostRole = strtolower(trim($hostRole));
    if (!in_array($hostRole, ['lead','cohost','checkin'], true)) {
        throw new InvalidArgumentException('Invalid event host role.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT e.*, g.status AS group_status
             FROM events e JOIN social_groups g ON g.id = e.group_id
             WHERE e.public_id = ? OR CAST(e.id AS CHAR) = ?
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$eventRef, $eventRef]);
        $event = $stmt->fetch();
        if (!$event || !coveted_event_can_manage($event, $actor)) {
            throw new InvalidArgumentException('You cannot manage this event.');
        }

        $userStmt = $pdo->prepare('SELECT status FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        $userStmt->execute([$userId]);
        if ($userStmt->fetchColumn() !== 'active') {
            throw new InvalidArgumentException('Event host account must be active.');
        }

        if (in_array($hostRole, ['lead','cohost'], true)) {
            $approval = $pdo->prepare(
                "SELECT 1 FROM user_roles
                 WHERE user_id = ? AND role_key IN ('attendee_host','system_admin')
                 LIMIT 1"
            );
            $approval->execute([$userId]);
            if (!$approval->fetchColumn()) {
                throw new InvalidArgumentException('Lead and cohost assignments require Attendee Host approval.');
            }
        }

        $pdo->prepare(
            "INSERT INTO event_hosts (event_id, user_id, host_role)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE host_role = VALUES(host_role)"
        )->execute([(int)$event['id'], $userId, $hostRole]);
        coveted_audit('event.host_assigned', 'event', (string)$event['public_id'], ['user_id' => $userId, 'host_role' => $hostRole], (int)$actor['id']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_event_record_attendance(array $actor, string $eventRef, int $userId, string $status): void
{
    $status = strtolower(trim($status));
    if (!in_array($status, ['checked_in','attended','no_show','left_early'], true)) {
        throw new InvalidArgumentException('Invalid attendance status.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT e.*, g.status AS group_status
             FROM events e JOIN social_groups g ON g.id = e.group_id
             WHERE e.public_id = ? OR CAST(e.id AS CHAR) = ?
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$eventRef, $eventRef]);
        $event = $stmt->fetch();
        if (!$event || !coveted_event_can_checkin($event, $actor)) {
            throw new InvalidArgumentException('Check-in access is required.');
        }
        if (in_array((string)$event['status'], ['draft','cancelled'], true)) {
            throw new InvalidArgumentException('Attendance cannot be recorded for this event.');
        }

        $participant = $pdo->prepare(
            "SELECT 1 FROM users u
             WHERE u.id = ? AND u.status = 'active'
               AND (
                   EXISTS (SELECT 1 FROM event_rsvps er WHERE er.event_id = ? AND er.user_id = u.id AND er.response = 'attending')
                   OR EXISTS (SELECT 1 FROM event_invitations ei WHERE ei.event_id = ? AND ei.user_id = u.id AND ei.status = 'accepted')
                   OR ( ? = 'group' AND EXISTS (
                       SELECT 1 FROM group_memberships gm
                       WHERE gm.group_id = ? AND gm.user_id = u.id AND gm.membership_status = 'active'
                   ))
               )
             LIMIT 1"
        );
        $participant->execute([$userId, (int)$event['id'], (int)$event['id'], (string)$event['audience'], (int)$event['group_id']]);
        if (!$participant->fetchColumn()) {
            throw new InvalidArgumentException('Attendance can only be recorded for an eligible event participant.');
        }

        $checkedInAt = in_array($status, ['checked_in','attended','left_early'], true) ? date('Y-m-d H:i:s') : null;
        $pdo->prepare(
            "INSERT INTO event_attendance (event_id, user_id, status, checked_in_at, verified_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                checked_in_at = COALESCE(event_attendance.checked_in_at, VALUES(checked_in_at)),
                verified_by = VALUES(verified_by),
                updated_at = NOW()"
        )->execute([(int)$event['id'], $userId, $status, $checkedInAt, (int)$actor['id']]);

        coveted_audit('event.attendance_recorded', 'event', (string)$event['public_id'], ['user_id' => $userId, 'status' => $status], (int)$actor['id']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
