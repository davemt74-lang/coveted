<?php
declare(strict_types=1);

require_once __DIR__ . '/businesses.php';
require_once __DIR__ . '/artists.php';
require_once __DIR__ . '/return_engine.php';
require_once __DIR__ . '/venue_relationships.php';

/** @return array<string,string> */
function coveted_outcome_periods(): array
{
    return [
        '30' => 'Last 30 days',
        '90' => 'Last 90 days',
        '365' => 'Last 12 months',
        'all' => 'All time',
    ];
}

/** @return array{key:string,label:string,since:string} */
function coveted_outcome_period(string $period): array
{
    $period = strtolower(trim($period));
    $periods = coveted_outcome_periods();
    if (!isset($periods[$period])) {
        $period = '90';
    }

    $since = match ($period) {
        '30' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-30 days')->format('Y-m-d H:i:s'),
        '365' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-365 days')->format('Y-m-d H:i:s'),
        'all' => '1970-01-01 00:00:00',
        default => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-90 days')->format('Y-m-d H:i:s'),
    };

    return [
        'key' => $period,
        'label' => $periods[$period],
        'since' => $since,
    ];
}

function coveted_outcome_rate(int $numerator, int $denominator): float
{
    if ($denominator < 1) {
        return 0.0;
    }
    return round(($numerator / $denominator) * 100, 1);
}

/**
 * Business partner analytics are aggregate-only. Member identities never leave
 * this service. Return counts delegate to the canonical return eligibility
 * logic instead of introducing a second visit definition.
 *
 * @return array<string,mixed>
 */
