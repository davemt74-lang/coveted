<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{
    $value=@file_get_contents($root.'/'.ltrim($path,'/'));
    if($value===false){fwrite(STDERR,"Missing required file: {$path}\n");exit(1);}return $value;
};
$contains=static function(string $content,string $needle,string $label):void{
    if(!str_contains($content,$needle)){fwrite(STDERR,"Account Agent Chat contract failed: {$label}\n");exit(1);}
};
$missing=static function(string $content,string $needle,string $label):void{
    if(str_contains($content,$needle)){fwrite(STDERR,"Account Agent Chat contract failed: {$label}\n");exit(1);}
};

$threads=$read('app/account_agent_threads.php');
$context=$read('app/account_agent_context.php');
$bootstrap=$read('api/account-agent-bootstrap.php');
$chat=$read('api/account-agent-chat.php');
$js=$read('assets/js/account-agent-shell-v1.js');
$css=$read('assets/css/account-agent-shell-v1.css');
$loader=$read('assets/js/coveted.js');

$contains($threads,"require_once __DIR__ . '/admin_agent_threads.php';",'generic chat must reuse existing durable thread storage');
$contains($threads,'owner_user_id = ?','thread lookup must be owner scoped');
$contains($threads,'owner_user_id=?','recent thread lookup must be owner scoped');
$contains($threads,"role IN ('user','assistant')",'generic history must expose only user/assistant messages');
$contains($threads,"if (!in_array(\$role, ['user','assistant'], true))",'generic append must reject Admin action message roles');
$contains($threads,"'account.agent_thread_created'",'thread creation must be audited');
$missing($threads,'CREATE TABLE','account chat must not create runtime schema');
$missing($threads,'ALTER TABLE','account chat must not alter runtime schema');

$contains($context,'function coveted_account_agent_context_snapshot','role-safe trusted context snapshot is required');
$contains($context,'function coveted_account_agent_capabilities','role capability gate is required');
$contains($context,"FROM event_invitations ei",'member context must use canonical invitation evidence');
$contains($context,"WHERE ei.user_id=?",'member invitation context must be self-scoped');
$contains($context,"FROM event_rsvps er",'member context must use canonical RSVP evidence');
$contains($context,"WHERE er.user_id=?",'member RSVP context must be self-scoped');
$contains($context,"FROM event_hosts eh",'host context must use canonical assignments');
$contains($context,"WHERE eh.user_id=?",'host context must be actor scoped');
$contains($context,"FROM business_admins ba",'business host context must use canonical business authority');
$contains($context,"WHERE ba.user_id=?",'business context must be actor scoped');
$contains($context,"Attendee Hosts assist with assigned Event operations only. They do not create or configure Events.",'host event authority boundary must be explicit');
$contains($context,'Admin-only member intelligence, private relationship timelines, contact details','private/Admin member intelligence must be excluded');
$contains($context,'This shared chat shell is read-and-advise only','shared Agent may not claim mutations');
$contains($context,'coveted_account_agent_provider_secret','account chat must read enabled server-side provider credentials without exposing secrets');
$contains($context,"provider IN ('openai','anthropic')",'only text chat providers may be exposed');
$contains($context,'coveted_ai_http_json(','account chat must reuse the existing allowlisted provider transport');
$missing($context,'coveted_admin_agent_execute_action','account context must never execute Admin actions');
$missing($context,'coveted_admin_agent_action_protocol_message','account context must never receive the Admin mutation protocol');
$missing($context,'member_journey','shared member Agent must not read Admin Member Journey intelligence');
$missing($context,'member_relationship','shared member Agent must not read Admin relationship intelligence');
$missing($context,'CREATE TABLE','account Agent context must not create runtime schema');
$missing($context,'ALTER TABLE','account Agent context must not alter runtime schema');

