<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{
    $value=@file_get_contents($root.'/'.ltrim($path,'/'));
    if($value===false){fwrite(STDERR,"Missing required file: {$path}\n");exit(1);}return $value;
};
$contains=static function(string $content,string $needle,string $label):void{
    if(!str_contains($content,$needle)){fwrite(STDERR,"Personal Member Concierge contract failed: {$label}\n");exit(1);}
};
$missing=static function(string $content,string $needle,string $label):void{
    if(str_contains($content,$needle)){fwrite(STDERR,"Personal Member Concierge contract failed: {$label}\n");exit(1);}
};

$concierge=$read('app/member_concierge.php');
$bootstrap=$read('api/account-agent-bootstrap.php');
$chat=$read('api/account-agent-chat.php');
$action=$read('api/account-agent-action.php');
$js=$read('assets/js/account-agent-shell-v1.js');
$css=$read('assets/css/account-agent-shell-v1.css');

$contains($concierge,'function coveted_member_concierge_snapshot','private Concierge snapshot helper is required');
$contains($concierge,"coveted_member_journey_metrics_row(\$pdo,(string)\$user['public_id'])",'Member Journey metrics must resolve only the authenticated member');
$contains($concierge,"coveted_member_journey_preferences(\$pdo,(int)\$user['id'])",'Event preference evidence must be self scoped');
$contains($concierge,"coveted_member_journey_timeline(\$pdo,(int)\$user['id'],16)",'recent Member Journey timeline must be self scoped');
$contains($concierge,'coveted_events_for_user($user,100)','Event recommendations must start from canonical permission-filtered member Events');
$contains($concierge,"coveted_reward_list_for_user((int)\$user['id'],[],'inbox')",'benefits must use the canonical member reward inbox');
$contains($concierge,'WHERE user_id=? AND read_at IS NULL','notification attention must be self scoped');
$contains($concierge,"\$visibility === 'immediate' || \$revealed",'unrevealed Event locations must be withheld');
$contains($concierge,"\$status!=='published' && !(\$status==='closed' && \$response==='attending')",'closed upcoming Events already being attended must remain available for Event preparation');
$contains($concierge,'coveted_member_v2_reconnect_events($user,$pdo)','Reconnect opportunities must use the existing member-safe verified Event surface');
$contains($concierge,'coveted_member_v2_reconnect_matches($user,$pdo)','person-level Reconnect context must use mutual matches only');
$contains($concierge,'one-sided reconnect choices are not included','Reconnect privacy boundary must be explicit');
$contains($concierge,"'requires_confirmation'=>true",'member mutation cards must explicitly require confirmation');
$contains($concierge,"'What should I attend next?'",'Concierge must support Event recommendations');
$contains($concierge,"'What perks or benefits can I use?'",'Concierge must support Benefit questions');
$contains($concierge,"'What reconnect opportunities are available to me?'",'Concierge must support permitted Reconnect questions');
$missing($concierge,'reconnect_requests','Concierge must not read raw one-sided reconnect request storage');
$missing($concierge,'coveted_member_journey_snapshot','Concierge must not call the System Admin Member Journey snapshot');
$missing($concierge,'coveted_member_journey_decision','Concierge must not expose the System Admin member intervention decision layer');
$missing($concierge,'CREATE TABLE','Concierge must not create runtime schema');
$missing($concierge,'ALTER TABLE','Concierge must not alter runtime schema');

$contains($bootstrap,"require_once dirname(__DIR__) . '/app/member_concierge.php';",'Agent bootstrap must load the Concierge');
$contains($bootstrap,'coveted_member_concierge_snapshot($user, $pdo)','Agent bootstrap must derive fresh private Concierge context');
$contains($bootstrap,"empty(\$caps['system_admin'])",'Member Concierge must be excluded from System Admin shell context');
$contains($bootstrap,"'concierge'=>\$concierge",'bootstrap must expose proactive Concierge items to the shared canvas');
$contains($bootstrap,"'title'=>'Your Coveted Concierge'",'member empty canvas must become a Concierge surface');

