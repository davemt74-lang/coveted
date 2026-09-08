<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/stripe_billing.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo coveted_json(['ok'=>false,'error'=>'method_not_allowed']);
    exit;
}

$pdo = coveted_db();
if (!coveted_stripe_schema_available($pdo) || !coveted_stripe_webhook_ready()) {
    http_response_code(503);
    echo coveted_json(['ok'=>false,'error'=>'stripe_webhook_not_configured']);
    exit;
}

$payload = (string)file_get_contents('php://input');
$signature = trim((string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));

if ($payload === '' || !coveted_stripe_verify_webhook_signature($payload,$signature)) {
    http_response_code(400);
    echo coveted_json(['ok'=>false,'error'=>'invalid_signature']);
    exit;
}

try {
    $event = json_decode($payload,true,512,JSON_THROW_ON_ERROR);
    if (!is_array($event)) {
        throw new InvalidArgumentException('Invalid Stripe event payload.');
    }
    $eventRef = trim((string)($event['id'] ?? ''));
    if (!str_starts_with($eventRef,'evt_')) {
        throw new InvalidArgumentException('Invalid Stripe event reference.');
    }
    $result = coveted_stripe_process_webhook($event,$payload,$pdo);
    http_response_code(200);
    echo coveted_json(['ok'=>true,'duplicate'=>(bool)($result['duplicate'] ?? false)]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo coveted_json(['ok'=>false,'error'=>'invalid_event']);
} catch (RuntimeException $e) {
    error_log('Stripe webhook processing failed: ' . $e->getMessage());
    http_response_code(409);
    echo coveted_json(['ok'=>false,'error'=>'retry']);
} catch (Throwable $e) {
    error_log('Stripe webhook processing failed: ' . $e->getMessage());
    http_response_code(500);
    echo coveted_json(['ok'=>false,'error'=>'internal_error']);
}