function coveted_business_outcomes(array $actor, int $businessId, string $period = '90'): array
{
    if ($businessId < 1 || !coveted_business_actor_can_view($actor, $businessId)) {
        throw new InvalidArgumentException('Business Admin access is required.');
    }

    $businessStmt = coveted_db()->prepare('SELECT id, public_id, name, status FROM businesses WHERE id = ? LIMIT 1');
    $businessStmt->execute([$businessId]);
    $business = $businessStmt->fetch();
    if (!$business) {
        throw new InvalidArgumentException('Business not found.');
    }

    $window = coveted_outcome_period($period);
    $since = $window['since'];
    $pdo = coveted_db();

    $eventStmt = $pdo->prepare(
        "SELECT
            COUNT(DISTINCT e.id) AS completed_events,
            COUNT(ea.user_id) AS verified_visits,
            COUNT(DISTINCT ea.user_id) AS unique_attendees,
            COUNT(DISTINCT e.group_id) AS groups_hosted
         FROM event_locations el
         JOIN locations l ON l.id = el.location_id AND l.business_id = ?
         JOIN events e ON e.id = el.event_id
                       AND e.status = 'completed'
                       AND e.starts_at >= ?
         LEFT JOIN event_attendance ea
           ON ea.event_id = e.id
          AND ea.status IN ('checked_in','attended','left_early')"
    );
    $eventStmt->execute([$businessId, $since]);
    $events = $eventStmt->fetch() ?: [];

    $repeatStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM (
             SELECT ea.user_id
             FROM event_attendance ea
             JOIN events e ON e.id = ea.event_id AND e.status = 'completed' AND e.starts_at >= ?
             JOIN event_locations el ON el.event_id = e.id
             JOIN locations l ON l.id = el.location_id AND l.business_id = ?
             WHERE ea.status IN ('checked_in','attended','left_early')
             GROUP BY ea.user_id
             HAVING COUNT(DISTINCT e.id) >= 2
         ) repeat_visitors"
    );
    $repeatStmt->execute([$since, $businessId]);
    $repeatAttendees = (int)$repeatStmt->fetchColumn();

    $benefitStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS issued,
            COUNT(DISTINCT ri.user_id) AS members_reached,
            SUM(ri.viewed_at IS NOT NULL) AS viewed
         FROM reward_issuances ri
         JOIN reward_templates rt ON rt.id = ri.reward_template_id
         WHERE rt.business_id = ?
           AND ri.status <> 'cancelled'
           AND ri.issued_at >= ?"
    );
    $benefitStmt->execute([$businessId, $since]);
    $benefits = $benefitStmt->fetch() ?: [];

    // Claim conversion uses the same issuance cohort as the denominator. This
    // keeps the rate bounded and avoids treating an old issuance claimed today
    // as conversion for a newer issuance cohort.
    $claimStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS claims,
            COUNT(DISTINCT ri.user_id) AS claiming_members,
            SUM(rc.status = 'refunded') AS refunds
         FROM reward_claims rc
         JOIN reward_issuances ri ON ri.id = rc.reward_issuance_id
         JOIN reward_templates rt ON rt.id = ri.reward_template_id
         JOIN locations l ON l.id = rc.location_id
         WHERE rt.business_id = ?
           AND l.business_id = ?
           AND ri.issued_at >= ?"
    );
    $claimStmt->execute([$businessId, $businessId, $since]);
    $claims = $claimStmt->fetch() ?: [];

    // Return activity is an activity metric, so its selected period is the
    // claim date. Classification itself remains owned by return_engine.php.
    $returnClaimStmt = $pdo->prepare(
        "SELECT rc.public_id
         FROM reward_claims rc
         JOIN reward_issuances ri ON ri.id = rc.reward_issuance_id
         JOIN reward_templates rt ON rt.id = ri.reward_template_id
         JOIN locations l ON l.id = rc.location_id
         WHERE rt.business_id = ?
           AND l.business_id = ?
           AND ri.event_id IS NOT NULL
           AND rc.claimed_at >= ?
         ORDER BY rc.id ASC"
    );
    $returnClaimStmt->execute([$businessId, $businessId, $since]);

    $verifiedReturns = 0;
    $guestReturns = 0;
    $returningMembers = [];
    $returnsByLocation = [];
    foreach ($returnClaimStmt->fetchAll() as $claimRow) {
        try {
            $context = coveted_return_claim_context((string)$claimRow['public_id']);
        } catch (InvalidArgumentException) {
            continue;
        }
        if (empty($context['eligible'])) {
            continue;
        }

        $verifiedReturns++;
        $userId = (int)$context['user_id'];
        if ($userId > 0) {
            $returningMembers[$userId] = true;
        }
        if (!empty($context['guest_origin'])) {
            $guestReturns++;
        }
        $locationId = (int)$context['return_location_id'];
        if ($locationId > 0) {
            if (!isset($returnsByLocation[$locationId])) {
                $returnsByLocation[$locationId] = ['returns' => 0, 'guest_returns' => 0];
            }
            $returnsByLocation[$locationId]['returns']++;
            if (!empty($context['guest_origin'])) {
                $returnsByLocation[$locationId]['guest_returns']++;
            }
        }
    }

    $locationStmt = $pdo->prepare(
        "SELECT
            l.id,
            l.public_id,
            l.name,
            l.city,
            l.region,
            l.status,
            COALESCE(ev.completed_events, 0) AS completed_events,
            COALESCE(ev.verified_visits, 0) AS verified_visits,
            COALESCE(ev.unique_attendees, 0) AS unique_attendees,
            COALESCE(cl.claims, 0) AS claims,
            COALESCE(cl.refunds, 0) AS refunds
         FROM locations l
         LEFT JOIN (
             SELECT
                el.location_id,
                COUNT(DISTINCT e.id) AS completed_events,
                COUNT(ea.user_id) AS verified_visits,
                COUNT(DISTINCT ea.user_id) AS unique_attendees
             FROM event_locations el
             JOIN events e ON e.id = el.event_id
                           AND e.status = 'completed'
                           AND e.starts_at >= ?
             LEFT JOIN event_attendance ea
               ON ea.event_id = e.id
              AND ea.status IN ('checked_in','attended','left_early')
             GROUP BY el.location_id
         ) ev ON ev.location_id = l.id
         LEFT JOIN (
             SELECT
                rc.location_id,
                COUNT(*) AS claims,
                SUM(rc.status = 'refunded') AS refunds
             FROM reward_claims rc
             JOIN reward_issuances ri ON ri.id = rc.reward_issuance_id
             JOIN reward_templates rt ON rt.id = ri.reward_template_id
             WHERE rt.business_id = ?
               AND rc.claimed_at >= ?
             GROUP BY rc.location_id
         ) cl ON cl.location_id = l.id
         WHERE l.business_id = ?
           AND l.status <> 'archived'
         ORDER BY verified_visits DESC, claims DESC, l.name"
    );
    $locationStmt->execute([$since, $businessId, $since, $businessId]);
    $locations = $locationStmt->fetchAll();
    foreach ($locations as &$location) {
        $locationId = (int)$location['id'];
        $location['completed_events'] = (int)$location['completed_events'];
        $location['verified_visits'] = (int)$location['verified_visits'];
        $location['unique_attendees'] = (int)$location['unique_attendees'];
        $location['claims'] = (int)$location['claims'];
        $location['refunds'] = (int)$location['refunds'];
        $location['verified_returns'] = (int)($returnsByLocation[$locationId]['returns'] ?? 0);
        $location['guest_returns'] = (int)($returnsByLocation[$locationId]['guest_returns'] ?? 0);
    }
    unset($location);

    $campaignStmt = $pdo->prepare(
        "SELECT
            c.id,
            c.public_id,
            c.title,
            c.trigger_key,
            c.status,
            rt.title AS reward_title,
            rt.reward_type,
            rt.claim_mode,
            (SELECT COUNT(*)
             FROM reward_issuances ri
             WHERE ri.campaign_id = c.id
               AND ri.status <> 'cancelled'
               AND ri.issued_at >= ?) AS issued_count,
            (SELECT COUNT(DISTINCT ri.user_id)
             FROM reward_issuances ri
             WHERE ri.campaign_id = c.id
               AND ri.status <> 'cancelled'
               AND ri.issued_at >= ?) AS members_reached,
            (SELECT COUNT(*)
             FROM reward_issuances ri
             WHERE ri.campaign_id = c.id
               AND ri.status <> 'cancelled'
               AND ri.viewed_at IS NOT NULL
               AND ri.issued_at >= ?) AS viewed_count,
            (SELECT COUNT(*)
             FROM reward_claims rc
             JOIN reward_issuances ri ON ri.id = rc.reward_issuance_id
             WHERE ri.campaign_id = c.id
               AND ri.issued_at >= ?) AS claim_count,
            (SELECT COUNT(*)
             FROM reward_claims rc
             JOIN reward_issuances ri ON ri.id = rc.reward_issuance_id
             WHERE ri.campaign_id = c.id
               AND rc.status = 'refunded'
               AND ri.issued_at >= ?) AS refund_count
         FROM campaigns c
         JOIN reward_templates rt ON rt.id = c.reward_template_id
         WHERE c.owner_type = 'business'
           AND c.business_id = ?
           AND c.status <> 'archived'
         ORDER BY issued_count DESC, c.created_at DESC, c.id DESC"
    );
    $campaignStmt->execute([$since, $since, $since, $since, $since, $businessId]);
    $campaigns = $campaignStmt->fetchAll();
    foreach ($campaigns as &$campaign) {
        $campaign['issued_count'] = (int)$campaign['issued_count'];
        $campaign['members_reached'] = (int)$campaign['members_reached'];
        $campaign['viewed_count'] = (int)$campaign['viewed_count'];
        $campaign['claim_count'] = (int)$campaign['claim_count'];
        $campaign['refund_count'] = (int)$campaign['refund_count'];
        $campaign['use_count'] = $campaign['claim_mode'] === 'location_code'
            ? $campaign['claim_count']
            : $campaign['viewed_count'];
        $campaign['use_rate'] = coveted_outcome_rate((int)$campaign['use_count'], (int)$campaign['issued_count']);
    }
    unset($campaign);

    $relationships = coveted_venue_relationships_for_business($actor, $businessId);
    $relationshipCounts = [
        'new' => 0,
        'event_venue' => 0,
        'partner' => 0,
        'preferred_partner' => 0,
        'home_venue' => 0,
    ];
    foreach ($relationships as $relationship) {
        $status = (string)$relationship['relationship_status'];
        if (isset($relationshipCounts[$status])) {
            $relationshipCounts[$status]++;
        }
    }

    $issued = (int)($benefits['issued'] ?? 0);
    $claimCount = (int)($claims['claims'] ?? 0);
    $uniqueAttendees = (int)($events['unique_attendees'] ?? 0);

    return [
        'business' => $business,
        'period' => $window,
        'summary' => [
            'completed_events' => (int)($events['completed_events'] ?? 0),
            'verified_visits' => (int)($events['verified_visits'] ?? 0),
            'unique_attendees' => $uniqueAttendees,
            'repeat_attendees' => $repeatAttendees,
            'groups_hosted' => (int)($events['groups_hosted'] ?? 0),
            'benefits_issued' => $issued,
            'members_reached' => (int)($benefits['members_reached'] ?? 0),
            'benefits_viewed' => (int)($benefits['viewed'] ?? 0),
            'claims' => $claimCount,
            'claiming_members' => (int)($claims['claiming_members'] ?? 0),
            'refunds' => (int)($claims['refunds'] ?? 0),
            'verified_returns' => $verifiedReturns,
            'guest_returns' => $guestReturns,
            'returning_members' => count($returningMembers),
            'repeat_rate' => coveted_outcome_rate($repeatAttendees, $uniqueAttendees),
            'claim_rate' => coveted_outcome_rate($claimCount, $issued),
            'return_rate' => coveted_outcome_rate(count($returningMembers), $uniqueAttendees),
        ],
        'locations' => $locations,
        'campaigns' => $campaigns,
        'relationship_counts' => $relationshipCounts,
    ];
}

