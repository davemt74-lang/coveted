<?php
declare(strict_types=1);

/**
 * Self-scoped onboarding context for the authenticated member Agent.
 *
 * This module deliberately derives guidance only from the member's own profile,
 * invitations, RSVPs, active group memberships and reward issuances. It does not
 * read Admin member intelligence or other members' private relationship state.
 *
 * @return array<string,mixed>
 */
function coveted_member_onboarding_agent_snapshot(array $user, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $userId = (int)($user['id'] ?? 0);

    $profile = [
        'display_name'=>trim((string)($user['display_name'] ?? '')),
        'city'=>'',
        'bio'=>'',
        'avatar_url'=>'',
        'interests'=>[],
        'gathering_styles'=>[],
    ];

    try {
        $stmt = $pdo->prepare(
            'SELECT bio,city,avatar_url,interests_json FROM profiles WHERE user_id=? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row) {
            $profile['city'] = trim((string)($row['city'] ?? ''));
            $profile['bio'] = trim((string)($row['bio'] ?? ''));
            $profile['avatar_url'] = trim((string)($row['avatar_url'] ?? ''));
            $decoded = json_decode((string)($row['interests_json'] ?? ''), true);
            if (is_array($decoded)) {
                $profile['interests'] = array_values(array_filter(array_map('strval', (array)($decoded['interests'] ?? []))));
                $profile['gathering_styles'] = array_values(array_filter(array_map('strval', (array)($decoded['gathering_styles'] ?? []))));
            }
        }
    } catch (Throwable) {
        // Onboarding remains usable when optional profile storage is unavailable.
    }

    $profileChecks = [
        'name'=>$profile['display_name'] !== '',
        'city'=>$profile['city'] !== '',
        'bio'=>$profile['bio'] !== '',
        'photo'=>$profile['avatar_url'] !== '',
        'interests'=>count($profile['interests']) > 0,
        'gathering_style'=>count($profile['gathering_styles']) > 0,
    ];
    $profileCompleted = count(array_filter($profileChecks));
    $profilePercent = (int)round(($profileCompleted / count($profileChecks)) * 100);
    $missingProfile = [];
    foreach ($profileChecks as $key=>$complete) {
        if (!$complete) $missingProfile[] = $key;
    }

    $activeGroups = [];
    try {
        $stmt = $pdo->prepare(
            "SELECT g.public_id,g.name
             FROM group_memberships gm
             JOIN social_groups g ON g.id=gm.group_id
             WHERE gm.user_id=? AND gm.membership_status='active' AND g.status='active'
             ORDER BY g.name ASC LIMIT 8"
        );
        $stmt->execute([$userId]);
        $activeGroups = array_map(static fn(array $row): array => [
            'group_ref'=>(string)$row['public_id'],
            'name'=>(string)$row['name'],
        ], $stmt->fetchAll());
    } catch (Throwable) {}

    $pastAttended = 0;
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM event_rsvps er
             JOIN events e ON e.id=er.event_id
             WHERE er.user_id=? AND er.response='attending' AND e.starts_at<UTC_TIMESTAMP()"
        );
        $stmt->execute([$userId]);
        $pastAttended = (int)$stmt->fetchColumn();
    } catch (Throwable) {}

    $nextEvent = null;
    try {
        $stmt = $pdo->prepare(
            "SELECT e.public_id,e.title,e.starts_at,g.name AS group_name
             FROM event_rsvps er
             JOIN events e ON e.id=er.event_id
             JOIN social_groups g ON g.id=e.group_id
             WHERE er.user_id=? AND er.response='attending'
               AND e.status IN ('published','closed') AND e.starts_at>=UTC_TIMESTAMP()
             ORDER BY e.starts_at ASC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row) {
            $nextEvent = [
                'event_ref'=>(string)$row['public_id'],
                'title'=>(string)$row['title'],
                'starts_at'=>(string)$row['starts_at'],
                'group'=>(string)$row['group_name'],
                'relationship'=>'attending',
                'action_url'=>'/my-events.php',
            ];
        }
    } catch (Throwable) {}

    if ($nextEvent === null) {
        try {
            $stmt = $pdo->prepare(
                "SELECT e.public_id,e.title,e.starts_at,g.name AS group_name
                 FROM event_invitations ei
                 JOIN events e ON e.id=ei.event_id
                 JOIN social_groups g ON g.id=e.group_id
                 WHERE ei.user_id=? AND ei.status='pending'
                   AND e.status='published' AND e.starts_at>UTC_TIMESTAMP()
                 ORDER BY e.starts_at ASC LIMIT 1"
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if ($row) {
                $nextEvent = [
                    'event_ref'=>(string)$row['public_id'],
                    'title'=>(string)$row['title'],
                    'starts_at'=>(string)$row['starts_at'],
                    'group'=>(string)$row['group_name'],
                    'relationship'=>'invited',
                    'action_url'=>'/invitations.php',
                ];
            }
        } catch (Throwable) {}
    }

    if ($nextEvent === null && $activeGroups) {
        try {
            $stmt = $pdo->prepare(
                "SELECT e.public_id,e.title,e.starts_at,g.name AS group_name
                 FROM group_memberships gm
                 JOIN social_groups g ON g.id=gm.group_id
                 JOIN events e ON e.group_id=g.id
                 WHERE gm.user_id=? AND gm.membership_status='active' AND g.status='active'
                   AND e.status='published' AND e.starts_at>UTC_TIMESTAMP()
                 ORDER BY e.starts_at ASC LIMIT 1"
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if ($row) {
                $nextEvent = [
                    'event_ref'=>(string)$row['public_id'],
                    'title'=>(string)$row['title'],
                    'starts_at'=>(string)$row['starts_at'],
                    'group'=>(string)$row['group_name'],
                    'relationship'=>'active_group',
                    'action_url'=>'/events.php',
                ];
            }
        } catch (Throwable) {}
    }

    $activeBenefits = 0;
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM reward_issuances
             WHERE user_id=? AND status NOT IN ('cancelled','expired')
               AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())"
        );
        $stmt->execute([$userId]);
        $activeBenefits = (int)$stmt->fetchColumn();
    } catch (Throwable) {}

    $milestones = [
        'profile_context'=>$profilePercent >= 67,
        'group_membership'=>count($activeGroups) > 0,
        'event_plan'=>$pastAttended > 0 || ($nextEvent !== null && ($nextEvent['relationship'] ?? '') === 'attending'),
        'first_shared_event'=>$pastAttended > 0,
    ];
    $milestoneCount = count(array_filter($milestones));
    $journeyPercent = (int)round(($milestoneCount / count($milestones)) * 100);

    if (!$milestones['profile_context']) {
        $stage = 'profile_setup';
        $headline = 'Build enough profile context for better Coveted recommendations.';
        $primaryAction = ['label'=>'Complete profile','url'=>'/profile.php'];
    } elseif (!$milestones['group_membership']) {
        $stage = 'find_group';
        $headline = 'Find a group that gives your first Coveted experience some context.';
        $primaryAction = ['label'=>'Explore groups','url'=>'/groups.php'];
    } elseif (!$milestones['event_plan']) {
        $stage = 'first_event';
        $headline = $nextEvent !== null
            ? 'Your next best step is to decide on an upcoming event.'
            : 'Choose a first event connected to your Coveted groups.';
        $primaryAction = [
            'label'=>$nextEvent !== null && ($nextEvent['relationship'] ?? '') === 'invited' ? 'Review invitation' : 'View events',
            'url'=>$nextEvent !== null ? (string)$nextEvent['action_url'] : '/events.php',
        ];
    } elseif (!$milestones['first_shared_event']) {
        $stage = 'prepare_first_event';
        $headline = 'Your first event is planned. The Agent can help you prepare for it.';
        $primaryAction = ['label'=>'My events','url'=>'/my-events.php'];
    } else {
        $stage = 'participating';
        $headline = 'You have shared-event history. Build on it through groups, benefits and private mutual reconnects.';
        $primaryAction = ['label'=>'Reconnect','url'=>'/reconnect.php'];
    }

    $recommendedActions = [];
    if ($missingProfile) {
        $recommendedActions[] = [
            'key'=>'profile',
            'label'=>'Add profile context',
            'reason'=>'Missing: ' . implode(', ', array_slice($missingProfile, 0, 4)),
            'url'=>'/profile.php',
        ];
    }
    if (!$activeGroups) {
        $recommendedActions[] = [
            'key'=>'groups',
            'label'=>'Explore groups',
            'reason'=>'Groups provide the social context for events and membership benefits.',
            'url'=>'/groups.php',
        ];
    }
    if ($nextEvent !== null) {
        $recommendedActions[] = [
            'key'=>'event',
            'label'=>($nextEvent['relationship'] ?? '') === 'invited' ? 'Review your next invitation' : 'Review your next event',
            'reason'=>(string)$nextEvent['title'] . ' · ' . (string)$nextEvent['group'],
            'url'=>(string)$nextEvent['action_url'],
        ];
    } elseif (!$milestones['event_plan']) {
        $recommendedActions[] = [
            'key'=>'event',
            'label'=>'Find your first event',
            'reason'=>'Choose from currently published Coveted events; the Agent will not invent availability.',
            'url'=>'/events.php',
        ];
    }
    if ($activeBenefits > 0) {
        $recommendedActions[] = [
            'key'=>'benefits',
            'label'=>'Review active benefits',
            'reason'=>$activeBenefits . ' active benefit' . ($activeBenefits === 1 ? '' : 's') . ' available on your account.',
            'url'=>'/benefits.php',
        ];
    }
    if ($pastAttended > 0) {
        $recommendedActions[] = [
            'key'=>'reconnect',
            'label'=>'Review private reconnect opportunities',
            'reason'=>'Reconnect is available only from completed events with verified attendance and stays private until mutual.',
            'url'=>'/reconnect.php',
        ];
    }

    $prompts = [];
    if (!$milestones['profile_context']) $prompts[] = 'What should I add to my profile first?';
    if ($nextEvent !== null && ($nextEvent['relationship'] ?? '') === 'invited') $prompts[] = 'Help me decide about my next invitation.';
    elseif ($nextEvent !== null && ($nextEvent['relationship'] ?? '') === 'attending') $prompts[] = 'Help me prepare for my next event.';
    elseif (!$milestones['event_plan']) $prompts[] = 'Help me find a good first Coveted event.';
    if (!$activeGroups) $prompts[] = 'How should I choose a Coveted group?';
    if ($activeBenefits > 0) $prompts[] = 'Which benefits or perks can I use?';
    if ($pastAttended > 0) $prompts[] = 'What can I do after my recent events?';

    return [
        'stage'=>$stage,
        'headline'=>$headline,
        'journey_percent'=>$journeyPercent,
        'profile'=>[
            'percent'=>$profilePercent,
            'missing'=>$missingProfile,
            'interest_count'=>count($profile['interests']),
            'gathering_style_count'=>count($profile['gathering_styles']),
        ],
        'milestones'=>$milestones,
        'active_groups'=>$activeGroups,
        'past_attended_events'=>$pastAttended,
        'next_event'=>$nextEvent,
        'active_benefits'=>$activeBenefits,
        'reconnect_available'=>$pastAttended > 0,
        'primary_action'=>$primaryAction,
        'recommended_actions'=>array_slice($recommendedActions, 0, 6),
        'starter_prompts'=>array_slice(array_values(array_unique($prompts)), 0, 5),
        'privacy'=>'Onboarding uses only this account own profile and participation evidence. It never exposes one-sided reconnect interest or another member private data.',
    ];
}
