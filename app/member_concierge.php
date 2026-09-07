<?php
declare(strict_types=1);

require_once __DIR__ . '/member_journey.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/rewards.php';
require_once __DIR__ . '/member_people_v2.php';

/** @return array<string,mixed> */
function coveted_member_concierge_event_view(array $event): array
{
    $visibility = (string)($event['location_visibility'] ?? 'host_only');
    $revealed = !empty($event['location_revealed']);
    $locationVisible = $visibility === 'immediate' || $revealed;

    return [
        'event_ref'=>(string)($event['public_id'] ?? $event['event_ref'] ?? ''),
        'title'=>(string)($event['title'] ?? ''),
        'description'=>trim((string)($event['description'] ?? '')),
        'event_type'=>(string)($event['event_type'] ?? 'regular'),
        'group'=>(string)($event['group_name'] ?? $event['group'] ?? ''),
        'starts_at'=>(string)($event['starts_at'] ?? ''),
        'timezone'=>(string)($event['timezone'] ?? ''),
        'capacity'=>isset($event['capacity']) && $event['capacity'] !== null ? (int)$event['capacity'] : null,
        'plus_one_allowed'=>!empty($event['plus_one_allowed']),
        'location_visibility'=>$visibility,
        'location_revealed'=>$revealed,
        'location'=>$locationVisible ? trim((string)($event['location_name'] ?? '')) : '',
        'location_city'=>$locationVisible ? trim((string)($event['location_city'] ?? '')) : '',
        'rsvp'=>(string)($event['response'] ?? $event['rsvp'] ?? ''),
        'invitation_status'=>(string)($event['invitation_status'] ?? ''),
    ];
}

function coveted_member_concierge_size_band(?int $capacity): string
{
    if ($capacity === null || $capacity < 1) return 'unknown';
    if ($capacity <= 18) return 'small';
    if ($capacity <= 40) return 'medium';
    return 'large';
}

/** @return array<int,array<string,mixed>> */
function coveted_member_concierge_pending_invitations(array $user, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $stmt = $pdo->prepare(
        "SELECT
            ei.public_id AS invite_ref,
            e.public_id,e.title,e.description,e.event_type,e.starts_at,e.timezone,e.capacity,e.plus_one_allowed,
            e.location_visibility,g.name AS group_name,
            COALESCE(l.name,el.private_location_label) AS location_name,l.city AS location_city,
            EXISTS(
                SELECT 1 FROM event_mystery_reveals mr
                WHERE mr.event_id=e.id AND mr.reveal_type='location' AND mr.reveal_at<=UTC_TIMESTAMP()
            ) AS location_revealed
         FROM event_invitations ei
         JOIN events e ON e.id=ei.event_id
         JOIN social_groups g ON g.id=e.group_id
         LEFT JOIN event_locations el ON el.event_id=e.id
         LEFT JOIN locations l ON l.id=el.location_id
         WHERE ei.user_id=? AND ei.status='pending'
           AND e.status='published' AND e.starts_at>UTC_TIMESTAMP()
         ORDER BY e.starts_at ASC,e.id ASC
         LIMIT 8"
    );
    $stmt->execute([(int)$user['id']]);

    $rows=[];
    foreach ($stmt->fetchAll() as $row) {
        $event=coveted_member_concierge_event_view($row);
        $event['invite_ref']=(string)$row['invite_ref'];
        $event['invitation_status']='pending';
        $rows[]=$event;
    }
    return $rows;
}