/**
 * Artist analytics expose only aggregate audience/event/reward results. No
 * attendee identity list or private event feedback is selected.
 *
 * @return array<string,mixed>
 */
function coveted_artist_outcomes(array $actor, int $artistId, string $period = '90'): array
{
    if ($artistId < 1 || !coveted_artist_actor_has_partner_approval($actor)) {
        throw new InvalidArgumentException('Artist Partner access is required.');
    }

    $permission = coveted_artist_actor_permission($actor, $artistId);
    if ($permission === 'none') {
        throw new InvalidArgumentException('You cannot view this artist.');
    }

    $artistStmt = coveted_db()->prepare('SELECT id, public_id, artist_name, status FROM artist_profiles WHERE id = ? LIMIT 1');
    $artistStmt->execute([$artistId]);
    $artist = $artistStmt->fetch();
    if (!$artist) {
        throw new InvalidArgumentException('Artist not found.');
    }

    $window = coveted_outcome_period($period);
    $since = $window['since'];
    $pdo = coveted_db();

    $eventStmt = $pdo->prepare(
        "SELECT
            COUNT(DISTINCT e.id) AS completed_appearances,
            COUNT(ea.user_id) AS verified_audience_visits,
            COUNT(DISTINCT ea.user_id) AS unique_audience,
            COUNT(DISTINCT e.group_id) AS groups_reached
         FROM event_artists art
         JOIN events e ON e.id = art.event_id
                       AND e.status = 'completed'
                       AND e.starts_at >= ?
         LEFT JOIN event_attendance ea
           ON ea.event_id = e.id
          AND ea.status IN ('checked_in','attended','left_early')
         WHERE art.artist_id = ?"
    );
    $eventStmt->execute([$since, $artistId]);
    $events = $eventStmt->fetch() ?: [];

    $repeatStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM (
             SELECT ea.user_id
             FROM event_artists art
             JOIN events e ON e.id = art.event_id
                           AND e.status = 'completed'
                           AND e.starts_at >= ?
             JOIN event_attendance ea
               ON ea.event_id = e.id
              AND ea.status IN ('checked_in','attended','left_early')
             WHERE art.artist_id = ?
             GROUP BY ea.user_id
             HAVING COUNT(DISTINCT e.id) >= 2
         ) repeat_audience"
    );
    $repeatStmt->execute([$since, $artistId]);
    $repeatAudience = (int)$repeatStmt->fetchColumn();

    $rewardStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS delivered,
            COUNT(DISTINCT ri.user_id) AS recipients,
            SUM(ri.viewed_at IS NOT NULL) AS opened
         FROM reward_issuances ri
         JOIN reward_templates rt ON rt.id = ri.reward_template_id
         WHERE rt.artist_id = ?
           AND ri.status <> 'cancelled'
           AND ri.issued_at >= ?"
    );
    $rewardStmt->execute([$artistId, $since]);
    $rewards = $rewardStmt->fetch() ?: [];

    $appearanceStmt = $pdo->prepare(
        "SELECT
            e.public_id,
            e.title,
            e.event_type,
            e.starts_at,
            e.timezone,
            e.status,
            g.public_id AS group_public_id,
            g.name AS group_name,
            art.appearance_type,
            COUNT(DISTINCT CASE
                WHEN ea.status IN ('checked_in','attended','left_early') THEN ea.user_id END) AS verified_attendance,
            (SELECT COUNT(*)
             FROM reward_issuances ri
             JOIN reward_templates rt ON rt.id = ri.reward_template_id
             WHERE ri.event_id = e.id
               AND rt.artist_id = ?
               AND ri.status <> 'cancelled'
               AND ri.issued_at >= ?) AS rewards_delivered,
            (SELECT COUNT(*)
             FROM reward_issuances ri
             JOIN reward_templates rt ON rt.id = ri.reward_template_id
             WHERE ri.event_id = e.id
               AND rt.artist_id = ?
               AND ri.status <> 'cancelled'
               AND ri.viewed_at IS NOT NULL
               AND ri.issued_at >= ?) AS rewards_opened
         FROM event_artists art
         JOIN events e ON e.id = art.event_id
         JOIN social_groups g ON g.id = e.group_id
         LEFT JOIN event_attendance ea ON ea.event_id = e.id
         WHERE art.artist_id = ?
           AND e.starts_at >= ?
           AND e.status = 'completed'
         GROUP BY
            e.id, e.public_id, e.title, e.event_type, e.starts_at, e.timezone,
            e.status, g.public_id, g.name, art.appearance_type
         ORDER BY e.starts_at DESC, e.id DESC
         LIMIT 100"
    );
    $appearanceStmt->execute([$artistId, $since, $artistId, $since, $artistId, $since]);
    $appearances = $appearanceStmt->fetchAll();
    foreach ($appearances as &$appearance) {
        $appearance['verified_attendance'] = (int)$appearance['verified_attendance'];
        $appearance['rewards_delivered'] = (int)$appearance['rewards_delivered'];
        $appearance['rewards_opened'] = (int)$appearance['rewards_opened'];
        $appearance['open_rate'] = coveted_outcome_rate(
            (int)$appearance['rewards_opened'],
            (int)$appearance['rewards_delivered']
        );
    }
    unset($appearance);

    $campaignStmt = $pdo->prepare(
        "SELECT
            c.id,
            c.public_id,
            c.title,
            c.trigger_key,
            c.status,
            rt.title AS reward_title,
            rt.reward_type,
            (SELECT COUNT(*)
             FROM reward_issuances ri
             WHERE ri.campaign_id = c.id
               AND ri.status <> 'cancelled'
               AND ri.issued_at >= ?) AS delivered_count,
            (SELECT COUNT(DISTINCT ri.user_id)
             FROM reward_issuances ri
             WHERE ri.campaign_id = c.id
               AND ri.status <> 'cancelled'
               AND ri.issued_at >= ?) AS recipients,
            (SELECT COUNT(*)
             FROM reward_issuances ri
             WHERE ri.campaign_id = c.id
               AND ri.status <> 'cancelled'
               AND ri.viewed_at IS NOT NULL
               AND ri.issued_at >= ?) AS opened_count
         FROM campaigns c
         JOIN reward_templates rt ON rt.id = c.reward_template_id
         WHERE c.owner_type = 'artist'
           AND c.artist_id = ?
           AND c.status <> 'archived'
         ORDER BY delivered_count DESC, c.created_at DESC, c.id DESC"
    );
    $campaignStmt->execute([$since, $since, $since, $artistId]);
    $campaigns = $campaignStmt->fetchAll();
    foreach ($campaigns as &$campaign) {
        $campaign['delivered_count'] = (int)$campaign['delivered_count'];
        $campaign['recipients'] = (int)$campaign['recipients'];
        $campaign['opened_count'] = (int)$campaign['opened_count'];
        $campaign['open_rate'] = coveted_outcome_rate((int)$campaign['opened_count'], (int)$campaign['delivered_count']);
    }
    unset($campaign);

    $relationshipStmt = $pdo->prepare(
        "SELECT agr.relationship_status
         FROM artist_group_relationships agr
         WHERE agr.artist_id = ?"
    );
    $relationshipStmt->execute([$artistId]);
    $relationships = $relationshipStmt->fetchAll();

    $relationshipCounts = ['new' => 0, 'featured' => 0, 'partner' => 0, 'preferred_partner' => 0];
    foreach ($relationships as $relationship) {
        $status = (string)$relationship['relationship_status'];
        if (isset($relationshipCounts[$status])) {
            $relationshipCounts[$status]++;
        }
    }

    $uniqueAudience = (int)($events['unique_audience'] ?? 0);
    $delivered = (int)($rewards['delivered'] ?? 0);
    $opened = (int)($rewards['opened'] ?? 0);

    return [
        'artist' => $artist,
        'permission' => $permission,
        'period' => $window,
        'summary' => [
            'completed_appearances' => (int)($events['completed_appearances'] ?? 0),
            'verified_audience_visits' => (int)($events['verified_audience_visits'] ?? 0),
            'unique_audience' => $uniqueAudience,
            'repeat_audience' => $repeatAudience,
            'groups_reached' => (int)($events['groups_reached'] ?? 0),
            'rewards_delivered' => $delivered,
            'reward_recipients' => (int)($rewards['recipients'] ?? 0),
            'rewards_opened' => $opened,
            'repeat_rate' => coveted_outcome_rate($repeatAudience, $uniqueAudience),
            'open_rate' => coveted_outcome_rate($opened, $delivered),
        ],
        'appearances' => $appearances,
        'campaigns' => $campaigns,
        'relationship_counts' => $relationshipCounts,
    ];
}
