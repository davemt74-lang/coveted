<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{
    $value=@file_get_contents($root.'/'.ltrim($path,'/'));
    if($value===false){fwrite(STDERR,"Missing required file: {$path}\n");exit(1);}return $value;
};
$contains=static function(string $content,string $needle,string $label):void{
    if(!str_contains($content,$needle)){fwrite(STDERR,"Member Relationship Actions contract failed: {$label}\n");exit(1);}
};
$missing=static function(string $content,string $needle,string $label):void{
    if(str_contains($content,$needle)){fwrite(STDERR,"Member Relationship Actions contract failed: {$label}\n");exit(1);}
};

$migration=$read('database/migrations/20260908_member_relationship_actions.sql');
$service=$read('app/member_relationship_actions.php');
$workspace=$read('admin/member-actions.php');
$journey=$read('app/member_journey.php');
$notifications=$read('app/notifications.php');
$eventManagement=$read('app/event_management.php');
$tasks=$read('app/admin_agent_tasks.php');

$contains($migration,'CREATE TABLE IF NOT EXISTS member_relationship_actions (','durable action storage migration is required');
$contains($migration,"ENUM('recommended','reviewed','approved','executed','skipped','expired','outcome')",'explicit action lifecycle states are required');
$contains($migration,"ENUM('hold','message','event_invitation')",'canonical execution types are required');
$contains($migration,'recommendation_key CHAR(64) NOT NULL UNIQUE','recommendation dedupe must be durable');
$contains($migration,'execution_error VARCHAR(1000) NULL','blocked execution state must be visible');
$contains($migration,'outcome_status','outcome learning state must be persisted');
$contains($migration,'created_by_user_id BIGINT UNSIGNED NOT NULL','queue must record recommendation creator without becoming per-admin scoped');
$missing($migration,'owner_user_id','member action queue must be unified across System Admins');

$contains($service,"require_once __DIR__ . '/member_journey_scan.php';",'service must consume canonical Member Journey evidence');
$contains($service,"require_once __DIR__ . '/notifications.php';",'service must use canonical notifications');
$contains($service,"require_once __DIR__ . '/event_management.php';",'service must use canonical Event invitation service');
$contains($service,'function coveted_member_relationship_actions_sync_recommendations','bounded recommendation sync is required');
$contains($service,'coveted_member_journey_scan_rows($pdo','recommendations must derive from bounded Member Journey scan');
$contains($service,"'pause_invitations' => 'hold'",'pacing recommendations must default to no-contact holds');
$contains($service,"if ((string)\$action['action_type'] === 'pause_invitations' && \$executionType !== 'hold')",'pacing holds must not be editable into outreach');
$contains($service,'function coveted_member_relationship_action_review','explicit review step is required');
$contains($service,'function coveted_member_relationship_action_approve','explicit approval step is required');
$contains($service,"(string)\$action['status'] !== 'reviewed'",'approval must require reviewed state');
$contains($service,'function coveted_member_relationship_action_execute','explicit canonical execution step is required');
$contains($service,"(string)\$action['status'] !== 'approved'",'execution must require approved state');
$contains($service,'coveted_member_journey_metrics_row($pdo','execution must revalidate live Member Journey state');
$contains($service,"(string)\$liveDecision['action'] === 'pause_invitations'",'live pacing must block stale outreach approval');
$contains($service,"'event.relationship_followup'",'relationship messages must feed existing event communication-pressure evidence');
$contains($service,"'member-relationship-action:' . (string)\$action['public_id']",'notification execution must have durable dedupe key');
$contains($service,'coveted_notification_create(','message execution must use canonical notification service');
$contains($service,'coveted_event_invite_user(','Event execution must use canonical invitation service');
$contains($service,"'require_active_group_member'=>true",'Event execution must revalidate active group membership');
$contains($service,"'respect_existing_response'=>true",'Event execution must preserve existing RSVP response');
$contains($service,"'idempotent_pending'=>true",'Event execution must be idempotent for pending invites');
$contains($service,'GET_LOCK(?,5)','canonical execution must serialize concurrent action attempts');
$contains($service,'member.relationship_action.execution_blocked','blocked execution attempts must be audited');
$contains($service,'function coveted_member_relationship_action_record_outcome','outcome learning history is required');
$contains($service,'function coveted_member_relationship_action_agent_context','aggregate Agent context is required');
$contains($service,'aggregate Member Action counts and outcomes only','broad Agent context must remain identity-free');
$contains($service,'coveted_admin_agent_tasks_sync_opportunities(','Member Action attention must feed the existing proactive task queue');
$contains($service,"'member-action-queue-' . \$maxId",'proactive queue task generations must avoid per-member identity exposure');
$contains($service,"in_array(\$state, ['paused','alumni'], true)",'membership lifecycle holds must block new relationship action recommendations');
$missing($service,'CREATE TABLE','runtime schema creation is forbidden');
$missing($service,'ALTER TABLE','runtime schema mutation is forbidden');
$missing($service,'INSERT INTO notifications','Member Action service must not bypass canonical notification service');
$missing($service,'INSERT INTO event_invitations','Member Action service must not bypass canonical Event invitation service');
$missing($service,'UPDATE users','Member Action execution must not change account access');
$missing($service,'UPDATE group_memberships','Member Action execution must not change group access');
$missing($service,"'score'=>",'Member Actions must not create hidden member scoring');

$contains($workspace,'coveted_require_system_admin();','workspace must require System Admin');
$contains($workspace,'coveted_require_csrf();','every workspace mutation must enforce CSRF');
$contains($workspace,"\$command==='refresh'",'workspace must expose deliberate Journey refresh');
$contains($workspace,'coveted_member_relationship_action_review(','workspace review must use canonical action service');
$contains($workspace,'coveted_member_relationship_action_approve(','workspace approval must use canonical action service');
$contains($workspace,'coveted_member_relationship_action_execute(','workspace execution must use canonical action service');
$contains($workspace,'confirm_execute','execution must require explicit confirmation');
$contains($workspace,'coveted_member_relationship_action_record_outcome(','workspace must close the learning loop with outcomes');
$contains($workspace,'No autonomous outreach.','workspace must explain the authority boundary');
$contains($workspace,'Review Rewards','workspace must preserve canonical reward-system handoff');
$contains($workspace,'Open Member Journey','workspace must link action evidence back to Member Journey');
$missing($workspace,'INSERT INTO member_relationship_actions','workspace must never bypass canonical action service');
$missing($workspace,'UPDATE member_relationship_actions','workspace must never bypass canonical action service');

$contains($journey,"notification_type LIKE 'event.%'",'Member Journey communication pressure must include canonical relationship follow-up notifications');
$contains($notifications,'function coveted_notification_create(','canonical notification service must remain available');
$contains($eventManagement,'function coveted_event_invite_user(','canonical Event invitation service must remain available');
$contains($tasks,'function coveted_admin_agent_tasks_sync_opportunities','existing proactive Agent task queue must remain the integration target');

fwrite(STDOUT,"Member Relationship Actions contract verified.\n");
