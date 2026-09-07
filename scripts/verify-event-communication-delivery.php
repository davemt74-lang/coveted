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
        fwrite(STDERR, "Event Communication Delivery contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Event Communication Delivery contract failed: {$label}\n");
        exit(1);
    }
};

$service = $read('app/event_communication_delivery.php');
$page = $read('admin/event-communication-delivery.php');
$operations = $read('app/operations.php');
$nav = $read('assets/js/event-invitation-waves-nav-v1.js');
$notifications = $read('app/notifications.php');

$contains($service, 'function coveted_event_communication_delivery_snapshot', 'Event-scoped delivery snapshot is required');
$contains($service, 'function coveted_event_communication_delivery_agent_context', 'aggregate Agent/Operations delivery context is required');
$contains($service, 'coveted_event_communications_require_admin($admin, $pdo)', 'delivery intelligence must reuse System Admin/Sample Mode authority');
$contains($service, "JSON_UNQUOTE(JSON_EXTRACT(n.payload_json,'$.event_id'))=?", 'delivery rows must be scoped by canonical Event notification payload');
$contains($service, "n.notification_type IN ('event.rsvp_reminder','event.confirmation','event.location','event.mystery_reveal')", 'only Event communications may enter delivery health');
$contains($service, "LEFT JOIN notification_deliveries nd ON nd.notification_id=n.id AND nd.transport='web_push'", 'delivery health must read canonical Web Push delivery rows');
$contains($service, "nd.status='pending'", 'pending transport state is required');
$contains($service, "nd.status='sending'", 'sending transport state is required');
$contains($service, "nd.status='sent'", 'sent transport state is required');
$contains($service, "nd.status='failed'", 'retryable failure transport state is required');
$contains($service, "nd.status='permanent_failure'", 'permanent failure transport state is required');
$contains($service, 'DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)', 'stale sending detection must match canonical queue timing');
$contains($service, 'DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)', 'stuck pending/failed detection must match canonical queue timing');
$contains($service, "'in_app_only'", 'members without a push delivery row must remain valid in-app notifications');
$contains($service, "'time_sensitive'", 'time-sensitive critical delivery state is required');
$contains($service, "'limited_push_coverage'", 'limited push coverage must be represented without treating in-app notifications as failed');
$contains($service, "elseif (\$permanent > 0)", 'mixed-device permanent failures must stay visible until another device succeeds');
$contains($service, "'event-communications-'", 'delivery recommendation must reuse the canonical Event Communications Agent task key');
$contains($service, "'/admin/event-communication-delivery.php?event='", 'delivery recommendations must route to the exact Admin delivery workspace');
$contains($service, "'#delivery-health'", 'delivery recommendation must target the delivery-health panel');
$contains($service, 'Broad Agent context receives aggregate delivery counts only.', 'Agent privacy boundary must be explicit');
$contains($service, 'Delivery Health is read-only.', 'read-only authority boundary must be explicit');
$contains($service, "coveted_system_sample_mode(\$admin,\$pdo)", 'delivery Agent context must isolate Sample Mode');

$missing($service, 'coveted_push_dispatch(', 'delivery intelligence must not invoke the global dispatcher');
$missing($service, 'INSERT INTO notifications', 'delivery intelligence must not create notifications');
$missing($service, 'UPDATE notifications', 'delivery intelligence must not mutate notifications');
$missing($service, 'UPDATE notification_deliveries', 'delivery intelligence must not mutate delivery state');
$missing($service, 'UPDATE events', 'delivery intelligence must not mutate Events');
$missing($service, 'UPDATE event_invitations', 'delivery intelligence must not mutate invitations');
$missing($service, 'UPDATE event_rsvps', 'delivery intelligence must not mutate RSVPs');
$missing($service, 'nd.response_body', 'provider response bodies must not be read');
$missing($service, 'endpoint_url', 'push endpoint URLs must not be read');
$missing($service, 'p256dh_key', 'push public keys must not be read');
$missing($service, 'auth_secret', 'push authentication material must not be read');
$missing($service, 'CREATE TABLE', 'no runtime schema creation allowed');
$missing($service, 'ALTER TABLE', 'no runtime schema alteration allowed');

$contains($page, 'coveted_require_system_admin();', 'Delivery Health workspace must be System Admin-only');
$contains($page, 'coveted_system_sample_mode($admin, $pdo)', 'Delivery Health workspace must isolate Sample Mode');
$contains($page, 'coveted_event_communication_delivery_snapshot(', 'workspace must use canonical delivery intelligence service');
$contains($page, 'EXACT SYSTEM ADMIN VIEW', 'exact recipient delivery state must stay in System Admin workspace');
$contains($page, 'No retry button by design.', 'workspace must make dispatcher authority explicit');
$contains($page, 'Endpoint URLs, push keys, authentication material or provider response bodies', 'workspace must explain sensitive transport privacy boundary');
$missing($page, 'coveted_push_dispatch(', 'Delivery Health workspace must not dispatch transport');
$missing($page, 'method="post"', 'Delivery Health workspace must remain read-only');

$contains($operations, "require_once __DIR__ . '/event_communication_delivery.php';", 'Operations must load Event delivery health');
$contains($operations, 'coveted_event_communication_delivery_agent_context($actor, 12, $pdo)', 'Operations must read aggregate Event delivery health');
$contains($operations, '$communicationDeliveryRecommendations', 'delivery recommendations must join the existing promoted Event stream');
$contains($operations, "'event_communication_delivery_attention'", 'Operations summary must expose Event delivery attention');
$contains($operations, "'event_communication_delivery' => \$eventCommunicationDelivery", 'Operations response must expose aggregate Event delivery health');
$contains($operations, '$seenRecommendationKeys', 'promoted Event recommendations must dedupe canonical source keys');
$contains($operations, "str_contains((string)(\$a['href']??''),'#delivery-health')", 'delivery health must win same-key/same-priority recommendation ties');
$contains($operations, 'rather than adding the same underlying failures to attention_count twice.', 'Event delivery drilldown must not double-count global transport failures');
$missing($operations, "+ (int)\$summary['event_communication_delivery_attention'];", 'overall Operations attention must not count the same transport failures twice');

$contains($nav, '/admin/event-communication-delivery.php?event=', 'shared Event navigation must expose Delivery Health');
$contains($nav, 'data-event-delivery-health-tab', 'Delivery Health must be a first-class Event tab');
$contains($nav, "location.pathname === '/admin/event-communications.php'", 'Communications-specific navigation must preserve duplicate-button protection');
$contains($nav, "location.pathname === '/admin/event-communication-delivery.php'", 'Delivery Health must not duplicate its own action controls');
$contains($nav, "shell.dataset.systemSample === '1'", 'Delivery Health navigation must remain isolated in Sample Mode');

$contains($notifications, 'function coveted_push_dispatch(', 'canonical notification worker must remain the dispatcher owner');

fwrite(STDOUT, "Event Communication Delivery Health contract verified.\n");
