<?php
declare(strict_types=1);

require_once __DIR__ . '/guest_member_conversion.php';

/**
 * Referral / Network Growth is a derived, read-only evidence layer.
 *
 * It deliberately measures outcomes from canonical Guest Pass introductions
 * instead of invite volume. No referral score, popularity rank, personality
 * inference, or parallel membership state is persisted here.
 */
function coveted_network_growth_require_admin(array $admin, ?PDO $pdo = null): PDO
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin, $pdo)) {
        throw new InvalidArgumentException('Full System Sample Mode does not expose live Network Growth evidence.');
    }
    return $pdo;
}

/** @return array<int,array<string,mixed>> */
function coveted_network_growth_referral_rows(
    array $admin,
    ?string $groupRef = null,
    int $limit = 300,
    ?PDO $pdo = null
): array {
    $pdo = coveted_network_growth_require_admin($admin, $pdo);
    $groupRef = trim((string)$groupRef);
    if ($groupRef !== '' && strlen($groupRef) > 64) {
        throw new InvalidArgumentException('Group not found.');
    }

    $where = "gp.status='used'
              AND gp.guest_user_id IS NOT NULL
              AND g.status='active'
              AND guest.status<>'deleted'
              AND referrer.status<>'deleted'";
    $params = [];
    if ($groupRef !== '') {
        $where .= ' AND (g.public_id=? OR CAST(g.id AS CHAR)=?)';
        $params[] = $groupRef;
        $params[] = $groupRef;
    }

    $sql = "SELECT
                gp.id AS guest_pass_id,
                gp.public_id AS guest_pass_ref,
                gp.group_id,
                gp.issued_to_user_id AS referrer_user_id,
                referrer.public_id AS referrer_ref,
                referrer.display_name AS referrer_name,
                gp.guest_user_id,
                guest.public_id AS guest_ref,
                guest.display_name AS guest_name,
                guest.email AS guest_email,
                gp.used_at AS introduced_at,
                gi.public_id AS invitation_ref,
                gi.status AS invitation_status,
                g.public_id AS group_ref,
                g.name AS group_name,
                g.city AS group_city,
                gm.group_role AS current_group_role,
                gm.membership_status AS current_membership_status,
                stay.converted_at,
                (SELECT gi2.public_id
                 FROM group_invitations gi2
                 WHERE gi2.group_id=gp.group_id
                   AND gi2.invitee_user_id=gp.guest_user_id
                   AND gi2.public_id LIKE 'gstay\\_%'
                   AND gi2.status='accepted'
                 ORDER BY COALESCE(gi2.accepted_at,gi2.created_at) DESC,gi2.id DESC
                 LIMIT 1) AS conversion_invitation_ref,
                COUNT(DISTINCT e.id) AS verified_events,
                COUNT(DISTINCT CASE WHEN e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 DAY) THEN e.id END) AS verified_events_180d,
                MIN(e.starts_at) AS first_verified_at,
                MAX(e.starts_at) AS last_verified_at,
                COUNT(DISTINCT CASE WHEN stay.converted_at IS NOT NULL AND e.starts_at>stay.converted_at THEN e.id END) AS post_conversion_verified
            FROM group_guest_passes gp
            JOIN social_groups g ON g.id=gp.group_id
            JOIN users referrer ON referrer.id=gp.issued_to_user_id
            JOIN users guest ON guest.id=gp.guest_user_id
            LEFT JOIN group_invitations gi ON gi.id=gp.invitation_id
            LEFT JOIN group_memberships gm
              ON gm.group_id=gp.group_id AND gm.user_id=gp.guest_user_id
            LEFT JOIN (
                SELECT group_id,invitee_user_id,MAX(accepted_at) AS converted_at
                FROM group_invitations
                WHERE public_id LIKE 'gstay\\_%'
                  AND status='accepted'
                  AND invitee_user_id IS NOT NULL
                  AND accepted_at IS NOT NULL
                GROUP BY group_id,invitee_user_id
            ) stay
              ON stay.group_id=gp.group_id AND stay.invitee_user_id=gp.guest_user_id
            LEFT JOIN event_attendance ea
              ON ea.user_id=gp.guest_user_id
             AND ea.status IN ('checked_in','attended','left_early')
            LEFT JOIN events e
              ON e.id=ea.event_id
             AND e.group_id=gp.group_id
             AND e.status='completed'
            WHERE {$where}
            GROUP BY
                gp.id,gp.public_id,gp.group_id,gp.issued_to_user_id,
                referrer.public_id,referrer.display_name,
                gp.guest_user_id,guest.public_id,guest.display_name,guest.email,
                gp.used_at,gi.public_id,gi.status,
                g.public_id,g.name,g.city,
                gm.group_role,gm.membership_status,stay.converted_at
            ORDER BY g.name,g.id,COALESCE(gp.used_at,gp.created_at) DESC,gp.id DESC
            LIMIT " . max(1, min(1000, $limit));

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $guestCandidates = [];
    try {
        foreach (coveted_guest_conversion_candidates($admin, 300, $pdo) as $candidate) {
            $guestCandidates[(int)$candidate['guest_user_id']] = $candidate;
        }
    } catch (Throwable $e) {
        error_log('Network Growth Guest Conversion bridge unavailable: ' . $e->getMessage());
    }

    $now = time();
    foreach ($rows as &$row) {
        foreach (['guest_pass_id','group_id','referrer_user_id','guest_user_id','verified_events','verified_events_180d','post_conversion_verified'] as $key) {
            $row[$key] = (int)($row[$key] ?? 0);
        }

        $convertedAt = trim((string)($row['converted_at'] ?? ''));
        $row['converted'] = $convertedAt !== '';
        $row['repeat_participation'] = (int)$row['verified_events'] >= 2;
        $row['current_guest'] = (string)($row['current_membership_status'] ?? '') === 'active'
            && (string)($row['current_group_role'] ?? '') === 'guest';

        $candidate = $guestCandidates[(int)$row['guest_user_id']] ?? null;
        $candidateBest = is_array($candidate['best_fit_group'] ?? null) ? (array)$candidate['best_fit_group'] : [];
        $sameBestFit = $candidate && (int)($candidateBest['group_id'] ?? 0) === (int)$row['group_id'];
        $row['guest_conversion_state'] = $sameBestFit ? (string)($candidate['state'] ?? '') : '';
        $row['conversion_ready_here'] = $sameBestFit && (string)($candidate['state'] ?? '') === 'conversion_ready';
        $row['conversion_hold_here'] = $sameBestFit && (string)($candidate['state'] ?? '') === 'hold';

        $introducedTs = strtotime((string)($row['introduced_at'] ?? '')) ?: 0;
        $daysSinceIntroduction = $introducedTs > 0 ? max(0, (int)floor(($now - $introducedTs) / 86400)) : null;
        $row['days_since_introduction'] = $daysSinceIntroduction;
        $row['attendance_gap'] = (int)$row['verified_events'] === 0
            && $daysSinceIntroduction !== null
            && $daysSinceIntroduction >= 60;

        if ($row['converted'] && (int)$row['post_conversion_verified'] > 0) {
            $row['outcome'] = 'sustained_member';
            $row['outcome_label'] = 'Converted + returned';
        } elseif ($row['converted']) {
            $row['outcome'] = 'converted_member';
            $row['outcome_label'] = 'Converted member';
        } elseif ($row['repeat_participation']) {
            $row['outcome'] = 'repeat_participation';
            $row['outcome_label'] = 'Repeat participation';
        } elseif ((int)$row['verified_events'] > 0) {
            $row['outcome'] = 'verified_participation';
            $row['outcome_label'] = 'Verified participation';
        } else {
            $row['outcome'] = 'introduced';
            $row['outcome_label'] = 'Introduced';
        }

        $row['healthy_growth_evidence'] = $row['repeat_participation'] || $row['converted'];
        $row['privacy'] = 'Restricted System Admin relationship evidence. This is an outcome record, not a referrer or guest score.';
    }
    unset($row);

    return $rows;
}

