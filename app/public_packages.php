<?php
declare(strict_types=1);

require_once __DIR__ . '/service_packages.php';

/** @return array<string,array{label:string,description:string,audience:string}> */
function coveted_service_entitlement_catalog(): array
{
    return [
        'membership.core' => ['label'=>'Core Coveted membership','description'=>'Member account, profile and participation access.','audience'=>'member'],
        'membership.plus' => ['label'=>'Member+ service','description'=>'Paid member service level.','audience'=>'member'],
        'events.rsvp' => ['label'=>'Events, invitations & RSVP','description'=>'Receive invitations and manage event participation.','audience'=>'member'],
        'groups.member' => ['label'=>'Group membership','description'=>'Participate in Coveted groups and communities.','audience'=>'member'],
        'benefits.wallet' => ['label'=>'Perk Wallet & member benefits','description'=>'Receive and manage eligible Coveted benefits.','audience'=>'member'],
        'member.concierge.basic' => ['label'=>'Social Concierge','description'=>'Personal guidance for invitations, events and member benefits.','audience'=>'member'],
        'member.concierge.advanced' => ['label'=>'Advanced Social Concierge','description'=>'Expanded personal planning and relationship intelligence.','audience'=>'member'],
        'travel.planning' => ['label'=>'Travel planning','description'=>'Plan destination experiences with the Coveted concierge layer.','audience'=>'member'],
        'travel.destination_events' => ['label'=>'Destination event access','description'=>'Access eligible guided and group destination event options.','audience'=>'member'],
        'travel.guided_groups' => ['label'=>'Guided group experiences','description'=>'Access eligible guided/group travel and destination experiences.','audience'=>'member'],
        'partner.workspace' => ['label'=>'Partner workspace','description'=>'Business-scoped Coveted partner operations.','audience'=>'partner'],
        'partner.profile' => ['label'=>'Partner public profile','description'=>'Business presence inside the Coveted partner network.','audience'=>'partner'],
        'partner.events' => ['label'=>'Event partner participation','description'=>'Participate in Admin-created Coveted events as a partner.','audience'=>'partner'],
        'partner.offers' => ['label'=>'Partner offers & perks','description'=>'Manage member-facing partner offers and benefits.','audience'=>'partner'],
        'partner.crm.basic' => ['label'=>'Partner customer history','description'=>'Basic relationship and participation history.','audience'=>'partner'],
        'partner.results.basic' => ['label'=>'Partner results','description'=>'Basic event, benefit and participation results.','audience'=>'partner'],
        'partner.crm.advanced' => ['label'=>'Advanced partner CRM','description'=>'Expanded customer relationship and engagement intelligence.','audience'=>'partner'],
        'partner.results.advanced' => ['label'=>'Advanced partner results','description'=>'Deeper event, campaign and partner outcome reporting.','audience'=>'partner'],
        'partner.campaigns' => ['label'=>'Benefit & campaign builder','description'=>'Build partner campaigns, rewards and benefit programs.','audience'=>'partner'],
        'partner.return_tracking' => ['label'=>'Return-visit tracking','description'=>'Measure repeat participation and return opportunities.','audience'=>'partner'],
        'partner.roi' => ['label'=>'Partner ROI reporting','description'=>'Connect participation, benefits and returns to partner value.','audience'=>'partner'],
        'partner.ai_insights' => ['label'=>'AI partner insights','description'=>'AI-assisted opportunity and relationship intelligence.','audience'=>'partner'],
        'partner.sponsored_benefits' => ['label'=>'Sponsored benefits & campaigns','description'=>'Sponsor Coveted benefits and campaign opportunities.','audience'=>'partner'],
        'organization.groups' => ['label'=>'Multiple groups & communities','description'=>'Operate multiple community/group programs.','audience'=>'organization'],
        'organization.membership_crm' => ['label'=>'Membership lifecycle CRM','description'=>'Lifecycle visibility across member stages.','audience'=>'organization'],
        'organization.guest_conversion' => ['label'=>'Guest-to-member conversion','description'=>'Conversion intelligence for verified guest participation.','audience'=>'organization'],
        'organization.referrals' => ['label'=>'Referral & network growth','description'=>'Relationship-aware referral and network-growth intelligence.','audience'=>'organization'],
        'organization.reporting' => ['label'=>'Organization reporting','description'=>'Cross-program reporting for organization operators.','audience'=>'organization'],
        'organization.ai_operations' => ['label'=>'AI operations intelligence','description'=>'Expanded AI guidance for organization operations.','audience'=>'organization'],
    ];
}

