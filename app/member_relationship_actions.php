<?php
declare(strict_types=1);

require_once __DIR__ . '/member_journey_scan.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/event_management.php';
require_once __DIR__ . '/admin_agent_tasks.php';
require_once __DIR__ . '/system_sample_data.php';

/**
 * Human-approval bridge from read-only Member Journey Intelligence to canonical
 * Coveted notifications and Event invitations. Recommendations never send.
 */
function coveted_member_relationship_actions_require_admin(array $admin, ?PDO $pdo = null): PDO
{
    if (!coveted_is_system_admin($admin)) throw new InvalidArgumentException('System Admin access is required.');
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin, $pdo)) {
        throw new InvalidArgumentException('Full System Sample Mode does not expose live Member Relationship Actions.');
    }
    return $pdo;
}

function coveted_member_relationship_actions_schema_available(?PDO $pdo = null): bool
{
    $pdo ??= coveted_db();
    try {
        $pdo->query('SELECT id FROM member_relationship_actions LIMIT 0');
        return true;
    } catch (PDOException $e) {
        if (in_array((string)$e->getCode(), ['42S02','42S22'], true) || in_array((int)($e->errorInfo[1] ?? 0), [1054,1146], true)) return false;
        throw $e;
    }
}

function coveted_member_relationship_actions_require_schema(?PDO $pdo = null): void
{
    if (!coveted_member_relationship_actions_schema_available($pdo)) {
        throw new RuntimeException('Member Relationship Action storage is unavailable. Import database/migrations/20260908_member_relationship_actions.sql.');
    }
}

function coveted_member_relationship_action_ref(string $ref): string
{
    $ref=trim($ref);
    if ($ref==='' || strlen($ref)>64 || preg_match('/^[A-Za-z0-9_-]+$/',$ref)!==1) throw new InvalidArgumentException('Member action not found.');
    return $ref;
}

/** @return array<int,string> */
function coveted_member_relationship_action_statuses(): array
{
    return ['recommended','reviewed','approved','executed','skipped','expired','outcome'];
}

/** @return array<int,string> */
function coveted_member_relationship_action_execution_types(): array
{
    return ['hold','message','event_invitation'];
}

function coveted_member_relationship_action_default_execution(string $actionType): string
{
    return match($actionType){
        'pause_invitations'=>'hold',
        'first_event_fit','invite_again_soon'=>'event_invitation',
        default=>'message',
    };
}

function coveted_member_relationship_action_default_message(string $actionType): string
{
    return match($actionType){
        'recover_after_no_show'=>'We missed you at the last gathering. No pressure at all — we would be glad to welcome you back when the right Coveted event comes along.',
        'reconnect_small_format','reconnect'=>'We would love to see you at a Coveted gathering again. We will keep the next opportunity thoughtful and low-pressure.',
        'first_event_fit'=>'We found a Coveted gathering that may be a good first fit. Take a look when you have a moment, and join us if it feels right.',
        'post_event_value_followup'=>'Thanks for joining us. We hope you enjoyed the gathering, and we will keep an eye out for member experiences and benefits that fit.',
        'post_event_followup'=>'Thanks for joining us. We hope you enjoyed the gathering and would be glad to welcome you again.',
        'invite_again_soon'=>'It has been great seeing you at recent Coveted events. We found another gathering that may be a good fit.',
        'partner_value'=>'Thanks for being part of Coveted. We are keeping an eye out for member benefits and experiences that fit your recent participation.',
        default=>'',
    };
}

