<?php
declare(strict_types=1);

require_once __DIR__ . '/site_settings.php';

/**
 * Global, read-only Coveted demo dataset.
 *
 * This is intentionally in-memory presentation state. It never inserts fake
 * users, events, businesses, claims, CRM records or Agent tasks into production
 * tables. System Admins can use it to exercise the full product story while
 * ordinary members and partner accounts continue to see live canonical data.
 */

function coveted_system_sample_mode(array $user, ?PDO $pdo = null): bool
{
    return coveted_is_system_admin($user)
        && coveted_site_setting_bool(COVETED_SETTING_SYSTEM_SAMPLE_DATA, false, $pdo);
}

function coveted_system_sample_time(string $modifier, int $hour = 12, int $minute = 0): string
{
    $local = new DateTimeImmutable('now', new DateTimeZone('America/Phoenix'));
    $local = $local->modify($modifier)->setTime($hour, $minute);
    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** @return array<string,mixed> */
function coveted_system_sample_data(): array
{
    $people = [
        ['id'=>101,'public_id'=>'sample-taylor-kim','name'=>'Taylor Kim','display_name'=>'Taylor Kim','email'=>'taylor.kim@example.test','status'=>'active','roles'=>['attendee'],'city'=>'Phoenix, Arizona','image'=>'/assets/images/sample/people/taylor-kim.webp','context'=>'The Inner Circle · product designer'],
        ['id'=>102,'public_id'=>'sample-jordan-ellis','name'=>'Jordan Ellis','display_name'=>'Jordan Ellis','email'=>'jordan.ellis@example.test','status'=>'active','roles'=>['attendee','attendee_host'],'city'=>'Phoenix, Arizona','image'=>'/assets/images/sample/people/jordan-ellis.webp','context'=>'The Inner Circle · attendee host'],
        ['id'=>103,'public_id'=>'sample-maya-rivera','name'=>'Maya Rivera','display_name'=>'Maya Rivera','email'=>'maya.rivera@example.test','status'=>'active','roles'=>['attendee'],'city'=>'Phoenix, Arizona','image'=>'/assets/images/sample/people/maya-rivera.webp','context'=>'City Table Club · member'],
        ['id'=>104,'public_id'=>'sample-leo-martinez','name'=>'Leo Martinez','display_name'=>'Leo Martinez','email'=>'leo.martinez@example.test','status'=>'active','roles'=>['attendee'],'city'=>'Phoenix, Arizona','image'=>'/assets/images/sample/people/leo-martinez.webp','context'=>'Harbor House · Business Admin'],
        ['id'=>105,'public_id'=>'sample-sienna-cole','name'=>'Sienna Cole','display_name'=>'Sienna Cole','email'=>'sienna.cole@example.test','status'=>'active','roles'=>['attendee','artist_partner'],'city'=>'Phoenix, Arizona','image'=>'/assets/images/sample/people/sienna-cole.webp','context'=>'Late Night Listening · artist partner'],
        ['id'=>106,'public_id'=>'sample-noah-bennett','name'=>'Noah Bennett','display_name'=>'Noah Bennett','email'=>'noah.bennett@example.test','status'=>'active','roles'=>['attendee','attendee_host'],'city'=>'Phoenix, Arizona','image'=>'/assets/images/sample/people/noah-bennett.webp','context'=>'City Table Club · attendee host'],
        ['id'=>107,'public_id'=>'sample-elena-park','name'=>'Elena Park','display_name'=>'Elena Park','email'=>'elena.park@example.test','status'=>'active','roles'=>['attendee'],'city'=>'Phoenix, Arizona','image'=>'/assets/images/sample/people/elena-park.webp','context'=>'City Table Club · member'],
        ['id'=>108,'public_id'=>'sample-marcus-reed','name'=>'Marcus Reed','display_name'=>'Marcus Reed','email'=>'marcus.reed@example.test','status'=>'active','roles'=>['attendee'],'city'=>'Phoenix, Arizona','image'=>'/assets/images/sample/people/marcus-reed.webp','context'=>'Late Night Listening · member'],
        ['id'=>109,'public_id'=>'sample-ava-stone','name'=>'Ava Stone','display_name'=>'Ava Stone','email'=>'ava.stone@example.test','status'=>'active','roles'=>['attendee'],'city'=>'Phoenix, Arizona','image'=>'/assets/images/sample/people/ava-stone.webp','context'=>'Late Night Listening · member'],
        ['id'=>110,'public_id'=>'sample-eli-thompson','name'=>'Eli Thompson','display_name'=>'Eli Thompson','email'=>'eli.thompson@example.test','status'=>'active','roles'=>['attendee'],'city'=>'Phoenix, Arizona','image'=>'/assets/images/sample/people/eli-thompson.webp','context'=>'Phoenix Explorers · member'],
        ['id'=>111,'public_id'=>'sample-riley-chen','name'=>'Riley Chen','display_name'=>'Riley Chen','email'=>'riley.chen@example.test','status'=>'invited','roles'=>['attendee'],'city'=>'Phoenix, Arizona','image'=>'/assets/images/sample/people/taylor-kim.webp','context'=>'Pending member invitation'],
        ['id'=>112,'public_id'=>'sample-devon-price','name'=>'Devon Price','display_name'=>'Devon Price','email'=>'devon.price@example.test','status'=>'active','roles'=>['attendee'],'city'=>'Scottsdale, Arizona','image'=>'/assets/images/sample/people/noah-bennett.webp','context'=>'Recent member'],
    ];

    $cities = [
        ['id'=>1,'public_id'=>'sample-city-phx','name'=>'Phoenix','region'=>'Arizona','status'=>'active','members'=>3248,'events'=>126,'partners'=>84],
        ['id'=>2,'public_id'=>'sample-city-aus','name'=>'Austin','region'=>'Texas','status'=>'active','members'=>2180,'events'=>87,'partners'=>52],
        ['id'=>3,'public_id'=>'sample-city-den','name'=>'Denver','region'=>'Colorado','status'=>'active','members'=>1840,'events'=>72,'partners'=>46],
        ['id'=>4,'public_id'=>'sample-city-nsh','name'=>'Nashville','region'=>'Tennessee','status'=>'active','members'=>1630,'events'=>66,'partners'=>39],
        ['id'=>5,'public_id'=>'sample-city-sd','name'=>'San Diego','region'=>'California','status'=>'active','members'=>2410,'events'=>91,'partners'=>58],
        ['id'=>6,'public_id'=>'sample-city-pdx','name'=>'Portland','region'=>'Oregon','status'=>'active','members'=>1510,'events'=>59,'partners'=>35],
    ];

    $businesses = [
        ['id'=>201,'public_id'=>'sample-ember-hospitality','name'=>'Ember Hospitality','description'=>'Independent Phoenix hospitality group centered on rooftop dining and small-format events.','status'=>'active','creator_name'=>'Coveted Admin','location_count'=>2,'admin_count'=>1,'reward_count'=>3,'campaign_count'=>3,'logo_url'=>'/assets/images/sample/locations/ember-room/hero.webp','cover_url'=>'/assets/images/sample/events/saturday-night-supper-club-hero.webp','website_url'=>'https://example.test/ember','phone'=>'(602) 555-0142','category_label'=>'Restaurant & Rooftop Hospitality'],
        ['id'=>202,'public_id'=>'sample-harbor-house-group','name'=>'Harbor House Group','description'=>'Dinner-first neighborhood hospitality partner with a strong return-visit program.','status'=>'active','creator_name'=>'Coveted Admin','location_count'=>1,'admin_count'=>1,'reward_count'=>2,'campaign_count'=>2,'logo_url'=>'/assets/images/sample/locations/harbor-house/hero.webp','cover_url'=>'/assets/images/sample/events/sunset-dinner-hero.webp','website_url'=>'https://example.test/harbor','phone'=>'(602) 555-0188','category_label'=>'Restaurant'],
        ['id'=>203,'public_id'=>'sample-velvet-note','name'=>'Velvet Note','description'=>'Listening lounge and artist partner venue for intimate music-led gatherings.','status'=>'active','creator_name'=>'Coveted Admin','location_count'=>1,'admin_count'=>1,'reward_count'=>3,'campaign_count'=>2,'logo_url'=>'/assets/images/sample/locations/velvet-note/hero.webp','cover_url'=>'/assets/images/sample/events/vinyl-and-cocktails-hero.webp','website_url'=>'https://example.test/velvet-note','phone'=>'(602) 555-0129','category_label'=>'Music Venue & Lounge'],
        ['id'=>204,'public_id'=>'sample-desert-bloom','name'=>'Desert Bloom Wellness','description'=>'Prospective wellness partner being qualified for a small-group recovery series.','status'=>'prospective','creator_name'=>'Coveted Admin','location_count'=>1,'admin_count'=>0,'reward_count'=>0,'campaign_count'=>0,'logo_url'=>'','cover_url'=>'','website_url'=>'https://example.test/desert-bloom','phone'=>'(480) 555-0191','category_label'=>'Wellness'],
    ];

    $locations = [
        ['id'=>301,'public_id'=>'sample-ember-room','business_id'=>201,'business_ref'=>'sample-ember-hospitality','business'=>'Ember Hospitality','name'=>'Ember Room','address1'=>'111 W Roosevelt St','city'=>'Phoenix','region'=>'Arizona','postal_code'=>'85003','country'=>'US','timezone'=>'America/Phoenix','latitude'=>33.4585,'longitude'=>-112.0758,'capacity'=>80,'status'=>'active','type'=>'Rooftop lounge','image'=>'/assets/images/sample/locations/ember-room/hero.webp'],
        ['id'=>302,'public_id'=>'sample-ember-private-room','business_id'=>201,'business_ref'=>'sample-ember-hospitality','business'=>'Ember Hospitality','name'=>'Ember Private Room','address1'=>'111 W Roosevelt St','city'=>'Phoenix','region'=>'Arizona','postal_code'=>'85003','country'=>'US','timezone'=>'America/Phoenix','latitude'=>33.4585,'longitude'=>-112.0758,'capacity'=>28,'status'=>'active','type'=>'Private dining','image'=>'/assets/images/sample/locations/ember-room/hero.webp'],
        ['id'=>303,'public_id'=>'sample-harbor-house','business_id'=>202,'business_ref'=>'sample-harbor-house-group','business'=>'Harbor House Group','name'=>'Harbor House','address1'=>'700 E Bethany Home Rd','city'=>'Phoenix','region'=>'Arizona','postal_code'=>'85014','country'=>'US','timezone'=>'America/Phoenix','latitude'=>33.5238,'longitude'=>-112.0642,'capacity'=>110,'status'=>'active','type'=>'Dining room','image'=>'/assets/images/sample/locations/harbor-house/hero.webp'],
        ['id'=>304,'public_id'=>'sample-velvet-note','business_id'=>203,'business_ref'=>'sample-velvet-note','business'=>'Velvet Note','name'=>'Velvet Note','address1'=>'222 E Roosevelt St','city'=>'Phoenix','region'=>'Arizona','postal_code'=>'85004','country'=>'US','timezone'=>'America/Phoenix','latitude'=>33.4587,'longitude'=>-112.0701,'capacity'=>95,'status'=>'active','type'=>'Listening lounge','image'=>'/assets/images/sample/locations/velvet-note/hero.webp'],
        ['id'=>305,'public_id'=>'sample-desert-bloom-central','business_id'=>204,'business_ref'=>'sample-desert-bloom','business'=>'Desert Bloom Wellness','name'=>'Desert Bloom Central','address1'=>'4040 N 16th St','city'=>'Phoenix','region'=>'Arizona','postal_code'=>'85016','country'=>'US','timezone'=>'America/Phoenix','latitude'=>33.4932,'longitude'=>-112.0482,'capacity'=>32,'status'=>'active','type'=>'Wellness studio','image'=>'/assets/images/sample/locations/harbor-house/hero.webp'],
    ];

    $groups = [
        ['id'=>401,'public_id'=>'sample-inner-circle','name'=>'The Inner Circle','description'=>'Small-table dinners, thoughtful introductions and the kind of nights that make a second meeting easy.','city'=>'Phoenix, Arizona','visibility'=>'invite_only','status'=>'active','creator_name'=>'Jordan Ellis','member_count'=>28,'event_count'=>5,'next'=>'Saturday Night Supper Club','image'=>'/assets/images/sample/groups/the-inner-circle.webp'],
        ['id'=>402,'public_id'=>'sample-city-table-club','name'=>'City Table Club','description'=>'A rotating table for people who like discovering good food and meeting someone new along the way.','city'=>'Phoenix, Arizona','visibility'=>'invite_only','status'=>'active','creator_name'=>'Noah Bennett','member_count'=>41,'event_count'=>4,'next'=>'Sunset Dinner','image'=>'/assets/images/sample/groups/city-table-club.webp'],
        ['id'=>403,'public_id'=>'sample-late-night-listening','name'=>'Late Night Listening','description'=>'Records, artists, low-light rooms and conversation between songs.','city'=>'Phoenix, Arizona','visibility'=>'invite_only','status'=>'active','creator_name'=>'Sienna Cole','member_count'=>33,'event_count'=>4,'next'=>'Vinyl & Cocktails','image'=>'/assets/images/sample/groups/late-night-listening.webp'],
        ['id'=>404,'public_id'=>'sample-phoenix-explorers','name'=>'Phoenix Explorers','description'=>'Small local experiences built around discovery, neighborhoods and independent businesses.','city'=>'Phoenix, Arizona','visibility'=>'private','status'=>'active','creator_name'=>'Eli Thompson','member_count'=>22,'event_count'=>2,'next'=>'Mystery Sunday','image'=>'/assets/images/sample/groups/city-table-club.webp'],
    ];

    $events = [
        ['id'=>501,'public_id'=>'sample-saturday-night-supper-club','group_id'=>401,'group_ref'=>'sample-inner-circle','group_name'=>'The Inner Circle','title'=>'Saturday Night Supper Club','description'=>'A long-table rooftop dinner with a small guest list and no agenda beyond a good night out.','event_type'=>'regular','audience'=>'group','timezone'=>'America/Phoenix','starts_at'=>coveted_system_sample_time('+3 days',19,30),'ends_at'=>coveted_system_sample_time('+3 days',22,0),'capacity'=>24,'plus_one_allowed'=>0,'location_visibility'=>'immediate','status'=>'published','creator_name'=>'Coveted Admin','location_id'=>301,'location_ref'=>'sample-ember-room','location_name'=>'Ember Room','invite_count'=>22,'attending_count'=>18,'attendance_count'=>0,'image'=>'/assets/images/sample/events/saturday-night-supper-club-hero.webp'],
        ['id'=>502,'public_id'=>'sample-sunset-dinner','group_id'=>402,'group_ref'=>'sample-city-table-club','group_name'=>'City Table Club','title'=>'Sunset Dinner','description'=>'Shared plates, new introductions and a quieter dinner hour.','event_type'=>'private_table','audience'=>'invitation_only','timezone'=>'America/Phoenix','starts_at'=>coveted_system_sample_time('+8 days',18,15),'ends_at'=>coveted_system_sample_time('+8 days',20,30),'capacity'=>18,'plus_one_allowed'=>0,'location_visibility'=>'immediate','status'=>'published','creator_name'=>'Coveted Admin','location_id'=>303,'location_ref'=>'sample-harbor-house','location_name'=>'Harbor House','invite_count'=>16,'attending_count'=>14,'attendance_count'=>0,'image'=>'/assets/images/sample/events/sunset-dinner-hero.webp'],
        ['id'=>503,'public_id'=>'sample-vinyl-and-cocktails','group_id'=>403,'group_ref'=>'sample-late-night-listening','group_name'=>'Late Night Listening','title'=>'Vinyl & Cocktails','description'=>'A low-light listening session with records, cocktails and conversation between songs.','event_type'=>'session','audience'=>'group','timezone'=>'America/Phoenix','starts_at'=>coveted_system_sample_time('+13 days',20,0),'ends_at'=>coveted_system_sample_time('+13 days',23,0),'capacity'=>30,'plus_one_allowed'=>1,'location_visibility'=>'immediate','status'=>'published','creator_name'=>'Coveted Admin','location_id'=>304,'location_ref'=>'sample-velvet-note','location_name'=>'Velvet Note','invite_count'=>28,'attending_count'=>22,'attendance_count'=>0,'image'=>'/assets/images/sample/events/vinyl-and-cocktails-hero.webp'],
        ['id'=>504,'public_id'=>'sample-mystery-sunday','group_id'=>404,'group_ref'=>'sample-phoenix-explorers','group_name'=>'Phoenix Explorers','title'=>'Mystery Sunday','description'=>'Location reveals the morning of the gathering.','event_type'=>'mystery','audience'=>'group','timezone'=>'America/Phoenix','starts_at'=>coveted_system_sample_time('+18 days',16,0),'ends_at'=>coveted_system_sample_time('+18 days',19,0),'capacity'=>20,'plus_one_allowed'=>0,'location_visibility'=>'scheduled_reveal','status'=>'published','creator_name'=>'Coveted Admin','location_id'=>305,'location_ref'=>'sample-desert-bloom-central','location_name'=>'Hidden until reveal','invite_count'=>18,'attending_count'=>11,'attendance_count'=>0,'image'=>'/assets/images/sample/events/sunset-dinner-hero.webp'],
        ['id'=>505,'public_id'=>'sample-first-friday-supper','group_id'=>401,'group_ref'=>'sample-inner-circle','group_name'=>'The Inner Circle','title'=>'First Friday Supper','description'=>'Completed dinner used to demonstrate attendance, benefits and reconnect history.','event_type'=>'regular','audience'=>'group','timezone'=>'America/Phoenix','starts_at'=>coveted_system_sample_time('-6 days',19,0),'ends_at'=>coveted_system_sample_time('-6 days',21,30),'capacity'=>24,'plus_one_allowed'=>0,'location_visibility'=>'immediate','status'=>'completed','creator_name'=>'Coveted Admin','location_id'=>301,'location_ref'=>'sample-ember-room','location_name'=>'Ember Room','invite_count'=>22,'attending_count'=>19,'attendance_count'=>18,'image'=>'/assets/images/sample/events/saturday-night-supper-club-hero.webp'],
        ['id'=>506,'public_id'=>'sample-listening-room-night','group_id'=>403,'group_ref'=>'sample-late-night-listening','group_name'=>'Late Night Listening','title'=>'Listening Room Night','description'=>'Completed artist-led listening night.','event_type'=>'session','audience'=>'group','timezone'=>'America/Phoenix','starts_at'=>coveted_system_sample_time('-15 days',20,0),'ends_at'=>coveted_system_sample_time('-15 days',23,0),'capacity'=>30,'plus_one_allowed'=>1,'location_visibility'=>'immediate','status'=>'completed','creator_name'=>'Coveted Admin','location_id'=>304,'location_ref'=>'sample-velvet-note','location_name'=>'Velvet Note','invite_count'=>27,'attending_count'=>23,'attendance_count'=>21,'image'=>'/assets/images/sample/events/vinyl-and-cocktails-hero.webp'],
        ['id'=>507,'public_id'=>'sample-harbor-chefs-table','group_id'=>402,'group_ref'=>'sample-city-table-club','group_name'=>'City Table Club','title'=>'Chef’s Table Return Night','description'=>'Completed Daily Event with a group reward threshold.','event_type'=>'private_table','audience'=>'invitation_only','timezone'=>'America/Phoenix','starts_at'=>coveted_system_sample_time('-28 days',18,30),'ends_at'=>coveted_system_sample_time('-28 days',21,0),'capacity'=>18,'plus_one_allowed'=>0,'location_visibility'=>'immediate','status'=>'completed','creator_name'=>'Coveted Admin','location_id'=>303,'location_ref'=>'sample-harbor-house','location_name'=>'Harbor House','invite_count'=>18,'attending_count'=>16,'attendance_count'=>15,'image'=>'/assets/images/sample/events/sunset-dinner-hero.webp'],
        ['id'=>508,'public_id'=>'sample-rooftop-brunch-draft','group_id'=>401,'group_ref'=>'sample-inner-circle','group_name'=>'The Inner Circle','title'=>'Rooftop Brunch','description'=>'Draft event intentionally included so the Admin Agent has a publishing opportunity.','event_type'=>'regular','audience'=>'group','timezone'=>'America/Phoenix','starts_at'=>coveted_system_sample_time('+25 days',11,0),'ends_at'=>coveted_system_sample_time('+25 days',13,0),'capacity'=>20,'plus_one_allowed'=>0,'location_visibility'=>'immediate','status'=>'draft','creator_name'=>'Coveted Admin','location_id'=>302,'location_ref'=>'sample-ember-private-room','location_name'=>'Ember Private Room','invite_count'=>0,'attending_count'=>0,'attendance_count'=>0,'image'=>'/assets/images/sample/events/saturday-night-supper-club-hero.webp'],
    ];

    $roleRequests = [
        ['id'=>601,'public_id'=>'sample-role-request-1','display_name'=>'Maya Rivera','email'=>'maya.rivera@example.test','role_key'=>'attendee_host','request_note'=>'I would like to help host City Table Club dinners.','status'=>'pending','created_at'=>coveted_system_sample_time('-2 days',10,0)],
        ['id'=>602,'public_id'=>'sample-role-request-2','display_name'=>'Marcus Reed','email'=>'marcus.reed@example.test','role_key'=>'artist_partner','request_note'=>'I manage local artist bookings and would like to create artist experiences.','status'=>'pending','created_at'=>coveted_system_sample_time('-1 day',15,0)],
    ];

    $inviteCrm = [
        ['id'=>701,'public_id'=>'sample-crm-ember-2','full_name'=>'Avery Brooks','email'=>'avery.brooks@example.test','company'=>'North Central Hospitality','city'=>'Phoenix','status'=>'qualified','source'=>'Book Demo','admin_note'=>'Good fit for a recurring workplace dinner program.','score'=>91,'next_action'=>'Schedule partner discovery call','created_at'=>coveted_system_sample_time('-3 days',9,30)],
        ['id'=>702,'public_id'=>'sample-crm-dana','full_name'=>'Dana Cole','email'=>'dana.cole@example.test','company'=>'Desert Bloom Wellness','city'=>'Phoenix','status'=>'contacted','source'=>'Partner referral','admin_note'=>'Interested in Sunday recovery events and return perks.','score'=>78,'next_action'=>'Send event partnership outline','created_at'=>coveted_system_sample_time('-5 days',14,0)],
        ['id'=>703,'public_id'=>'sample-crm-sam','full_name'=>'Sam Patel','email'=>'sam.patel@example.test','company'=>'Copper State Coffee','city'=>'Phoenix','status'=>'new','source'=>'Website','admin_note'=>'No outreach yet.','score'=>64,'next_action'=>'Qualify business fit','created_at'=>coveted_system_sample_time('-1 day',11,15)],
        ['id'=>704,'public_id'=>'sample-crm-jules','full_name'=>'Jules Morgan','email'=>'jules.morgan@example.test','company'=>'Independent','city'=>'Phoenix','status'=>'qualified','source'=>'Member referral','admin_note'=>'Potential attendee host for Phoenix Explorers.','score'=>86,'next_action'=>'Review host role fit','created_at'=>coveted_system_sample_time('-4 days',16,0)],
        ['id'=>705,'public_id'=>'sample-crm-rina','full_name'=>'Rina Foster','email'=>'rina.foster@example.test','company'=>'Mesa Arts Collective','city'=>'Mesa','status'=>'converted','source'=>'Artist referral','admin_note'=>'Converted to artist partner.','score'=>95,'next_action'=>'Complete artist workspace','created_at'=>coveted_system_sample_time('-12 days',13,0)],
    ];

    $dailyEvents = [
        ['public_id'=>'sample-daily-ember','event_ref'=>'sample-first-friday-supper','business_ref'=>'sample-ember-hospitality','business_name'=>'Ember Hospitality','location_ref'=>'sample-ember-room','location_name'=>'Ember Room','group_ref'=>'sample-inner-circle','group_name'=>'The Inner Circle','title'=>'First Friday Supper','status'=>'completed','attendance_threshold'=>14,'verified_attendance'=>18,'loyalty_points'=>75,'reward_title'=>'Dinner on us','rewards_issued'=>18,'reward_unlocked_at'=>coveted_system_sample_time('-6 days',21,0),'active_checkin_codes'=>2],
        ['public_id'=>'sample-daily-harbor','event_ref'=>'sample-harbor-chefs-table','business_ref'=>'sample-harbor-house-group','business_name'=>'Harbor House Group','location_ref'=>'sample-harbor-house','location_name'=>'Harbor House','group_ref'=>'sample-city-table-club','group_name'=>'City Table Club','title'=>'Chef’s Table Return Night','status'=>'completed','attendance_threshold'=>12,'verified_attendance'=>15,'loyalty_points'=>60,'reward_title'=>'Dessert for two','rewards_issued'=>15,'reward_unlocked_at'=>coveted_system_sample_time('-28 days',20,30),'active_checkin_codes'=>1],
        ['public_id'=>'sample-daily-velvet','event_ref'=>'sample-vinyl-and-cocktails','business_ref'=>'sample-velvet-note','business_name'=>'Velvet Note','location_ref'=>'sample-velvet-note','location_name'=>'Velvet Note','group_ref'=>'sample-late-night-listening','group_name'=>'Late Night Listening','title'=>'Vinyl & Cocktails','status'=>'published','attendance_threshold'=>18,'verified_attendance'=>0,'loyalty_points'=>80,'reward_title'=>'Listening room guest pass','rewards_issued'=>0,'reward_unlocked_at'=>null,'active_checkin_codes'=>1],
    ];

    $rewards = [
        ['id'=>801,'public_id'=>'sample-reward-dinner','title'=>'Dinner on us','owner'=>'Ember Hospitality','owner_type'=>'business','reward_type'=>'credit','claim_mode'=>'partner_code','value_amount'=>25.00,'value_text'=>'$25 dining credit','status'=>'active'],
        ['id'=>802,'public_id'=>'sample-reward-cocktail','title'=>'Member welcome','owner'=>'Velvet Note','owner_type'=>'business','reward_type'=>'free_item','claim_mode'=>'partner_code','value_amount'=>null,'value_text'=>'One house cocktail','status'=>'active'],
        ['id'=>803,'public_id'=>'sample-reward-dessert','title'=>'Dessert for two','owner'=>'Harbor House Group','owner_type'=>'business','reward_type'=>'perk','claim_mode'=>'partner_code','value_amount'=>null,'value_text'=>'Complimentary dessert','status'=>'active'],
        ['id'=>804,'public_id'=>'sample-reward-listening','title'=>'Listening room guest pass','owner'=>'Velvet Note','owner_type'=>'business','reward_type'=>'access','claim_mode'=>'partner_code','value_amount'=>null,'value_text'=>'Guest admission','status'=>'active'],
        ['id'=>805,'public_id'=>'sample-reward-artist','title'=>'Backstage listening note','owner'=>'Sienna Cole','owner_type'=>'artist','reward_type'=>'content','claim_mode'=>'automatic','value_amount'=>null,'value_text'=>'Private artist audio note','status'=>'active'],
    ];

    $campaigns = [
        ['id'=>901,'public_id'=>'sample-campaign-return-ember','title'=>'Return to Ember','owner_type'=>'business','owner_name'=>'Ember Hospitality','status'=>'active','trigger_key'=>'return_visit','reward_title'=>'Dinner on us','issued_count'=>31,'claim_count'=>17],
        ['id'=>902,'public_id'=>'sample-campaign-velvet-welcome','title'=>'Listening Night Welcome','owner_type'=>'business','owner_name'=>'Velvet Note','status'=>'active','trigger_key'=>'event_attendance','reward_title'=>'Member welcome','issued_count'=>24,'claim_count'=>14],
        ['id'=>903,'public_id'=>'sample-campaign-harbor-return','title'=>'Harbor Return Table','owner_type'=>'business','owner_name'=>'Harbor House Group','status'=>'active','trigger_key'=>'guest_return','reward_title'=>'Dessert for two','issued_count'=>19,'claim_count'=>11],
        ['id'=>904,'public_id'=>'sample-campaign-listening-pass','title'=>'Bring Someone Back','owner_type'=>'business','owner_name'=>'Velvet Note','status'=>'active','trigger_key'=>'return_visit','reward_title'=>'Listening room guest pass','issued_count'=>12,'claim_count'=>7],
        ['id'=>905,'public_id'=>'sample-campaign-artist-note','title'=>'After the Set','owner_type'=>'artist','owner_name'=>'Sienna Cole','status'=>'active','trigger_key'=>'event_attendance','reward_title'=>'Backstage listening note','issued_count'=>21,'claim_count'=>19],
    ];

    $benefitPrograms = [
        ['public_id'=>'sample-program-ember-return','title'=>'Ember Return Dinner','owner'=>'Ember Hospitality','trigger'=>'Return visit','reward'=>'Dinner on us','pool'=>40,'issued'=>31,'claimed'=>17,'status'=>'active','claim_rate'=>54.8],
        ['public_id'=>'sample-program-velvet-welcome','title'=>'Listening Night Welcome','owner'=>'Velvet Note','trigger'=>'Verified event attendance','reward'=>'Member welcome','pool'=>50,'issued'=>24,'claimed'=>14,'status'=>'active','claim_rate'=>58.3],
        ['public_id'=>'sample-program-harbor-return','title'=>'Harbor Return Table','owner'=>'Harbor House Group','trigger'=>'Guest return','reward'=>'Dessert for two','pool'=>30,'issued'=>19,'claimed'=>11,'status'=>'active','claim_rate'=>57.9],
        ['public_id'=>'sample-program-artist-note','title'=>'After the Set','owner'=>'Sienna Cole','trigger'=>'Artist event attendance','reward'=>'Backstage listening note','pool'=>100,'issued'=>21,'claimed'=>19,'status'=>'active','claim_rate'=>90.5],
    ];

    $sponsorships = [
        ['public_id'=>'sample-sponsor-ember-fall','business'=>'Ember Hospitality','program'=>'Fall Supper Return Credit','status'=>'submitted','quantity_limit'=>60,'trigger_key'=>'return_visit','estimated_value'=>'$1,500 committed value','created_at'=>coveted_system_sample_time('-1 day',12,0)],
        ['public_id'=>'sample-sponsor-harbor-dessert','business'=>'Harbor House Group','program'=>'City Table Dessert Pool','status'=>'converted','quantity_limit'=>30,'trigger_key'=>'event_attendance','estimated_value'=>'30 desserts','created_at'=>coveted_system_sample_time('-20 days',12,0)],
        ['public_id'=>'sample-sponsor-velvet-pass','business'=>'Velvet Note','program'=>'Guest Pass Bank','status'=>'converted','quantity_limit'=>40,'trigger_key'=>'guest_return','estimated_value'=>'40 guest admissions','created_at'=>coveted_system_sample_time('-32 days',12,0)],
    ];

    $loyalty = [
        ['group_ref'=>'sample-inner-circle','group_name'=>'The Inner Circle','members'=>28,'active_members'=>24,'points_issued'=>4320,'points_redeemed'=>1880,'avg_points'=>101,'top_tier'=>'Insider','streak_members'=>9],
        ['group_ref'=>'sample-city-table-club','group_name'=>'City Table Club','members'=>41,'active_members'=>35,'points_issued'=>5180,'points_redeemed'=>2410,'avg_points'=>79,'top_tier'=>'Regular','streak_members'=>12],
        ['group_ref'=>'sample-late-night-listening','group_name'=>'Late Night Listening','members'=>33,'active_members'=>29,'points_issued'=>4760,'points_redeemed'=>2050,'avg_points'=>93,'top_tier'=>'Insider','streak_members'=>11],
        ['group_ref'=>'sample-phoenix-explorers','group_name'=>'Phoenix Explorers','members'=>22,'active_members'=>18,'points_issued'=>1810,'points_redeemed'=>620,'avg_points'=>54,'top_tier'=>'Member','streak_members'=>4],
    ];

    $wallet = [
        ['id'=>'dinner-on-us','title'=>'Dinner on us','partner'=>'Ember Room','description'=>'A dining credit for your next return visit after Saturday Night Supper Club.','value'=>'$25 dining credit','status'=>'Ready to use','reward_type'=>'credit','state'=>'inbox','image'=>'/assets/images/sample/benefits/dinner-on-us.webp'],
        ['id'=>'member-gift','title'=>'Member welcome','partner'=>'Velvet Note','description'=>'One house cocktail when you return for another listening night.','value'=>'One house cocktail','status'=>'Unlocked','reward_type'=>'free_item','state'=>'inbox','image'=>'/assets/images/sample/benefits/member-gift.webp'],
        ['id'=>'dessert-for-two','title'=>'Dessert for two','partner'=>'Harbor House','description'=>'A shared dessert added to your next dinner reservation.','value'=>'Complimentary dessert','status'=>'Ready to use','reward_type'=>'perk','state'=>'inbox','image'=>'/assets/images/sample/benefits/dinner-on-us.webp'],
        ['id'=>'listening-room-pass','title'=>'Listening room guest pass','partner'=>'Velvet Note','description'=>'A guest-access reward from a previous listening session.','value'=>'Guest admission','status'=>'Redeemed','reward_type'=>'access','state'=>'claimed','image'=>'/assets/images/sample/benefits/member-gift.webp'],
    ];

    $claims = [
        ['public_id'=>'sample-claim-1','reward'=>'Dinner on us','member'=>'Jordan Ellis','business'=>'Ember Hospitality','location'=>'Ember Room','status'=>'claimed','claimed_at'=>coveted_system_sample_time('-2 days',19,0),'value'=>'$25'],
        ['public_id'=>'sample-claim-2','reward'=>'Member welcome','member'=>'Ava Stone','business'=>'Velvet Note','location'=>'Velvet Note','status'=>'claimed','claimed_at'=>coveted_system_sample_time('-8 days',21,0),'value'=>'House cocktail'],
        ['public_id'=>'sample-claim-3','reward'=>'Dessert for two','member'=>'Maya Rivera','business'=>'Harbor House Group','location'=>'Harbor House','status'=>'claimed','claimed_at'=>coveted_system_sample_time('-18 days',19,30),'value'=>'Dessert'],
        ['public_id'=>'sample-claim-4','reward'=>'Dinner on us','member'=>'Taylor Kim','business'=>'Ember Hospitality','location'=>'Ember Room','status'=>'refunded','claimed_at'=>coveted_system_sample_time('-21 days',18,30),'value'=>'$25'],
    ];

    $partnerRelationships = [
        ['business_id'=>201,'business_ref'=>'sample-ember-hospitality','business_name'=>'Ember Hospitality','group_id'=>401,'group_public_id'=>'sample-inner-circle','group_name'=>'The Inner Circle','location_id'=>301,'location_public_id'=>'sample-ember-room','location_name'=>'Ember Room','city'=>'Phoenix','region'=>'Arizona','relationship_status'=>'preferred_partner','partner_since'=>coveted_system_sample_time('-140 days',12,0),'benefits_enabled'=>1,'mystery_events_enabled'=>1,'completed_events'=>4,'upcoming_events'=>1,'verified_visits'=>72,'unique_attendees'=>41,'repeat_attendees'=>18,'business_benefits_issued'=>49,'claims'=>27,'refunds'=>1,'return_claims'=>16,'guest_return_claims'=>6],
        ['business_id'=>202,'business_ref'=>'sample-harbor-house-group','business_name'=>'Harbor House Group','group_id'=>402,'group_public_id'=>'sample-city-table-club','group_name'=>'City Table Club','location_id'=>303,'location_public_id'=>'sample-harbor-house','location_name'=>'Harbor House','city'=>'Phoenix','region'=>'Arizona','relationship_status'=>'partner','partner_since'=>coveted_system_sample_time('-95 days',12,0),'benefits_enabled'=>1,'mystery_events_enabled'=>0,'completed_events'=>3,'upcoming_events'=>1,'verified_visits'=>51,'unique_attendees'=>34,'repeat_attendees'=>13,'business_benefits_issued'=>35,'claims'=>19,'refunds'=>0,'return_claims'=>9,'guest_return_claims'=>5],
        ['business_id'=>203,'business_ref'=>'sample-velvet-note','business_name'=>'Velvet Note','group_id'=>403,'group_public_id'=>'sample-late-night-listening','group_name'=>'Late Night Listening','location_id'=>304,'location_public_id'=>'sample-velvet-note','location_name'=>'Velvet Note','city'=>'Phoenix','region'=>'Arizona','relationship_status'=>'home_venue','partner_since'=>coveted_system_sample_time('-180 days',12,0),'benefits_enabled'=>1,'mystery_events_enabled'=>1,'completed_events'=>5,'upcoming_events'=>1,'verified_visits'=>103,'unique_attendees'=>56,'repeat_attendees'=>29,'business_benefits_issued'=>77,'claims'=>43,'refunds'=>2,'return_claims'=>24,'guest_return_claims'=>12],
        ['business_id'=>204,'business_ref'=>'sample-desert-bloom','business_name'=>'Desert Bloom Wellness','group_id'=>404,'group_public_id'=>'sample-phoenix-explorers','group_name'=>'Phoenix Explorers','location_id'=>305,'location_public_id'=>'sample-desert-bloom-central','location_name'=>'Desert Bloom Central','city'=>'Phoenix','region'=>'Arizona','relationship_status'=>'new','partner_since'=>null,'benefits_enabled'=>0,'mystery_events_enabled'=>1,'completed_events'=>0,'upcoming_events'=>1,'verified_visits'=>0,'unique_attendees'=>0,'repeat_attendees'=>0,'business_benefits_issued'=>0,'claims'=>0,'refunds'=>0,'return_claims'=>0,'guest_return_claims'=>0],
    ];

    $partnerContacts = [
        ['public_id'=>'sample-contact-ember','business_ref'=>'sample-ember-hospitality','group_ref'=>'sample-inner-circle','location_ref'=>'sample-ember-room','full_name'=>'Claire Morgan','role_title'=>'General Manager','preferred_contact'=>'email','is_primary'=>1,'status'=>'active'],
        ['public_id'=>'sample-contact-ember-events','business_ref'=>'sample-ember-hospitality','group_ref'=>'sample-inner-circle','location_ref'=>'sample-ember-room','full_name'=>'Nico Hall','role_title'=>'Events Lead','preferred_contact'=>'text','is_primary'=>0,'status'=>'active'],
        ['public_id'=>'sample-contact-harbor','business_ref'=>'sample-harbor-house-group','group_ref'=>'sample-city-table-club','location_ref'=>'sample-harbor-house','full_name'=>'Leo Martinez','role_title'=>'Operations Manager','preferred_contact'=>'phone','is_primary'=>1,'status'=>'active'],
        ['public_id'=>'sample-contact-velvet','business_ref'=>'sample-velvet-note','group_ref'=>'sample-late-night-listening','location_ref'=>'sample-velvet-note','full_name'=>'Mara Wells','role_title'=>'Venue Director','preferred_contact'=>'email','is_primary'=>1,'status'=>'active'],
    ];

    $partnerInteractions = [
        ['public_id'=>'sample-interaction-1','business_ref'=>'sample-ember-hospitality','group_ref'=>'sample-inner-circle','location_ref'=>'sample-ember-room','contact_name'=>'Claire Morgan','interaction_type'=>'meeting','direction'=>'outbound','subject'=>'Fall supper calendar','summary'=>'Reviewed three possible fall dates and agreed to keep the long-table format with a 20-person target.','occurred_at'=>coveted_system_sample_time('-2 days',14,30)],
        ['public_id'=>'sample-interaction-2','business_ref'=>'sample-harbor-house-group','group_ref'=>'sample-city-table-club','location_ref'=>'sample-harbor-house','contact_name'=>'Leo Martinez','interaction_type'=>'call','direction'=>'inbound','subject'=>'Return benefit performance','summary'=>'Leo asked to review dessert claims before committing another 30-reward pool.','occurred_at'=>coveted_system_sample_time('-5 days',11,0)],
        ['public_id'=>'sample-interaction-3','business_ref'=>'sample-velvet-note','group_ref'=>'sample-late-night-listening','location_ref'=>'sample-velvet-note','contact_name'=>'Mara Wells','interaction_type'=>'email','direction'=>'outbound','subject'=>'Vinyl & Cocktails check-in','summary'=>'Confirmed employee claim code, artist arrival window and guest-pass inventory for the next listening night.','occurred_at'=>coveted_system_sample_time('-1 day',16,15)],
    ];

    $partnerNotes = [
        ['public_id'=>'sample-note-1','business_ref'=>'sample-ember-hospitality','group_ref'=>'sample-inner-circle','location_ref'=>'sample-ember-room','note_type'=>'relationship','body'=>'Strongest dinner partner. Protect the quieter rooftop section and avoid stacking public reservations beside the group.','created_at'=>coveted_system_sample_time('-7 days',10,0),'author_name'=>'Coveted Admin'],
        ['public_id'=>'sample-note-2','business_ref'=>'sample-harbor-house-group','group_ref'=>'sample-city-table-club','location_ref'=>'sample-harbor-house','note_type'=>'contact','body'=>'Leo prefers a short phone call before any new reward commitment.','created_at'=>coveted_system_sample_time('-10 days',12,0),'author_name'=>'Coveted Admin'],
        ['public_id'=>'sample-note-3','business_ref'=>'sample-velvet-note','group_ref'=>'sample-late-night-listening','location_ref'=>'sample-velvet-note','note_type'=>'timeline','body'=>'Home Venue candidate confirmed after five completed listening events and strong return behavior.','created_at'=>coveted_system_sample_time('-20 days',9,0),'author_name'=>'Coveted Admin'],
    ];

    $partnerFollowups = [
        ['public_id'=>'sample-followup-ember','business_ref'=>'sample-ember-hospitality','group_ref'=>'sample-inner-circle','location_ref'=>'sample-ember-room','contact_name'=>'Claire Morgan','assigned_to'=>'Coveted Admin','title'=>'Confirm October supper date','detail'=>'Choose between the two held rooftop dates and record the preferred service window.','due_at'=>coveted_system_sample_time('+2 days',15,0),'priority'=>'high','status'=>'open'],
        ['public_id'=>'sample-followup-harbor','business_ref'=>'sample-harbor-house-group','group_ref'=>'sample-city-table-club','location_ref'=>'sample-harbor-house','contact_name'=>'Leo Martinez','assigned_to'=>'Coveted Admin','title'=>'Review dessert claim economics','detail'=>'Bring claim rate and return visit counts to the next partner call.','due_at'=>coveted_system_sample_time('-1 day',12,0),'priority'=>'high','status'=>'open'],
        ['public_id'=>'sample-followup-velvet','business_ref'=>'sample-velvet-note','group_ref'=>'sample-late-night-listening','location_ref'=>'sample-velvet-note','contact_name'=>'Mara Wells','assigned_to'=>'Coveted Admin','title'=>'Send final listening-night run of show','detail'=>'Include artist arrival, check-in code and guest-pass inventory.','due_at'=>coveted_system_sample_time('+5 days',10,0),'priority'=>'normal','status'=>'open'],
        ['public_id'=>'sample-followup-ember-complete','business_ref'=>'sample-ember-hospitality','group_ref'=>'sample-inner-circle','location_ref'=>'sample-ember-room','contact_name'=>'Nico Hall','assigned_to'=>'Coveted Admin','title'=>'Confirm rooftop capacity','detail'=>'Capacity verified at 24 for the private section.','due_at'=>coveted_system_sample_time('-8 days',12,0),'priority'=>'normal','status'=>'completed','completed_at'=>coveted_system_sample_time('-9 days',16,0)],
    ];

    $partnerPerks = [
        ['public_id'=>'sample-perk-ember','business_ref'=>'sample-ember-hospitality','group_ref'=>'sample-inner-circle','location_ref'=>'sample-ember-room','title'=>'Member table upgrade','perk_type'=>'special_access','status'=>'active','issued_count'=>22,'claimed_count'=>13,'description'=>'Priority access to the quieter rooftop table on selected nights.'],
        ['public_id'=>'sample-perk-harbor','business_ref'=>'sample-harbor-house-group','group_ref'=>'sample-city-table-club','location_ref'=>'sample-harbor-house','title'=>'Shared dessert return perk','perk_type'=>'complimentary_item','status'=>'active','issued_count'=>19,'claimed_count'=>11,'description'=>'Complimentary dessert on a member return visit.'],
        ['public_id'=>'sample-perk-velvet','business_ref'=>'sample-velvet-note','group_ref'=>'sample-late-night-listening','location_ref'=>'sample-velvet-note','title'=>'Early room access','perk_type'=>'special_access','status'=>'active','issued_count'=>27,'claimed_count'=>18,'description'=>'Members can enter fifteen minutes before doors for selected listening sessions.'],
        ['public_id'=>'sample-perk-velvet-guest','business_ref'=>'sample-velvet-note','group_ref'=>'sample-late-night-listening','location_ref'=>'sample-velvet-note','title'=>'Bring-a-friend pass','perk_type'=>'guest_access','status'=>'paused','issued_count'=>12,'claimed_count'=>7,'description'=>'One guest admission for a future listening night.'],
    ];

    $artists = [
        ['id'=>1001,'public_id'=>'sample-artist-sienna-cole','artist_name'=>'Sienna Cole','bio'=>'Phoenix singer-songwriter blending desert soul, indie pop and late-night acoustic sets.','status'=>'active','owner_name'=>'Sienna Cole','team_count'=>2,'appearance_count'=>3,'reward_count'=>2,'avatar_url'=>'/assets/images/sample/people/sienna-cole.webp','cover_url'=>'/assets/images/sample/events/vinyl-and-cocktails-hero.webp'],
        ['id'=>1002,'public_id'=>'sample-artist-rina-foster','artist_name'=>'Rina Foster','bio'=>'Electronic producer and selector focused on intimate rooms and immersive listening sessions.','status'=>'active','owner_name'=>'Rina Foster','team_count'=>1,'appearance_count'=>2,'reward_count'=>1,'avatar_url'=>'/assets/images/sample/people/ava-stone.webp','cover_url'=>'/assets/images/sample/events/vinyl-and-cocktails-hero.webp'],
    ];

    $artistMedia = [
        ['public_id'=>'sample-media-sienna-1','artist_ref'=>'sample-artist-sienna-cole','title'=>'Desert After Dark','media_type'=>'audio','duration'=>'3:42','status'=>'published','reward_enabled'=>1],
        ['public_id'=>'sample-media-sienna-2','artist_ref'=>'sample-artist-sienna-cole','title'=>'Rooftop Session','media_type'=>'video','duration'=>'5:18','status'=>'published','reward_enabled'=>0],
        ['public_id'=>'sample-media-rina-1','artist_ref'=>'sample-artist-rina-foster','title'=>'Neon Mesa Mix','media_type'=>'audio','duration'=>'18:06','status'=>'published','reward_enabled'=>1],
    ];

    $artistAppearances = [
        ['artist_ref'=>'sample-artist-sienna-cole','event_ref'=>'sample-listening-room-night','event'=>'Listening Room Night','role'=>'featured','status'=>'completed'],
        ['artist_ref'=>'sample-artist-sienna-cole','event_ref'=>'sample-vinyl-and-cocktails','event'=>'Vinyl & Cocktails','role'=>'featured','status'=>'upcoming'],
        ['artist_ref'=>'sample-artist-rina-foster','event_ref'=>'sample-mystery-sunday','event'=>'Mystery Sunday','role'=>'guest','status'=>'upcoming'],
    ];

    $distribution = [
        ['public_id'=>'sample-dist-1','campaign'=>'Return to Ember','event'=>'First Friday Supper','recipient_count'=>18,'issued_count'=>18,'skipped_count'=>0,'status'=>'completed','created_at'=>coveted_system_sample_time('-6 days',22,0)],
        ['public_id'=>'sample-dist-2','campaign'=>'Listening Night Welcome','event'=>'Listening Room Night','recipient_count'=>21,'issued_count'=>21,'skipped_count'=>0,'status'=>'completed','created_at'=>coveted_system_sample_time('-15 days',23,10)],
        ['public_id'=>'sample-dist-3','campaign'=>'Harbor Return Table','event'=>'Chef’s Table Return Night','recipient_count'=>15,'issued_count'=>15,'skipped_count'=>0,'status'=>'completed','created_at'=>coveted_system_sample_time('-28 days',21,10)],
    ];

    $notifications = [
        ['public_id'=>'sample-notification-1','type'=>'event.reminder','title'=>'Saturday Night Supper Club is coming up','recipient'=>'Taylor Kim','priority'=>'normal','status'=>'delivered','created_at'=>coveted_system_sample_time('-1 day',9,0)],
        ['public_id'=>'sample-notification-2','type'=>'reward.unlocked','title'=>'Dinner on us unlocked','recipient'=>'Jordan Ellis','priority'=>'normal','status'=>'delivered','created_at'=>coveted_system_sample_time('-6 days',21,5)],
        ['public_id'=>'sample-notification-3','type'=>'partner.followup','title'=>'Harbor House follow-up overdue','recipient'=>'Coveted Admin','priority'=>'high','status'=>'delivered','created_at'=>coveted_system_sample_time('-1 day',8,0)],
        ['public_id'=>'sample-notification-4','type'=>'connection.mutual','title'=>'You and Jordan want to reconnect','recipient'=>'Taylor Kim','priority'=>'normal','status'=>'delivered','created_at'=>coveted_system_sample_time('-4 days',12,0)],
        ['public_id'=>'sample-notification-5','type'=>'push.failed','title'=>'Push delivery retry','recipient'=>'Ava Stone','priority'=>'low','status'=>'retrying','created_at'=>coveted_system_sample_time('-1 day',18,0)],
    ];

    $operations = [
        'summary'=>[
            'overdue_events'=>0,
            'lifecycle_backlog'=>2,
            'permanent_failures_24h'=>0,
            'stuck_deliveries'=>1,
            'future_events'=>5,
            'events_without_hosts'=>0,
            'events_without_locations'=>0,
            'claims_30d'=>4,
            'partner_followups_overdue'=>1,
        ],
        'lifecycle'=>[
            ['kind'=>'invitation_expiry','label'=>'2 stale invitation records ready for reconciliation','priority'=>2],
            ['kind'=>'push_retry','label'=>'1 notification delivery waiting for retry','priority'=>2],
        ],
        'automation'=>[
            ['key'=>'event-reminders','label'=>'Event reminders','status'=>'healthy','last_run'=>coveted_system_sample_time('-1 day',8,0)],
            ['key'=>'daily-event-settlement','label'=>'Daily Event settlement','status'=>'healthy','last_run'=>coveted_system_sample_time('-1 day',2,0)],
            ['key'=>'reward-expiration','label'=>'Reward expiration','status'=>'healthy','last_run'=>coveted_system_sample_time('-1 day',3,0)],
        ],
    ];

    $agentOpportunities = [
        ['priority'=>1,'key'=>'sample-partner-followup','category'=>'Partners','title'=>'Follow up with Leo Martinez at Harbor House','detail'=>'The scheduled Partner CRM follow-up is overdue. Review dessert claim economics and log the outcome.','href'=>'/admin/sample-data.php#sample-partners','evidence'=>'Due yesterday · 57.9% observed claim rate · 9 return-linked claims.','kind'=>'partner_followup_overdue'],
        ['priority'=>1,'key'=>'sample-crm-qualified','category'=>'Growth','title'=>'Work two qualified CRM prospects','detail'=>'Avery Brooks and Jules Morgan are qualified and ready for an Admin decision or next outreach.','href'=>'/admin/sample-data.php#sample-crm','evidence'=>'2 qualified records in the synthetic CRM pipeline.','kind'=>'crm_pipeline'],
        ['priority'=>2,'key'=>'sample-draft-event','category'=>'Events','title'=>'Review Rooftop Brunch for publishing','detail'=>'A draft gathering exists with a group, venue and capacity but has not been published.','href'=>'/admin/sample-data.php#sample-events','evidence'=>'Rooftop Brunch · The Inner Circle · 20 seats.','kind'=>'draft_event'],
        ['priority'=>2,'key'=>'sample-role-requests','category'=>'People','title'=>'Review pending host and artist role requests','detail'=>'Two members are waiting for expanded access.','href'=>'/admin/sample-data.php#sample-people','evidence'=>'1 Attendee Host request · 1 Artist Partner request.','kind'=>'role_request'],
        ['priority'=>2,'key'=>'sample-desert-bloom','category'=>'Partners','title'=>'Complete Desert Bloom partner readiness','detail'=>'The prospective partner has a location and upcoming mystery event but no Business Admin, rewards or active relationship benefits.','href'=>'/admin/sample-data.php#sample-partners','evidence'=>'1 location · 0 Business Admins · 0 reward programs.','kind'=>'partner_readiness'],
        ['priority'=>2,'key'=>'sample-benefit-sponsor','category'=>'Value','title'=>'Review Ember’s submitted Benefit sponsorship','detail'=>'Ember submitted a bounded 60-reward return-visit proposal. Review it before conversion.','href'=>'/admin/sample-data.php#sample-value','evidence'=>'60 committed rewards · estimated $1,500 value.','kind'=>'sponsorship_review'],
        ['priority'=>2,'key'=>'sample-lifecycle','category'=>'Operations','title'=>'Reconcile the lifecycle backlog','detail'=>'Two stale invitation records are ready for the canonical lifecycle worker.','href'=>'/admin/sample-data.php#sample-operations','evidence'=>'2 stale lifecycle records.','kind'=>'lifecycle_backlog'],
        ['priority'=>3,'key'=>'sample-artist-growth','category'=>'Artists','title'=>'Use Sienna Cole’s strong reward engagement','detail'=>'Artist content rewards are claiming at a high rate. Consider another bounded artist-attendance reward on a future listening event.','href'=>'/admin/sample-data.php#sample-artists','evidence'=>'21 issued · 19 claimed · 90.5% claim rate.','kind'=>'artist_value'],
    ];

    $agentMemory = [
        ['event_type'=>'partner_crm.interaction_logged','entity_type'=>'partner_interaction','entity_id'=>'sample-interaction-3','actor'=>'Coveted Admin','at'=>coveted_system_sample_time('-1 day',16,15)],
        ['event_type'=>'benefit_sponsorship.submitted','entity_type'=>'benefit_sponsorship','entity_id'=>'sample-sponsor-ember-fall','actor'=>'Claire Morgan','at'=>coveted_system_sample_time('-1 day',12,0)],
        ['event_type'=>'invite_crm.qualified','entity_type'=>'invite_request','entity_id'=>'sample-crm-ember-2','actor'=>'Coveted Admin','at'=>coveted_system_sample_time('-2 days',13,0)],
        ['event_type'=>'event.published','entity_type'=>'event','entity_id'=>'sample-vinyl-and-cocktails','actor'=>'Coveted Admin','at'=>coveted_system_sample_time('-3 days',10,0)],
        ['event_type'=>'partner_crm.followup_created','entity_type'=>'partner_followup','entity_id'=>'sample-followup-ember','actor'=>'Coveted Admin','at'=>coveted_system_sample_time('-3 days',9,30)],
        ['event_type'=>'reward.claimed','entity_type'=>'reward_claim','entity_id'=>'sample-claim-1','actor'=>'Jordan Ellis','at'=>coveted_system_sample_time('-2 days',19,0)],
    ];

    $agentTasks = [
        ['public_id'=>'sample-task-1','title'=>'Review Harbor House follow-up','status'=>'open','priority'=>'high','source'=>'partner_opportunity','due_at'=>coveted_system_sample_time('-1 day',12,0),'execution_ready'=>false],
        ['public_id'=>'sample-task-2','title'=>'Qualify Copper State Coffee','status'=>'open','priority'=>'normal','source'=>'crm','due_at'=>coveted_system_sample_time('+2 days',11,0),'execution_ready'=>false],
        ['public_id'=>'sample-task-3','title'=>'Review Rooftop Brunch draft','status'=>'open','priority'=>'normal','source'=>'event_opportunity','due_at'=>coveted_system_sample_time('+4 days',10,0),'execution_ready'=>false],
    ];

    $reconnectEvents = [
        ['public_id'=>'sample-first-friday-supper','title'=>'First Friday Supper','starts_at'=>coveted_system_sample_time('-6 days',19,0),'group_id'=>'inner-circle','group'=>'The Inner Circle','location'=>'Ember Room','image'=>'/assets/images/sample/events/saturday-night-supper-club-hero.webp'],
        ['public_id'=>'sample-listening-room-night','title'=>'Listening Room Night','starts_at'=>coveted_system_sample_time('-15 days',20,0),'group_id'=>'late-night-listening','group'=>'Late Night Listening','location'=>'Velvet Note','image'=>'/assets/images/sample/events/vinyl-and-cocktails-hero.webp'],
    ];

    $memberPeople = array_map(static fn(array $person): array => [
        'id'=>str_replace('sample-','',(string)$person['public_id']),
        'name'=>(string)$person['name'],
        'image'=>(string)$person['image'],
        'context'=>(string)$person['context'],
    ], array_slice($people, 0, 10));

    $memberEvents = array_map(static fn(array $event): array => [
        'public_id'=>(string)$event['public_id'],
        'title'=>(string)$event['title'],
        'event_type'=>(string)$event['event_type'],
        'timezone'=>(string)$event['timezone'],
        'starts_at'=>(string)$event['starts_at'],
        'location'=>(string)$event['location_name'],
        'city'=>'Phoenix, Arizona',
        'group'=>(string)$event['group_name'],
        'image'=>(string)$event['image'],
        'description'=>(string)$event['description'],
        'rsvp'=>match ((string)$event['public_id']) {
            'sample-saturday-night-supper-club' => 'attending',
            'sample-sunset-dinner' => 'invited',
            default => 'open',
        },
        'guest_count'=>(int)$event['attending_count'],
    ], array_slice($events, 0, 3));

    $memberGroups = array_map(static fn(array $group): array => [
        'id'=>str_replace('sample-','',(string)$group['public_id']),
        'name'=>(string)$group['name'],
        'members'=>(int)$group['member_count'],
        'next'=>(string)$group['next'],
        'description'=>(string)$group['description'],
        'city'=>(string)$group['city'],
        'image'=>(string)$group['image'],
    ], array_slice($groups, 0, 3));

    $reconnects = [
        $memberPeople[1] + ['event_public_id'=>'sample-first-friday-supper','status'=>'mutual'],
        $memberPeople[2] + ['event_public_id'=>'sample-first-friday-supper','status'=>'pending'],
        $memberPeople[4] + ['event_public_id'=>'sample-first-friday-supper','status'=>''],
        $memberPeople[3] + ['event_public_id'=>'sample-listening-room-night','status'=>'mutual'],
        $memberPeople[5] + ['event_public_id'=>'sample-listening-room-night','status'=>''],
        $memberPeople[0] + ['event_public_id'=>'sample-listening-room-night','status'=>''],
    ];

    $member = [
        'people'=>$memberPeople,
        'locations'=>array_map(static fn(array $location): array => [
            'id'=>str_replace('sample-','',(string)$location['public_id']),
            'name'=>(string)$location['name'],
            'city'=>'Phoenix, Arizona',
            'type'=>(string)$location['type'],
            'image'=>(string)$location['image'],
        ], [$locations[0],$locations[2],$locations[3]]),
        'events'=>$memberEvents,
        'groups'=>$memberGroups,
        'benefits'=>$wallet,
        'reconnect_events'=>$reconnectEvents,
        'reconnects'=>$reconnects,
        'profile'=>[
            'display_name'=>'Taylor Kim','city'=>'Phoenix, Arizona',
            'bio'=>'Phoenix-based product designer who likes good food, live music, small rooms and meeting people through something worth leaving the house for.',
            'avatar_url'=>'/assets/images/sample/people/taylor-kim.webp','cover_url'=>'/assets/images/sample/events/saturday-night-supper-club-hero.webp',
            'interests'=>['Local dining','Live music','Design','Travel','Independent venues'],
            'gathering_styles'=>['Dinner table','Listening night','Mystery gathering'],
        ],
    ];

    return [
        'meta'=>[
            'name'=>'Coveted Full System Demo','version'=>'2026.09','city'=>'Phoenix, Arizona','read_only'=>true,
            'description'=>'One coherent synthetic operating network spanning every major Coveted product domain.',
        ],
        'people'=>$people,'role_requests'=>$roleRequests,'invite_crm'=>$inviteCrm,'cities'=>$cities,
        'businesses'=>$businesses,'locations'=>$locations,'groups'=>$groups,'events'=>$events,'daily_events'=>$dailyEvents,
        'rewards'=>$rewards,'campaigns'=>$campaigns,'benefit_programs'=>$benefitPrograms,'sponsorships'=>$sponsorships,
        'loyalty'=>$loyalty,'wallet'=>$wallet,'claims'=>$claims,'distribution'=>$distribution,
        'partner_relationships'=>$partnerRelationships,'partner_contacts'=>$partnerContacts,'partner_notes'=>$partnerNotes,
        'partner_interactions'=>$partnerInteractions,'partner_followups'=>$partnerFollowups,'partner_perks'=>$partnerPerks,
        'artists'=>$artists,'artist_media'=>$artistMedia,'artist_appearances'=>$artistAppearances,
        'notifications'=>$notifications,'operations'=>$operations,
        'agent'=>['opportunities'=>$agentOpportunities,'memory'=>$agentMemory,'tasks'=>$agentTasks],
        'branding'=>['logo_uploaded'=>true,'accent'=>'Coveted monochrome','public_theme'=>'light'],
        'pwa'=>['assets_ready'=>6,'assets_total'=>6,'push_subscribers'=>9,'deliveries_24h'=>14,'failures_24h'=>1],
        'landing'=>['cities'=>$cities,'members'=>12818,'events'=>501,'business_partners'=>314,'connections_made'=>37240],
        'member'=>$member,
    ];
}

/** @return array<string,int> */
function coveted_system_sample_inventory(?array $sample = null): array
{
    $sample ??= coveted_system_sample_data();
    $keys = [
        'people','role_requests','invite_crm','cities','businesses','locations','groups','events','daily_events',
        'rewards','campaigns','benefit_programs','sponsorships','loyalty','wallet','claims','distribution',
        'partner_relationships','partner_contacts','partner_notes','partner_interactions','partner_followups','partner_perks',
        'artists','artist_media','artist_appearances','notifications',
    ];
    $inventory = [];
    foreach ($keys as $key) $inventory[$key] = count((array)($sample[$key] ?? []));
    $inventory['agent_opportunities'] = count((array)($sample['agent']['opportunities'] ?? []));
    $inventory['agent_tasks'] = count((array)($sample['agent']['tasks'] ?? []));
    $inventory['agent_memory'] = count((array)($sample['agent']['memory'] ?? []));
    return $inventory;
}

/** @return array<string,int> */
function coveted_system_sample_admin_counts(?array $sample = null): array
{
    $sample ??= coveted_system_sample_data();
    return [
        'users'=>count((array)$sample['people']),
        'groups'=>count((array)$sample['groups']),
        'events'=>count((array)$sample['events']),
        'businesses'=>count((array)$sample['businesses']),
        'artists'=>count((array)$sample['artists']),
        'pending_requests'=>count(array_filter((array)$sample['role_requests'],static fn(array $row):bool=>(string)$row['status']==='pending')),
        'invite_requests'=>count(array_filter((array)$sample['invite_crm'],static fn(array $row):bool=>in_array((string)$row['status'],['new','contacted','qualified'],true))),
        'cities'=>count(array_filter((array)$sample['cities'],static fn(array $row):bool=>(string)$row['status']==='active')),
    ];
}

/** @return array<string,mixed> */
function coveted_system_sample_agent_snapshot(array $admin, array $providerStatuses = []): array
{
    if (!coveted_is_system_admin($admin)) throw new InvalidArgumentException('System Admin access is required.');
    $sample = coveted_system_sample_data();
    $counts = coveted_system_sample_admin_counts($sample);
    $ops = (array)$sample['operations'];
    $opportunities = (array)$sample['agent']['opportunities'];
    $checks = [
        ['key'=>'people','label'=>'Member base started','done'=>true],
        ['key'=>'business','label'=>'Partner businesses added','done'=>true],
        ['key'=>'business_location','label'=>'Business locations covered','done'=>true],
        ['key'=>'business_admin','label'=>'Business ownership covered','done'=>false],
        ['key'=>'group','label'=>'Community groups added','done'=>true],
        ['key'=>'event','label'=>'Events created','done'=>true],
        ['key'=>'published_event','label'=>'Future events published','done'=>true],
        ['key'=>'value','label'=>'Active member value configured','done'=>true],
        ['key'=>'partner_crm','label'=>'Partner CRM exercised','done'=>true],
        ['key'=>'artist','label'=>'Artist partner workspace exercised','done'=>true],
        ['key'=>'branding','label'=>'Branding configured','done'=>true],
        ['key'=>'pwa','label'=>'PWA assets ready','done'=>true],
    ];
    $ready = count(array_filter($checks,static fn(array $check):bool=>!empty($check['done'])));
    $total = count($checks);

    return [
        'generated_at'=>gmdate('Y-m-d H:i:s'),
        'sample_mode'=>true,
        'sample_notice'=>'FULL SYSTEM SAMPLE MODE: every operational record in this snapshot is synthetic preview data. Discuss, compare and recommend freely, but never claim that sample entities are live or execute mutations against sample references.',
        'installation'=>['name'=>'Coveted','environment'=>'sample-preview','base_url'=>'','timezone'=>'America/Phoenix'],
        'readiness'=>['ready'=>$ready,'total'=>$total,'percent'=>(int)round(($ready/$total)*100),'checks'=>$checks],
        'metrics'=>[
            'active_users'=>$counts['users']-1,'businesses'=>$counts['businesses'],'active_businesses'=>3,
            'businesses_without_locations'=>0,'businesses_without_admins'=>1,'locations'=>count($sample['locations']),
            'groups'=>$counts['groups'],'active_groups'=>$counts['groups'],'groups_without_leadership'=>0,
            'events'=>$counts['events'],'draft_events'=>1,'published_future_events'=>4,'published_without_hosts'=>0,
            'published_without_locations'=>0,'published_without_invitations'=>0,'artists'=>$counts['artists'],
            'active_rewards'=>count($sample['rewards']),'active_campaigns'=>count($sample['campaigns']),
            'campaign_event_links'=>3,'venue_relationships'=>count($sample['partner_relationships']),'claims_30d'=>count($sample['claims']),
            'pending_role_requests'=>$counts['pending_requests'],
        ],
        'crm'=>[
            'new_count'=>1,'contacted_count'=>1,'qualified_count'=>2,'converted_count'=>1,'declined_count'=>0,
            'sample_records'=>array_slice($sample['invite_crm'],0,5),
        ],
        'operations'=>[
            'summary'=>$ops['summary'],
            'partner_relationships'=>$sample['partner_relationships'],
            'partner_crm'=>[
                'contacts'=>$sample['partner_contacts'],'notes'=>$sample['partner_notes'],'interactions'=>$sample['partner_interactions'],'followups'=>$sample['partner_followups'],
            ],
            'daily_events'=>$sample['daily_events'],'benefit_programs'=>$sample['benefit_programs'],'sponsorships'=>$sample['sponsorships'],
            'loyalty'=>$sample['loyalty'],'artists'=>$sample['artists'],'distribution'=>$sample['distribution'],
        ],
        'public_experience'=>['landing_events_enabled'=>true,'sample_events_enabled'=>true],
        'pwa'=>$sample['pwa'],'providers'=>$providerStatuses,'branding'=>$sample['branding'],
        'opportunities'=>$opportunities,
        'capabilities'=>[
            ['key'=>'people','label'=>'People & access','href'=>'/admin/?view=users'],
            ['key'=>'crm','label'=>'Invite CRM','href'=>'/admin/crm.php'],
            ['key'=>'businesses','label'=>'Businesses & Partner CRM','href'=>'/venue-relationships.php'],
            ['key'=>'groups','label'=>'Groups','href'=>'/admin/?view=groups'],
            ['key'=>'events','label'=>'Events & Daily Events','href'=>'/admin/daily-events.php'],
            ['key'=>'artists','label'=>'Artist partners','href'=>'/admin/?view=artists'],
            ['key'=>'benefits','label'=>'Benefits, campaigns & loyalty','href'=>'/admin/benefit-programs.php'],
            ['key'=>'operations','label'=>'Operations & automation','href'=>'/admin/operations.php'],
        ],
        'memory'=>$sample['agent']['memory'],'agent_tasks'=>$sample['agent']['tasks'],
        'issues'=>[],'sample_inventory'=>coveted_system_sample_inventory($sample),
    ];
}