/** @return array<int,array<string,mixed>> */
function coveted_member_concierge_notifications(array $user, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    try {
        $stmt=$pdo->prepare(
            "SELECT public_id,notification_type,title,body,action_url,created_at
             FROM notifications
             WHERE user_id=? AND read_at IS NULL
             ORDER BY created_at DESC,id DESC LIMIT 6"
        );
        $stmt->execute([(int)$user['id']]);
        return array_map(static fn(array $row):array=>[
            'notification_ref'=>(string)$row['public_id'],
            'type'=>(string)$row['notification_type'],
            'title'=>(string)$row['title'],
            'body'=>trim((string)($row['body'] ?? '')),
            'action_url'=>coveted_safe_internal_path((string)($row['action_url'] ?? ''),'/notifications.php'),
            'created_at'=>(string)$row['created_at'],
        ],$stmt->fetchAll());
    } catch (Throwable) {
        return [];
    }
}

/** @return array<int,array<string,mixed>> */
function coveted_member_concierge_benefits(array $user): array
{
    try {
        $rows=coveted_reward_list_for_user((int)$user['id'],[],'inbox');
    } catch (Throwable) {
        return [];
    }

    $result=[];
    foreach (array_slice($rows,0,12) as $row) {
        $result[]=[
            'reward_ref'=>(string)$row['public_id'],
            'title'=>(string)$row['title'],
            'description'=>trim((string)($row['description'] ?? '')),
            'reward_type'=>(string)$row['reward_type'],
            'value_text'=>trim((string)($row['value_text'] ?? '')),
            'status'=>(string)$row['status'],
            'expires_at'=>(string)($row['expires_at'] ?? ''),
            'campaign'=>(string)($row['campaign_title'] ?? ''),
            'event'=>(string)($row['event_title'] ?? ''),
            'location'=>(string)($row['location_name'] ?? ''),
            'action_url'=>'/benefits.php',
        ];
    }
    return $result;
}

/** @return array<string,mixed> */
function coveted_member_concierge_reconnect(array $user, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    try {
        $events=coveted_member_v2_reconnect_events($user,$pdo);
        $matches=coveted_member_v2_reconnect_matches($user,$pdo);
    } catch (Throwable) {
        return ['eligible_events'=>[],'mutual_matches'=>[],'action_url'=>'/reconnect.php'];
    }

    $eligible=[];
    foreach (array_slice($events,0,8) as $event) {
        $eligible[]=[
            'event_ref'=>(string)($event['public_id'] ?? ''),
            'title'=>(string)($event['title'] ?? ''),
            'starts_at'=>(string)($event['starts_at'] ?? ''),
            'group'=>(string)($event['group_name'] ?? $event['group'] ?? ''),
        ];
    }

    $mutual=[];
    foreach (array_slice($matches,0,8) as $match) {
        $mutual[]=[
            'event_ref'=>(string)($match['event_public_id'] ?? ''),
            'event_title'=>(string)($match['event_title'] ?? ''),
            'matched_display_name'=>(string)($match['matched_display_name'] ?? ''),
            'matched_at'=>(string)($match['matched_at'] ?? ''),
        ];
    }

    return [
        'eligible_events'=>$eligible,
        'mutual_matches'=>$mutual,
        'action_url'=>'/reconnect.php',
        'privacy'=>'Eligible Events come from this member verified attendance. Person-level results include mutual matches only; one-sided reconnect choices are not included.',
    ];
}

