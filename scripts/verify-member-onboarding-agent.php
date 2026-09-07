<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{
    $value=@file_get_contents($root.'/'.ltrim($path,'/'));
    if($value===false){fwrite(STDERR,"Missing required file: {$path}\n");exit(1);}return $value;
};
$contains=static function(string $content,string $needle,string $label):void{
    if(!str_contains($content,$needle)){fwrite(STDERR,"Member Onboarding Agent contract failed: {$label}\n");exit(1);}
};
$missing=static function(string $content,string $needle,string $label):void{
    if(str_contains($content,$needle)){fwrite(STDERR,"Member Onboarding Agent contract failed: {$label}\n");exit(1);}
};

$onboarding=$read('app/member_onboarding_agent.php');
$bootstrap=$read('api/account-agent-bootstrap.php');
$chat=$read('api/account-agent-chat.php');

$contains($onboarding,'function coveted_member_onboarding_agent_snapshot','onboarding snapshot helper is required');
$contains($onboarding,'FROM profiles WHERE user_id=?','profile evidence must be self scoped');
$contains($onboarding,"WHERE gm.user_id=? AND gm.membership_status='active'",'group evidence must be self scoped');
$contains($onboarding,"WHERE er.user_id=? AND er.response='attending'",'attendance evidence must be self scoped');
$contains($onboarding,"WHERE ei.user_id=? AND ei.status='pending'",'invitation evidence must be self scoped');
$contains($onboarding,'WHERE user_id=? AND status NOT IN','benefit evidence must be self scoped');
$contains($onboarding,"'profile_setup'",'profile setup stage is required');
$contains($onboarding,"'find_group'",'group onboarding stage is required');
$contains($onboarding,"'first_event'",'first Event onboarding stage is required');
$contains($onboarding,"'prepare_first_event'",'first Event preparation stage is required');
$contains($onboarding,"'participating'",'participating member stage is required');
$contains($onboarding,"'action_url'=>'/invitations.php'",'invited member must route to canonical Invitations control');
$contains($onboarding,"'url'=>'/profile.php'",'profile guidance must route to canonical Profile control');
$contains($onboarding,"'url'=>'/groups.php'",'group guidance must route to canonical Groups control');
$contains($onboarding,"'url'=>'/events.php'",'Event discovery must route to canonical Events control');
$contains($onboarding,"'url'=>'/benefits.php'",'benefit guidance must route to canonical Benefits control');
$contains($onboarding,"'url'=>'/reconnect.php'",'post-Event relationship guidance must route to canonical Reconnect control');
$contains($onboarding,"'reconnect_available'=>\$pastAttended > 0",'Reconnect guidance must require the member own verified attendance evidence');
$contains($onboarding,'never exposes one-sided reconnect interest','privacy boundary must be explicit');
$missing($onboarding,'reconnect_requests','onboarding must not inspect private reconnect request state');
$missing($onboarding,'member_journey','onboarding must not read Admin Member Journey intelligence');
$missing($onboarding,'member_relationship','onboarding must not read Admin relationship intelligence');
$missing($onboarding,'CREATE TABLE','onboarding must not create runtime schema');
$missing($onboarding,'ALTER TABLE','onboarding must not alter runtime schema');

$contains($bootstrap,"require_once dirname(__DIR__) . '/app/member_onboarding_agent.php';",'bootstrap must load onboarding context');
$contains($bootstrap,'coveted_member_onboarding_agent_snapshot($user, $pdo)','bootstrap must derive live onboarding guidance');
$contains($bootstrap,"empty(\$caps['system_admin'])",'shared member onboarding must stay out of System Admin mode');
$contains($bootstrap,"'welcome'=>\$welcome",'bootstrap must expose proactive onboarding welcome copy');
$contains($bootstrap,"'onboarding'=>\$onboarding",'bootstrap must expose onboarding state to the shared shell');
$contains($bootstrap,"starter_prompts",'onboarding must drive relevant starter prompts');

$contains($chat,"require_once dirname(__DIR__) . '/app/member_onboarding_agent.php';",'chat must load onboarding context');
$contains($chat,"\$snapshot['onboarding'] = coveted_member_onboarding_agent_snapshot(\$user, \$pdo);",'chat provider context must include the member onboarding snapshot');
$contains($chat,"empty(\$snapshot['capabilities']['system_admin'])",'chat must not inject member onboarding into System Admin context');
$contains($chat,"'onboarding_stage'=>(string)(\$snapshot['onboarding']['stage'] ?? '')",'durable assistant metadata should retain the onboarding stage used');
$missing($chat,'coveted_admin_agent_execute_action','onboarding chat must not gain Admin mutation authority');

fwrite(STDOUT,"Member Onboarding Agent contract verified.\n");
