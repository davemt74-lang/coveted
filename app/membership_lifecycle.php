<?php
declare(strict_types=1);

require_once __DIR__ . '/member_journey.php';
require_once __DIR__ . '/member_journey_scan.php';

/** @return array<int,string> */
function coveted_membership_lifecycle_states(): array
{
    return ['invited','applicant','active','engaged','drifting','paused','alumni'];
}

/** @return array<string,array<int,string>> */
function coveted_membership_lifecycle_transition_map(): array
{
    return [
        'invited'=>['applicant','active','alumni'],
        'applicant'=>['active','alumni'],
        'active'=>['engaged','paused','alumni'],
        'engaged'=>['drifting','paused','alumni'],
        'drifting'=>['engaged','paused','alumni'],
        'paused'=>['active','engaged','alumni'],
        'alumni'=>['active'],
    ];
}

/** @return array<int,string> */
function coveted_membership_lifecycle_allowed_targets(string $state): array
{
    $state=strtolower(trim($state));
    return coveted_membership_lifecycle_transition_map()[$state]??[];
}

function coveted_membership_lifecycle_schema_available(?PDO $pdo=null): bool
{
    $pdo ??= coveted_db();
    try {
        $pdo->query('SELECT id,user_id,lifecycle_state FROM membership_lifecycle LIMIT 0');
        $pdo->query('SELECT id,lifecycle_id,to_state FROM membership_lifecycle_history LIMIT 0');
        return true;
    } catch (PDOException $e) {
        if (in_array((string)$e->getCode(),['42S02','42S22'],true)
            || in_array((int)($e->errorInfo[1]??0),[1054,1146],true)) return false;
        throw $e;
    }
}

function coveted_membership_lifecycle_require_schema(?PDO $pdo=null): void
{
    if (!coveted_membership_lifecycle_schema_available($pdo)) {
        throw new RuntimeException('Membership Lifecycle CRM storage is unavailable. Import database/migrations/20260907_membership_lifecycle_crm.sql.');
    }
}

/** @return array<string,mixed> */
function coveted_membership_lifecycle_user(PDO $pdo,string $userRef,bool $forUpdate=false): array
{
    $userRef=trim($userRef);
    if($userRef==='' || strlen($userRef)>255) throw new InvalidArgumentException('Member not found.');
    $sql='SELECT id,public_id,display_name,status,created_at FROM users WHERE (public_id=? OR CAST(id AS CHAR)=? OR LOWER(email)=LOWER(?)) LIMIT 1';
    if($forUpdate)$sql.=' FOR UPDATE';
    $stmt=$pdo->prepare($sql);$stmt->execute([$userRef,$userRef,$userRef]);
    $row=$stmt->fetch();
    if(!$row)throw new InvalidArgumentException('Member not found.');
    return $row;
}

function coveted_membership_lifecycle_baseline(array $user): string
{
    return (string)($user['status']??'active')==='invited' ? 'invited' : 'active';
}