/** @return array<int,array<string,mixed>> */
function coveted_member_concierge_event_recommendations(array $user, array $preferences): array
{
    $formatVisits=[];
    foreach ((array)($preferences['formats'] ?? []) as $row) {
        $formatVisits[(string)($row['event_type'] ?? '')]=(int)($row['visits'] ?? 0);
    }
    $sizes=(array)($preferences['sizes'] ?? []);
    $sizeVisits=[
        'small'=>(int)($sizes['small_visits'] ?? 0),
        'medium'=>(int)($sizes['medium_visits'] ?? 0),
        'large'=>(int)($sizes['large_visits'] ?? 0),
    ];
    arsort($sizeVisits);
    $preferredSize=(int)reset($sizeVisits)>0 ? (string)array_key_first($sizeVisits) : '';

    $ranked=[];
    foreach (coveted_events_for_user($user,100) as $event) {
        $status=(string)($event['status'] ?? '');
        $response=(string)($event['response'] ?? '');
        if ($status!=='published' && !($status==='closed' && $response==='attending')) continue;
        $starts=(string)($event['starts_at'] ?? '');
        if ($starts==='' || strtotime($starts)===false || strtotime($starts)<=time()) continue;
        if ($response==='declined') continue;
        if ((string)($event['invitation_status'] ?? '')==='declined') continue;

        $score=1;
        $reasons=[];
        $invite=(string)($event['invitation_status'] ?? '');
        if ($response==='attending') {
            $score+=120;
            $reasons[]='Already on your Coveted calendar';
        } elseif ($invite==='pending') {
            $score+=100;
            $reasons[]='A current invitation needs your decision';
        }

        $type=(string)($event['event_type'] ?? 'regular');
        $visits=(int)($formatVisits[$type] ?? 0);
        if ($visits>0) {
            $score+=min(40,$visits*10);
            $reasons[]=$visits.' verified '.str_replace('_',' ',$type).' event'.($visits===1?'':'s').' in your recent history';
        }

        $band=coveted_member_concierge_size_band(isset($event['capacity']) && $event['capacity']!==null ? (int)$event['capacity'] : null);
        if ($preferredSize!=='' && $band===$preferredSize) {
            $score+=12;
            $reasons[]='Matches your most common recent '.$preferredSize.'-format participation';
        }

        if (!$reasons) $reasons[]='Available through one of your current Coveted groups or invitations';
        $view=coveted_member_concierge_event_view($event);
        $view['fit_reasons']=array_slice($reasons,0,3);
        $view['_rank']=$score;
        $ranked[]=$view;
    }

    usort($ranked,static function(array $a,array $b):int{
        $rank=((int)$b['_rank'])<=>((int)$a['_rank']);
        return $rank!==0?$rank:strcmp((string)$a['starts_at'],(string)$b['starts_at']);
    });
    foreach ($ranked as &$row) unset($row['_rank']);
    unset($row);
    return array_slice($ranked,0,6);
}

/** @return array<string,mixed>|null */
function coveted_member_concierge_next_event(array $recommendations): ?array
{
    foreach ($recommendations as $row) {
        if ((string)($row['rsvp'] ?? '')==='attending') {
            $notes=[];
            if ((string)($row['location'] ?? '')!=='') {
                $notes[]='Location is available: '.(string)$row['location'];
            } else {
                $notes[]='The location is not yet available to your account.';
            }
            if (!empty($row['plus_one_allowed'])) $notes[]='This Event supports a +1.';
            $start=strtotime((string)$row['starts_at']);
            if ($start!==false) {
                $hours=(int)floor(($start-time())/3600);
                if ($hours>=0 && $hours<=24) $notes[]='This Event starts within 24 hours.';
            }
            $row['prep_notes']=$notes;
            $row['action_url']='/my-events.php';
            return $row;
        }
    }
    return null;
}

