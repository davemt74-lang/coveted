<?php
declare(strict_types=1);

require_once __DIR__ . '/venue_relationships.php';
require_once __DIR__ . '/daily_events.php';

function coveted_partner_opportunity_key(string $kind, int $businessId, int $groupId, int $locationId): string
{
    return 'partner-' . $kind . '-' . substr(hash('sha256', $businessId . '|' . $groupId . '|' . $locationId), 0, 24);
}

/** @return array<string,array<string,int|float|string|null>> */
function coveted_partner_daily_metrics(PDO $pdo, int $businessId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            e.group_id,
            deo.location_id,
            COUNT(*) AS daily_events,
            SUM(e.status = 'completed') AS completed_daily_events,
            SUM(e.status IN ('published','closed') AND COALESCE(e.ends_at,e.starts_at) >= UTC_TIMESTAMP()) AS upcoming_daily_events,
            SUM(deo.reward_unlocked_at IS NOT NULL) AS thresholds_unlocked,
            SUM(COALESCE(att.verified_attendance,0)) AS verified_attendance,
            SUM(deo.attendance_threshold) AS threshold_total,
            SUM(COALESCE(issued.rewards_issued,0)) AS rewards_issued,
            MAX(CASE WHEN e.status = 'completed' THEN e.starts_at END) AS latest_daily_completed_at
         FROM daily_event_opportunities deo
         JOIN events e ON e.id = deo.event_id
         LEFT JOIN (
             SELECT event_id, COUNT(*) AS verified_attendance
             FROM event_attendance
             WHERE status IN ('checked_in','attended','left_early')
             GROUP BY event_id
         ) att ON att.event_id = e.id
         LEFT JOIN (
             SELECT event_id, campaign_id, COUNT(*) AS rewards_issued
             FROM reward_issuances
             WHERE status <> 'cancelled'
             GROUP BY event_id, campaign_id
         ) issued ON issued.event_id = e.id AND issued.campaign_id = deo.reward_campaign_id
         WHERE deo.business_id = ?
           AND deo.status <> 'archived'
           AND e.status <> 'cancelled'
         GROUP BY e.group_id, deo.location_id"
    );
    $stmt->execute([$businessId]);

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[(int)$row['group_id'] . ':' . (int)$row['location_id']] = $row;
    }
    return $result;
}

/** @return array{locations:array<int,int>,global_employee:int} */
function coveted_partner_claim_code_metrics(PDO $pdo, int $businessId): array
{
    $stmt = $pdo->prepare(
        "SELECT location_id, code_type, COUNT(*) AS total
         FROM business_claim_codes
         WHERE business_id = ? AND status = 'active'
         GROUP BY location_id, code_type"
    );
    $stmt->execute([$businessId]);

    $locations = [];
    $globalEmployee = 0;
    foreach ($stmt->fetchAll() as $row) {
        if ($row['location_id'] === null && (string)$row['code_type'] === 'employee') {
            $globalEmployee += (int)$row['total'];
            continue;
        }
        if ($row['location_id'] !== null) {
            $locationId = (int)$row['location_id'];
            $locations[$locationId] = (int)($locations[$locationId] ?? 0) + (int)$row['total'];
        }
    }

    return ['locations' => $locations, 'global_employee' => $globalEmployee];
}

/** @return array{locations:array<int,int>,global:int} */
function coveted_partner_return_campaign_metrics(PDO $pdo, int $businessId): array
{
    $stmt = $pdo->prepare(
        "SELECT location_id, COUNT(*) AS total
         FROM campaigns
         WHERE owner_type = 'business'
           AND business_id = ?
           AND trigger_key IN ('return_visit','guest_return')
           AND status <> 'archived'
         GROUP BY location_id"
    );
    $stmt->execute([$businessId]);

    $locations = [];
    $global = 0;
    foreach ($stmt->fetchAll() as $row) {
        if ($row['location_id'] === null) {
            $global += (int)$row['total'];
        } else {
            $locations[(int)$row['location_id']] = (int)$row['total'];
        }
    }
    return ['locations' => $locations, 'global' => $global];
}

/**
 * @return array{generated_at:string,business_ref:string,business_name:string,privacy:string,action_policy:string,counts:array<string,int>,recommendations:array<int,array<string,mixed>>}
 */
