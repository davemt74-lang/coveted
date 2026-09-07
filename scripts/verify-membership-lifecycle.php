<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{
    $value=@file_get_contents($root.'/'.ltrim($path,'/'));
    if($value===false){fwrite(STDERR,"Missing required file: {$path}\n");exit(1);}return $value;
};
$contains=static function(string $content,string $needle,string $label):void{
    if(!str_contains($content,$needle)){fwrite(STDERR,"Membership Lifecycle CRM contract failed: {$label}\n");exit(1);}
};
$missing=static function(string $content,string $needle,string $label):void{
    if(str_contains($content,$needle)){fwrite(STDERR,"Membership Lifecycle CRM contract failed: {$label}\n");exit(1);}
};

$migration=$read('database/migrations/20260907_membership_lifecycle_crm.sql');
$service=$read('app/membership_lifecycle.php');
$brain=$read('app/admin_agent_brain.php');
$actions=$read('app/admin_agent_actions.php');
$concierge=$read('app/member_concierge.php');
$workspace=$read('admin/membership-lifecycle.php');
$journey=$read('admin/member-journeys.php');
$relationships=$read('admin/member-relationships.php');

$contains($migration,'CREATE TABLE IF NOT EXISTS membership_lifecycle (','lifecycle state table migration is required');
$contains($migration,'CREATE TABLE IF NOT EXISTS membership_lifecycle_history (','append-only lifecycle history migration is required');
$contains($migration,"ENUM('invited','applicant','active','engaged','drifting','paused','alumni')",'canonical lifecycle states must be migration-backed');
$contains($migration,'evidence_json JSON NULL','transition evidence must be persisted');
$contains($migration,"source ENUM('baseline','system_admin','admin_agent_approved')",'transition authority source must be explicit');

$contains($service,"return ['invited','applicant','active','engaged','drifting','paused','alumni'];",'canonical lifecycle state list is required');
$contains($service,"'invited'=>['applicant','active','alumni']",'invited transition rules are required');
$contains($service,"'active'=>['engaged','paused','alumni']",'active transition rules are required');
$contains($service,"'engaged'=>['drifting','paused','alumni']",'engaged transition rules are required');
$contains($service,"'paused'=>['active','engaged','alumni']",'paused reactivation rules are required');
$contains($service,'function coveted_membership_lifecycle_set_state','canonical lifecycle mutator is required');
$contains($service,"coveted_is_system_admin(\$admin)",'lifecycle mutations must require System Admin authority');
$contains($service,"coveted_member_journey_scan_rows(\$pdo,200)",'lifecycle index must reuse bounded Member Journey evidence');
$contains($service,"u.status IN ('active','invited')",'Admin lifecycle candidates must preserve invited accounts');
$contains($service,'OR ml.id IS NOT NULL','persisted applicant/paused/alumni records must remain visible');
$contains($service,"'task_sync'=>\$to!==\$state",'only state-changing lifecycle recommendations may sync into Agent tasks');
$contains($service,"in_array(\$state,['paused','alumni'],true)",'paused and alumni states must create relationship-planning holds');
$contains($service,"'state'=>'engaged'",'engagement recommendation is required');
$contains($service,"'state'=>'drifting'",'drift recommendation is required');
$contains($service,'Review upcoming membership renewal','renewal workflow is required');
$contains($service,'Review paused-member reactivation','paused-member workflow is required');
$contains($service,'private membership lifecycle context','member lifecycle privacy boundary must be explicit');
$missing($service,'CREATE TABLE','runtime lifecycle DDL is forbidden');
$missing($service,'ALTER TABLE','runtime lifecycle schema mutation is forbidden');
$missing($service,'UPDATE users','lifecycle CRM must not change account access');
$missing($service,'UPDATE group_memberships','lifecycle CRM must not change group membership access');
$missing($service,"'score'=>",'lifecycle CRM must not persist or expose a hidden member score');

