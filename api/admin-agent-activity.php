<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/invite_crm.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    coveted_require_system_admin();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        echo coveted_json(['ok' => false, 'error' => 'GET required.']);
        exit;
    }

    $cursor = max(0, (int)($_GET['cursor'] ?? 0));
    $pdo = coveted_db();

    try {
        $latestId = (int)($pdo->query('SELECT COALESCE(MAX(id), 0) FROM invite_requests')->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        echo coveted_json([
            'ok' => true,
            'available' => false,
            'cursor' => $cursor,
            'items' => [],
            'has_more' => false,
        ]);
        exit;
    }

    if ($cursor > $latestId) {
        $cursor = $latestId;
    }

    $stmt = $pdo->prepare(
        "SELECT ir.id, ir.public_id, ir.full_name, ir.city_other,
                ir.event_interests_json, ir.how_heard, ir.created_at,
                c.name AS city_name, c.region AS city_region
         FROM invite_requests ir
         LEFT JOIN cities c ON c.id = ir.city_id
         WHERE ir.id > ?
         ORDER BY ir.id ASC
         LIMIT 26"
    );
    $stmt->execute([$cursor]);
    $rows = $stmt->fetchAll();

    $hasMore = count($rows) > 25;
    if ($hasMore) {
        $rows = array_slice($rows, 0, 25);
    }

    $interestOptions = coveted_invite_event_interest_options();
    $items = [];
    $nextCursor = $cursor;

    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $nextCursor = max($nextCursor, $id);

        $city = trim((string)($row['city_other'] ?? ''));
        $cityName = trim((string)($row['city_name'] ?? ''));
        if ($cityName !== '') {
            $city = $cityName;
            $region = trim((string)($row['city_region'] ?? ''));
            if ($region !== '') {
                $city .= ', ' . $region;
            }
        }

        $interestLabels = [];
        try {
            $decoded = json_decode((string)($row['event_interests_json'] ?? '[]'), true, 32, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                foreach (coveted_invite_normalize_interests($decoded) as $key) {
                    $interestLabels[] = $interestOptions[$key] ?? $key;
                }
            }
        } catch (Throwable) {
            $interestLabels = [];
        }

        $createdAt = trim((string)($row['created_at'] ?? ''));
        $submittedLabel = $createdAt;
        if ($createdAt !== '') {
            try {
                $submittedLabel = coveted_utc_datetime($createdAt)
                    ->setTimezone(coveted_timezone())
                    ->format('M j, g:i A');
            } catch (Throwable) {
                $submittedLabel = $createdAt;
            }
        }

        $items[] = [
            'id' => $id,
            'public_id' => (string)($row['public_id'] ?? ''),
            'kind' => trim((string)($row['how_heard'] ?? '')) === 'Newsletter signup' ? 'newsletter' : 'invite',
            'name' => trim((string)($row['full_name'] ?? '')),
            'city' => $city,
            'interests' => $interestLabels,
            'submitted_at' => $submittedLabel,
            'href' => '/admin/crm.php?status=new',
        ];
    }

    echo coveted_json([
        'ok' => true,
        'available' => true,
        'cursor' => $nextCursor,
        'items' => $items,
        'has_more' => $hasMore,
    ]);
} catch (Throwable $e) {
    error_log('Admin Agent CRM activity failed: ' . $e->getMessage());
    http_response_code(500);
    echo coveted_json(['ok' => false, 'error' => 'Unable to read CRM activity.']);
}