/** @return array<int,array<string,mixed>> */
function coveted_member_concierge_attention(array $pendingInvitations,array $notifications,array $benefits): array
{
    $items=[];
    foreach (array_slice($pendingInvitations,0,3) as $invite) {
        $actions=[
            [
                'action'=>'respond_invitation','decision'=>'accepted','target_ref'=>(string)$invite['invite_ref'],
                'label'=>'Accept','confirm_label'=>'Confirm RSVP','requires_confirmation'=>true,
            ],
            [
                'action'=>'respond_invitation','decision'=>'declined','target_ref'=>(string)$invite['invite_ref'],
                'label'=>'Maybe later','confirm_label'=>'Confirm decline','requires_confirmation'=>true,
            ],
        ];
        if (!empty($invite['plus_one_allowed'])) {
            $actions[]=[
                'action'=>'respond_invitation','decision'=>'accepted','target_ref'=>(string)$invite['invite_ref'],
                'guest_count'=>1,'label'=>'Accept +1','confirm_label'=>'Confirm RSVP +1','requires_confirmation'=>true,
            ];
        }
        $items[]=[
            'kind'=>'invitation','title'=>(string)$invite['title'],
            'detail'=>'Invitation from '.(string)$invite['group'].' · '.(string)$invite['starts_at'],
            'url'=>'/invitations.php','actions'=>$actions,
        ];
    }

    foreach (array_slice($notifications,0,2) as $notification) {
        $items[]=[
            'kind'=>'notification','title'=>(string)$notification['title'],
            'detail'=>(string)$notification['body'],
            'url'=>(string)$notification['action_url'],'actions'=>[],
        ];
    }

    $now=time();
    foreach ($benefits as $benefit) {
        $expires=trim((string)($benefit['expires_at'] ?? ''));
        if ($expires==='' || ($ts=strtotime($expires))===false || $ts<=$now || $ts>$now+7*86400) continue;
        $items[]=[
            'kind'=>'benefit','title'=>(string)$benefit['title'],
            'detail'=>'Benefit expires soon. Review it before '.(string)$benefit['expires_at'],
            'url'=>'/benefits.php','actions'=>[],
        ];
        break;
    }
    return array_slice($items,0,6);
}

/** @return array<string,mixed> */
function coveted_member_concierge_snapshot(array $user, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    if (coveted_is_system_admin($user)) {
        return [];
    }

    $metrics=coveted_member_journey_metrics_row($pdo,(string)$user['public_id']);
    $preferences=coveted_member_journey_preferences($pdo,(int)$user['id']);
    $timeline=coveted_member_journey_timeline($pdo,(int)$user['id'],16);
    $pendingInvitations=coveted_member_concierge_pending_invitations($user,$pdo);
    $notifications=coveted_member_concierge_notifications($user,$pdo);
    $benefits=coveted_member_concierge_benefits($user);
    $reconnect=coveted_member_concierge_reconnect($user,$pdo);
    $recommendations=coveted_member_concierge_event_recommendations($user,$preferences);
    $nextEvent=coveted_member_concierge_next_event($recommendations);

    $journey=[
        'active_groups'=>(int)$metrics['active_groups'],
        'verified_events_365d'=>(int)$metrics['verified_365d'],
        'verified_events_90d'=>(int)$metrics['verified_90d'],
        'verified_events_30d'=>(int)$metrics['verified_30d'],
        'last_verified_at'=>(string)($metrics['last_verified_at'] ?? ''),
        'pending_invitations'=>(int)$metrics['pending_invitations'],
        'rewards_claimed_180d'=>(int)$metrics['rewards_claimed_180d'],
        'event_type_history'=>(array)$preferences['formats'],
        'event_size_history'=>(array)$preferences['sizes'],
    ];

    return [
        'journey'=>$journey,
        'attention'=>coveted_member_concierge_attention($pendingInvitations,$notifications,$benefits),
        'pending_invitations'=>$pendingInvitations,
        'recommended_events'=>$recommendations,
        'next_attending_event'=>$nextEvent,
        'benefits'=>array_slice($benefits,0,8),
        'unread_notifications'=>array_slice($notifications,0,6),
        'recent_journey'=>array_slice($timeline,0,12),
        'reconnect'=>$reconnect,
        'starter_prompts'=>[
            'What should I attend next?',
            'What invitations or RSVP actions need my attention?',
            'What perks or benefits can I use?',
            'What should I know before my next event?',
            'What reconnect opportunities are available to me?',
        ],
        'privacy'=>'This Concierge uses only this authenticated member own Member Journey, visible Events, rewards, notifications and permitted relationship context. It does not expose other members restricted timelines, one-sided reconnect choices, contact details or Admin-only intelligence.',
        'authority'=>'Recommendations are private. The model itself cannot mutate Coveted. RSVP changes run only after an explicit member confirmation through the server-owned Concierge action endpoint and canonical Event services.',
    ];
}
