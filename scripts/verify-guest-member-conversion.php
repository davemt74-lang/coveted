<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{
    $value=@file_get_contents($root.'/'.ltrim($path,'/'));
    if($value===false){fwrite(STDERR,"Missing required file: {$path}\n");exit(1);}return $value;
};
$contains=static function(string $content,string $needle,string $label):void{
    if(!str_contains($content,$needle)){fwrite(STDERR,"Guest → Member Conversion contract failed: {$label}\n");exit(1);}
};
$missing=static function(string $content,string $needle,string $label):void{
    if(str_contains($content,$needle)){fwrite(STDERR,"Guest → Member Conversion contract failed: {$label}\n");exit(1);}
};

$service=$read('app/guest_member_conversion.php');
$workspace=$read('admin/guest-conversions.php');
$operations=$read('app/operations.php');
$operationsUi=$read('admin/operations.php');
$brain=$read('app/admin_agent_brain.php');
$tasks=$read('app/admin_agent_tasks.php');
$journey=$read('app/member_journey.php');
$journeyUi=$read('admin/member-journeys.php');
$adminUi=$read('app/admin_ui.php');
$groups=$read('app/groups.php');
$continuity=$read('app/guest_continuity.php');

$contains($service,'function coveted_guest_conversion_require_admin','conversion service must have a System Admin authority gate');
$contains($service,"coveted_is_system_admin(\$admin)",'conversion service authority must be System Admin');
$contains($service,"gm.membership_status = 'active'",'candidates must be current active group relationships');
$contains($service,"gm.group_role = 'guest'",'candidates must be current Guests');
$contains($service,"ea.status IN ('checked_in','attended','left_early')",'candidate evidence must use verified attendance statuses');
$contains($service,"e.status = 'completed'",'candidate evidence must use completed Events');
$contains($service,'verified_events_180d','conversion readiness must include transparent recency evidence');
$contains($service,"'first_time'",'first-time guest state is required');
$contains($service,"'returning'",'returning guest state is required');
$contains($service,"'conversion_ready'",'conversion-ready guest state is required');
$contains($service,"'hold'",'no-pressure hold state is required');
$contains($service,"(int)\$best['verified_events'] >= 2 && (int)\$best['verified_events_180d'] >= 1",'conversion-ready rule must require repeat and recent verified participation');
$contains($service,"'guest.stay_declined'",'recent Invite-to-Stay decline must feed the no-pressure hold');
$contains($service,'60 * 86400','decline pacing window must be explicit and bounded');
$contains($service,"'pending_stay_invite'",'active Invite-to-Stay must create a hold');
$contains($service,"'guest_pass_referrer_name'",'Guest Pass relationship path must be retained when known');
$contains($service,'gp.issued_to_user_id','Guest Pass owner must be the canonical referral source');
$contains($service,"'group_membership_inviter'",'membership inviter must be an explicit referral fallback');
$contains($service,"((int)\$b['verified_events']) <=> ((int)\$a['verified_events'])",'best-fit group must prioritize verified attendance count');
$contains($service,"strcmp((string)\$b['last_verified_at'], (string)\$a['last_verified_at'])",'best-fit tie break must prioritize most recent verified attendance');
$contains($service,'function coveted_guest_conversion_agent_context','aggregate Agent context is required');
$contains($service,"'counts' => \$counts",'Agent context must expose aggregate counts');
$contains($service,"'key' => 'guest-conversion-review'",'aggregate proactive Agent opportunity is required');
$contains($service,"'href' => '/admin/guest-conversions.php'",'Agent opportunity must route to the private workspace');
$contains($service,'Aggregate counts only. Guest names, emails, referrers and event history are restricted','Agent privacy boundary must be explicit');
$contains($service,'function coveted_guest_conversion_approve_invite','explicit approval wrapper is required');
$contains($service,"(string)\$candidate['state'] !== 'conversion_ready'",'approval must revalidate current conversion-ready state');
$contains($service,'coveted_group_create_stay_invitation(','Admin approval must use the canonical Invite-to-Stay service');
$contains($service,'function coveted_guest_conversion_member_origin','accepted conversion provenance is required');
$contains($service,"gi.public_id LIKE 'gstay\\\\_%'",'Member Journey origin must be limited to Invite-to-Stay records');
$contains($service,"gi.status = 'accepted'",'Member Journey origin must require guest acceptance');
$missing($service,'CREATE TABLE','runtime conversion DDL is forbidden');
$missing($service,'ALTER TABLE','runtime conversion schema mutation is forbidden');
$missing($service,'UPDATE group_memberships','conversion intelligence must never directly change group membership');
$missing($service,'INSERT INTO group_memberships','conversion intelligence must never create group membership directly');
$missing($service,'guest_value_score','guest value scoring is forbidden');
$missing($service,'propensity_score','guest conversion propensity scoring is forbidden');
$missing($service,"'score'=>",'scalar guest scoring is forbidden');

