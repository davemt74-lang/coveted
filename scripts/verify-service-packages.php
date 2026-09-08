<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $value = @file_get_contents($root . '/' . ltrim($path, '/'));
    if ($value === false) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
    return $value;
};
$contains = static function (string $content, string $needle, string $label): void {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "Service Packages contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Service Packages contract failed: {$label}\n");
        exit(1);
    }
};

$migration = $read('database/migrations/20260907_service_packages_billing.sql');
$service = $read('app/service_packages.php');
$billing = $read('billing.php');
$admin = $read('admin/service-packages.php');

$contains($migration, 'CREATE TABLE IF NOT EXISTS service_packages (', 'package catalog table is required');
$contains($migration, 'CREATE TABLE IF NOT EXISTS service_package_entitlements (', 'entitlement table is required');
$contains($migration, 'CREATE TABLE IF NOT EXISTS billing_subscriptions (', 'provider-neutral subscription table is required');
$contains($migration, 'CREATE TABLE IF NOT EXISTS service_package_assignments (', 'Admin assignment table is required');
$contains($migration, "scope_type ENUM('user','business','user_role')", 'assignment scopes must use canonical user, business and user role models');
$contains($migration, "role_key ENUM('attendee','attendee_host','artist_partner','system_admin')", 'user-type assignments must use canonical platform roles');
$contains($migration, 'provider VARCHAR(40) NOT NULL', 'subscriptions must remain provider-neutral');
$contains($migration, 'revoked_at DATETIME NULL', 'Admin grants must preserve revocation history');
$contains($migration, 'billing_bypass', 'migration should document the Admin bypass model');

$contains($service, "return ['attendee', 'attendee_host', 'artist_partner', 'system_admin'];", 'service user types must match canonical user_roles');
$contains($service, "'user_override'", 'user override source must be explicit');
$contains($service, "'partner_override'", 'partner override source must be explicit');
$contains($service, "'user_type_override'", 'user-type override source must be explicit');
$contains($service, "'subscription'", 'subscription source must be explicit');
$contains($service, "'default'", 'default package source must be explicit');
$contains($service, "coveted_service_assignment_for_scope('user'", 'user override must resolve first');
$contains($service, "coveted_service_assignment_for_scope('business'", 'partner override must use canonical business assignment');
$contains($service, "coveted_service_assignment_for_scope('user_role'", 'user-type override must use canonical role assignment');
$contains($service, 'function coveted_service_has_entitlement', 'canonical feature entitlement helper is required');
$contains($service, 'function coveted_service_require_entitlement', 'canonical entitlement gate helper is required');
$contains($service, 'function coveted_service_assign_package', 'canonical Admin assignment mutator is required');
$contains($service, 'Only a System Admin can bypass billing or assign packages.', 'Admin bypass must require System Admin authority');
$contains($service, "'billing_bypass' => true", 'Admin assignment audit must identify billing bypass');
$contains($service, 'coveted_audit(', 'package changes must use the canonical audit trail');
$contains($service, 'revoked_at = NOW()', 'replacing/revoking assignments must preserve historical records');
$missing($service, 'CREATE TABLE', 'runtime DDL is forbidden');
$missing($service, 'ALTER TABLE', 'runtime schema mutation is forbidden');
$missing($service, 'stripe', 'authorization service must not be coupled to Stripe');

$contains($billing, 'coveted_service_effective_package(', 'Billing page must display canonical effective access');
$contains($billing, 'Admin package override + active subscription.', 'Billing page must warn about double-billing overlap');
$contains($billing, 'Payment bypass active.', 'Billing page must explain Admin-granted access');
$contains($billing, 'billing_subscriptions', 'Billing page must explain provider-neutral subscription records');

$contains($admin, 'coveted_require_system_admin();', 'Service Packages workspace must require System Admin');
$contains($admin, 'coveted_require_csrf();', 'Service Packages mutations must enforce CSRF');
$contains($admin, 'name="scope_type" value="user"', 'Admin must be able to assign a package per user');
$contains($admin, 'name="scope_type" value="business"', 'Admin must be able to assign a package per partner');
$contains($admin, 'name="scope_type" value="user_role"', 'Admin must be able to assign a package per user type');
$contains($admin, 'User override → Partner override → User-type override → Subscription → Default.', 'Admin UI must state effective precedence');
$contains($admin, 'does not auto-cancel provider billing', 'Admin UI must preserve payment-provider authority boundary');
$missing($admin, 'INSERT INTO service_package_assignments', 'Admin page must not bypass the canonical assignment service');
$missing($admin, 'UPDATE service_package_assignments', 'Admin page must not bypass the canonical assignment service');

fwrite(STDOUT, "Service Packages & Billing contract verified.\n");