$contains($brain,"require_once __DIR__ . '/membership_lifecycle.php';",'Admin Agent brain must load lifecycle CRM');
$contains($brain,"coveted_membership_lifecycle_agent_context(\$admin, 120, \$pdo)",'Admin Agent brain must load current lifecycle recommendations');
$contains($brain,"'membership_lifecycle' => \$membershipLifecycle",'Admin Agent snapshot must expose lifecycle context');
$contains($brain,"\$opportunities[]=\$recommendation",'lifecycle recommendations must enter the existing opportunity queue');
$contains($brain,"'key' => 'membership_lifecycle'",'Admin Agent capability catalog must include lifecycle CRM');

$contains($actions,"'set_membership_lifecycle_state'",'allowlisted Admin Agent lifecycle action is required');
$contains($actions,'coveted_membership_lifecycle_set_state(','Agent action must use canonical lifecycle service');
$contains($actions,"'admin_agent_approved'",'Agent lifecycle execution source must be auditable');
$contains($actions,'it never changes account or group access','Agent protocol must preserve lifecycle authority boundary');
$missing($actions,'UPDATE membership_lifecycle','Admin Agent must never bypass canonical lifecycle service with raw writes');
$missing($actions,'UPDATE group_memberships','Admin Agent lifecycle integration must not write group access directly');

$contains($concierge,"require_once __DIR__ . '/membership_lifecycle.php';",'member Concierge must load lifecycle guidance');
$contains($concierge,"coveted_membership_lifecycle_member_snapshot(\$user,\$pdo)",'member Concierge must be scoped to the authenticated member lifecycle snapshot');
$contains($concierge,"'membership_lifecycle'=>\$membershipLifecycle",'member Concierge provider context must include own lifecycle state');
$contains($concierge,'What is my membership lifecycle status and what does it mean?','member-facing lifecycle guidance prompt is required');
$contains($concierge,'Membership lifecycle changes remain System Admin-controlled','member Concierge must not gain lifecycle mutation authority');
$missing($concierge,'coveted_membership_lifecycle_set_state(','member Concierge must not mutate lifecycle state');

$contains($workspace,'coveted_require_system_admin();','lifecycle workspace must require System Admin');
$contains($workspace,'coveted_require_csrf();','lifecycle workspace mutations must enforce CSRF');
$contains($workspace,'coveted_membership_lifecycle_set_state(','workspace must use canonical lifecycle mutation service');
$contains($workspace,'coveted_membership_lifecycle_history(','workspace must display append-only lifecycle history');
$contains($workspace,'does not suspend an account, remove a group membership, send a message, or change an RSVP','workspace authority boundary must be explicit');
$missing($workspace,'UPDATE membership_lifecycle','workspace must not contain raw lifecycle writes');
$missing($workspace,'UPDATE users','workspace must not contain raw account-access writes');
$missing($workspace,'UPDATE group_memberships','workspace must not contain raw membership-access writes');

$contains($journey,"require_once dirname(__DIR__) . '/app/membership_lifecycle.php';",'Member Journey workspace must load lifecycle outcomes');
$contains($journey,"coveted_membership_lifecycle_recommendation((array)\$snapshot['metrics'],\$lifecycle)",'Member Journey evidence must feed lifecycle recommendation');
$contains($journey,'Review Lifecycle CRM','Member Journey must link into the lifecycle workflow');

$contains($relationships,"require_once dirname(__DIR__) . '/app/membership_lifecycle.php';",'Relationship Planning must load lifecycle context');
$contains($relationships,"coveted_membership_lifecycle_group_context(\$admin,\$selectedRef,\$pdo)",'Relationship Planning must consume group lifecycle context');
$contains($relationships,"\$snapshot['drifting']=array_values(array_filter",'lifecycle holds must be removed from routine drift planning');
$contains($relationships,"\$snapshot['reconnect_pairs']=array_values(array_filter",'lifecycle holds must be removed from routine reconnect planning');
$contains($relationships,'excluded from routine reconnect planning','relationship UI must explain lifecycle hold behavior');

fwrite(STDOUT,"Membership Lifecycle CRM contract verified.\n");
