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
        fwrite(STDERR,"Partner/Pricing contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content,string $needle,string $label): void {
    if (str_contains($content,$needle)) {
        fwrite(STDERR,"Partner/Pricing contract failed: {$label}\n");
        exit(1);
    }
};

$migration = $read('database/migrations/20260908_paid_member_partner_packages.sql');
$packages = $read('app/public_packages.php');
$partners = $read('app/partner_accounts.php');
$onboarding = $read('partner-onboarding.php');
$pricing = $read('pricing.php');
$subscribe = $read('subscribe.php');
$billingAction = $read('billing-action.php');
$billingReturn = $read('billing-return.php');
$webhook = $read('api/stripe-webhook.php');
$footer = $read('assets/js/legal-footer.js');
$bootstrap = $read('app/bootstrap.php');
$home = $read('index.php');
$adminPackages = $read('admin/service-packages.php');

$contains($migration,"WHERE package_key = 'free'",'existing Free package must become the Member catalog entry');
$contains($migration,"WHERE package_key = 'plus'",'existing Plus package must become the paid Member+ entry');
$contains($migration,"'partner_pro','Partner Pro'",'Partner Pro must be seeded into the dynamic catalog');
$contains($migration,"'organization','Organization'",'Organization must be seeded for future/manual packaging');
$contains($migration,"SELECT id,'billing.subject','user',1",'member packages must declare a user billing subject');
$contains($migration,"SELECT id,'billing.subject','business',1",'partner packages must declare a business billing subject');
$contains($migration,"SELECT id,'billing.subject','manual',1",'organization package must remain assisted/manual until organization billing exists');
$contains($migration,"SELECT id,'billing.public','1',1",'public package visibility must be dynamic entitlement metadata');
$contains($migration,"SELECT id,'travel.planning','0',0",'future travel planning hook must be seeded disabled');
$contains($migration,"SELECT id,'travel.destination_events','0',0",'future destination-event hook must be seeded disabled');
$contains($migration,"SELECT id,'travel.guided_groups','0',0",'future guided-group hook must be seeded disabled');
$missing($migration,"monthly_price_cents,currency,sort_order,is_active,is_default)\nVALUES\n    ('plus'",'migration must not hard-code a new Member+ price');

$contains($packages,'function coveted_service_package_subject','package subject must come from the canonical entitlement table');
$contains($packages,"['user','business','manual']",'package subjects must be allowlisted');
$contains($packages,"'billing.public'",'public pricing visibility must be controlled by package entitlement metadata');
$contains($packages,'coveted_service_packages(true, $pdo)','public pricing must read the live active package catalog');
$contains($packages,'coveted_service_entitlements_for_package','public features must read the same package entitlement records as authorization');
$contains($packages,"if (str_starts_with(\$key, 'billing.') || !coveted_service_entitlement_truthy(\$value))",'billing metadata and disabled future capabilities must not appear as public features');

$contains($partners,"VALUES (?,?,?,'prospective',?)",'self-service partners must begin prospective');
$contains($partners,'INSERT INTO business_admins (business_id,user_id)','creator must become first resource-scoped Business Admin');
$contains($partners,"'partner.self_service_created'",'self-service partner creation must be audited');
$contains($partners,'function coveted_partner_activate_business','partner activation must have one idempotent canonical helper');
$contains($partners,'function coveted_partner_activate_from_stripe_subscription_payload','signed subscription payloads must support retry-safe activation');
$contains($partners,"['active','trialing']",'only paid/trial subscription states may activate a prospective partner');
$contains($partners,"WHERE id=? AND status='prospective'",'activation must only promote prospective businesses');
$missing($partners,'INSERT INTO user_roles','partner creation must not grant a global role');
$missing($partners,'CREATE TABLE','partner onboarding must not perform runtime DDL');

$contains($onboarding,'coveted_require_user()','partner onboarding must require an authenticated member');
$contains($onboarding,'coveted_require_csrf();','partner creation must require CSRF');
$contains($onboarding,"coveted_service_public_packages('business'",'partner choices must come from dynamic business packages');
$contains($onboarding,'coveted_partner_create_self_service','partner creation must use the canonical self-service function');
$contains($onboarding,"'/subscribe.php?business='",'selected partner package must continue through subject-aware confirmation');
$contains($onboarding,'Creating a partner does not change your global Coveted user role.','resource-scoped authority boundary must be explicit');

$contains($pricing,'coveted_service_public_packages(null,$pdo)','public Pricing must read the live package catalog');
$contains($pricing,"'/subscribe.php?package='",'paid member package must enter subject-aware subscription confirmation');
$contains($pricing,"'/partner-onboarding.php?package='",'business package must enter partner onboarding');
$contains($pricing,"\$subject === 'manual' || \$price === null",'unpriced/manual packages must stay out of self-service checkout');
$contains($pricing,'$package[\'monthly_price_cents\']','public price must be rendered from the live package row');
$contains($pricing,'$package[\'public_entitlements\']','public feature list must be derived from enabled package entitlements');
$missing($pricing,'59.00','Pricing page must not hard-code Partner pricing');
$missing($pricing,'149.00','Pricing page must not hard-code Partner Pro pricing');
$missing($pricing,'299.00','Pricing page must not hard-code Organization pricing');

$contains($subscribe,'You are purchasing <?= coveted_e((string)$package[\'name\']) ?> for <?= coveted_e($subjectLabel) ?>.','confirmation must name both package and billing subject');
$contains($subscribe,'coveted_service_package_available_to_subject','confirmation must enforce package billing audience');
$contains($subscribe,"\$subjectType = 'business'",'business subscriptions must have a business billing subject');
$contains($subscribe,"\$subjectType = 'user'",'member subscriptions must have a user billing subject');
$contains($subscribe,'Stripe hosts the payment form. Coveted does not receive or store your card number or CVC.','hosted payment boundary must remain explicit');
$contains($subscribe,'name="business_ref"','business identity must be carried into checkout');

$contains($billingAction,"require_once __DIR__ . '/app/public_packages.php';",'checkout action must load package audience rules');
$contains($billingAction,'coveted_service_package_available_to_subject($packageId,$billingSubject,$pdo)','checkout must reject cross-audience packages server-side');
$contains($billingAction,"\$billingSubject = \$business !== null ? 'business' : 'user';",'checkout audience must be determined by the actual billing subject');
$contains($billingReturn,'coveted_partner_activate_from_billing_result($result,$pdo);','checkout return must activate eligible prospective partners');
$contains($webhook,'coveted_partner_activate_from_stripe_subscription_payload($eventObject,$pdo);','signed subscription webhook must activate before event completion');
$contains($webhook,"str_starts_with(\$eventType,'customer.subscription.')",'partner activation must only inspect signed subscription events');
$missing($webhook,'coveted_partner_activate_from_billing_result($result,$pdo);','webhook must not perform a fallible activation after marking an event processed');

$contains($footer,"['/pricing.php', 'Pricing']",'Pricing link must be available in the footer');
$missing($bootstrap,'href="/pricing.php"','Pricing must not be promoted in the primary/account navigation');
$missing($home,'<a href="/pricing.php"','Pricing must not be hard-coded into the public landing navigation/body');

$contains($adminPackages,'name="monthly_price"','System Admin must control live monthly package pricing');
$contains($adminPackages,'name="entitlements"','System Admin must control package entitlements');
$contains($adminPackages,'name="is_active"','System Admin must control package active state');
$contains($adminPackages,'scope_type" value="business"','System Admin must be able to assign packages directly to partners');

fwrite(STDOUT,"Partner onboarding + dynamic pricing contract verified.\n");
