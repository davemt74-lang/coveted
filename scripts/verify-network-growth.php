<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{
    $value=@file_get_contents($root.'/'.ltrim($path,'/'));
    if($value===false){fwrite(STDERR,"Missing required file: {$path}\n");exit(1);}return $value;
};
$contains=static function(string $content,string $needle,string $label):void{
    if(!str_contains($content,$needle)){fwrite(STDERR,"Network Growth contract failed: {$label}\n");exit(1);}
};
$missing=static function(string $content,string $needle,string $label):void{
    if(str_contains($content,$needle)){fwrite(STDERR,"Network Growth contract failed: {$label}\n");exit(1);}
};

$service=$read('app/network_growth.php');
$page=$read('admin/network-growth.php');
$operations=$read('app/operations.php');
$brain=$read('app/admin_agent_brain.php');
$tasks=$read('app/admin_agent_tasks.php');
$adminUi=$read('app/admin_ui.php');
$journey=$read('app/member_journey.php');
$journeyPage=$read('admin/member-journeys.php');
$relationships=$read('admin/member-relationships.php');

$contains($service,'function coveted_network_growth_referral_rows','canonical referral outcome reader is required');
$contains($service,"gp.status='used'",'network growth must begin with an actually used Guest Pass, not sent invite volume');
$contains($service,'gp.issued_to_user_id AS referrer_user_id','canonical Guest Pass owner must identify the referrer');
$contains($service,"ea.status IN ('checked_in','attended','left_early')",'participation must use verified attendance statuses');
$contains($service,"e.status='completed'",'participation must require completed Events');
$contains($service,"(int)\$row['verified_events'] >= 2",'repeat participation must have a transparent two-event rule');
$contains($service,"public_id LIKE 'gstay\\\\_%'",'membership conversion must use accepted canonical Invite-to-Stay evidence');
$contains($service,"e.starts_at>stay.converted_at",'stronger post-conversion engagement must be measured after conversion');
$contains($service,'$daysSinceIntroduction >= 60','arrival gap must use a transparent 60-day pacing rule');
$contains($service,"'conversion_ready_here'",'current referral outcomes must bridge to the existing Guest Conversion rule');
$contains($service,"'sustained_member'",'post-conversion participation outcome is required');
$contains($service,"'healthy_growth'",'healthy group growth evidence state is required');
$contains($service,"'network-growth-conversion-'",'group-level conversion review opportunity is required');
$contains($service,"'network-growth-arrival-gap-'",'group-level arrival-gap opportunity is required');
$contains($service,'function coveted_network_growth_agent_context','aggregate Agent context is required');
$contains($service,'Broad Agent context intentionally contains no referrer, guest, email','Agent privacy boundary must explicitly exclude relationship identities');
$contains($service,'Healthy network growth means verified attendance, repeat participation, conversion or post-conversion participation—not raw invitation volume.','measurement boundary must reject raw invite volume');
$contains($service,'function coveted_network_growth_member_origin','Member Journey referral provenance is required');

foreach(['CREATE TABLE','ALTER TABLE','INSERT INTO group_invitations','UPDATE group_invitations','INSERT INTO group_memberships','UPDATE group_memberships','coveted_group_create_invitation(','coveted_group_issue_guest_pass(','coveted_group_create_stay_invitation('] as $needle){
    $missing($service,$needle,'Network Growth service must remain read-only and not bypass canonical actions: '.$needle);
}
foreach(["'score'=>",'referral_score','member_value_score','leaderboard','popularity_rank'] as $needle){
    $missing($service,$needle,'Network Growth must not create ranking/scoring state: '.$needle);
}

$contains($page,'coveted_require_system_admin();','Network Growth workspace must require System Admin');
$contains($page,"coveted_admin_ui_start(\$admin, 'network-growth', 'Referral / Network Growth')",'workspace must own its Admin nav state');
$contains($page,'Each row starts with a <strong>used</strong> canonical Guest Pass','workspace must explain outcome-first measurement');
$contains($page,'/admin/guest-conversions.php?guest=','conversion action must hand off to the existing private Guest Conversion workflow');
$contains($page,'/admin/member-journeys.php?member=','successful conversions must link into Member Journey');
$contains($page,'does not create referral points, public leaderboards, member-value scores or personality labels','workspace must state the anti-ranking boundary');
foreach(['coveted_group_create_invitation(','coveted_group_issue_guest_pass(','coveted_group_create_stay_invitation(','UPDATE group_memberships','INSERT INTO group_invitations'] as $needle){
    $missing($page,$needle,'Network Growth workspace must not execute invitation/membership changes: '.$needle);
}

$contains($operations,"require_once __DIR__ . '/network_growth.php';",'Operations must load Network Growth');
$contains($operations,'coveted_network_growth_agent_context($actor, 30, $pdo)','Operations must load aggregate Network Growth context');
$contains($operations,"'network_growth_attention'",'Operations launch health must include Network Growth attention');
$contains($operations,"'network_growth'",'Operations summary must expose Network Growth context');
$contains($operations,'$networkGrowthRecommendations=array_slice','Network Growth recommendations must be bounded before Agent promotion');
$contains($operations,'$resultRecommendations=array_merge(','Network Growth recommendations must use the shared promoted result stream');
$contains($brain,"foreach (['event_planning','host_command','event_results','guest_conversion'] as \$streamKey)",'Admin Agent must continue promoting the Operations recommendation streams');
$contains($tasks,'function coveted_admin_agent_tasks_sync_opportunities','Network Growth recommendations must reach the canonical proactive task queue');

$contains($adminUi,"'/admin/network-growth.php',",'Sample Mode router must cover Network Growth');
$contains($adminUi,"'network-growth' => 'people'",'Network Growth nav must be Sample Mode safe');
$contains($adminUi,"coveted_admin_nav_link(\$active, 'network-growth', '/admin/network-growth.php', 'Network Growth')",'Network Growth must be first-class People navigation');

$contains($journey,"require_once __DIR__ . '/network_growth.php';",'Member Journey must load referral outcome provenance');
$contains($journey,"'network_growth_origin'=>coveted_network_growth_member_origin",'Member Journey snapshot must include successful referral outcome evidence');
$contains($journeyPage,'REFERRAL / NETWORK GROWTH','Member Journey UI must display referral/network outcome');
$contains($journeyPage,"'pre_conversion_verified'",'Member Journey must show verified participation before conversion');
$contains($journeyPage,"'post_conversion_verified'",'Member Journey must show verified participation after conversion');

$contains($relationships,"require_once dirname(__DIR__) . '/app/network_growth.php';",'Group Relationship Planning must load Network Growth');
$contains($relationships,'coveted_network_growth_group_snapshot($admin,$selectedRef,$pdo)','Group Relationship Planning must consume selected-group referral outcomes');
$contains($relationships,'NETWORK GROWTH','Group Relationship Planning must display network-growth evidence');
$contains($relationships,'does not create a referral rank','Group Relationship Planning must preserve anti-ranking boundary');

fwrite(STDOUT,"Referral / Network Growth contract verified.\n");
