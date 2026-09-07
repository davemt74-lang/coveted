<?php
declare(strict_types=1);

require_once __DIR__ . '/guest_continuity.php';

/**
 * Guest → Member Conversion Intelligence is a private System Admin read model
 * over canonical group membership, verified attendance, Guest Pass and group
 * invitation history. It does not persist a score or create a second
 * conversion state machine.
 */
function coveted_guest_conversion_require_admin(array $admin, ?PDO $pdo = null): PDO
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }
    return $pdo ?? coveted_db();
}

/** @return array<int,array<string,mixed>> */
function coveted_guest_conversion_evidence_rows(PDO $pdo, int $limit = 240): array
{
    $limit = max(1, min(500, $limit));
    $sql = "SELECT
                u.id AS guest_user_id,
                u.public_id AS guest_ref,
                u.display_name,
                u.email,
                p.avatar_url,
                g.id AS group_id,
                g.public_id AS group_ref,
                g.name AS group_name,
                gm.joined_at AS guest_joined_at,
                gm.invited_by AS membership_inviter_id,
                membership_inviter.display_name AS membership_inviter_name,
                COUNT(DISTINCT e.id) AS verified_events,
                COUNT(DISTINCT CASE
                    WHEN e.starts_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 180 DAY) THEN e.id
                    ELSE NULL
                END) AS verified_events_180d,
                MAX(e.starts_at) AS last_verified_at,
                (
                    SELECT gi.status
                    FROM group_invitations gi
                    WHERE gi.group_id = gm.group_id
                      AND gi.invitee_user_id = gm.user_id
                      AND gi.public_id LIKE 'gstay\\_%'
                    ORDER BY gi.created_at DESC, gi.id DESC
                    LIMIT 1
                ) AS latest_stay_status,
                (
                    SELECT gi.public_id
                    FROM group_invitations gi
                    WHERE gi.group_id = gm.group_id
                      AND gi.invitee_user_id = gm.user_id
                      AND gi.public_id LIKE 'gstay\\_%'
                    ORDER BY gi.created_at DESC, gi.id DESC
                    LIMIT 1
                ) AS latest_stay_ref,
                (
                    SELECT gi.created_at
                    FROM group_invitations gi
                    WHERE gi.group_id = gm.group_id
                      AND gi.invitee_user_id = gm.user_id
                      AND gi.public_id LIKE 'gstay\\_%'
                    ORDER BY gi.created_at DESC, gi.id DESC
                    LIMIT 1
                ) AS latest_stay_created_at,
                (
                    SELECT gi.expires_at
                    FROM group_invitations gi
                    WHERE gi.group_id = gm.group_id
                      AND gi.invitee_user_id = gm.user_id
                      AND gi.public_id LIKE 'gstay\\_%'
                    ORDER BY gi.created_at DESC, gi.id DESC
                    LIMIT 1
                ) AS latest_stay_expires_at,
                (
                    SELECT inviter.display_name
                    FROM group_invitations gi
                    JOIN users inviter ON inviter.id = gi.inviter_user_id
                    WHERE gi.group_id = gm.group_id
                      AND gi.invitee_user_id = gm.user_id
                      AND gi.public_id LIKE 'gstay\\_%'
                    ORDER BY gi.created_at DESC, gi.id DESC
                    LIMIT 1
                ) AS latest_stay_inviter_name,
                (
                    SELECT MAX(gae.created_at)
                    FROM group_admin_events gae
                    WHERE gae.group_id = gm.group_id
                      AND gae.subject_user_id = gm.user_id
                      AND gae.event_type = 'guest.stay_declined'
                ) AS last_stay_declined_at,
                (
                    SELECT gp.issued_to_user_id
                    FROM group_guest_passes gp
                    WHERE gp.group_id = gm.group_id
                      AND gp.guest_user_id = gm.user_id
                      AND gp.status = 'used'
                    ORDER BY COALESCE(gp.used_at, gp.created_at) DESC, gp.id DESC
                    LIMIT 1
                ) AS guest_pass_referrer_id,
                (
                    SELECT owner.display_name
                    FROM group_guest_passes gp
                    JOIN users owner ON owner.id = gp.issued_to_user_id
                    WHERE gp.group_id = gm.group_id
                      AND gp.guest_user_id = gm.user_id
                      AND gp.status = 'used'
                    ORDER BY COALESCE(gp.used_at, gp.created_at) DESC, gp.id DESC
                    LIMIT 1
                ) AS guest_pass_referrer_name
            FROM group_memberships gm
            JOIN users u
              ON u.id = gm.user_id
             AND u.status = 'active'
            LEFT JOIN profiles p ON p.user_id = u.id
            JOIN social_groups g
              ON g.id = gm.group_id
             AND g.status = 'active'
            LEFT JOIN users membership_inviter ON membership_inviter.id = gm.invited_by
            JOIN event_attendance ea
              ON ea.user_id = gm.user_id
             AND ea.status IN ('checked_in','attended','left_early')
            JOIN events e
              ON e.id = ea.event_id
             AND e.group_id = gm.group_id
             AND e.status = 'completed'
            WHERE gm.membership_status = 'active'
              AND gm.group_role = 'guest'
            GROUP BY
                u.id,u.public_id,u.display_name,u.email,p.avatar_url,
                g.id,g.public_id,g.name,gm.joined_at,gm.invited_by,
                membership_inviter.display_name
            ORDER BY u.display_name, u.id, verified_events DESC, last_verified_at DESC, g.id
            LIMIT {$limit}";

    $rows = $pdo->query($sql)->fetchAll();
    foreach ($rows as &$row) {
        foreach (['guest_user_id','group_id','membership_inviter_id','verified_events','verified_events_180d','guest_pass_referrer_id'] as $key) {
            $row[$key] = isset($row[$key]) && $row[$key] !== null ? (int)$row[$key] : null;
        }
    }
    unset($row);
    return $rows;
}

