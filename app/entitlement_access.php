<?php
declare(strict_types=1);

require_once __DIR__ . '/public_packages.php';

/**
 * System Admin keeps global operational authority. Everyone else resolves
 * business capability through the canonical Service Packages engine so Admin
 * overrides, business overrides, user-type overrides and subscriptions retain
 * their existing precedence.
 */
function coveted_entitlement_business_has(
    array $actor,
    int $businessId,
    string $entitlement,
    ?PDO $pdo = null
): bool {
    if (coveted_is_system_admin($actor)) {
        return true;
    }
    if ($businessId < 1 || trim($entitlement) === '') {
        return false;
    }
    $pdo ??= coveted_db();
    return coveted_service_has_entitlement($actor, $entitlement, $businessId, $pdo);
}

function coveted_entitlement_require_business(
    array $actor,
    int $businessId,
    string $entitlement,
    string $message = 'Your current partner package does not include this capability.',
    ?PDO $pdo = null
): void {
    if (!coveted_entitlement_business_has($actor, $businessId, $entitlement, $pdo)) {
        throw new InvalidArgumentException($message);
    }
}

/**
 * Find the first live public package for a billing subject that grants a
 * capability. Sort order remains controlled by System Admin. This deliberately
 * avoids coupling product authorization to package keys such as partner_pro.
 */
function coveted_entitlement_upgrade_package(
    string $entitlement,
    string $subject = 'business',
    ?PDO $pdo = null
): ?array {
    $pdo ??= coveted_db();
    $entitlement = strtolower(trim($entitlement));
    if ($entitlement === '') {
        return null;
    }

    foreach (coveted_service_public_packages($subject, $pdo) as $package) {
        $included = coveted_service_entitlements_for_package((int)$package['id'], $pdo);
        if (array_key_exists($entitlement, $included)
            && coveted_service_entitlement_truthy($included[$entitlement])) {
            return $package;
        }
    }
    return null;
}

function coveted_entitlement_upgrade_href(
    string $entitlement,
    ?array $business = null,
    ?PDO $pdo = null
): string {
    $pdo ??= coveted_db();
    $subject = $business ? 'business' : 'user';
    $package = coveted_entitlement_upgrade_package($entitlement, $subject, $pdo);
    if (!$package) {
        return '/pricing.php';
    }

    $price = $package['monthly_price_cents'] !== null ? (int)$package['monthly_price_cents'] : null;
    if ($price === null || $price <= 0) {
        return '/pricing.php';
    }

    $query = 'package=' . rawurlencode((string)$package['package_key']);
    if ($business) {
        $query = 'business=' . rawurlencode((string)$business['public_id']) . '&' . $query;
    }
    return '/subscribe.php?' . $query;
}

/** @return array{package:?array,href:string,label:string,description:string} */
function coveted_entitlement_upgrade_offer(
    string $entitlement,
    ?array $business = null,
    ?PDO $pdo = null
): array {
    $pdo ??= coveted_db();
    $subject = $business ? 'business' : 'user';
    $package = coveted_entitlement_upgrade_package($entitlement, $subject, $pdo);
    return [
        'package' => $package,
        'href' => coveted_entitlement_upgrade_href($entitlement, $business, $pdo),
        'label' => $package ? 'Upgrade to ' . (string)$package['name'] : 'View service packages',
        'description' => $package ? (string)($package['description'] ?? '') : 'See the currently available Coveted service packages.',
    ];
}