$contains($bootstrap,'coveted_require_user();','bootstrap must require an authenticated account');
$contains($bootstrap,"REQUEST_METHOD'] ?? 'GET') !== 'GET'",'bootstrap must be GET-only');
$contains($bootstrap,'coveted_account_agent_thread_by_ref($user','requested chat must remain owner scoped');
$contains($bootstrap,"'storage_ready'=>\$storageReady",'shell must fail closed when durable storage is unavailable');
$contains($bootstrap,"'providers'=>\$providers",'provider choices must come from server configuration');
$contains($bootstrap,"'csrf'=>coveted_csrf_token()",'bootstrap must provide CSRF for chat POST');
$contains($bootstrap,"'authority'=>'Chat does not elevate account permissions",'bootstrap must state authority boundary');

$contains($chat,'coveted_require_user();','chat endpoint must require authentication');
$contains($chat,"REQUEST_METHOD'] ?? 'GET') !== 'POST'",'chat endpoint must be POST-only');
$contains($chat,'coveted_require_csrf();','chat endpoint must enforce CSRF');
$contains($chat,'coveted_account_agent_request_id','chat must require bounded request identifiers');
$contains($chat,"SELECT id,public_id,title,status FROM admin_agent_threads",'request claim must lock the durable owner thread');
$contains($chat,'owner_user_id=? LIMIT 1 FOR UPDATE','request claim must be owner-scoped and row locked');
$contains($chat,"That Agent request is already processing. It will not be executed twice.",'concurrent replay must fail closed');
$contains($chat,'session_write_close();','session lock must be released before external provider latency');
$contains($chat,'coveted_account_agent_context_snapshot($user, $surface','server must rebuild role-safe context for every request');
$contains($chat,'coveted_account_agent_chat_history($user','server-side durable history must be authoritative');
$contains($chat,"count(\$recent) >= 20",'per-session chat rate limit is required');
$missing($chat,'coveted_admin_agent_execute_action','shared account endpoint must never execute Admin actions');
$missing($chat,'coveted_event_invite_user','shared account endpoint must never send invitations');
$missing($chat,'UPDATE event_rsvps','shared account endpoint must never mutate RSVPs');
$missing($chat,'UPDATE group_memberships','shared account endpoint must never mutate membership');
$missing($chat,'CREATE TABLE','shared account endpoint must never create runtime schema');

$contains($js,"document.querySelector('[data-admin-agent]')",'shared shell must not duplicate dedicated Admin Agent UI');
$contains($js,"document.querySelector('.cv-app-topbar')",'member shell must only mount for signed-in account pages');
$contains($js,"document.body.classList.contains('cv-admin-body')",'System Admin pages must receive the shared shell too');
$contains($js,"root.dataset.accountAgentShell = '1'",'one shared account Agent shell marker is required');
$contains($js,'/api/account-agent-bootstrap.php','shell must bootstrap from authenticated server context');
$contains($js,"'/api/account-agent-chat.php'",'composer must submit to account-safe chat endpoint');
$contains($js,"localStorage.setItem(storageKey",'active thread must persist across account page navigation');
$contains($js,'window.visualViewport','mobile keyboard viewport handling is required');
$contains($js,"event.key === 'Enter' && !event.shiftKey",'Enter must send while Shift+Enter remains multiline');
$contains($js,'body.set(\'surface\'','current page surface must be sent as bounded context only');
$contains($js,'body.textContent = content','Agent results must render as text, not executable HTML');
$contains($js,'setOpen(true);','sending a prompt must open the chat canvas');
$missing($js,'.innerHTML','shared Agent UI must not inject response or template HTML');

$contains($css,'.cv-account-agent-shell{position:fixed','account Agent must be a persistent fixed shell');
$contains($css,'.cv-account-agent-composer-wrap{position:absolute','composer must stay sticky within the fixed shell');
$contains($css,'.cv-account-agent-canvas{position:absolute','chat results must render in the persistent canvas above the composer');
$contains($css,'env(safe-area-inset-bottom)','mobile safe-area support is required');
$contains($css,'--cv-agent-keyboard-offset','mobile keyboard offset support is required');
$contains($css,'.cv-player:not([hidden])~.cv-account-agent-shell','Agent composer must coexist with the sticky audio player');

$contains($loader,'account-agent-shell-v1.js','canonical app loader must mount the shared Agent shell');

fwrite(STDOUT,"Persistent logged-in Account Agent Chat shell contract verified.\n");