/**
 * Transparent named-state rules. There is intentionally no scalar score.
 *
 * first_time       = exactly one verified completed Event across active Guest groups
 * returning        = repeat verified history, but best-fit group is not currently ready
 * conversion_ready = best-fit group has >=2 verified completed Events and >=1 in 180 days
 * hold              = active Invite-to-Stay or a Stay decline in the last 60 days
 *
 * @return array<int,array<string,mixed>>
 */
function coveted_guest_conversion_candidates(array $admin, int $limit = 160, ?PDO $pdo = null): array
{
    $pdo = coveted_guest_conversion_require_admin($admin, $pdo);
    $rows = coveted_guest_conversion_evidence_rows($pdo, min(500, max($limit * 3, 120)));
    $byGuest = [];

    foreach ($rows as $row) {
        $guestId = (int)$row['guest_user_id'];
        if (!isset($byGuest[$guestId])) {
            $byGuest[$guestId] = [
                'guest_user_id' => $guestId,
                'guest_ref' => (string)$row['guest_ref'],
                'display_name' => (string)$row['display_name'],
                'email' => (string)$row['email'],
                'avatar_url' => (string)($row['avatar_url'] ?? ''),
                'total_verified_events' => 0,
                'groups' => [],
            ];
        }

        $latestStayStatus = trim((string)($row['latest_stay_status'] ?? ''));
        $latestStayExpires = trim((string)($row['latest_stay_expires_at'] ?? ''));
        $pendingStay = $latestStayStatus === 'pending'
            && ($latestStayExpires === '' || (strtotime($latestStayExpires) ?: 0) > time());
        $declinedAt = trim((string)($row['last_stay_declined_at'] ?? ''));
        $recentDecline = $declinedAt !== ''
            && (strtotime($declinedAt) ?: 0) >= time() - (60 * 86400);

        $referrerName = trim((string)($row['guest_pass_referrer_name'] ?? ''));
        $referrerId = (int)($row['guest_pass_referrer_id'] ?? 0);
        $referrerSource = $referrerId > 0 ? 'guest_pass_owner' : '';
        if ($referrerId < 1 && (int)($row['membership_inviter_id'] ?? 0) > 0) {
            $referrerId = (int)$row['membership_inviter_id'];
            $referrerName = trim((string)($row['membership_inviter_name'] ?? ''));
            $referrerSource = 'group_membership_inviter';
        }

        $groupEvidence = [
            'group_id' => (int)$row['group_id'],
            'group_ref' => (string)$row['group_ref'],
            'group_name' => (string)$row['group_name'],
            'guest_joined_at' => (string)($row['guest_joined_at'] ?? ''),
            'verified_events' => (int)$row['verified_events'],
            'verified_events_180d' => (int)$row['verified_events_180d'],
            'last_verified_at' => (string)($row['last_verified_at'] ?? ''),
            'pending_stay_invite' => $pendingStay,
            'latest_stay_status' => $latestStayStatus,
            'latest_stay_ref' => (string)($row['latest_stay_ref'] ?? ''),
            'latest_stay_created_at' => (string)($row['latest_stay_created_at'] ?? ''),
            'last_stay_declined_at' => $declinedAt,
            'recent_stay_decline' => $recentDecline,
            'stay_inviter_name' => (string)($row['latest_stay_inviter_name'] ?? ''),
            'referrer' => $referrerId > 0 ? [
                'user_id' => $referrerId,
                'display_name' => $referrerName,
                'source' => $referrerSource,
            ] : null,
        ];
        $byGuest[$guestId]['groups'][] = $groupEvidence;
        $byGuest[$guestId]['total_verified_events'] += (int)$row['verified_events'];
    }

    $candidates = [];
    foreach ($byGuest as $candidate) {
        usort($candidate['groups'], static function (array $a, array $b): int {
            $count = ((int)$b['verified_events']) <=> ((int)$a['verified_events']);
            if ($count !== 0) return $count;
            $recent = strcmp((string)$b['last_verified_at'], (string)$a['last_verified_at']);
            if ($recent !== 0) return $recent;
            return strcmp((string)$a['group_ref'], (string)$b['group_ref']);
        });

        $best = (array)$candidate['groups'][0];
        $holdGroups = array_values(array_filter(
            $candidate['groups'],
            static fn(array $g): bool => !empty($g['pending_stay_invite']) || !empty($g['recent_stay_decline'])
        ));
        $hasHold = count($holdGroups) > 0;
        $bestFitReady = (int)$best['verified_events'] >= 2 && (int)$best['verified_events_180d'] >= 1;

        if ($hasHold) {
            $state = 'hold';
            $title = 'Hold — no additional membership pressure';
            $detail = !empty($holdGroups[0]['pending_stay_invite'])
                ? 'An Invite to Stay is already active. Wait for the guest to respond before any additional membership outreach.'
                : 'A recent Invite-to-Stay decline is a pacing signal. Keep this guest out of conversion follow-up for 60 days from that decline.';
        } elseif ((int)$candidate['total_verified_events'] === 1) {
            $state = 'first_time';
            $title = 'First-time guest';
            $detail = 'This guest has one verified completed Event. Keep the experience relationship-first before considering membership conversion.';
        } elseif ($bestFitReady) {
            $state = 'conversion_ready';
            $title = 'Conversion-ready guest';
            $detail = 'Repeat verified participation is concentrated in one active group and includes recent attendance. System Admin may review an Invite to Stay.';
        } else {
            $state = 'returning';
            $title = 'Returning guest';
            $detail = 'This guest has repeat verified participation, but the evidence is not yet concentrated and recent enough for the conversion-ready rule.';
        }

        $candidate['state'] = $state;
        $candidate['title'] = $title;
        $candidate['detail'] = $detail;
        $candidate['best_fit_group'] = $best;
        $candidate['hold_groups'] = $holdGroups;
        $candidate['evidence'] = (int)$candidate['total_verified_events'] . ' verified completed Event'
            . ((int)$candidate['total_verified_events'] === 1 ? '' : 's')
            . ' across active Guest groups · best fit: ' . (string)$best['group_name']
            . ' (' . (int)$best['verified_events'] . ' verified, ' . (int)$best['verified_events_180d'] . ' in 180d)';
        $candidate['privacy'] = 'Private System Admin evidence only; no guest-value score, personality inference or public ranking.';
        $candidates[] = $candidate;
    }

    $stateOrder = ['conversion_ready' => 1, 'returning' => 2, 'first_time' => 3, 'hold' => 4];
    usort($candidates, static function (array $a, array $b) use ($stateOrder): int {
        $state = ($stateOrder[(string)$a['state']] ?? 9) <=> ($stateOrder[(string)$b['state']] ?? 9);
        if ($state !== 0) return $state;
        $recent = strcmp(
            (string)($b['best_fit_group']['last_verified_at'] ?? ''),
            (string)($a['best_fit_group']['last_verified_at'] ?? '')
        );
        if ($recent !== 0) return $recent;
        return strcmp((string)$a['display_name'], (string)$b['display_name']);
    });

    return array_slice($candidates, 0, max(1, min(300, $limit)));
}

