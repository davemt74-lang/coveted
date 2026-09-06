<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . ltrim($path, '/'));
    if ($content === false) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
    return $content;
};

$contains = static function (string $content, string $needle, string $label): void {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "Admin Agent briefing contract failed: {$label}\n");
        exit(1);
    }
};

$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Admin Agent briefing contract failed: {$label}\n");
        exit(1);
    }
};

$briefing = $read('app/admin_agent_briefing.php');
$agent = $read('admin/agent.php');
$cssEntry = $read('assets/css/coveted.css');
$css = $read('assets/css/admin-agent-briefing-v1.css');

$contains($briefing, 'function coveted_admin_agent_briefing(', 'briefing service is missing');
$contains($briefing, 'coveted_is_system_admin($admin)', 'briefing must require System Admin');
$contains($briefing, "DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)", 'briefing activity window must remain 24 hours');
$contains($briefing, 'SELECT COUNT(*) FROM audit_events ae', '24-hour change count must read the full canonical audit window');
$contains($briefing, 'LIMIT 60', 'recent briefing activity query must stay bounded');
$contains($briefing, "ae.event_type NOT LIKE 'admin.agent.%'", 'Agent self-audit noise must be excluded');
$contains($briefing, "ae.event_type NOT LIKE 'auth.%'", 'authentication noise must be excluded');
$contains($briefing, "=> 'Events'", 'event activity must be categorized as Events');
$contains($briefing, "=> 'Community'", 'group activity must be categorized as Community');
$contains($briefing, "(array)($snapshot['opportunities'] ?? [])", 'briefing must use canonical Agent opportunities');
$contains($briefing, "(array)($snapshot['crm'] ?? [])", 'briefing must use canonical CRM metrics');
$contains($briefing, "(array)($snapshot['operations']['summary'] ?? [])", 'briefing must use canonical Operations summary');
$contains($briefing, 'array_slice($opportunities, 0, 3)', 'briefing next moves must be bounded to three');
$missing($briefing, 'coveted_ai_chat(', 'deterministic briefing must never call an AI provider');
$missing($briefing, 'INSERT INTO ', 'briefing must remain read-only');
$missing($briefing, 'UPDATE ', 'briefing must remain read-only');
$missing($briefing, 'DELETE FROM ', 'briefing must remain read-only');

$contains($agent, "require_once dirname(__DIR__) . '/app/admin_agent_briefing.php';", 'Agent page must load the briefing service locally');
$contains($agent, 'coveted_admin_agent_briefing($admin, $brain, $pdo)', 'Agent page must derive briefing from the current canonical brain snapshot');
$contains($agent, 'DAILY BRIEFING', 'daily briefing UI is missing');
$contains($agent, 'PROACTIVE OPPORTUNITIES', 'briefing must expose next best moves');
$contains($agent, 'LAST 24 HOURS', 'briefing must expose recent meaningful changes');
$contains($agent, 'no AI call', 'briefing must disclose deterministic generation');
$contains($agent, 'Discuss this briefing', 'user-triggered briefing discussion is missing');
$contains($agent, 'data-agent-starter=', 'briefing discussion must use the existing explicit chat-send path');

$contains($cssEntry, 'admin-agent-briefing-v1.css?v=admin-agent-briefing-v1-20260905', 'briefing stylesheet is not loaded');
$contains($css, '.cv-admin-agent-briefing', 'briefing card styling is missing');
$contains($css, '.cv-admin-agent-briefing-stats', 'briefing signal styling is missing');
$contains($css, '@media (max-width: 720px)', 'briefing mobile layout is missing');

fwrite(STDOUT, "Admin Agent briefing contract verified.\n");