/**
 * Build one transparent group evidence snapshot from referral rows.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<string,mixed>
 */
function coveted_network_growth_group_from_rows(array $group, array $rows): array
{
    $counts = [
        'introductions' => count($rows),
        'verified_participation' => 0,
        'repeat_participation' => 0,
        'conversions' => 0,
        'post_conversion_participation' => 0,
        'attendance_gaps' => 0,
        'conversion_ready' => 0,
        'active_guest_referrals' => 0,
    ];
    $referrers = [];
    $outcomeReferrers = [];
    foreach ($rows as $row) {
        if ((int)$row['verified_events'] > 0) $counts['verified_participation']++;
        if (!empty($row['repeat_participation'])) $counts['repeat_participation']++;
        if (!empty($row['converted'])) $counts['conversions']++;
        if ((int)$row['post_conversion_verified'] > 0) $counts['post_conversion_participation']++;
        if (!empty($row['attendance_gap'])) $counts['attendance_gaps']++;
        if (!empty($row['conversion_ready_here'])) $counts['conversion_ready']++;
        if (!empty($row['current_guest'])) $counts['active_guest_referrals']++;
        $referrerId = (int)$row['referrer_user_id'];
        if ($referrerId > 0) $referrers[$referrerId] = true;
        if ($referrerId > 0 && !empty($row['healthy_growth_evidence'])) $outcomeReferrers[$referrerId] = true;
    }
    $counts['referrers_with_introductions'] = count($referrers);
    $counts['referrers_with_healthy_outcomes'] = count($outcomeReferrers);

    if ($counts['introductions'] === 0) {
        $state = 'no_evidence';
        $stateLabel = 'No outcome evidence yet';
    } elseif ($counts['post_conversion_participation'] > 0) {
        $state = 'sustained_growth';
        $stateLabel = 'Sustained member growth';
    } elseif ($counts['conversions'] > 0 || $counts['repeat_participation'] > 0) {
        $state = 'healthy_growth';
        $stateLabel = 'Healthy growth evidence';
    } elseif ($counts['verified_participation'] > 0) {
        $state = 'relationship_building';
        $stateLabel = 'Relationship building';
    } else {
        $state = 'introduction_only';
        $stateLabel = 'Introductions awaiting evidence';
    }

    $groupRef = (string)($group['public_id'] ?? $group['group_ref'] ?? '');
    $groupName = (string)($group['name'] ?? $group['group_name'] ?? 'Group');
    $href = '/admin/network-growth.php?group=' . rawurlencode($groupRef);
    $recommendations = [];

    if ($counts['conversion_ready'] > 0) {
        $count = $counts['conversion_ready'];
        $recommendations[] = [
            'priority' => 2,
            'key' => 'network-growth-conversion-' . $groupRef,
            'category' => 'Growth',
            'title' => 'Review referral-driven membership opportunities in ' . $groupName,
            'detail' => 'Existing Guest Pass introductions have developed repeat verified participation and now meet the transparent Guest Conversion rule. Review the relationship path before any membership invitation.',
            'evidence' => $count . ' referred guest' . ($count === 1 ? '' : 's') . ' currently meet the conversion-ready rule.',
            'href' => $href,
            'task_sync' => true,
        ];
    }
    if ($counts['attendance_gaps'] > 0) {
        $count = $counts['attendance_gaps'];
        $recommendations[] = [
            'priority' => 2,
            'key' => 'network-growth-arrival-gap-' . $groupRef,
            'category' => 'Growth',
            'title' => 'Review referral arrival gaps in ' . $groupName,
            'detail' => 'Some accepted Guest Pass introductions have not produced verified completed-Event attendance after 60 days. Review fit and relationship context instead of increasing invite volume.',
            'evidence' => $count . ' used Guest Pass introduction' . ($count === 1 ? ' has' : 's have') . ' no verified attendance after 60 days.',
            'href' => $href,
            'task_sync' => true,
        ];
    }

    return [
        'group' => [
            'id' => (int)($group['id'] ?? $group['group_id'] ?? 0),
            'public_id' => $groupRef,
            'name' => $groupName,
            'city' => (string)($group['city'] ?? $group['group_city'] ?? ''),
        ],
        'state' => $state,
        'state_label' => $stateLabel,
        'counts' => $counts,
        'referrals' => $rows,
        'recommendations' => $recommendations,
        'privacy' => 'Exact referrer/guest relationship paths are restricted to System Admin. Group-level Agent context contains counts only and no member or guest identities.',
        'authority' => 'Network Growth never sends outreach, issues Guest Passes, creates invitations, or changes membership. System Admin uses existing canonical workflows for any approved action.',
    ];
}