function coveted_member_relationship_action_lifecycle_hold(PDO $pdo,int $userId): bool
{
    try{
        $stmt=$pdo->prepare('SELECT lifecycle_state FROM membership_lifecycle WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);
        $state=(string)($stmt->fetchColumn()?:'');
        return in_array($state, ['paused','alumni'], true);
    }catch(PDOException $e){
        if(in_array((string)$e->getCode(),['42S02','42S22'],true)||in_array((int)($e->errorInfo[1]??0),[1054,1146],true))return false;
        throw $e;
    }
}

/** @return array<int,bool> */
function coveted_member_relationship_action_lifecycle_holds(PDO $pdo): array
{
    try{
        $rows=$pdo->query("SELECT user_id FROM membership_lifecycle WHERE lifecycle_state IN ('paused','alumni')")->fetchAll();
    }catch(PDOException $e){
        if(in_array((string)$e->getCode(),['42S02','42S22'],true)||in_array((int)($e->errorInfo[1]??0),[1054,1146],true))return [];
        throw $e;
    }
    $result=[];
    foreach($rows as $row)$result[(int)$row['user_id']]=true;
    return $result;
}

/** @return array<int,array<string,mixed>> */
function coveted_member_relationship_action_human_open_map(PDO $pdo): array
{
    $rows=$pdo->query(
        "SELECT id,public_id,member_user_id,recommendation_key,status
         FROM member_relationship_actions
         WHERE status IN ('reviewed','approved')
         ORDER BY FIELD(status,'approved','reviewed'),updated_at DESC,id DESC"
    )->fetchAll();
    $result=[];
    foreach($rows as $row){$uid=(int)$row['member_user_id'];if($uid>0&&!isset($result[$uid]))$result[$uid]=$row;}
    return $result;
}

function coveted_member_relationship_action_recommendation_key(array $metrics,array $decision): string
{
    return hash('sha256',implode('|',[
        (string)($metrics['public_id']??''),(string)($decision['state']??''),(string)($decision['action']??''),(string)($decision['evidence']??''),
    ]));
}

/** @return array<string,mixed>|null */
function coveted_member_relationship_action_by_ref(array $admin,string $ref,?PDO $pdo=null): ?array
{
    $pdo=coveted_member_relationship_actions_require_admin($admin,$pdo);
    if(!coveted_member_relationship_actions_schema_available($pdo))return null;
    $ref=coveted_member_relationship_action_ref($ref);
    $stmt=$pdo->prepare(
        "SELECT a.*,u.public_id AS member_ref,u.display_name AS member_name,u.status AS member_status,
                e.public_id AS event_ref,e.title AS event_title,e.starts_at AS event_starts_at,e.status AS event_status
         FROM member_relationship_actions a
         JOIN users u ON u.id=a.member_user_id
         LEFT JOIN events e ON e.id=a.target_event_id
         WHERE a.public_id=? OR CAST(a.id AS CHAR)=? LIMIT 1"
    );
    $stmt->execute([$ref,$ref]);$row=$stmt->fetch();return $row?:null;
}

/** @return array<int,array<string,mixed>> */
function coveted_member_relationship_actions_list(array $admin,string $status='active',?string $memberRef=null,int $limit=150,?PDO $pdo=null): array
{
    $pdo=coveted_member_relationship_actions_require_admin($admin,$pdo);
    if(!coveted_member_relationship_actions_schema_available($pdo))return [];
    $limit=max(1,min(250,$limit));
    if(!in_array($status,array_merge(['active','all'],coveted_member_relationship_action_statuses()),true))$status='active';
    $where='1=1';$params=[];
    if($status==='active')$where.=" AND a.status IN ('recommended','reviewed','approved')";
    elseif($status!=='all'){$where.=' AND a.status=?';$params[]=$status;}
    $memberRef=trim((string)$memberRef);
    if($memberRef!==''){$where.=' AND (u.public_id=? OR CAST(u.id AS CHAR)=?)';$params[]=$memberRef;$params[]=$memberRef;}
    $stmt=$pdo->prepare(
        "SELECT a.*,u.public_id AS member_ref,u.display_name AS member_name,u.status AS member_status,
                e.public_id AS event_ref,e.title AS event_title,e.starts_at AS event_starts_at,e.status AS event_status
         FROM member_relationship_actions a
         JOIN users u ON u.id=a.member_user_id
         LEFT JOIN events e ON e.id=a.target_event_id
         WHERE {$where}
         ORDER BY FIELD(a.status,'approved','reviewed','recommended','executed','outcome','skipped','expired'),a.priority ASC,a.updated_at DESC,a.id DESC
         LIMIT {$limit}"
    );
    $stmt->execute($params);return $stmt->fetchAll();
}

/** @return array<string,int> */
function coveted_member_relationship_action_counts(array $admin,?PDO $pdo=null): array
{
    $pdo=coveted_member_relationship_actions_require_admin($admin,$pdo);
    $counts=array_fill_keys(coveted_member_relationship_action_statuses(),0);
    if(!coveted_member_relationship_actions_schema_available($pdo))return $counts;
    foreach($pdo->query('SELECT status,COUNT(*) AS total FROM member_relationship_actions GROUP BY status')->fetchAll() as $row){
        $key=(string)$row['status'];if(array_key_exists($key,$counts))$counts[$key]=(int)$row['total'];
    }
    return $counts;
}

/** @return array<string,mixed> */
function coveted_member_relationship_actions_sync_recommendations(array $admin,int $limit=180,?PDO $pdo=null): array
{
    $pdo=coveted_member_relationship_actions_require_admin($admin,$pdo);coveted_member_relationship_actions_require_schema($pdo);
    $created=0;$refreshed=0;$expired=0;$held=0;$currentByMember=[];
    $lifecycleHolds=coveted_member_relationship_action_lifecycle_holds($pdo);
    $humanOpen=coveted_member_relationship_action_human_open_map($pdo);
    $find=$pdo->prepare('SELECT id,status FROM member_relationship_actions WHERE recommendation_key=? LIMIT 1');
    $insert=$pdo->prepare(
        "INSERT INTO member_relationship_actions
            (public_id,created_by_user_id,member_user_id,recommendation_key,journey_state,action_type,priority,status,title,detail,evidence,draft_message,execution_type,source_snapshot_json)
         VALUES (?,?,?,?,?,?,?,'recommended',?,?,?,?,?,?)"
    );
    $refresh=$pdo->prepare(
        "UPDATE member_relationship_actions SET journey_state=?,action_type=?,priority=?,title=?,detail=?,evidence=?,draft_message=?,execution_type=?,source_snapshot_json=?,updated_at=UTC_TIMESTAMP()
         WHERE id=? AND status='recommended'"
    );

    foreach(coveted_member_journey_scan_rows($pdo,max(1,min(200,$limit))) as $metrics){
        $userId=(int)($metrics['id']??0);
        if($userId<1||isset($lifecycleHolds[$userId])){$held++;continue;}
        if(isset($humanOpen[$userId])){
            // Never generate a second open recommendation around a human-reviewed
            // or approved action. The Admin must resolve that authorization first.
            $currentByMember[$userId]=(string)$humanOpen[$userId]['recommendation_key'];
            continue;
        }
        $decision=coveted_member_journey_decision($metrics);
        $actionType=(string)($decision['action']??'hold_steady');$state=(string)($decision['state']??'steady');
        if($state==='steady'||$actionType==='hold_steady'||(int)($decision['priority']??3)>2)continue;
        $key=coveted_member_relationship_action_recommendation_key($metrics,$decision);$currentByMember[$userId]=$key;
        $find->execute([$key]);$existing=$find->fetch();
        $executionType=coveted_member_relationship_action_default_execution($actionType);
        $draft=$executionType==='hold'?'':coveted_member_relationship_action_default_message($actionType);
        $snapshot=[
            'generated_at'=>gmdate('Y-m-d H:i:s'),'member_ref'=>(string)$metrics['public_id'],'decision'=>$decision,
            'metrics'=>[
                'verified_365d'=>(int)($metrics['verified_365d']??0),'verified_90d'=>(int)($metrics['verified_90d']??0),'verified_30d'=>(int)($metrics['verified_30d']??0),
                'invitations_30d'=>(int)($metrics['invitations_30d']??0),'future_invitations'=>(int)($metrics['future_invitations']??0),'event_messages_30d'=>(int)($metrics['event_messages_30d']??0),
                'no_shows_180d'=>(int)($metrics['no_shows_180d']??0),'declines_180d'=>(int)($metrics['declines_180d']??0),'rewards_claimed_180d'=>(int)($metrics['rewards_claimed_180d']??0),
            ],
        ];
        if(!$existing){
            $publicId=coveted_uuid('mact');
            $insert->execute([$publicId,(int)$admin['id'],$userId,$key,$state,$actionType,(int)$decision['priority'],(string)$decision['title'],(string)$decision['detail'],(string)$decision['evidence'],$draft!==''?$draft:null,$executionType,coveted_json($snapshot)]);
            coveted_audit('member.relationship_action.recommended','member_relationship_action',$publicId,['member_user_id'=>$userId,'action_type'=>$actionType,'journey_state'=>$state,'priority'=>(int)$decision['priority']],(int)$admin['id']);
            $created++;
        }elseif((string)$existing['status']==='recommended'){
            $refresh->execute([$state,$actionType,(int)$decision['priority'],(string)$decision['title'],(string)$decision['detail'],(string)$decision['evidence'],$draft!==''?$draft:null,$executionType,coveted_json($snapshot),(int)$existing['id']]);
            $refreshed+=$refresh->rowCount()>0?1:0;
        }
    }

    // Only untouched recommendations auto-expire. Reviewed/approved authority is
    // never silently rewritten by a later scan.
    foreach($pdo->query("SELECT id,public_id,member_user_id,recommendation_key,action_type FROM member_relationship_actions WHERE status='recommended'")->fetchAll() as $row){
        $userId=(int)$row['member_user_id'];$currentKey=$currentByMember[$userId]??null;
        if($currentKey!==null&&hash_equals((string)$row['recommendation_key'],$currentKey))continue;
        $stmt=$pdo->prepare("UPDATE member_relationship_actions SET status='expired',expired_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND status='recommended'");
        $stmt->execute([(int)$row['id']]);
        if($stmt->rowCount()===1){$expired++;coveted_audit('member.relationship_action.expired','member_relationship_action',(string)$row['public_id'],['member_user_id'=>$userId,'action_type'=>(string)$row['action_type'],'reason'=>'journey_recommendation_changed'],(int)$admin['id']);}
    }
    coveted_audit('member.relationship_action.queue_refreshed','member_relationship_action','queue',['created'=>$created,'refreshed'=>$refreshed,'expired'=>$expired,'lifecycle_holds'=>$held],(int)$admin['id']);
    coveted_member_relationship_action_sync_agent_task($admin,$pdo);
    return ['created'=>$created,'refreshed'=>$refreshed,'expired'=>$expired,'lifecycle_holds'=>$held];
}

/** @return array<int,array<string,mixed>> */
function coveted_member_relationship_action_event_choices(array $admin,int $memberUserId,?PDO $pdo=null): array
{
    $pdo=coveted_member_relationship_actions_require_admin($admin,$pdo);
    $stmt=$pdo->prepare(
        "SELECT e.id,e.public_id,e.title,e.starts_at,e.event_type,g.name AS group_name
         FROM events e JOIN social_groups g ON g.id=e.group_id
         JOIN group_memberships gm ON gm.group_id=e.group_id AND gm.user_id=? AND gm.membership_status='active'
         LEFT JOIN event_hosts eh ON eh.event_id=e.id AND eh.user_id=?
         WHERE e.status='published' AND e.starts_at>UTC_TIMESTAMP() AND eh.id IS NULL
         ORDER BY e.starts_at ASC,e.id ASC LIMIT 40"
    );
    $stmt->execute([$memberUserId,$memberUserId]);return $stmt->fetchAll();
}

/** @return array<string,mixed> */
function coveted_member_relationship_action_resolve_event(PDO $pdo,int $memberUserId,string $eventRef): array
{
    $eventRef=trim($eventRef);if($eventRef===''||strlen($eventRef)>64)throw new InvalidArgumentException('Choose a future published Event for this action.');
    $stmt=$pdo->prepare(
        "SELECT e.id,e.public_id,e.title,e.starts_at,e.status,e.group_id
         FROM events e
         JOIN group_memberships gm ON gm.group_id=e.group_id AND gm.user_id=? AND gm.membership_status='active'
         LEFT JOIN event_hosts eh ON eh.event_id=e.id AND eh.user_id=?
         WHERE (e.public_id=? OR CAST(e.id AS CHAR)=?) AND e.status='published' AND e.starts_at>UTC_TIMESTAMP() AND eh.id IS NULL LIMIT 1"
    );
    $stmt->execute([$memberUserId,$memberUserId,$eventRef,$eventRef]);$event=$stmt->fetch();
    if(!$event)throw new InvalidArgumentException('That Event is no longer an eligible future Event for this member.');return $event;
}

function coveted_member_relationship_action_review(array $admin,string $actionRef,string $executionType,string $draftMessage='',string $eventRef='',?PDO $pdo=null): void
{
    $pdo=coveted_member_relationship_actions_require_admin($admin,$pdo);coveted_member_relationship_actions_require_schema($pdo);
    $action=coveted_member_relationship_action_by_ref($admin,$actionRef,$pdo);
    if(!$action||!in_array((string)$action['status'],['recommended','reviewed'],true))throw new InvalidArgumentException('Only a recommended or reviewed action can be edited.');
    $executionType=strtolower(trim($executionType));
    if(!in_array($executionType,coveted_member_relationship_action_execution_types(),true))throw new InvalidArgumentException('Choose a valid execution type.');
    if ((string)$action['action_type'] === 'pause_invitations' && $executionType !== 'hold') throw new InvalidArgumentException('A pacing recommendation is a no-contact hold and cannot be converted into outreach.');
    $draftMessage=preg_replace('/\s+/u',' ',trim($draftMessage))?:'';
    if(mb_strlen($draftMessage)>2000)throw new InvalidArgumentException('Keep the member message under 2,000 characters.');
    if($executionType==='message'&&$draftMessage==='')throw new InvalidArgumentException('Add the member message before review.');
    $targetEventId=null;
    if($executionType==='event_invitation'){$event=coveted_member_relationship_action_resolve_event($pdo,(int)$action['member_user_id'],$eventRef);$targetEventId=(int)$event['id'];}
    $previous=(string)$action['status'];
    $stmt=$pdo->prepare(
        "UPDATE member_relationship_actions SET status='reviewed',execution_type=?,draft_message=?,target_event_id=?,reviewed_by_user_id=?,reviewed_at=UTC_TIMESTAMP(),approved_by_user_id=NULL,approved_at=NULL,execution_error=NULL,updated_at=UTC_TIMESTAMP()
         WHERE id=? AND status IN ('recommended','reviewed')"
    );
    $stmt->execute([$executionType,$draftMessage!==''?$draftMessage:null,$targetEventId,(int)$admin['id'],(int)$action['id']]);
    if($stmt->rowCount()!==1&&$previous==='recommended')throw new InvalidArgumentException('This member action changed in another tab. Refresh before reviewing it.');
    coveted_audit('member.relationship_action.reviewed','member_relationship_action',(string)$action['public_id'],['member_user_id'=>(int)$action['member_user_id'],'action_type'=>(string)$action['action_type'],'from'=>$previous,'to'=>'reviewed','execution_type'=>$executionType,'target_event_id'=>$targetEventId],(int)$admin['id']);
    coveted_member_relationship_action_sync_agent_task($admin,$pdo);
}

function coveted_member_relationship_action_approve(array $admin,string $actionRef,?PDO $pdo=null): void
{
    $pdo=coveted_member_relationship_actions_require_admin($admin,$pdo);coveted_member_relationship_actions_require_schema($pdo);
    $action=coveted_member_relationship_action_by_ref($admin,$actionRef,$pdo);
    if(!$action || (string)$action['status'] !== 'reviewed') throw new InvalidArgumentException('Review this member action before approving it.');
    if((string)$action['execution_type']==='message'&&trim((string)$action['draft_message'])==='')throw new InvalidArgumentException('A message action must have reviewed copy before approval.');
    if((string)$action['execution_type']==='event_invitation'){
        if((int)($action['target_event_id']??0)<1)throw new InvalidArgumentException('Choose an Event before approval.');
        coveted_member_relationship_action_resolve_event($pdo,(int)$action['member_user_id'],(string)$action['target_event_id']);
    }
    $stmt=$pdo->prepare("UPDATE member_relationship_actions SET status='approved',approved_by_user_id=?,approved_at=UTC_TIMESTAMP(),execution_error=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='reviewed'");
    $stmt->execute([(int)$admin['id'],(int)$action['id']]);if($stmt->rowCount()!==1)throw new InvalidArgumentException('This member action changed in another tab. Refresh before approving it.');
    coveted_audit('member.relationship_action.approved','member_relationship_action',(string)$action['public_id'],['member_user_id'=>(int)$action['member_user_id'],'action_type'=>(string)$action['action_type'],'execution_type'=>(string)$action['execution_type']],(int)$admin['id']);
    coveted_member_relationship_action_sync_agent_task($admin,$pdo);
}

function coveted_member_relationship_action_skip(array $admin,string $actionRef,string $note='',?PDO $pdo=null): void
{
    $pdo=coveted_member_relationship_actions_require_admin($admin,$pdo);coveted_member_relationship_actions_require_schema($pdo);
    $action=coveted_member_relationship_action_by_ref($admin,$actionRef,$pdo);
    if(!$action||!in_array((string)$action['status'],['recommended','reviewed','approved'],true))throw new InvalidArgumentException('Only an open member action can be skipped.');
    $note=preg_replace('/\s+/u',' ',trim($note))?:'';if(mb_strlen($note)>1000)throw new InvalidArgumentException('Keep the skip note under 1,000 characters.');
    $previous=(string)$action['status'];$stmt=$pdo->prepare("UPDATE member_relationship_actions SET status='skipped',skipped_at=UTC_TIMESTAMP(),execution_error=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND status=?");
    $stmt->execute([(int)$action['id'],$previous]);if($stmt->rowCount()!==1)throw new InvalidArgumentException('This member action changed in another tab. Refresh before skipping it.');
    coveted_audit('member.relationship_action.skipped','member_relationship_action',(string)$action['public_id'],['member_user_id'=>(int)$action['member_user_id'],'action_type'=>(string)$action['action_type'],'from'=>$previous,'note'=>$note],(int)$admin['id']);
    coveted_member_relationship_action_sync_agent_task($admin,$pdo);
}

function coveted_member_relationship_action_lock(PDO $pdo,string $publicId): string
{
    $name='covmact:'.substr(hash('sha256',$publicId),0,48);$stmt=$pdo->prepare('SELECT GET_LOCK(?,5)');$stmt->execute([$name]);
    if((int)$stmt->fetchColumn()!==1)throw new RuntimeException('This member action is already being executed. Try again after refreshing.');return $name;
}

function coveted_member_relationship_action_unlock(PDO $pdo,string $name): void
{
    try{$stmt=$pdo->prepare('SELECT RELEASE_LOCK(?)');$stmt->execute([$name]);}catch(Throwable){}
}

/** @return array<string,mixed>|null */
function coveted_member_relationship_action_recent_contact(PDO $pdo,int $memberUserId,int $excludeActionId=0,int $hours=48): ?array
{
    $hours=max(1,min(168,$hours));
    $stmt=$pdo->prepare(
        "SELECT public_id,action_type,execution_type,executed_at
         FROM member_relationship_actions
         WHERE member_user_id=? AND id<>? AND status IN ('executed','outcome')
           AND execution_type IN ('message','event_invitation')
           AND executed_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$hours} HOUR)
         ORDER BY executed_at DESC,id DESC LIMIT 1"
    );
    $stmt->execute([$memberUserId,$excludeActionId]);$row=$stmt->fetch();return $row?:null;
}