function coveted_partner_opportunities_for_business(array $actor, int $businessId, ?PDO $pdo = null): array
{
    if (!coveted_business_actor_can_view($actor, $businessId)) {
        throw new InvalidArgumentException('You cannot view partner opportunities for that business.');
    }

    $pdo ??= coveted_db();
    $businessStmt = $pdo->prepare("SELECT public_id,name FROM businesses WHERE id=? AND status<>'archived' LIMIT 1");
    $businessStmt->execute([$businessId]);
    $business = $businessStmt->fetch();
    if (!$business) {
        throw new InvalidArgumentException('Business not found.');
    }

    $relationships = coveted_venue_relationships_for_business($actor, $businessId);
    $daily = coveted_partner_daily_metrics($pdo, $businessId);
    $codes = coveted_partner_claim_code_metrics($pdo, $businessId);
    $returnCampaigns = coveted_partner_return_campaign_metrics($pdo, $businessId);
    $items = [];
    $isSystemAdmin = coveted_is_system_admin($actor);

    $add = static function (
        array &$items,
        int $priority,
        string $kind,
        string $title,
        string $detail,
        string $evidence,
        string $href,
        array $relationship,
        string $actionLabel
    ) use ($businessId): void {
        $groupId = (int)$relationship['group_id'];
        $locationId = (int)$relationship['location_id'];
        $items[] = [
            'key' => coveted_partner_opportunity_key($kind, $businessId, $groupId, $locationId),
            'priority' => max(1, min(3, $priority)),
            'kind' => $kind,
            'title' => $title,
            'detail' => $detail,
            'evidence' => $evidence,
            'href' => $href,
            'action_label' => $actionLabel,
            'business_ref' => (string)$relationship['business_ref'],
            'business_name' => (string)$relationship['business_name'],
            'group_ref' => (string)$relationship['group_public_id'],
            'group_name' => (string)$relationship['group_name'],
            'location_ref' => (string)$relationship['location_public_id'],
            'location_name' => (string)$relationship['location_name'],
            'relationship_status' => (string)$relationship['relationship_status'],
        ];
    };

    foreach ($relationships as $relationship) {
        $groupId = (int)$relationship['group_id'];
        $locationId = (int)$relationship['location_id'];
        $key = $groupId . ':' . $locationId;
        $dailyRow = (array)($daily[$key] ?? []);
        $dailyEvents = (int)($dailyRow['daily_events'] ?? 0);
        $completedDaily = (int)($dailyRow['completed_daily_events'] ?? 0);
        $upcomingDaily = (int)($dailyRow['upcoming_daily_events'] ?? 0);
        $dailyVerified = (int)($dailyRow['verified_attendance'] ?? 0);
        $thresholdTotal = (int)($dailyRow['threshold_total'] ?? 0);
        $thresholdsUnlocked = (int)($dailyRow['thresholds_unlocked'] ?? 0);
        $dailyRewards = (int)($dailyRow['rewards_issued'] ?? 0);
        $activeCodes = (int)($codes['locations'][$locationId] ?? 0) + (int)$codes['global_employee'];
        $activeReturnCampaigns = (int)($returnCampaigns['locations'][$locationId] ?? 0) + (int)$returnCampaigns['global'];

        $completedEvents = (int)$relationship['completed_events'];
        $upcomingEvents = (int)$relationship['upcoming_events'];
        $verifiedVisits = (int)$relationship['verified_visits'];
        $repeatAttendees = (int)$relationship['repeat_attendees'];
        $returnClaims = (int)$relationship['return_claims'];
        $claims = (int)$relationship['claims'];
        $benefitsEnabled = (int)$relationship['benefits_enabled'] === 1;
        $status = (string)$relationship['relationship_status'];
        $relationshipHref = '/venue-relationships.php?business=' . rawurlencode((string)$relationship['business_ref'])
            . '&group=' . rawurlencode((string)$relationship['group_public_id'])
            . '&location=' . rawurlencode((string)$relationship['location_public_id']);

        if ($upcomingDaily > 0 && $activeCodes < 1) {
            $add(
                $items, 1, 'restore_checkin', 'Restore Daily Event check-in readiness',
                'An upcoming Daily Event is assigned to this relationship, but the location has no active location or business-wide employee claim code. Restore a canonical claim-code path before members arrive.',
                $upcomingDaily . ' upcoming Daily Event' . ($upcomingDaily === 1 ? '' : 's') . '; 0 active check-in codes.',
                '/business.php?business=' . rawurlencode((string)$relationship['business_ref']) . '&tab=locations',
                $relationship,
                'Manage claim codes'
            );
        }

        if ($status === 'event_venue' && $completedEvents >= 2 && $verifiedVisits >= 8) {
            $add(
                $items, 2, 'review_partner_status', 'Review this venue for Partner status',
                'The venue has repeated completed-event history and meaningful verified attendance. Relationship status remains an intentional Admin decision; Coveted should recommend the review, never auto-promote it.',
                $completedEvents . ' completed events; ' . $verifiedVisits . ' verified visits.',
                $relationshipHref,
                $relationship,
                'Review relationship'
            );
        } elseif ($status === 'partner' && $completedEvents >= 3 && $repeatAttendees >= 2 && $returnClaims >= 1) {
            $add(
                $items, 2, 'review_preferred_status', 'Review this Partner for Preferred Partner status',
                'The relationship has repeat attendance plus verified return activity. Review whether this should become a Preferred Partner; no metric changes relationship status automatically.',
                $completedEvents . ' completed events; ' . $repeatAttendees . ' repeat attendees; ' . $returnClaims . ' return-linked claim' . ($returnClaims === 1 ? '' : 's') . '.',
                $relationshipHref,
                $relationship,
                'Review relationship'
            );
        } elseif ($status === 'preferred_partner' && $completedEvents >= 5 && $repeatAttendees >= 3 && $returnClaims >= 2) {
            $add(
                $items, 3, 'review_home_venue_status', 'Review whether this has become a Home Venue relationship',
                'This Preferred Partner has deep repeated usage and return activity. Home Venue should still be an explicit relationship decision based on the real group/venue fit, not an automatic score.',
                $completedEvents . ' completed events; ' . $repeatAttendees . ' repeat attendees; ' . $returnClaims . ' return-linked claims.',
                $relationshipHref,
                $relationship,
                'Review relationship'
            );
        }

        if ($benefitsEnabled && $completedEvents >= 1 && $verifiedVisits >= 5 && $upcomingEvents === 0) {
            $href = $isSystemAdmin
                ? '/admin/daily-events.php'
                : '/business-daily-events.php?business=' . rawurlencode((string)$relationship['business_ref']);
            $add(
                $items, 2, 'schedule_next_daily', 'Plan the next Daily Event with this partner',
                $isSystemAdmin
                    ? 'This benefit-enabled relationship has proven attendance but no upcoming gathering. Consider another Admin-created Daily Event using the same group × location relationship if the timing and reward economics make sense.'
                    : 'This benefit-enabled relationship has proven attendance but no upcoming gathering. Prepare the partner location, reward and claim-code readiness for a future Daily Event; Coveted System Admin retains event creation authority.',
                $verifiedVisits . ' verified visits across ' . $completedEvents . ' completed event' . ($completedEvents === 1 ? '' : 's') . '; no upcoming event.',
                $href,
                $relationship,
                $isSystemAdmin ? 'Plan Daily Event' : 'Review Daily Events'
            );
        }

        if ($completedDaily >= 2 && $thresholdTotal > 0 && $thresholdsUnlocked >= $completedDaily && $dailyVerified >= (int)ceil($thresholdTotal * 1.35)) {
            $averageVerified = (int)round($dailyVerified / max(1, $completedDaily));
            $averageThreshold = (int)round($thresholdTotal / max(1, $completedDaily));
            $add(
                $items, 3, 'raise_future_threshold', 'Consider a higher threshold on the next Daily Event',
                'Recent Daily Events at this relationship consistently cleared their group reward thresholds by a wide margin. Review a higher threshold for the next event rather than changing any completed event or retroactively altering earned rewards.',
                $completedDaily . ' completed Daily Events; average ' . $averageVerified . ' verified vs. ' . $averageThreshold . ' threshold; all observed thresholds unlocked.',
                $isSystemAdmin ? '/admin/daily-events.php' : $relationshipHref,
                $relationship,
                $isSystemAdmin ? 'Plan next threshold' : 'Review performance'
            );
        }

        if ($benefitsEnabled && $verifiedVisits >= 5 && $returnClaims === 0 && $activeReturnCampaigns === 0) {
            $add(
                $items, 2, 'create_return_value', 'Add a return-visit value layer',
                'This relationship has delivered verified visits but has no observed return-linked claims and no active/non-archived Business return-visit or guest-return campaign at this location. Consider a return Benefit Program after reviewing partner economics.',
                $verifiedVisits . ' verified visits; 0 return-linked claims; 0 return-visit campaigns for this location.',
                $isSystemAdmin ? '/admin/benefit-programs.php' : '/business.php?business=' . rawurlencode((string)$relationship['business_ref']) . '&tab=campaigns',
                $relationship,
                $isSystemAdmin ? 'Review Benefit Programs' : 'Review campaigns'
            );
        }

        $lastCompleted = trim((string)($relationship['last_completed_event_at'] ?? ''));
        $lastCompletedTs = $lastCompleted !== '' ? strtotime($lastCompleted) : false;
        if (
            in_array($status, ['partner','preferred_partner','home_venue'], true)
            && $completedEvents > 0
            && $upcomingEvents === 0
            && $lastCompletedTs !== false
            && $lastCompletedTs < time() - (45 * 86400)
        ) {
            $days = max(45, (int)floor((time() - $lastCompletedTs) / 86400));
            $add(
                $items, 2, 'reengage_dormant', 'Re-engage this established partner relationship',
                'The venue is an established partner with successful history but no upcoming event. Review the relationship before the historical momentum goes cold.',
                $completedEvents . ' completed events; last completed gathering was about ' . $days . ' days ago; no upcoming event.',
                $relationshipHref,
                $relationship,
                'Open relationship'
            );
        }

        if ($completedDaily > 0 && $dailyRewards >= 5 && $claims === 0) {
            $add(
                $items, 3, 'review_reward_use', 'Review post-event reward use',
                'Daily Event group rewards have been issued here but this relationship currently shows no verified claims. Check reward fit, expiration, claim instructions and return timing before repeating the same offer.',
                $dailyRewards . ' Daily Event reward issuances; 0 relationship claims.',
                '/business-daily-events.php?business=' . rawurlencode((string)$relationship['business_ref']),
                $relationship,
                'Review Daily Event rewards'
            );
        }

        if ($dailyEvents > 0 && $upcomingDaily === 0 && $activeCodes < 1) {
            $add(
                $items, 3, 'prepare_future_checkin', 'Prepare claim-code readiness before the next Daily Event',
                'This location has Daily Event history but no active check-in code today. Restore a location or business-wide employee claim code before another Daily Event is assigned.',
                $dailyEvents . ' Daily Event' . ($dailyEvents === 1 ? '' : 's') . ' in relationship history; 0 active check-in codes.',
                '/business.php?business=' . rawurlencode((string)$relationship['business_ref']) . '&tab=locations',
                $relationship,
                'Manage claim codes'
            );
        }
    }

    $deduped = [];
    foreach ($items as $item) {
        $deduped[(string)$item['key']] = $item;
    }
    $items = array_values($deduped);
    usort($items, static function (array $a, array $b): int {
        $priority = (int)$a['priority'] <=> (int)$b['priority'];
        if ($priority !== 0) return $priority;
        return strcmp((string)$a['key'], (string)$b['key']);
    });

    $counts = ['total' => count($items), 'p1' => 0, 'p2' => 0, 'p3' => 0];
    foreach ($items as $item) {
        $priority = max(1, min(3, (int)$item['priority']));
        $counts['p' . $priority]++;
    }

    return [
        'generated_at' => gmdate('Y-m-d H:i:s'),
        'business_ref' => (string)$business['public_id'],
        'business_name' => (string)$business['name'],
        'privacy' => 'Relationship recommendations use aggregate operational metrics only. Member names, emails, private Loyalty balances and person-level scoring are not exposed.',
        'action_policy' => 'Recommendations are read-only. Relationship status, event creation, reward configuration and campaign changes remain explicit authorized actions.',
        'counts' => $counts,
        'recommendations' => array_slice($items, 0, 24),
    ];
}