/** @return array<int,array<string,mixed>> */
function coveted_network_growth_groups(array $admin, ?PDO $pdo = null): array
{
    $pdo = coveted_network_growth_require_admin($admin, $pdo);
    $groups = $pdo->query(
        "SELECT id,public_id,name,city
         FROM social_groups
         WHERE status='active'
         ORDER BY name,id"
    )->fetchAll();
    $rows = coveted_network_growth_referral_rows($admin, null, 1000, $pdo);
    $byGroup = [];
    foreach ($rows as $row) $byGroup[(int)$row['group_id']][] = $row;

    $result = [];
    foreach ($groups as $group) {
        $result[] = coveted_network_growth_group_from_rows($group, $byGroup[(int)$group['id']] ?? []);
    }
    return $result;
}

/** @return array<string,mixed> */
function coveted_network_growth_group_snapshot(array $admin, string $groupRef, ?PDO $pdo = null): array
{
    $pdo = coveted_network_growth_require_admin($admin, $pdo);
    $groupRef = trim($groupRef);
    if ($groupRef === '' || strlen($groupRef) > 64) throw new InvalidArgumentException('Group not found.');

    $stmt = $pdo->prepare(
        "SELECT id,public_id,name,city
         FROM social_groups
         WHERE status='active' AND (public_id=? OR CAST(id AS CHAR)=?)
         LIMIT 1"
    );
    $stmt->execute([$groupRef, $groupRef]);
    $group = $stmt->fetch();
    if (!$group) throw new InvalidArgumentException('Active group not found.');

    return coveted_network_growth_group_from_rows(
        $group,
        coveted_network_growth_referral_rows($admin, (string)$group['public_id'], 500, $pdo)
    );
}