$contains($chat,"require_once dirname(__DIR__) . '/app/member_concierge.php';",'Agent chat must load the Concierge');
$contains($chat,"\$snapshot['concierge'] = coveted_member_concierge_snapshot(\$user, \$pdo);",'provider context must receive the current member private Concierge snapshot');
$contains($chat,"empty(\$snapshot['capabilities']['system_admin'])",'chat must not inject member Concierge context for System Admin');
$contains($chat,"'concierge_attention_count'=>count",'durable Agent metadata should record the attention state used');
$missing($chat,'coveted_event_set_rsvp(','LLM chat endpoint must never execute an RSVP mutation');
$missing($chat,'coveted_event_respond_invitation(','LLM chat endpoint must never execute an invitation response');

$contains($action,'coveted_require_user();','Concierge action endpoint must require authentication');
$contains($action,"REQUEST_METHOD'] ?? 'GET')!=='POST'",'Concierge action endpoint must be POST only');
$contains($action,'coveted_require_csrf();','Concierge action endpoint must enforce CSRF');
$contains($action,"(string)(\$_POST['confirmed'] ?? '')!=='1'",'Concierge actions must fail without explicit member confirmation');
$contains($action,"in_array(\$action,['respond_invitation','set_rsvp'],true)",'Concierge action types must be allowlisted');
$contains($action,'coveted_is_system_admin($user)','System Admin must be routed to the dedicated Admin Agent');
$contains($action,'coveted_account_agent_request_id','Concierge actions must use bounded request identifiers');
$contains($action,'member_concierge_action_timestamps','Concierge mutations must be rate limited');
$contains($action,'member_concierge_action_results','Concierge action retries must be replay-safe within the authenticated session');
$contains($action,'coveted_event_respond_invitation(','invitation mutations must use the canonical Event service');
$contains($action,'coveted_event_set_rsvp(','RSVP mutations must use the canonical Event service');
$contains($action,"coveted_account_agent_thread_create(\$user,'Concierge action'",'confirmed actions must create durable chat history when no active thread exists');
$contains($action,"'source'=>'member_concierge_action'",'confirmed action result must be traceable in Agent history');
$contains($action,"'account.member_concierge_action_confirmed'",'confirmed member actions must be audited');
$missing($action,'UPDATE event_rsvps','Concierge endpoint must never bypass canonical RSVP service with raw writes');
$missing($action,'UPDATE event_invitations','Concierge endpoint must never bypass canonical invitation service with raw writes');
$missing($action,'coveted_admin_agent_execute_action','Member Concierge must not gain Admin mutation authority');

$contains($js,"const conciergePanel = el('section', 'cv-account-agent-concierge')",'persistent Agent canvas must include proactive Concierge attention');
$contains($js,"confirm.dataset.conciergeConfirm = '1'",'mutation controls must render a separate confirmation state');
$contains($js,"body.set('confirmed', '1')",'only the explicit confirmation path may call the mutation endpoint');
$contains($js,"'/api/account-agent-action.php'",'confirmed action must use the server-owned Concierge endpoint');
$contains($js,"Chat history could not be saved for this action.",'confirmed action result must remain visible when durable chat persistence is unavailable');
$contains($js,"body.set('surface', window.location.pathname);",'Concierge must preserve pathname-only page context hardening');
$contains($js,'safeInternalPath','Concierge links must be restricted to internal paths');
$contains($js,'body.textContent = content','Agent response rendering must remain text only');
$missing($js,'.innerHTML','Concierge UI must not inject executable server/model HTML');

$contains($css,'.cv-account-agent-concierge{','Concierge attention surface styling is required');
$contains($css,'.cv-account-agent-concierge-confirm{','explicit confirmation styling is required');
$contains($css,'.cv-account-agent-concierge-confirm-button{','confirmed mutation button styling is required');

fwrite(STDOUT,"Personal Member Concierge contract verified.\n");
