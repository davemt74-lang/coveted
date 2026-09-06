<?php
declare(strict_types=1);

require_once __DIR__ . '/invite_profile.php';

/** @return array<int,string> */
function coveted_invite_crm_intelligence_interest_keys(array $request): array
{
    try {
        $decoded = json_decode((string)($request['event_interests_json'] ?? '[]'), true, 32, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? coveted_invite_normalize_interests($decoded) : [];
    } catch (Throwable) {
        return [];
    }
}

/** @return array<int,string> */
function coveted_invite_crm_intelligence_profile_list(array $profile, string $key): array
{
    return coveted_invite_profile_decode_list($profile[$key] ?? '[]');
}

function coveted_invite_crm_intelligence_age_days(string $value, ?DateTimeImmutable $now = null): int
{
    try {
        $created = coveted_utc_datetime($value);
    } catch (Throwable) {
        return 9999;
    }
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $seconds = max(0, $now->getTimestamp() - $created->getTimestamp());
    return (int)floor($seconds / 86400);
}

/**
 * Deterministic work-priority score for one canonical Invite CRM record.
 *
 * This is deliberately NOT a prediction of a person's value, intent, income,
 * character or likelihood to buy. It only prioritizes Admin workflow using
 * current CRM status, recency and explicit form completeness. Sensitive fields
 * such as gender and all free-text sentiment are excluded from scoring.
 *
 * @return array{
 *   score:int,band:string,label:string,next_action:string,next_action_key:string,
 *   reasons:array<int,string>,age_days:int,follow_up_due:bool,is_newsletter:bool,
 *   active:bool,supported_city:bool,interest_count:int,goal_count:int
 * }
 */
function coveted_invite_crm_intelligence_record(
    array $request,
    array $profile = [],
    ?DateTimeImmutable $now = null
): array {
    $status = strtolower(trim((string)($request['status'] ?? 'new')));
    $isNewsletter = trim((string)($request['how_heard'] ?? '')) === 'Newsletter signup';
    $active = in_array($status, ['new', 'contacted', 'qualified'], true);
    $ageDays = coveted_invite_crm_intelligence_age_days((string)($request['created_at'] ?? ''), $now);
    $activityAgeDays = coveted_invite_crm_intelligence_age_days(
        (string)($request['reviewed_at'] ?? $request['updated_at'] ?? $request['created_at'] ?? ''),
        $now
    );
    $interests = coveted_invite_crm_intelligence_interest_keys($request);
    $goals = coveted_invite_crm_intelligence_profile_list($profile, 'goals_json');
    $sources = coveted_invite_crm_intelligence_profile_list($profile, 'source_keys_json');
    $links = coveted_invite_profile_decode_links($profile['social_links_json'] ?? null);
    $supportedCity = (int)($request['city_id'] ?? 0) > 0;
    $hasPhone = trim((string)($request['phone'] ?? '')) !== '';
    $hasSource = !empty($sources) || (
        trim((string)($request['how_heard'] ?? '')) !== ''
        && !$isNewsletter
    );

    if (!$active) {
        return [
            'score' => 0,
            'band' => 'closed',
            'label' => $status === 'converted' ? 'Converted' : 'Closed',
            'next_action' => $status === 'converted' ? 'Member converted' : 'No active follow-up',
            'next_action_key' => 'none',
            'reasons' => [],
            'age_days' => $ageDays,
            'follow_up_due' => false,
            'is_newsletter' => $isNewsletter,
            'active' => false,
            'supported_city' => $supportedCity,
            'interest_count' => count($interests),
            'goal_count' => count($goals),
        ];
    }

    $score = match ($status) {
        'qualified' => 62,
        'contacted' => 48,
        default => 42,
    };
    $reasons = [];

    if ($status === 'qualified') {
        $reasons[] = 'Already qualified';
    } elseif ($status === 'contacted') {
        $reasons[] = 'Outreach already started';
    } else {
        $reasons[] = 'New CRM submission';
    }

    if ($ageDays <= 1) {
        $score += 12;
        $reasons[] = 'Submitted within 24 hours';
    } elseif ($ageDays <= 3) {
        $score += 9;
        $reasons[] = 'Submitted within 3 days';
    } elseif ($ageDays <= 7) {
        $score += 5;
    } elseif ($status === 'new' && $ageDays >= 14) {
        $score += 8;
        $reasons[] = 'New record is aging';
    }

    if ($supportedCity) {
        $score += 8;
        $reasons[] = 'Matches a supported city';
    }
    if ($hasPhone) {
        $score += 5;
        $reasons[] = 'Phone provided';
    }
    if (count($interests) >= 3) {
        $score += 8;
        $reasons[] = 'Multiple event interests selected';
    } elseif (count($interests) >= 1) {
        $score += 4;
    }
    if (count($goals) >= 2) {
        $score += 6;
        $reasons[] = 'Member goals completed';
    } elseif (count($goals) === 1) {
        $score += 3;
    }
    if ($hasSource) {
        $score += 3;
    }
    if ($links) {
        $score += 2;
    }

    $followUpDue = $status === 'contacted' && $activityAgeDays >= 3;
    if ($followUpDue) {
        $score += min(12, 5 + max(0, $activityAgeDays - 3));
        $reasons[] = 'Follow-up is due';
    }

    if ($isNewsletter) {
        $score = min($score, 48);
        $reasons = array_values(array_filter(
            $reasons,
            static fn(string $reason): bool => $reason !== 'New CRM submission'
        ));
        array_unshift($reasons, 'Newsletter nurture record');
    }

    $score = max(0, min(100, $score));
    if ($score >= 75) {
        $band = 'high';
        $label = 'High priority';
    } elseif ($score >= 50) {
        $band = 'medium';
        $label = 'Medium priority';
    } else {
        $band = 'low';
        $label = $isNewsletter ? 'Nurture' : 'Low priority';
    }

    if ($status === 'qualified') {
        $nextActionKey = 'convert';
        $nextAction = 'Review for conversion';
    } elseif ($followUpDue) {
        $nextActionKey = 'follow_up';
        $nextAction = 'Follow up now';
    } elseif ($status === 'contacted') {
        $nextActionKey = 'await';
        $nextAction = 'Continue outreach';
    } elseif ($isNewsletter) {
        $nextActionKey = 'nurture';
        $nextAction = 'Nurture subscriber';
    } elseif ($score >= 75) {
        $nextActionKey = 'review_now';
        $nextAction = 'Review now';
    } else {
        $nextActionKey = 'review';
        $nextAction = 'Review submission';
    }

    return [
        'score' => $score,
        'band' => $band,
        'label' => $label,
        'next_action' => $nextAction,
        'next_action_key' => $nextActionKey,
        'reasons' => array_slice(array_values(array_unique($reasons)), 0, 5),
        'age_days' => $ageDays,
        'follow_up_due' => $followUpDue,
        'is_newsletter' => $isNewsletter,
        'active' => true,
        'supported_city' => $supportedCity,
        'interest_count' => count($interests),
        'goal_count' => count($goals),
    ];
}

/**
 * Load the active canonical CRM rows needed for aggregate intelligence.
 * Deliberately excludes names, emails, phone numbers, notes, messages, gender
 * and social-link values from the returned summary path.
 *
 * @return array<int,array<string,mixed>>
 */
function coveted_invite_crm_intelligence_active_rows(?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    coveted_invite_profile_ensure_schema($pdo);
    $stmt = $pdo->query(
        "SELECT ir.id, ir.status, ir.city_id, ir.event_interests_json, ir.how_heard,
                CASE WHEN NULLIF(TRIM(ir.phone), '') IS NULL THEN 0 ELSE 1 END AS has_phone,
                ir.created_at, ir.updated_at, ir.reviewed_at,
                ip.goals_json, ip.source_keys_json,
                CASE WHEN ip.social_links_json IS NULL THEN 0 ELSE 1 END AS has_links
         FROM invite_requests ir
         LEFT JOIN invite_request_profiles ip ON ip.invite_request_id = ir.id
         WHERE ir.status IN ('new','contacted','qualified')
         ORDER BY ir.id ASC"
    );
    return $stmt->fetchAll();
}

/** @return array<string,int> */
function coveted_invite_crm_intelligence_summary(?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $summary = [
        'active' => 0,
        'high_priority' => 0,
        'medium_priority' => 0,
        'low_priority' => 0,
        'follow_up_due' => 0,
        'conversion_ready' => 0,
        'aging_new' => 0,
        'newsletter_nurture' => 0,
        'supported_city' => 0,
    ];
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    foreach (coveted_invite_crm_intelligence_active_rows($pdo) as $row) {
        // Rehydrate only boolean/completeness signals needed by the same scorer.
        $request = [
            'status' => (string)$row['status'],
            'city_id' => (int)$row['city_id'],
            'event_interests_json' => (string)$row['event_interests_json'],
            'how_heard' => (string)($row['how_heard'] ?? ''),
            'phone' => !empty($row['has_phone']) ? 'present' : '',
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
            'reviewed_at' => $row['reviewed_at'],
        ];
        $profile = [
            'goals_json' => (string)($row['goals_json'] ?? '[]'),
            'source_keys_json' => (string)($row['source_keys_json'] ?? '[]'),
            'social_links_json' => !empty($row['has_links']) ? '{"present":"https://example.invalid"}' : null,
        ];
        $intel = coveted_invite_crm_intelligence_record($request, $profile, $now);

        $summary['active']++;
        if ($intel['band'] === 'high') {
            $summary['high_priority']++;
        } elseif ($intel['band'] === 'medium') {
            $summary['medium_priority']++;
        } else {
            $summary['low_priority']++;
        }
        if ($intel['follow_up_due']) {
            $summary['follow_up_due']++;
        }
        if ($intel['next_action_key'] === 'convert') {
            $summary['conversion_ready']++;
        }
        if ((string)$row['status'] === 'new' && (int)$intel['age_days'] >= 7) {
            $summary['aging_new']++;
        }
        if ($intel['is_newsletter']) {
            $summary['newsletter_nurture']++;
        }
        if ($intel['supported_city']) {
            $summary['supported_city']++;
        }
    }

    return $summary;
}