/** @return array<string,mixed>|null */
function coveted_membership_lifecycle_row(int $userId,?PDO $pdo=null): ?array
{
    $pdo ??= coveted_db();
    if(!coveted_membership_lifecycle_schema_available($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM membership_lifecycle WHERE user_id=? LIMIT 1');
    $stmt->execute([$userId]);$row=$stmt->fetch();
    return $row?:null;
}

/** @return array<string,mixed> */
function coveted_membership_lifecycle_current(array $user,?PDO $pdo=null): array
{
    $pdo ??= coveted_db();
    $row=coveted_membership_lifecycle_row((int)$user['id'],$pdo);
    $state=$row?(string)$row['lifecycle_state']:coveted_membership_lifecycle_baseline($user);
    return [
        'record_ref'=>(string)($row['public_id']??''),
        'state'=>$state,
        'state_since'=>(string)($row['state_since']??$user['created_at']??''),
        'renewal_due_at'=>(string)($row['renewal_due_at']??''),
        'paused_until'=>(string)($row['paused_until']??''),
        'reason'=>(string)($row['reason']??''),
        'persisted'=>(bool)$row,
    ];
}

/** @return array{state:string,title:string,detail:string,evidence:string,priority:int}|null */
function coveted_membership_lifecycle_recommendation(array $metrics,array $current): ?array
{
    $state=(string)($current['state']??'active');
    $verified30=(int)($metrics['verified_30d']??0);
    $verified90=(int)($metrics['verified_90d']??0);
    $verified365=(int)($metrics['verified_365d']??0);
    $groups=(int)($metrics['active_groups']??0);
    $last=(string)($metrics['last_verified_at']??'');
    $renewal=(string)($current['renewal_due_at']??'');
    $pausedUntil=(string)($current['paused_until']??'');

    if($state==='active' && ($verified30>0 || $verified90>=2)) {
        return ['state'=>'engaged','title'=>'Recognize active participation','detail'=>'Verified recent Coveted participation supports moving this member from Active to Engaged.','evidence'=>$verified30.' verified event'.($verified30===1?'':'s').' in 30d · '.$verified90.' in 90d','priority'=>2];
    }
    if($state==='engaged' && $verified365>0 && $verified90===0) {
        return ['state'=>'drifting','title'=>'Review a drifting member','detail'=>'This member has verified Coveted history but no verified participation in the last 90 days.','evidence'=>$verified365.' verified event'.($verified365===1?'':'s').' in 365d · 0 in 90d'.($last!==''?' · last '.$last:''),'priority'=>1];
    }
    if($state==='drifting' && $verified30>0) {
        return ['state'=>'engaged','title'=>'Recognize renewed engagement','detail'=>'Recent verified participation supports returning this member to Engaged.','evidence'=>$verified30.' verified event'.($verified30===1?'':'s').' in 30d','priority'=>1];
    }
    if(in_array($state,['invited','applicant'],true) && $groups>0) {
        return ['state'=>'active','title'=>'Activate an established member','detail'=>'The account now has an active Coveted group membership and is ready for an Admin lifecycle review.','evidence'=>$groups.' active group membership'.($groups===1?'':'s'),'priority'=>1];
    }
    if($state==='paused' && $pausedUntil!=='' && strtotime($pausedUntil)!==false && strtotime($pausedUntil)<=time()) {
        return ['state'=>'active','title'=>'Review paused-member reactivation','detail'=>'The recorded pause window has ended. Review whether this member should return to Active.','evidence'=>'Pause window ended '.$pausedUntil,'priority'=>1];
    }
    if(in_array($state,['active','engaged','drifting'],true) && $renewal!=='' && ($ts=strtotime($renewal))!==false && $ts<=time()+30*86400) {
        return ['state'=>$state,'title'=>'Review upcoming membership renewal','detail'=>'The membership renewal date is due or approaching. Review renewal before changing lifecycle state.','evidence'=>'Renewal due '.$renewal,'priority'=>1];
    }
    return null;
}

/** @return array<string,mixed> */
function coveted_membership_lifecycle_member_snapshot(array $user,?PDO $pdo=null): array
{
    $pdo ??= coveted_db();
    $current=coveted_membership_lifecycle_current($user,$pdo);
    $metrics=[];
    if((string)($user['status']??'active')==='active'){
        try{$metrics=coveted_member_journey_metrics_row($pdo,(string)$user['public_id']);}catch(Throwable){}
    }
    $recommendation=$metrics?coveted_membership_lifecycle_recommendation($metrics,$current):null;
    return [
        'state'=>$current['state'],'state_since'=>$current['state_since'],'renewal_due_at'=>$current['renewal_due_at'],'paused_until'=>$current['paused_until'],
        'guidance'=>$recommendation?['title'=>$recommendation['title'],'detail'=>$recommendation['detail'],'evidence'=>$recommendation['evidence']]:null,
        'actions'=>match((string)$current['state']){
            'paused'=>[['label'=>'Review membership status','url'=>'/groups.php']],
            'drifting'=>[['label'=>'Find a next event','url'=>'/events.php'],['label'=>'Review reconnect','url'=>'/reconnect.php']],
            'active','engaged'=>[['label'=>'View my events','url'=>'/my-events.php'],['label'=>'Review benefits','url'=>'/benefits.php']],
            default=>[['label'=>'Review groups','url'=>'/groups.php']],
        },
        'privacy'=>'This is your own private membership lifecycle context. It contains no other member lifecycle state, hidden score, personality inference, or public ranking.',
        'authority'=>'Membership lifecycle changes are System Admin-controlled. Your Concierge may explain the current state and next steps but cannot change it.',
    ];
}

/** @return array<int,array<string,mixed>> */
function coveted_membership_lifecycle_admin_candidates(PDO $pdo,int $limit=120): array
{
    coveted_membership_lifecycle_require_schema($pdo);
    $limit=max(1,min(200,$limit));
    $sql="SELECT u.id,u.public_id,u.display_name,u.status,u.created_at,
                 (SELECT COUNT(*) FROM group_memberships gm
                  WHERE gm.user_id=u.id AND gm.membership_status='active' AND gm.group_role<>'guest') AS active_groups
          FROM users u
          LEFT JOIN membership_lifecycle ml ON ml.user_id=u.id
          WHERE u.status IN ('active','invited')
            AND (
                u.status='invited'
                OR ml.id IS NOT NULL
                OR EXISTS (
                    SELECT 1 FROM group_memberships gm2
                    WHERE gm2.user_id=u.id AND gm2.membership_status='active' AND gm2.group_role<>'guest'
                )
            )
          ORDER BY u.display_name,u.id
          LIMIT {$limit}";
    $rows=$pdo->query($sql)->fetchAll();
    foreach($rows as &$row){$row['id']=(int)$row['id'];$row['active_groups']=(int)$row['active_groups'];}
    unset($row);
    return $rows;
}

/** @return array<int,array<string,mixed>> */
function coveted_membership_lifecycle_admin_index(array $admin,int $limit=100,?PDO $pdo=null): array
{
    if(!coveted_is_system_admin($admin))throw new InvalidArgumentException('System Admin access is required.');
    $pdo ??= coveted_db();
    if(!coveted_membership_lifecycle_schema_available($pdo))return [];

    $scanMap=[];
    foreach(coveted_member_journey_scan_rows($pdo,200) as $metrics)$scanMap[(int)$metrics['id']]=$metrics;

    $rows=[];
    foreach(coveted_membership_lifecycle_admin_candidates($pdo,max(1,min(200,$limit))) as $user){
        $metrics=$scanMap[(int)$user['id']]??[
            'id'=>(int)$user['id'],'public_id'=>(string)$user['public_id'],'display_name'=>(string)$user['display_name'],'created_at'=>(string)$user['created_at'],
            'active_groups'=>(int)$user['active_groups'],'verified_365d'=>0,'verified_90d'=>0,'verified_30d'=>0,'last_verified_at'=>'',
            'small_format_365d'=>0,'no_shows_180d'=>0,'declines_180d'=>0,'invitations_30d'=>0,'invitations_60d'=>0,
            'pending_invitations'=>0,'future_invitations'=>0,'event_messages_30d'=>0,'rewards_issued_180d'=>0,'rewards_claimed_180d'=>0,
        ];
        $current=coveted_membership_lifecycle_current($user,$pdo);
        $rec=coveted_membership_lifecycle_recommendation($metrics,$current);
        $rows[]=[
            'member_ref'=>(string)$user['public_id'],'display_name'=>(string)$user['display_name'],'account_status'=>(string)$user['status'],'state'=>(string)$current['state'],
            'state_since'=>(string)$current['state_since'],'renewal_due_at'=>(string)$current['renewal_due_at'],'paused_until'=>(string)$current['paused_until'],
            'recommendation'=>$rec,'active_groups'=>(int)$metrics['active_groups'],'verified_30d'=>(int)$metrics['verified_30d'],'verified_90d'=>(int)$metrics['verified_90d'],'verified_365d'=>(int)$metrics['verified_365d'],
        ];
    }
    usort($rows,static fn(array $a,array $b):int=>((int)($a['recommendation']['priority']??9)<=> (int)($b['recommendation']['priority']??9)) ?: strcasecmp((string)$a['display_name'],(string)$b['display_name']));
    return array_slice($rows,0,max(1,min(200,$limit)));
}

/** @return array<string,mixed> */
function coveted_membership_lifecycle_agent_context(array $admin,int $limit=80,?PDO $pdo=null): array
{
    if(!coveted_is_system_admin($admin))throw new InvalidArgumentException('System Admin access is required.');
    $pdo ??= coveted_db();
    if(!coveted_membership_lifecycle_schema_available($pdo))return ['available'=>false,'reason'=>'schema_unavailable','summary'=>[],'recommendations'=>[],'attention'=>0];
    $summary=array_fill_keys(coveted_membership_lifecycle_states(),0);$recommendations=[];$attention=0;
    foreach(coveted_membership_lifecycle_admin_index($admin,$limit,$pdo) as $row){
        $state=(string)$row['state'];$summary[$state]=($summary[$state]??0)+1;$rec=$row['recommendation'];
        if(!is_array($rec))continue;
        $attention++;
        $to=(string)$rec['state'];
        $recommendations[]=[
            'priority'=>(int)$rec['priority'],'key'=>'membership-lifecycle-'.(string)$row['member_ref'].'-'.$state.'-'.$to,
            'category'=>'Membership Lifecycle','title'=>(string)$rec['title'].' — '.(string)$row['display_name'],
            'detail'=>(string)$rec['detail'].' Current state: '.$state.'. Recommended state: '.$to.'. Member ref: '.(string)$row['member_ref'].'.',
            'evidence'=>(string)$rec['evidence'],'href'=>'/admin/membership-lifecycle.php?member='.rawurlencode((string)$row['member_ref']),
            'member_ref'=>(string)$row['member_ref'],'from_state'=>$state,'recommended_state'=>$to,
            'task_sync'=>$to!==$state,
        ];
    }
    return ['available'=>true,'summary'=>$summary,'recommendations'=>array_slice($recommendations,0,20),'attention'=>$attention,
        'privacy'=>'System Admin-only lifecycle context. States are evidence-based CRM labels, never personality traits, popularity scores, or public rankings.',
        'authority'=>'Only System Admin may persist lifecycle transitions. Approved Agent task execution may use the allowlisted lifecycle action through the canonical lifecycle service.'];
}

/** @return array<string,mixed> */
function coveted_membership_lifecycle_group_context(array $admin,string $groupRef,?PDO $pdo=null): array
{
    if(!coveted_is_system_admin($admin))throw new InvalidArgumentException('System Admin access is required.');
    $pdo ??= coveted_db();
    if(!coveted_membership_lifecycle_schema_available($pdo))return ['available'=>false,'summary'=>[],'held_members'=>[],'held_count'=>0];
    $stmt=$pdo->prepare(
        "SELECT gm.user_id,u.public_id,u.display_name,
                COALESCE(ml.lifecycle_state,IF(u.status='invited','invited','active')) AS lifecycle_state
         FROM social_groups g
         JOIN group_memberships gm ON gm.group_id=g.id
         JOIN users u ON u.id=gm.user_id
         LEFT JOIN membership_lifecycle ml ON ml.user_id=u.id
         WHERE g.public_id=? AND g.status='active'
           AND gm.membership_status='active' AND gm.group_role<>'guest' AND u.status='active'
         ORDER BY u.display_name,u.id"
    );
    $stmt->execute([$groupRef]);
    $summary=array_fill_keys(coveted_membership_lifecycle_states(),0);$held=[];
    foreach($stmt->fetchAll() as $row){
        $state=(string)$row['lifecycle_state'];$summary[$state]=($summary[$state]??0)+1;
        if(in_array($state,['paused','alumni'],true))$held[]=[
            'user_id'=>(int)$row['user_id'],'member_ref'=>(string)$row['public_id'],'display_name'=>(string)$row['display_name'],'state'=>$state,
        ];
    }
    return ['available'=>true,'summary'=>$summary,'held_members'=>$held,'held_count'=>count($held),
        'guidance'=>'Paused and alumni lifecycle states are explicit relationship-planning holds. Do not treat those members as routine drift/reconnect targets until System Admin changes their lifecycle state.'];
}

/** @return array<string,mixed> */
function coveted_membership_lifecycle_set_state(array $admin,string $userRef,string $toState,string $reason='',array $evidence=[],string $source='system_admin',?string $renewalDueAt=null,?string $pausedUntil=null,?PDO $pdo=null): array
{
    if(!coveted_is_system_admin($admin))throw new InvalidArgumentException('System Admin access is required for membership lifecycle changes.');
    $toState=strtolower(trim($toState));
    if(!in_array($toState,coveted_membership_lifecycle_states(),true))throw new InvalidArgumentException('Invalid membership lifecycle state.');
    if(!in_array($source,['system_admin','admin_agent_approved'],true))$source='system_admin';
    $reason=trim($reason);if(mb_strlen($reason)>1000)throw new InvalidArgumentException('Lifecycle reason is too long.');
    $pdo ??= coveted_db();coveted_membership_lifecycle_require_schema($pdo);
    $pdo->beginTransaction();
    try{
        $user=coveted_membership_lifecycle_user($pdo,$userRef,true);
        if(!in_array((string)$user['status'],['active','invited'],true))throw new InvalidArgumentException('Only active or invited accounts can have membership lifecycle state changed.');
        $stmt=$pdo->prepare('SELECT * FROM membership_lifecycle WHERE user_id=? LIMIT 1 FOR UPDATE');$stmt->execute([(int)$user['id']]);$row=$stmt->fetch()?:null;
        $from=$row?(string)$row['lifecycle_state']:coveted_membership_lifecycle_baseline($user);
        if($from!==$toState && !in_array($toState,coveted_membership_lifecycle_transition_map()[$from]??[],true))throw new InvalidArgumentException('That membership lifecycle transition is not allowed.');
        foreach(['renewal'=>$renewalDueAt,'paused'=>$pausedUntil] as $label=>$value){if($value!==null && trim($value)!=='' && strtotime($value)===false)throw new InvalidArgumentException('Invalid '.$label.' date.');}
        $renewal=$renewalDueAt!==null&&trim($renewalDueAt)!==''?coveted_utc_datetime($renewalDueAt)->format('Y-m-d H:i:s'):($row['renewal_due_at']??null);
        $pause=$pausedUntil!==null&&trim($pausedUntil)!==''?coveted_utc_datetime($pausedUntil)->format('Y-m-d H:i:s'):($toState==='paused'?($row['paused_until']??null):null);
        if($row){
            $pdo->prepare('UPDATE membership_lifecycle SET lifecycle_state=?,state_since=IF(lifecycle_state<>?,UTC_TIMESTAMP(),state_since),renewal_due_at=?,paused_until=?,reason=?,updated_by_user_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?')
                ->execute([$toState,$toState,$renewal,$pause,$reason!==''?$reason:null,(int)$admin['id'],(int)$row['id']]);
            $lifecycleId=(int)$row['id'];$publicId=(string)$row['public_id'];
        }else{
            $publicId=coveted_uuid('mlc');
            $pdo->prepare('INSERT INTO membership_lifecycle (public_id,user_id,lifecycle_state,state_since,renewal_due_at,paused_until,reason,updated_by_user_id) VALUES (?,?,?,UTC_TIMESTAMP(),?,?,?,?)')
                ->execute([$publicId,(int)$user['id'],$toState,$renewal,$pause,$reason!==''?$reason:null,(int)$admin['id']]);
            $lifecycleId=(int)$pdo->lastInsertId();
        }
        if($from!==$toState || !$row){
            $evidenceJson=$evidence?coveted_json($evidence):null;if($evidenceJson!==null&&strlen($evidenceJson)>8000)throw new InvalidArgumentException('Lifecycle evidence is too large.');
            $pdo->prepare('INSERT INTO membership_lifecycle_history (public_id,lifecycle_id,from_state,to_state,source,reason,evidence_json,actor_user_id) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([coveted_uuid('mlch'),$lifecycleId,$from,$toState,$source,$reason!==''?$reason:null,$evidenceJson,(int)$admin['id']]);
        }
        coveted_audit('membership.lifecycle_changed','membership_lifecycle',$publicId,['user_ref'=>(string)$user['public_id'],'from'=>$from,'to'=>$toState,'source'=>$source],(int)$admin['id']);
        $pdo->commit();
        return ['record_ref'=>$publicId,'member_ref'=>(string)$user['public_id'],'from_state'=>$from,'state'=>$toState,'changed'=>$from!==$toState];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** @return array<int,array<string,mixed>> */
function coveted_membership_lifecycle_history(array $admin,string $userRef,int $limit=50,?PDO $pdo=null): array
{
    if(!coveted_is_system_admin($admin))throw new InvalidArgumentException('System Admin access is required.');
    $pdo ??= coveted_db();if(!coveted_membership_lifecycle_schema_available($pdo))return [];
    $user=coveted_membership_lifecycle_user($pdo,$userRef,false);$limit=max(1,min(100,$limit));
    $stmt=$pdo->prepare("SELECT h.public_id,h.from_state,h.to_state,h.source,h.reason,h.evidence_json,h.created_at,COALESCE(a.display_name,'System') AS actor FROM membership_lifecycle_history h JOIN membership_lifecycle l ON l.id=h.lifecycle_id LEFT JOIN users a ON a.id=h.actor_user_id WHERE l.user_id=? ORDER BY h.created_at DESC,h.id DESC LIMIT {$limit}");
    $stmt->execute([(int)$user['id']]);return $stmt->fetchAll();
}
