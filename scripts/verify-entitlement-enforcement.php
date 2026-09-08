<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $value = @file_get_contents($root . '/' . ltrim($path,'/'));
    if ($value === false) {
        fwrite(STDERR,"Missing required file: {$path}\n");
        exit(1);
    }
    return $value;
};
$contains = static function (string $content,string $needle,string $label): void {
    if (!str_contains($content,$needle)) {
        fwrite(STDERR,"Entitlement enforcement contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content,string $needle,string $label): void {
    if (str_contains($content,$needle)) {
        fwrite(STDERR,"Entitlement enforcement contract failed: {$label}\n");
        exit(1);
    }
};

$access = $read('app/entitlement_access.php');
$business = $read('business.php');
$businesses = $read('app/businesses.php');
$rewards = $read('app/rewards.php');
$campaigns = $read('app/campaigns.php');
$pricing = $read('pricing.php');
$migration = $read('database/migrations/20260908_paid_member_partner_packages.sql');

// Canonical authorization and upgrade discovery.
$contains($access,"coveted_service_has_entitlement($actor, $entitlement, $businessId, $pdo)",'business entitlement checks must use the canonical package resolver');
$contains($access,'coveted_is_system_admin($actor)','System Admin authority must remain an explicit bypass');
$contains($access,'coveted_service_public_packages($subject, $pdo)','upgrade targets must be discovered from the live package catalog');
$contains($access,'coveted_service_entitlements_for_package','upgrade discovery must use the same entitlement records as authorization');
$contains($access,"return '/pricing.php';",'unpriced or unavailable upgrade paths must fall back to dynamic Pricing');
$contains($access,"return '/subscribe.php?'",'priced upgrade paths must use subject-aware subscription confirmation');
$missing($access,'partner_pro','enforcement must not depend on the Partner Pro package key');
$missing($access,"provider='stripe'",'authorization helpers must not use Stripe state directly');
$missing($access,'billing_subscriptions','authorization helpers must not query subscription tables directly');

// Workspace read/action boundaries.
$contains($business,"'partner.workspace'",'business workspace must require the partner workspace capability');
$contains($business,"'create_location' => 'partner.profile'",'location mutations must require partner profile capability');
$contains($business,"'create_reward' => 'partner.offers'",'reward mutations must require partner offers capability');
$contains($business,"'create_campaign' => 'partner.campaigns'",'campaign mutations must require partner campaigns capability');
$contains($business,"'claims' => 'partner.results.basic'",'claim history must require basic partner results capability');
$contains($business,"'insights' => 'partner.results.advanced'",'advanced outcome reporting must require advanced partner results capability');
$contains($business,'if (!$workspaceAllowed && !$isSystemAdmin)','unentitled partners must be stopped before operational workspace data loads');
$contains($business,'if (!$tabLocked && in_array($tab','locked tabs must not load operational datasets');
$contains($business,'PACKAGE UPGRADE','locked capabilities must produce contextual upgrade UX');
$contains($business,"coveted_entitlement_upgrade_offer((string)$tabEntitlement, $business)",'upgrade UX must resolve dynamically by entitlement');
$contains($business,'/partner-onboarding.php','members without a business must be offered self-service Partner onboarding');
$missing($business,"package_key === 'partner_pro'",'business workspace must not gate on package names/keys');
$missing($business,'travel.planning','future travel hooks must not surface in the business workspace');

// Shared business services stay dependency-safe. service_packages.php already
// depends on businesses.php, so businesses.php must not import entitlement_access.
$missing($businesses,"require_once __DIR__ . '/entitlement_access.php';",'business services must not create a service_packages/businesses dependency cycle');
$missing($businesses,'coveted_entitlement_require_business(','business base services must stay below the package layer');

// Alternate reward/campaign callers cannot bypass premium business capabilities.
$contains($rewards,"require_once __DIR__ . '/entitlement_access.php';",'reward service must explicitly load entitlement enforcement after business services');
$contains($rewards,"$ownerType === 'business'",'business reward branches must be explicit');
$contains($rewards,"$actor, $ownerId, 'partner.offers'",'business reward creation must require partner offers');
$contains($rewards,"$actor, $ownerId, 'partner.offers',",'business reward mutations must require partner offers');
$contains($campaigns,"$actor, $ownerId, 'partner.campaigns'",'business campaign creation must require partner campaigns');
$contains($campaigns,"$actor, $ownerId, 'partner.campaigns',",'business campaign status mutations must require partner campaigns');
$missing($rewards,'partner_pro','reward service must not know package keys');
$missing($campaigns,'partner_pro','campaign service must not know package keys');

// Future member travel capability remains infrastructure-only and disabled.
$contains($migration,"SELECT id,'travel.planning','0',0",'travel planning hook must remain disabled');
$contains($migration,"SELECT id,'travel.destination_events','0',0",'destination-event hook must remain disabled');
$contains($migration,"SELECT id,'travel.guided_groups','0',0",'guided-group hook must remain disabled');
$missing($pricing,'Travel planning','disabled future travel capability must not be hard-coded into public Pricing');
$missing($pricing,'Guided group experiences','disabled future travel capability must not be advertised publicly');

// This phase is authorization/UI only.
foreach ([$access,$business,$businesses,$rewards,$campaigns] as $content) {
    $missing($content,'CREATE TABLE','entitlement enforcement must not perform runtime DDL');
    $missing($content,'ALTER TABLE','entitlement enforcement must not mutate schema at runtime');
}

fwrite(STDOUT,"Entitlement enforcement + upgrade UX contract verified.\n");