/** @return array<string,mixed> */
function coveted_member_relationship_action_execute(array $admin,string $actionRef,?PDO $pdo=null): array
{
    $pdo=coveted_member_relationship_actions_require_admin($admin,$pdo);coveted_member_relationship_actions_require_schema($pdo);
    $action=coveted_member_relationship_action_by_ref($admin,$actionRef,$pdo);
    if(!$action || (string)$action['status'] !== 'approved') throw new InvalidArgumentException('Only an explicitly approved member action can be executed.');
    $lock=coveted_member_relationship_action_lock($pdo,(string)$action['public_id']);
    try{
        $action=coveted_member_relationship_action_by_ref($admin,(string)$action['public_id'],$pdo);
        if(!$action||(string)$action['status']!=='approved')throw new InvalidArgumentException('This member action is no longer approved for execution.');
        if((string)$action['member_status']!=='active')throw new InvalidArgumentException('The member account is no longer active.');
        $executionType=(string)$action['execution_type'];
        if($executionType!=='hold'&&coveted_member_relationship_action_lifecycle_hold($pdo,(int)$action['member_user_id']))throw new InvalidArgumentException('This member is currently on a lifecycle hold. Review Membership Lifecycle before contact.');

        // Approval freezes intent, not eligibility. Re-check live Journey pressure
        // and recent contact immediately before any outreach.
        $metrics=coveted_member_journey_metrics_row($pdo,(string)$action['member_ref']);$liveDecision=coveted_member_journey_decision($metrics);
        if ($executionType !== 'hold' && (string)$liveDecision['action'] === 'pause_invitations') throw new InvalidArgumentException('Live Member Journey pressure now requires a no-contact hold. Review the action again before outreach.');
        if($executionType!=='hold'){
            $recent=coveted_member_relationship_action_recent_contact($pdo,(int)$action['member_user_id'],(int)$action['id'],48);
            if($recent)throw new InvalidArgumentException('Another member contact action was executed within the last 48 hours. Keep the relationship paced before contacting this member again.');
        }

        $pdo->prepare("UPDATE member_relationship_actions SET execution_attempted_at=UTC_TIMESTAMP(),execution_error=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='approved'")->execute([(int)$action['id']]);
        $resultType='hold';$resultRef=null;
        if($executionType==='message'){
            $message=trim((string)$action['draft_message']);if($message==='')throw new InvalidArgumentException('Approved message copy is missing. Review the action before execution.');
            $notification=coveted_notification_create((int)$action['member_user_id'],'event.relationship_followup','A note from Coveted',$message,null,['member_action_ref'=>(string)$action['public_id'],'action_type'=>(string)$action['action_type']],'normal','member-relationship-action:'.(string)$action['public_id'],(int)$admin['id']);
            $resultType='notification';$resultRef=(string)($notification['public_id']??'');
        }elseif($executionType==='event_invitation'){
            $event=coveted_member_relationship_action_resolve_event($pdo,(int)$action['member_user_id'],(string)($action['target_event_id']??''));
            $resultRef=coveted_event_invite_user($admin,(string)$event['public_id'],(int)$action['member_user_id'],'member',[
                'require_active_group_member'=>true,'reject_event_host'=>true,'respect_existing_response'=>true,'idempotent_pending'=>true,
            ]);
            $resultType='event_invitation';
        }elseif($executionType!=='hold')throw new InvalidArgumentException('The approved execution type is not supported.');

        $stmt=$pdo->prepare("UPDATE member_relationship_actions SET status='executed',canonical_result_type=?,canonical_result_ref=?,executed_by_user_id=?,executed_at=UTC_TIMESTAMP(),execution_error=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='approved'");
        $stmt->execute([$resultType,$resultRef!==''?$resultRef:null,(int)$admin['id'],(int)$action['id']]);
        if($stmt->rowCount()!==1)throw new RuntimeException('The canonical action completed, but the Member Action record changed. Refresh before retrying; canonical dedupe protections remain active.');
        coveted_audit('member.relationship_action.executed','member_relationship_action',(string)$action['public_id'],[
            'member_user_id'=>(int)$action['member_user_id'],'action_type'=>(string)$action['action_type'],'execution_type'=>$executionType,
            'canonical_result_type'=>$resultType,'canonical_result_ref'=>$resultRef,'live_journey_state'=>(string)$liveDecision['state'],'live_journey_action'=>(string)$liveDecision['action'],
        ],(int)$admin['id']);
        coveted_member_relationship_action_sync_agent_task($admin,$pdo);
        return coveted_member_relationship_action_by_ref($admin,(string)$action['public_id'],$pdo)??$action;
    }catch(Throwable $e){
        try{
            $message=preg_replace('/\s+/u',' ',trim($e->getMessage()))?:'Execution was blocked.';
            $pdo->prepare("UPDATE member_relationship_actions SET execution_error=?,execution_attempted_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE public_id=? AND status='approved'")->execute([mb_substr($message,0,1000),(string)$action['public_id']]);
            coveted_audit('member.relationship_action.execution_blocked','member_relationship_action',(string)$action['public_id'],['member_user_id'=>(int)$action['member_user_id'],'action_type'=>(string)$action['action_type'],'reason'=>mb_substr($message,0,1000)],(int)$admin['id']);
        }catch(Throwable $auditError){error_log('Member Relationship Action blocked-state persistence failed: '.$auditError->getMessage());}
        throw $e;
    }finally{coveted_member_relationship_action_unlock($pdo,$lock);}
}

