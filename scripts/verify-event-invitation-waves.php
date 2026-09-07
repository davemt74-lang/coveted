<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{$v=@file_get_contents($root.'/'.ltrim($path,'/'));if($v===false){fwrite(STDERR,"Missing required file: {$path}\n");exit(1);}return $v;};
$contains=static function(string $content,string $needle,string $label):void{if(!str_contains($content,$needle)){fwrite(STDERR,"Invitation Waves contract failed: {$label}\n");exit(1);}};
$missing=static function(string $content,string $needle,string $label):void{if(str_contains($content,$needle)){fwrite(STDERR,"Invitation Waves contract failed: {$label}\n");exit(1);}};

$service=$read('app/event_invitation_waves.php');
$page=$read('admin/event-invitation-waves.php');
$operations=$read('app/operations.php');
$nav=$read('assets/js/event-invitation-waves-nav-v1.js');
$loader=$read('assets/js/coveted.js');

$contains($service,'function coveted_event_invitation_wave_snapshot','event forecast snapshot required');
$contains($service,'function coveted_event_invitation_wave_agent_context','aggregate Agent context required');
$contains($service,'function coveted_event_invitation_wave_reconcile_waitlist','explicit waitlist reconciliation required');
$contains($service,"e.status='completed'",'forecast learning must use completed historical events');
$contains($service,"JOIN event_invitations ei",'historical invitation evidence required');
$contains($service,"LEFT JOIN event_rsvps er",'historical RSVP evidence required');
$contains($service,"LEFT JOIN event_attendance ea",'verified attendance evidence required');
$contains($service,"'high' => 0.90",'confidence-weighted history required');
$contains($service,"'response_window_hours'",'learned response window required');
$contains($service,"'wave_1'",'Wave 1 required');
$contains($service,"'wave_2'",'Wave 2 required');
$contains($service,"'wave_3'",'Wave 3 required');
$contains($service,"'hold_response_window'",'pending-response hold state required');
$contains($service,"'hold_waitlist'",'waitlist hold state required');
$contains($service,"'reconcile_waitlist'",'waitlist reconciliation recommendation required');
$contains($service,"\$safeAdditionalInvites = min(\$rawAdditionalInvites, \$safeInvitationSlots, \$availableCandidates);",'forecast must obey Guest Mix safe invitation slots');
$contains($service,"coveted_event_promote_waitlist_locked(\$pdo, \$event)",'waitlist reconciliation must use canonical promotion service');
$contains($service,"coveted_event_require_system_admin(\$admin)",'waitlist reconciliation must preserve System Admin authority');
$contains($service,"No recommended member identities",'Agent privacy boundary must explicitly exclude recipient identities');
$missing($service,'coveted_event_invite_user(','forecast service must never send invitations');
$missing($service,'INSERT INTO event_invitations','forecast service must not create invitations directly');
$missing($service,'UPDATE event_invitations','forecast service must not mutate invitations directly');
$missing($service,'CREATE TABLE','forecast service must not create schema');
$missing($service,'ALTER TABLE','forecast service must not alter schema');

$contains($page,'coveted_require_system_admin();','workspace must be System Admin-only');
$contains($page,'coveted_system_sample_mode($admin,$pdo)','workspace must isolate Full System Sample Mode');
$contains($page,'coveted_require_csrf();','waitlist mutation must require CSRF');
$contains($page,"name=\"action\" value=\"reconcile_waitlist\"",'waitlist action must be explicit');
$contains($page,'Canonical Invitations','workspace must hand off to canonical invitation controls');
$contains($page,'No autonomous invitations.','workspace must explain invitation authority');
$contains($page,'RECOMMENDED RECIPIENTS','detailed Admin-only recipient recommendation view required');
$missing($page,'coveted_event_invite_user(','workspace must not send invitations itself');

$contains($operations,"require_once __DIR__ . '/event_invitation_waves.php';",'Operations must load Invitation Waves');
$contains($operations,'coveted_event_invitation_wave_agent_context($actor, 12, $pdo)','Operations must request aggregate wave context');
$contains($operations,"\$summary['invitation_waves']",'Operations summary must expose wave forecasts');
$contains($operations,"\$summary['invitation_wave_attention']",'wave attention must contribute to Operations');
$contains($operations,'$invitationWaveRecommendations','Invitation Wave recommendations must enter promoted Agent stream');
$contains($operations,'usort($resultRecommendations','promoted Agent stream must be priority-sorted before slicing');
$contains($operations,"'invitation_waves' => \$invitationWaves",'Operations response must expose Invitation Waves context');

$contains($nav,'event-invitation-waves.php?event=','Event navigation must expose Invitation Waves');
$contains($nav,"shell.dataset.systemSample === '1'",'navigation must stay isolated in Sample Mode');
$contains($loader,'event-invitation-waves-nav-v1.js','canonical JS loader must load Invitation Waves navigation');

fwrite(STDOUT,"Invitation Waves + RSVP Forecasting contract verified.\n");
