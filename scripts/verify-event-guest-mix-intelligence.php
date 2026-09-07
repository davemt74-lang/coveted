<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{$v=@file_get_contents($root.'/'.ltrim($path,'/'));if($v===false){fwrite(STDERR,"Missing required file: {$path}\n");exit(1);}return $v;};
$contains=static function(string $content,string $needle,string $label):void{if(!str_contains($content,$needle)){fwrite(STDERR,"Guest Mix Intelligence contract failed: {$label}\n");exit(1);}};
$missing=static function(string $content,string $needle,string $label):void{if(str_contains($content,$needle)){fwrite(STDERR,"Guest Mix Intelligence contract failed: {$label}\n");exit(1);}};

$service=$read('app/event_guest_mix.php');
$page=$read('admin/event-guest-mix.php');
$relationships=$read('app/member_relationships.php');
$nav=$read('assets/js/event-guest-mix-nav-v1.js');
$loader=$read('assets/js/coveted.js');

$contains($service,'function coveted_event_guest_mix_snapshot','event-specific Guest Mix snapshot required');
$contains($service,'function coveted_event_guest_mix_agent_context','aggregate Admin Agent context required');
$contains($service,"gm.membership_status='active'",'only active group members may be candidates');
$contains($service,"u.status='active'",'inactive accounts must be excluded');
$contains($service,"NOT EXISTS (SELECT 1 FROM event_hosts",'event hosts must be excluded from candidates');
$contains($service,"NOT EXISTS (SELECT 1 FROM event_invitations",'existing event invitees must be excluded');
$contains($service,"NOT EXISTS (SELECT 1 FROM event_rsvps",'existing RSVP state must be respected');
$contains($service,"ea.status IN ('checked_in','attended','left_early')",'positive fit evidence must use verified attendance');
$contains($service,"ea.status='no_show'",'recent no-show evidence must reduce fit');
$contains($service,"er.response='declined'",'recent decline evidence must reduce fit');
$contains($service,"'reconnect'",'reconnect segment required');
$contains($service,"'widen_circle'",'participation-breadth segment required');
$contains($service,"'reliable_repeat'",'repeat-attendee segment required');
$contains($service,"'small_format'",'small-format evidence segment required');
$contains($service,"'pace'",'invitation pacing segment required');
$contains($service,"invitations_60d",'recent invitation-load evidence required');
$contains($service,"other_future_invitations",'future invitation-load evidence required');
$contains($service,"pending_invites",'pending invitations must reduce new recommendation volume');
$contains($service,"'invitation_slots'",'new invitation capacity must be explicit');
$contains($service,'Fit scores are event-specific invitation recommendations, not member-worth or popularity scores.','event-specific scoring boundary required');
$contains($service,'No member identities or private Mutual Reconnect choices are included.','broad Agent identity/privacy boundary required');
$contains($service,'System Admin sends invitations through canonical Event controls.','System Admin invitation authority required');
$missing($service,'coveted_event_invite_user(','Guest Mix must never send an invitation');
$missing($service,'INSERT INTO','Guest Mix service must remain read-only');
$missing($service,'UPDATE ','Guest Mix service must remain read-only');
$missing($service,'DELETE FROM','Guest Mix service must remain read-only');
$missing($service,'CREATE TABLE','Guest Mix must not create runtime schema');
$missing($service,'ALTER TABLE','Guest Mix must not alter runtime schema');

$contains($page,'coveted_require_system_admin();','workspace must be System Admin-only');
$contains($page,'coveted_system_sample_mode($admin,$pdo)','workspace must isolate Full System Sample Mode');
$contains($page,'This is not a member ranking.','workspace must reject member ranking');
$contains($page,'Guest Mix is read-only. It never sends invitations.','workspace must state read-only authority');
$contains($page,'Open Canonical Invitations','workspace must hand off to canonical invitation controls');
$contains($page,'Invitation fit, not member worth','workspace must explain event-specific fit boundary');
$missing($page,'$_POST','Guest Mix workspace must remain read-only');

$contains($relationships,"require_once __DIR__ . '/event_guest_mix.php';",'relationship intelligence must load Guest Mix Agent bridge');
$contains($relationships,'coveted_event_guest_mix_agent_context($admin,12,$pdo)','relationship intelligence must request aggregate Guest Mix context');
$contains($relationships,"'guest_mix'=>",'aggregate Guest Mix context must enter the existing Agent relationship stream');
$contains($relationships,'Aggregate group/event/member-journey relationship and Guest Mix signals only.','combined Agent context must remain aggregate across relationship, journey and Guest Mix signals');
$contains($relationships,'pair identities, Mutual Reconnect choices, contact details, private messages, personality inference, and public rankings','combined Agent context must preserve the established private-data boundary');

$contains($nav,'/admin/event-guest-mix.php?event=','Event Workspace navigation must expose Guest Mix');
$contains($nav,"shell.dataset.systemSample==='1'",'Guest Mix navigation must remain isolated in Sample Mode');
$contains($loader,'event-guest-mix-nav-v1.js','canonical JS loader must load Guest Mix navigation');

fwrite(STDOUT,"Event Guest Mix + Invitation Intelligence contract verified.\n");