/**
 * Aggregate-only Agent/Operations context. Guest identity intentionally does
 * not leave the authorized Guest Conversion workspace.
 *
 * @return array<string,mixed>
 */
function coveted_guest_conversion_agent_context(array $admin, ?PDO $pdo = null): array
{
    $pdo = coveted_guest_conversion_require_admin($admin, $pdo);
    $candidates = coveted_guest_conversion_candidates($admin, 240, $pdo);
    $counts = ['first_time' => 0, 'returning' => 0, 'conversion_ready' => 0, 'hold' => 0];
    foreach ($candidates as $candidate) {
        $state = (string)$candidate['state'];
        if (array_key_exists($state, $counts)) $counts[$state]++;
    }

    $recommendations = [];
    if ($counts['conversion_ready'] > 0) {
        $count = $counts['conversion_ready'];
        $recommendations[] = [
            'priority' => 2,
            'key' => 'guest-conversion-review',
            'category' => 'Membership',
            'title' => 'Review conversion-ready guests',
            'detail' => 'Repeat verified guest participation has produced membership follow-up candidates. Review identities and relationship paths only inside the private Guest Conversion workspace.',
            'evidence' => $count . ' conversion-ready guest' . ($count === 1 ? '' : 's') . ' meet the transparent repeat-attendance and recency rule.',
            'href' => '/admin/guest-conversions.php',
            'task_sync' => true,
        ];
    }

    return [
        'available' => true,
        'counts' => $counts,
        'attention' => $counts['conversion_ready'],
        'recommendations' => $recommendations,
        'privacy' => 'Aggregate counts only. Guest names, emails, referrers and event history are restricted to the System Admin Guest Conversion workspace.',
        'authority' => 'The Agent may recommend review only. Issuing an Invite to Stay requires an explicit System Admin approval, and membership changes only when the guest accepts the canonical invitation.',
    ];
}