function coveted_member_relationship_action_record_outcome(array $admin,string $actionRef,string $outcome,string $note='',?PDO $pdo=null): void
{
    $pdo=coveted_member_relationship_actions_require_admin($admin,$pdo);coveted_member_relationship_actions_require_schema($pdo);
    $action=coveted_member_relationship_action_by_ref($admin,$actionRef,$pdo);if(!$action||(string)$action['status']!=='executed')throw new InvalidArgumentException('An outcome can be recorded only after execution.');
    $outcome=strtolower(trim($outcome));if(!in_array($outcome,['positive','neutral','negative','unknown'],true))throw new InvalidArgumentException('Choose a valid outcome.');
    $note=preg_replace('/\s+/u',' ',trim($note))?:'';if(mb_strlen($note)>2000)throw new InvalidArgumentException('Keep the outcome note under 2,000 characters.');
    $stmt=$pdo->prepare("UPDATE member_relationship_actions SET status='outcome',outcome_status=?,outcome_note=?,outcome_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND status='executed'");
    $stmt->execute([$outcome,$note!==''?$note:null,(int)$action['id']]);if($stmt->rowCount()!==1)throw new InvalidArgumentException('This member action changed in another tab. Refresh before recording the outcome.');
    coveted_audit('member.relationship_action.outcome','member_relationship_action',(string)$action['public_id'],['member_user_id'=>(int)$action['member_user_id'],'action_type'=>(string)$action['action_type'],'outcome'=>$outcome,'note'=>$note],(int)$admin['id']);
    coveted_member_relationship_action_sync_agent_task($admin,$pdo);
}