function coveted_service_entitlement_truthy(mixed $value): bool
{
    $value = strtolower(trim((string)$value));
    return !in_array($value, ['', '0', 'false', 'off', 'no'], true);
}

/**
 * Package purchase subject is deliberately stored in the existing dynamic
 * entitlement table instead of a second pricing configuration.
 */
function coveted_service_package_subject(array $package, ?PDO $pdo = null): string
{
    $pdo ??= coveted_db();
    $entitlements = coveted_service_entitlements_for_package((int)$package['id'], $pdo);
    $subject = strtolower(trim((string)($entitlements['billing.subject'] ?? '')));
    if (in_array($subject, ['user','business','manual'], true)) {
        return $subject;
    }

    // Safe legacy inference until the paid-package catalog migration is run.
    if (isset($entitlements['partner.workspace']) || str_starts_with((string)($package['package_key'] ?? ''), 'partner')) {
        return 'business';
    }
    if (array_filter(array_keys($entitlements), static fn(string $key): bool => str_starts_with($key, 'organization.'))) {
        return 'manual';
    }
    return 'user';
}

function coveted_service_package_is_public(array $package, ?PDO $pdo = null): bool
{
    $pdo ??= coveted_db();
    $entitlements = coveted_service_entitlements_for_package((int)$package['id'], $pdo);
    if (array_key_exists('billing.public', $entitlements)) {
        return coveted_service_entitlement_truthy($entitlements['billing.public']);
    }
    return in_array((string)($package['package_key'] ?? ''), ['free','plus','partner'], true);
}

/** @return list<array<string,mixed>> */
function coveted_service_public_packages(?string $subject = null, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $subject = $subject !== null ? strtolower(trim($subject)) : null;
    if ($subject !== null && !in_array($subject, ['user','business','manual'], true)) {
        throw new InvalidArgumentException('Invalid package subject.');
    }

    $result = [];
    foreach (coveted_service_packages(true, $pdo) as $package) {
        if (!coveted_service_package_is_public($package, $pdo)) {
            continue;
        }
        $packageSubject = coveted_service_package_subject($package, $pdo);
        if ($subject !== null && $packageSubject !== $subject) {
            continue;
        }
        $package['billing_subject'] = $packageSubject;
        $package['public_entitlements'] = coveted_service_public_entitlements($package, $pdo);
        $result[] = $package;
    }
    return $result;
}

function coveted_service_package_available_to_subject(int $packageId, string $subject, ?PDO $pdo = null): bool
{
    $pdo ??= coveted_db();
    $package = coveted_service_package($packageId, $pdo);
    if (!$package || (int)$package['is_active'] !== 1 || !coveted_service_package_is_public($package, $pdo)) {
        return false;
    }
    return coveted_service_package_subject($package, $pdo) === strtolower(trim($subject));
}

/** @return list<array{key:string,label:string,description:string,audience:string,value:string}> */
function coveted_service_public_entitlements(array $package, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $catalog = coveted_service_entitlement_catalog();
    $rows = [];
    foreach (coveted_service_entitlements_for_package((int)$package['id'], $pdo) as $key => $value) {
        if (str_starts_with($key, 'billing.') || !coveted_service_entitlement_truthy($value)) {
            continue;
        }
        $meta = $catalog[$key] ?? null;
        $rows[] = [
            'key' => $key,
            'label' => $meta['label'] ?? ucwords(str_replace(['.','_','-'], ' ', $key)),
            'description' => $meta['description'] ?? '',
            'audience' => $meta['audience'] ?? 'custom',
            'value' => (string)$value,
        ];
    }
    return $rows;
}
