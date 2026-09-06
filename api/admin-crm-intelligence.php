<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/invite_crm_intelligence.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    coveted_require_system_admin();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        echo coveted_json(['ok' => false, 'error' => 'GET required.']);
        exit;
    }

    $rawIds = trim((string)($_GET['ids'] ?? ''));
    $ids = [];
    if ($rawIds !== '') {
        foreach (explode(',', $rawIds) as $value) {
            $value = trim($value);
            if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
                throw new InvalidArgumentException('Invalid CRM record list.');
            }
            $id = (int)$value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) > 250) {
            throw new InvalidArgumentException('Too many CRM records requested.');
        }
    }

    $pdo = coveted_db();
    $records = coveted_invite_crm_intelligence_for_ids($ids, $pdo);
    $items = [];
    foreach ($records as $id => $intel) {
        $items[] = [
            'id' => (int)$id,
            'score' => (int)$intel['score'],
            'band' => (string)$intel['band'],
            'label' => (string)$intel['label'],
            'next_action' => (string)$intel['next_action'],
            'next_action_key' => (string)$intel['next_action_key'],
            'reasons' => array_values(array_map('strval', (array)$intel['reasons'])),
            'age_days' => (int)$intel['age_days'],
            'follow_up_due' => !empty($intel['follow_up_due']),
            'active' => !empty($intel['active']),
        ];
    }

    echo coveted_json([
        'ok' => true,
        'summary' => coveted_invite_crm_intelligence_summary($pdo),
        'items' => $items,
        'scoring' => [
            'name' => 'Admin action priority',
            'version' => 1,
            'description' => 'Deterministic workflow priority based on CRM status, recency and explicit form completeness. It is not a prediction of personal value or purchase intent.',
        ],
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo coveted_json(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('CRM intelligence endpoint failed: ' . $e->getMessage());
    http_response_code(500);
    echo coveted_json(['ok' => false, 'error' => 'CRM intelligence is temporarily unavailable.']);
}