/** @return array<int,array<string,mixed>> */
function coveted_member_relationship_action_history(array $admin,string $actionRef,int $limit=30,?PDO $pdo=null): array
{
    $pdo=coveted_member_relationship_actions_require_admin($admin,$pdo);$action=coveted_member_relationship_action_by_ref($admin,$actionRef,$pdo);if(!$action)return [];
    $limit=max(1,min(80,$limit));$stmt=$pdo->prepare(
        "SELECT ae.event_type,ae.metadata_json,ae.created_at,COALESCE(u.display_name,'System') AS actor_name
         FROM audit_events ae LEFT JOIN users u ON u.id=ae.actor_user_id
         WHERE ae.entity_type='member_relationship_action' AND ae.entity_id=? ORDER BY ae.created_at DESC,ae.id DESC LIMIT {$limit}"
    );
    $stmt->execute([(string)$action['public_id']]);$rows=$stmt->fetchAll();
    foreach($rows as &$row){try{$decoded=json_decode((string)($row['metadata_json']??''),true,32,JSON_THROW_ON_ERROR);$row['metadata']=is_array($decoded)?$decoded:[];}catch(Throwable){$row['metadata']=[];}}unset($row);return $rows;
}

/** @return array<string,mixed> */
function coveted_member_relationship_action_agent_context(array $admin,?PDO $pdo=null): array
{
    $pdo=coveted_member_relationship_actions_require_admin($admin,$pdo);
    if(!coveted_member_relationship_actions_schema_available($pdo))return ['available'=>false,'attention'=>0,'counts'=>[],'learning'=>[],'recommendations'=>[]];
    $counts=coveted_member_relationship_action_counts($admin,$pdo);$learning=['positive'=>0,'neutral'=>0,'negative'=>0,'unknown'=>0];
    foreach($pdo->query("SELECT outcome_status,COUNT(*) AS total FROM member_relationship_actions WHERE status='outcome' AND outcome_status IS NOT NULL GROUP BY outcome_status")->fetchAll() as $row){$key=(string)$row['outcome_status'];if(array_key_exists($key,$learning))$learning[$key]=(int)$row['total'];}
    $attention=(int)$counts['recommended']+(int)$counts['reviewed']+(int)$counts['approved'];$recommendations=[];
    if($attention>0)$recommendations[]=[
        'priority'=>(int)$counts['approved']>0?1:2,'key'=>'member-action-queue','category'=>'Member relationships','title'=>'Review the Member Action Queue',
        'detail'=>'Member Journey Intelligence has relationship actions waiting for explicit System Admin review, approval or execution.',
        'evidence'=>(int)$counts['approved'].' approved · '.(int)$counts['reviewed'].' reviewed · '.(int)$counts['recommended'].' recommended','href'=>'/admin/member-actions.php',
    ];
    return [
        'available'=>true,'attention'=>$attention,'counts'=>$counts,'learning'=>$learning,'recommendations'=>$recommendations,
        'privacy'=>'Broad Agent context contains aggregate Member Action counts and outcomes only; member identities and draft messages stay inside the System Admin action workspace.',
        'authority'=>'The Agent may recommend and track Member Actions. Only an explicit System Admin review and approval can authorize canonical outreach or invitation execution.',
    ];
}

function coveted_member_relationship_action_sync_agent_task(array $admin,?PDO $pdo=null): void
{
    $pdo=coveted_member_relationship_actions_require_admin($admin,$pdo);
    if(!coveted_member_relationship_actions_schema_available($pdo)||!coveted_admin_agent_tasks_schema_available($pdo))return;
    $context=coveted_member_relationship_action_agent_context($admin,$pdo);if(empty($context['attention'])||empty($context['recommendations']))return;
    $active=coveted_member_relationship_actions_list($admin,'active',null,250,$pdo);$maxId=0;foreach($active as $row)$maxId=max($maxId,(int)$row['id']);
    $item=(array)$context['recommendations'][0];$item['key']='member-action-queue-' . $maxId;
    coveted_admin_agent_tasks_sync_opportunities($admin,[$item],$pdo);
}