/**
 * @return array{generated_at:string,privacy:string,action_policy:string,counts:array<string,int>,recommendations:array<int,array<string,mixed>>,issues:array<int,string>}
 */
function coveted_partner_opportunities_agent_context(array $admin, int $limit = 12, ?PDO $pdo = null): array
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    $pdo ??= coveted_db();
    $limit = max(1, min(24, $limit));
    $recommendations = [];
    $issues = [];

    foreach (array_slice(coveted_businesses_for_actor($admin), 0, 50) as $business) {
        try {
            $snapshot = coveted_partner_opportunities_for_business($admin, (int)$business['id'], $pdo);
            foreach ((array)$snapshot['recommendations'] as $item) {
                if (is_array($item)) $recommendations[] = $item;
            }
        } catch (Throwable $e) {
            $issues[] = 'business:' . (string)($business['public_id'] ?? $business['id'] ?? 'unknown');
            error_log('Partner opportunity context unavailable for business: ' . $e->getMessage());
        }
    }

    usort($recommendations, static function (array $a, array $b): int {
        $priority = (int)($a['priority'] ?? 3) <=> (int)($b['priority'] ?? 3);
        if ($priority !== 0) return $priority;
        return strcmp((string)($a['key'] ?? ''), (string)($b['key'] ?? ''));
    });
    $recommendations = array_slice($recommendations, 0, $limit);

    $counts = ['total' => count($recommendations), 'p1' => 0, 'p2' => 0, 'p3' => 0];
    foreach ($recommendations as $item) {
        $priority = max(1, min(3, (int)($item['priority'] ?? 2)));
        $counts['p' . $priority]++;
    }

    return [
        'generated_at' => gmdate('Y-m-d H:i:s'),
        'privacy' => 'Aggregate partner relationship intelligence only; no member identities or private Loyalty balances.',
        'action_policy' => 'Read-only recommendations. The Admin Agent may explain and prioritize these opportunities but must not treat them as authorization to mutate relationship, event, reward or campaign state.',
        'counts' => $counts,
        'recommendations' => $recommendations,
        'issues' => array_values(array_unique($issues)),
    ];
}
