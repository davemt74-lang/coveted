<?php
declare(strict_types=1);

require_once __DIR__ . '/events.php';
require_once __DIR__ . '/artists.php';

function coveted_event_manage_locked(PDO $pdo, array $actor, string $eventRef): array
{
    $eventRef = trim($eventRef);
    if ($eventRef === '' || strlen($eventRef) > 64) {
        throw new InvalidArgumentException('Event not found.');
    }

    $stmt = $pdo->prepare(
        "SELECT e.*, g.status AS group_status
         FROM events e
         JOIN social_groups g ON g.id = e.group_id
         WHERE e.public_id = ? OR CAST(e.id AS CHAR) = ?
         LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([$eventRef, $eventRef]);
    $event = $stmt->fetch();
    if (!$event || !coveted_event_can_manage($event, $actor)) {
        throw new InvalidArgumentException('You cannot manage this event.');
    }
    return $event;
}

function coveted_event_update(array $actor, string $eventRef, array $data): void
{
    $input = coveted_event_validate_input($data);
    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $event = coveted_event_manage_locked($pdo, $actor, $eventRef);
        if (in_array((string)$event['status'], ['completed','cancelled'], true)) {
            throw new InvalidArgumentException('Completed or cancelled events cannot be edited.');
        }
        if ($event['group_status'] !== 'active') {
            throw new InvalidArgumentException('Events in an inactive group cannot be edited.');
        }
        if ($event['status'] === 'published' && coveted_utc_datetime($input['starts_at'])->getTimestamp() <= time()) {
            throw new InvalidArgumentException('Published events must start in the future.');
        }

        if ($input['capacity'] !== null) {
            $occupied = coveted_event_occupied_seats_locked($pdo, (int)$event['id']);
            if ($occupied > $input['capacity']) {
                throw new InvalidArgumentException('Capacity cannot be lower than the seats already reserved.');
            }
        }

        if ($event['status'] !== 'draft' && $input['audience'] !== $event['audience']) {
            throw new InvalidArgumentException('Event audience cannot change after publication.');
        }

        $fields = [
            'title', 'description', 'event_type', 'audience', 'timezone',
            'starts_at', 'ends_at', 'capacity', 'plus_one_allowed', 'location_visibility',
        ];
        $changes = [];
        foreach ($fields as $field) {
            $before = $event[$field] ?? null;
            $after = $input[$field] ?? null;
            if ((string)$before !== (string)$after) {
                $changes[$field] = ['before' => $before, 'after' => $after];
            }
        }

        if (!$changes) {
            $pdo->commit();
            return;
        }

        $pdo->prepare(
            "UPDATE events
             SET title = ?, description = ?, event_type = ?, audience = ?, timezone = ?,
                 starts_at = ?, ends_at = ?, capacity = ?, plus_one_allowed = ?,
                 location_visibility = ?, updated_at = NOW()
             WHERE id = ?"
        )->execute([
            $input['title'], $input['description'], $input['event_type'], $input['audience'],
            $input['timezone'], $input['starts_at'], $input['ends_at'], $input['capacity'],
            $input['plus_one_allowed'], $input['location_visibility'], (int)$event['id'],
        ]);

        coveted_audit(
            'event.updated',
            'event',
            (string)$event['public_id'],
            ['changes' => $changes],
            (int)$actor['id']
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_event_set_location(
    array $actor,
    string $eventRef,
    ?int $locationId,
    string $privateLabel = '',
    string $revealNotes = ''
): void {
    $privateLabel = trim($privateLabel);
    $revealNotes = trim($revealNotes);
    if (mb_strlen($privateLabel) > 255 || mb_strlen($revealNotes) > 5000) {
        throw new InvalidArgumentException('Event location copy is too long.');
    }
    if (($locationId === null || $locationId < 1) && $privateLabel === '') {
        throw new InvalidArgumentException('Choose a Coveted location or enter a private location label.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $event = coveted_event_manage_locked($pdo, $actor, $eventRef);
        if (in_array((string)$event['status'], ['completed','cancelled'], true)) {
            throw new InvalidArgumentException('Completed or cancelled event locations cannot be changed.');
        }

        $location = null;
        if ($locationId !== null && $locationId > 0) {
            $locationStmt = $pdo->prepare(
                "SELECT l.*, b.status AS business_status
                 FROM locations l
                 JOIN businesses b ON b.id = l.business_id
                 WHERE l.id = ? LIMIT 1 FOR UPDATE"
            );
            $locationStmt->execute([$locationId]);
            $location = $locationStmt->fetch();
            if (!$location || $location['status'] !== 'active' || $location['business_status'] !== 'active') {
                throw new InvalidArgumentException('Event location must be active.');
            }

            $conflict = $pdo->prepare(
                "SELECT 1
                 FROM campaign_event_links cel
                 JOIN campaigns c ON c.id = cel.campaign_id
                 WHERE cel.event_id = ? AND c.owner_type = 'business'
                   AND (
                       c.business_id <> ?
                       OR (c.location_id IS NOT NULL AND c.location_id <> ?)
                   )
                 LIMIT 1"
            );
            $conflict->execute([(int)$event['id'], (int)$location['business_id'], $locationId]);
            if ($conflict->fetchColumn()) {
                throw new InvalidArgumentException('This location conflicts with a business campaign already linked to the event.');
            }
        } else {
            $linkedBusiness = $pdo->prepare(
                "SELECT 1 FROM campaign_event_links cel
                 JOIN campaigns c ON c.id = cel.campaign_id
                 WHERE cel.event_id = ? AND c.owner_type = 'business' LIMIT 1"
            );
            $linkedBusiness->execute([(int)$event['id']]);
            if ($linkedBusiness->fetchColumn()) {
                throw new InvalidArgumentException('A business-linked event must keep its Coveted business location.');
            }
        }

        $pdo->prepare(
            "INSERT INTO event_locations (event_id, location_id, private_location_label, reveal_notes)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                location_id = VALUES(location_id),
                private_location_label = VALUES(private_location_label),
                reveal_notes = VALUES(reveal_notes)"
        )->execute([
            (int)$event['id'],
            $locationId !== null && $locationId > 0 ? $locationId : null,
            $location ? null : $privateLabel,
            $revealNotes !== '' ? $revealNotes : null,
        ]);

        coveted_audit(
            'event.location_changed',
            'event',
            (string)$event['public_id'],
            [
                'location_id' => $locationId,
                'private_location' => $location ? false : true,
            ],
            (int)$actor['id']
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_event_set_artist(
    array $actor,
    string $eventRef,
    int $artistId,
    string $appearanceType = 'featured'
): void {
    $appearanceType = strtolower(trim($appearanceType));
    if (!in_array($appearanceType, ['featured','support','dj','session','mystery'], true)) {
        throw new InvalidArgumentException('Invalid artist appearance type.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $event = coveted_event_manage_locked($pdo, $actor, $eventRef);
        if (in_array((string)$event['status'], ['completed','cancelled'], true)) {
            throw new InvalidArgumentException('Artists cannot be changed on a completed or cancelled event.');
        }

        $artist = $pdo->prepare('SELECT status FROM artist_profiles WHERE id = ? LIMIT 1 FOR UPDATE');
        $artist->execute([$artistId]);
        if ($artist->fetchColumn() !== 'active') {
            throw new InvalidArgumentException('Only an active artist can be added to an event.');
        }

        $pdo->prepare(
            "INSERT INTO event_artists (event_id, artist_id, appearance_type)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE appearance_type = VALUES(appearance_type)"
        )->execute([(int)$event['id'], $artistId, $appearanceType]);
        coveted_audit(
            'event.artist_changed',
            'event',
            (string)$event['public_id'],
            ['artist_id' => $artistId, 'appearance_type' => $appearanceType],
            (int)$actor['id']
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_event_remove_artist(array $actor, string $eventRef, int $artistId): void
{
    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $event = coveted_event_manage_locked($pdo, $actor, $eventRef);
        if (in_array((string)$event['status'], ['completed','cancelled'], true)) {
            throw new InvalidArgumentException('Artists cannot be changed on a completed or cancelled event.');
        }

        $linked = $pdo->prepare(
            "SELECT 1
             FROM campaign_event_links cel
             JOIN campaigns c ON c.id = cel.campaign_id
             WHERE cel.event_id = ? AND c.owner_type = 'artist' AND c.artist_id = ?
             LIMIT 1"
        );
        $linked->execute([(int)$event['id'], $artistId]);
        if ($linked->fetchColumn()) {
            throw new InvalidArgumentException('Remove the linked artist campaign before removing this artist from the event.');
        }

        $pdo->prepare('DELETE FROM event_artists WHERE event_id = ? AND artist_id = ?')
            ->execute([(int)$event['id'], $artistId]);
        coveted_audit(
            'event.artist_removed',
            'event',
            (string)$event['public_id'],
            ['artist_id' => $artistId],
            (int)$actor['id']
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_event_invite_user(
    array $actor,
    string $eventRef,
    int $userId,
    string $inviteType = 'member',
    array $policy = []
): string {
    $inviteType = strtolower(trim($inviteType));
    if (!in_array($inviteType, ['member','guest','plus_one','standby'], true)) {
        throw new InvalidArgumentException('Invalid invitation type.');
    }

    $allowedPolicyKeys = [
        'require_active_group_member',
        'reject_event_host',
        'respect_existing_response',
        'idempotent_pending',
    ];
    foreach (array_keys($policy) as $key) {
        if (!in_array((string)$key, $allowedPolicyKeys, true)) {
            throw new InvalidArgumentException('Invalid invitation policy.');
        }
    }

    $requireActiveGroupMember = !empty($policy['require_active_group_member']);
    $rejectEventHost = !empty($policy['reject_event_host']);
    $respectExistingResponse = !empty($policy['respect_existing_response']);
    $idempotentPending = !empty($policy['idempotent_pending']);

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $event = coveted_event_manage_locked($pdo, $actor, $eventRef);
        if ($event['status'] !== 'published' || coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() <= time()) {
            throw new InvalidArgumentException('Invitations can be sent only for a future published event.');
        }

        $target = $pdo->prepare('SELECT status FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        $target->execute([$userId]);
        if ($target->fetchColumn() !== 'active') {
            throw new InvalidArgumentException('Invitation recipient must have an active Coveted account.');
        }

        if ($requireActiveGroupMember) {
            $membership = $pdo->prepare(
                "SELECT membership_status
                 FROM group_memberships
                 WHERE group_id = ? AND user_id = ?
                 LIMIT 1 FOR UPDATE"
            );
            $membership->execute([(int)$event['group_id'], $userId]);
            if ($membership->fetchColumn() !== 'active') {
                throw new InvalidArgumentException('That member is no longer eligible for this group invitation.');
            }
        }

        if ($rejectEventHost) {
            $host = $pdo->prepare(
                'SELECT 1 FROM event_hosts WHERE event_id = ? AND user_id = ? LIMIT 1 FOR UPDATE'
            );
            $host->execute([(int)$event['id'], $userId]);
            if ($host->fetchColumn()) {
                throw new InvalidArgumentException('That member is already part of the event host team.');
            }
        }

        if ($respectExistingResponse) {
            $rsvp = $pdo->prepare(
                'SELECT response FROM event_rsvps WHERE event_id = ? AND user_id = ? LIMIT 1 FOR UPDATE'
            );
            $rsvp->execute([(int)$event['id'], $userId]);
            $rsvpResponse = (string)($rsvp->fetchColumn() ?: '');
            if ($rsvpResponse === 'declined') {
                throw new InvalidArgumentException('That member already declined this gathering.');
            }
            if (in_array($rsvpResponse, ['attending','waitlist'], true)) {
                throw new InvalidArgumentException('That member already has an RSVP for this gathering.');
            }
        }

        $existing = $pdo->prepare(
            'SELECT id, public_id, status FROM event_invitations WHERE event_id = ? AND user_id = ? LIMIT 1 FOR UPDATE'
        );
        $existing->execute([(int)$event['id'], $userId]);
        $row = $existing->fetch();
        if ($row && $respectExistingResponse && (string)$row['status'] === 'declined') {
            throw new InvalidArgumentException('That member already declined this gathering.');
        }
        if ($row && ((string)$row['status'] === 'accepted' || ($idempotentPending && (string)$row['status'] === 'pending'))) {
            $publicId = (string)$row['public_id'];
            $pdo->commit();
            return $publicId;
        }

        if ($row) {
            $pdo->prepare(
                "UPDATE event_invitations
                 SET invited_by = ?, invite_type = ?, status = 'pending'
                 WHERE id = ?"
            )->execute([(int)$actor['id'], $inviteType, (int)$row['id']]);
            $publicId = (string)$row['public_id'];
        } else {
            $publicId = coveted_uuid('einv');
            $pdo->prepare(
                "INSERT INTO event_invitations
                    (public_id, event_id, user_id, invited_by, invite_type, status)
                 VALUES (?, ?, ?, ?, ?, 'pending')"
            )->execute([$publicId, (int)$event['id'], $userId, (int)$actor['id'], $inviteType]);
        }

        coveted_audit(
            'event.user_invited',
            'event',
            (string)$event['public_id'],
            ['user_id' => $userId, 'invitation_id' => $publicId, 'invite_type' => $inviteType],
            (int)$actor['id']
        );
        $pdo->commit();
        return $publicId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_event_add_mystery_reveal(
    array $actor,
    string $eventRef,
    string $revealAt,
    string $revealType,
    string $title,
    string $content
): int {
    $revealType = strtolower(trim($revealType));
    $title = trim($title);
    $content = trim($content);
    if (!in_array($revealType, ['area','experience','instructions','location','artist','custom'], true)) {
        throw new InvalidArgumentException('Invalid mystery reveal type.');
    }
    if (mb_strlen($title) > 180 || $content === '' || mb_strlen($content) > 10000) {
        throw new InvalidArgumentException('Enter valid reveal content.');
    }
    $reveal = coveted_utc_datetime($revealAt);

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $event = coveted_event_manage_locked($pdo, $actor, $eventRef);
        if (in_array((string)$event['status'], ['completed','cancelled'], true)) {
            throw new InvalidArgumentException('Reveals cannot be added to a completed or cancelled event.');
        }
        if ($reveal > coveted_utc_datetime((string)$event['starts_at'])) {
            throw new InvalidArgumentException('A mystery reveal must occur no later than the event start time.');
        }

        $pdo->prepare(
            "INSERT INTO event_mystery_reveals
                (event_id, reveal_at, reveal_type, title, content)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([
            (int)$event['id'],
            $reveal->format('Y-m-d H:i:s'),
            $revealType,
            $title !== '' ? $title : null,
            $content,
        ]);
        $revealId = (int)$pdo->lastInsertId();
        coveted_audit(
            'event.reveal_created',
            'event',
            (string)$event['public_id'],
            ['reveal_id' => $revealId, 'reveal_type' => $revealType, 'reveal_at' => $reveal->format('Y-m-d H:i:s')],
            (int)$actor['id']
        );
        $pdo->commit();
        return $revealId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