/**
 * Explicit Admin approval wrapper. This intentionally calls the existing
 * canonical Invite-to-Stay service instead of updating group membership.
 *
 * @return array{candidate:array<string,mixed>,invitation:array{public_id:string,token:string,url:string}}
 */
function coveted_guest_conversion_approve_invite(
    array $admin,
    string $guestRef,
    string $groupRef,
    ?PDO $pdo = null
): array {
    $pdo = coveted_guest_conversion_require_admin($admin, $pdo);
    $guestRef = trim($guestRef);
    $groupRef = trim($groupRef);
    if ($guestRef === '' || strlen($guestRef) > 64 || $groupRef === '' || strlen($groupRef) > 64) {
        throw new InvalidArgumentException('Choose a conversion-ready guest and best-fit group.');
    }

    $candidate = null;
    foreach (coveted_guest_conversion_candidates($admin, 300, $pdo) as $row) {
        if ((string)$row['guest_ref'] === $guestRef || (string)$row['guest_user_id'] === $guestRef) {
            $candidate = $row;
            break;
        }
    }
    if (!$candidate) {
        throw new InvalidArgumentException('Guest conversion candidate is no longer available.');
    }
    if ((string)$candidate['state'] !== 'conversion_ready') {
        throw new InvalidArgumentException('That guest is not currently conversion-ready. Review the current evidence and pacing state.');
    }

    $best = (array)$candidate['best_fit_group'];
    if ((string)$best['group_ref'] !== $groupRef && (string)$best['group_id'] !== $groupRef) {
        throw new InvalidArgumentException('Invite to Stay must use the current evidence-based best-fit group.');
    }

    $group = coveted_group_by_ref((string)$best['group_ref']);
    if (!$group || (int)$group['id'] !== (int)$best['group_id'] || (string)$group['status'] !== 'active') {
        throw new InvalidArgumentException('Best-fit group is no longer active.');
    }

    $invitation = coveted_group_create_stay_invitation($group, $admin, (int)$candidate['guest_user_id']);
    return ['candidate' => $candidate, 'invitation' => $invitation];
}