/**
 * Aggregate-only Operations / Admin Agent context.
 *
 * @return array<string,mixed>
 */
function coveted_network_growth_agent_context(array $admin, int $limit = 30, ?PDO $pdo = null): array
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin, $pdo)) {
        return ['available'=>false,'reason'=>'sample_mode','groups'=>[],'summary'=>[],'recommendations'=>[],'attention'=>0];
    }

    $groups = coveted_network_growth_groups($admin, $pdo);
    $summary = [
        'groups_with_evidence' => 0,
        'introductions' => 0,
        'verified_participation' => 0,
        'repeat_participation' => 0,
        'conversions' => 0,
        'post_conversion_participation' => 0,
        'attendance_gaps' => 0,
        'conversion_ready' => 0,
    ];
    $compact = [];
    $recommendations = [];
    $attention = 0;

    foreach ($groups as $snapshot) {
        $counts = (array)$snapshot['counts'];
        if ((int)$counts['introductions'] > 0) $summary['groups_with_evidence']++;
        foreach (['introductions','verified_participation','repeat_participation','conversions','post_conversion_participation','attendance_gaps','conversion_ready'] as $key) {
            $summary[$key] += (int)($counts[$key] ?? 0);
        }
        if ((int)$counts['attendance_gaps'] > 0 || (int)$counts['conversion_ready'] > 0) $attention++;

        // Broad Agent context intentionally contains no referrer, guest, email,
        // user-id, Guest Pass, or invitation identity.
        $compact[] = [
            'group_ref' => (string)$snapshot['group']['public_id'],
            'group' => (string)$snapshot['group']['name'],
            'state' => (string)$snapshot['state'],
            'introductions' => (int)$counts['introductions'],
            'verified_participation' => (int)$counts['verified_participation'],
            'repeat_participation' => (int)$counts['repeat_participation'],
            'conversions' => (int)$counts['conversions'],
            'post_conversion_participation' => (int)$counts['post_conversion_participation'],
            'attendance_gaps' => (int)$counts['attendance_gaps'],
            'conversion_ready' => (int)$counts['conversion_ready'],
            'href' => '/admin/network-growth.php?group=' . rawurlencode((string)$snapshot['group']['public_id']),
        ];
        foreach ((array)$snapshot['recommendations'] as $recommendation) $recommendations[] = $recommendation;
    }

    usort($recommendations, static function (array $a, array $b): int {
        $priority = ((int)($a['priority'] ?? 3)) <=> ((int)($b['priority'] ?? 3));
        return $priority !== 0 ? $priority : strcmp((string)($a['key'] ?? ''), (string)($b['key'] ?? ''));
    });

    return [
        'available' => true,
        'summary' => $summary,
        'groups' => array_slice($compact, 0, max(1, min(100, $limit))),
        'recommendations' => array_slice($recommendations, 0, 12),
        'attention' => $attention,
        'privacy' => 'Aggregate group-level referral outcomes only. Exact member/guest relationship paths remain inside restricted System Admin workspaces.',
        'authority' => 'The Agent may recommend a review, but outreach, Guest Pass issuance, invitations and membership decisions remain explicit Admin actions through existing canonical services.',
        'measurement' => 'Healthy network growth means verified attendance, repeat participation, conversion or post-conversion participation—not raw invitation volume.',
    ];
}

