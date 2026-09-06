<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/partner_opportunities.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $user = coveted_require_user();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo coveted_json(['ok' => false, 'error' => 'GET required.']);
        exit;
    }

    $businessRef = trim((string)($_GET['business'] ?? ''));
    $groupRef = trim((string)($_GET['group'] ?? ''));
    $locationRef = trim((string)($_GET['location'] ?? ''));
    $business = coveted_business_resolve_context($user, $businessRef);
    if (!$business) {
        throw new InvalidArgumentException('No Business Partner workspace is available to this account.');
    }

    $snapshot = coveted_partner_opportunities_for_business($user, (int)$business['id']);
    $recommendations = array_values((array)$snapshot['recommendations']);

    if ($groupRef !== '' || $locationRef !== '') {
        if ($groupRef === '' || $locationRef === '') {
            throw new InvalidArgumentException('Both group and location are required for a relationship filter.');
        }
        if (strlen($groupRef) > 64 || strlen($locationRef) > 64) {
            throw new InvalidArgumentException('Relationship filter is invalid.');
        }
        $recommendations = array_values(array_filter(
            $recommendations,
            static fn(array $item): bool =>
                hash_equals((string)($item['group_ref'] ?? ''), $groupRef)
                && hash_equals((string)($item['location_ref'] ?? ''), $locationRef)
        ));
    }

    echo coveted_json([
        'ok' => true,
        'generated_at' => (string)$snapshot['generated_at'],
        'business' => [
            'public_id' => (string)$snapshot['business_ref'],
            'name' => (string)$snapshot['business_name'],
        ],
        'privacy' => (string)$snapshot['privacy'],
        'action_policy' => (string)$snapshot['action_policy'],
        'counts' => (array)$snapshot['counts'],
        'recommendations' => $recommendations,
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo coveted_json(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Partner opportunities API failed: ' . $e->getMessage());
    http_response_code(500);
    echo coveted_json(['ok' => false, 'error' => 'Partner opportunities are unavailable right now.']);
}