/**
 * Provenance for a Member Journey after a guest personally accepts gstay_*.
 * This is read-only history; it does not influence access control.
 *
 * @return array<string,mixed>|null
 */
function coveted_guest_conversion_member_origin(int $userId, ?PDO $pdo = null): ?array
{
    if ($userId < 1) return null;
    $pdo ??= coveted_db();
    $stmt = $pdo->prepare(
        "SELECT
            gi.public_id AS invitation_ref,
            gi.group_id,
            gi.accepted_at,
            gi.created_at,
            g.public_id AS group_ref,
            g.name AS group_name,
            inviter.id AS inviter_user_id,
            inviter.display_name AS inviter_name
         FROM group_invitations gi
         JOIN social_groups g ON g.id = gi.group_id
         JOIN users inviter ON inviter.id = gi.inviter_user_id
         WHERE gi.invitee_user_id = ?
           AND gi.public_id LIKE 'gstay\\_%'
           AND gi.status = 'accepted'
         ORDER BY COALESCE(gi.accepted_at, gi.created_at) DESC, gi.id DESC
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $referrerStmt = $pdo->prepare(
        "SELECT owner.id, owner.display_name
         FROM group_guest_passes gp
         JOIN users owner ON owner.id = gp.issued_to_user_id
         WHERE gp.group_id = ?
           AND gp.guest_user_id = ?
           AND gp.status = 'used'
         ORDER BY COALESCE(gp.used_at, gp.created_at) DESC, gp.id DESC
         LIMIT 1"
    );
    $referrerStmt->execute([(int)$row['group_id'], $userId]);
    $referrer = $referrerStmt->fetch() ?: null;

    return [
        'source' => 'guest_invite_to_stay',
        'invitation_ref' => (string)$row['invitation_ref'],
        'group_ref' => (string)$row['group_ref'],
        'group_name' => (string)$row['group_name'],
        'converted_at' => (string)($row['accepted_at'] ?: $row['created_at']),
        'invited_by' => [
            'user_id' => (int)$row['inviter_user_id'],
            'display_name' => (string)$row['inviter_name'],
        ],
        'referrer' => $referrer ? [
            'user_id' => (int)$referrer['id'],
            'display_name' => (string)$referrer['display_name'],
            'source' => 'guest_pass_owner',
        ] : null,
    ];
}