/**
 * Read-only referral provenance for a converted member's Member Journey.
 *
 * @return array<string,mixed>|null
 */
function coveted_network_growth_member_origin(int $userId, ?PDO $pdo = null): ?array
{
    if ($userId < 1) return null;
    $pdo ??= coveted_db();
    $origin = coveted_guest_conversion_member_origin($userId, $pdo);
    if (!$origin) return null;

    $groupStmt = $pdo->prepare('SELECT id FROM social_groups WHERE public_id=? LIMIT 1');
    $groupStmt->execute([(string)$origin['group_ref']]);
    $groupId = (int)($groupStmt->fetchColumn() ?: 0);
    if ($groupId < 1) return null;

    $passStmt = $pdo->prepare(
        "SELECT gp.public_id AS guest_pass_ref,gp.used_at,
                owner.id AS referrer_user_id,owner.public_id AS referrer_ref,owner.display_name AS referrer_name
         FROM group_guest_passes gp
         JOIN users owner ON owner.id=gp.issued_to_user_id
         WHERE gp.group_id=? AND gp.guest_user_id=? AND gp.status='used'
         ORDER BY COALESCE(gp.used_at,gp.created_at) DESC,gp.id DESC
         LIMIT 1"
    );
    $passStmt->execute([$groupId, $userId]);
    $pass = $passStmt->fetch() ?: null;

    $convertedAt = (string)$origin['converted_at'];
    $attendanceStmt = $pdo->prepare(
        "SELECT
            COUNT(DISTINCT CASE WHEN e.starts_at<=? THEN e.id END) AS pre_conversion_verified,
            COUNT(DISTINCT CASE WHEN e.starts_at>? THEN e.id END) AS post_conversion_verified,
            MAX(e.starts_at) AS last_verified_at
         FROM event_attendance ea
         JOIN events e ON e.id=ea.event_id
         WHERE ea.user_id=?
           AND ea.status IN ('checked_in','attended','left_early')
           AND e.group_id=?
           AND e.status='completed'"
    );
    $attendanceStmt->execute([$convertedAt, $convertedAt, $userId, $groupId]);
    $attendance = $attendanceStmt->fetch() ?: [];
    $pre = (int)($attendance['pre_conversion_verified'] ?? 0);
    $post = (int)($attendance['post_conversion_verified'] ?? 0);

    return [
        'source' => 'guest_pass_referral',
        'group_ref' => (string)$origin['group_ref'],
        'group_name' => (string)$origin['group_name'],
        'introduced_at' => $pass ? (string)($pass['used_at'] ?? '') : '',
        'converted_at' => $convertedAt,
        'guest_pass_ref' => $pass ? (string)$pass['guest_pass_ref'] : null,
        'referrer' => $pass ? [
            'user_id' => (int)$pass['referrer_user_id'],
            'public_id' => (string)$pass['referrer_ref'],
            'display_name' => (string)$pass['referrer_name'],
        ] : (is_array($origin['referrer'] ?? null) ? $origin['referrer'] : null),
        'pre_conversion_verified' => $pre,
        'post_conversion_verified' => $post,
        'last_verified_at' => (string)($attendance['last_verified_at'] ?? ''),
        'outcome' => $post > 0 ? 'sustained_member' : 'converted_member',
        'outcome_label' => $post > 0 ? 'Converted + returned' : 'Converted member',
        'privacy' => 'Historical referral provenance is visible only in restricted Admin relationship/member workspaces and is never a member or referrer score.',
    ];
}