$contains($workspace,'coveted_require_system_admin();','Guest Conversion workspace must require System Admin');
$contains($workspace,'coveted_require_csrf();','Invite-to-Stay approval must enforce CSRF');
$contains($workspace,'coveted_guest_conversion_approve_invite(','workspace must use the canonical conversion approval wrapper');
$contains($workspace,'Approve Invite to Stay','workspace must expose an explicit Admin approval action');
$contains($workspace,'They remain a Guest until they personally accept the invitation.','workspace must explain guest acceptance authority');
$contains($workspace,'No-pressure hold.','workspace must visibly honor pacing holds');
$contains($workspace,'No personality inference or guest-value score','workspace privacy model must be visible');
$missing($workspace,'UPDATE group_memberships','workspace must not directly mutate group membership');
$missing($workspace,'INSERT INTO group_memberships','workspace must not directly create group membership');

$contains($operations,"require_once __DIR__ . '/guest_member_conversion.php';",'Operations must load Guest Conversion intelligence');
$contains($operations,'coveted_guest_conversion_agent_context($actor, $pdo)','Operations must load aggregate conversion context');
$contains($operations,"\$summary['guest_conversion']",'Operations summary must expose aggregate Guest Conversion state');
$contains($operations,"\$summary['guest_conversion_attention']",'Operations attention must include conversion-ready work');
$contains($operations,"'guest_conversion' => \$guestConversion",'Operations snapshot must retain aggregate conversion context');
$missing($operations,'guest_ref','Operations must not expose exact Guest refs');
$missing($operations,'guest_user_id','Operations must not expose exact Guest user ids');
$missing($operations,'guest_pass_referrer_name','Operations must not expose referrer identities');

$contains($operationsUi,"href=\"/admin/guest-conversions.php\"",'Operations UI must link to the private conversion workspace');
$contains($operationsUi,"\$guestCounts['conversion_ready']",'Operations UI must visibly surface conversion-ready count');
$contains($operationsUi,"\$guestCounts['hold']",'Operations UI must visibly surface no-pressure holds');
$contains($operationsUi,'Exact guest identities, referral paths and attendance evidence stay inside the private System Admin workspace.','Operations UI must explain aggregate-only privacy');

$contains($brain,"'guest_conversion'",'Admin Agent capability/stream must include Guest Conversion');
$contains($brain,"'guest-conversions.php'",'Admin Agent must route conversion review to the private workspace');
$contains($brain,"['event_planning','host_command','event_results','guest_conversion']",'Guest Conversion recommendations must enter the canonical Agent opportunity queue');
$contains($brain,'recommend an explicit System Admin Invite-to-Stay review without automatic enrollment','Agent capability must preserve Admin and guest authority');
$missing($brain,'coveted_group_create_stay_invitation(','Admin Agent brain must never execute Invite to Stay itself');

$contains($tasks,"array_key_exists('task_sync', \$item) && \$item['task_sync'] === false",'Agent task sync must default deterministic Guest Conversion opportunity to Suggested');
$contains($tasks,"'suggested'",'Agent opportunities must enter the existing Suggested task workflow');

$contains($journey,"require_once __DIR__ . '/guest_member_conversion.php';",'Member Journey must load conversion provenance');
$contains($journey,"'membership_origin'=>coveted_guest_conversion_member_origin",'Member Journey snapshot must contain accepted Guest conversion origin');
$contains($journeyUi,'GUEST → MEMBER ORIGIN','Member Journey UI must display conversion origin');
$contains($journeyUi,'Accepted Invite to Stay','Member Journey must explain canonical accepted conversion outcome');
$contains($journeyUi,'The member conversion was recorded only after the guest accepted','Member Journey must preserve guest acceptance semantics');

$contains($adminUi,"'/admin/guest-conversions.php'",'Admin shell must route Guest Conversion safely in sample mode');
$contains($adminUi,"'guest-conversions' => 'people'",'Admin navigation sample mapping must include Guest Conversion');
$contains($adminUi,"'/admin/guest-conversions.php', 'Guest Conversion'",'Admin People navigation must expose Guest Conversion');

$contains($continuity,'function coveted_group_create_stay_invitation','canonical Invite-to-Stay creator must remain the only Admin issuance path');
$contains($groups,"str_starts_with((string)\$invite['public_id'], 'gstay_')",'canonical group invite response must recognize Invite to Stay');
$contains($groups,"SET group_role = 'member'",'canonical guest acceptance must remain the membership conversion mutation');
$contains($groups,"'guest.became_member'",'canonical conversion outcome must remain auditable');

fwrite(STDOUT,"Guest → Member Conversion contract verified.\n");