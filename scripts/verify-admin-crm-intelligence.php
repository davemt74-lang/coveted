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
        fwrite(STDERR, "CRM intelligence contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "CRM intelligence contract failed: {$label}\n");
        exit(1);
    }
};

$service = $read('app/invite_crm_intelligence.php');
$endpoint = $read('api/admin-crm-intelligence.php');
$branding = $read('app/site_branding.php');
$js = $read('assets/js/invite-crm-v2.js');
$jsEntry = $read('assets/js/coveted.js');
$css = $read('assets/css/invite-crm-intelligence-v1.css');
$cssEntry = $read('assets/css/coveted.css');

// Transparent deterministic scoring contract.
$contains($service, 'function coveted_invite_crm_intelligence_record(', 'record scorer is missing');
$contains($service, "'qualified' => 62", 'qualified workflow baseline is missing');
$contains($service, "'contacted' => 48", 'contacted workflow baseline is missing');
$contains($service, '$activityAgeDays >= 3', 'three-day contacted follow-up rule is missing');
$contains($service, '$score = min($score, 48);', 'newsletter nurture cap is missing');
$contains($service, '$score = max(0, min(100, $score));', 'score must remain bounded from 0 to 100');
$contains($service, "'High priority'", 'high-priority band is missing');
$contains($service, "'Review for conversion'", 'qualified conversion recommendation is missing');
$contains($service, 'Sensitive fields', 'sensitive-field exclusion rationale is missing');
$contains($service, 'free-text sentiment are excluded from scoring', 'free-text sentiment exclusion is missing');
$contains($service, 'never returns names, emails, raw phone numbers, notes, messages, gender', 'aggregate PII boundary is missing');
$contains($service, "WHERE ir.status IN ('new','contacted','qualified')", 'aggregate intelligence must stay on active CRM workflow states');
$contains($service, 'CASE WHEN NULLIF(TRIM(ir.phone)', 'aggregate scorer may use phone completeness only');
$contains($service, 'coveted_invite_crm_intelligence_for_ids', 'per-visible-record scorer is missing');

// Read-only System Admin API with no AI provider or PII payload.
$contains($endpoint, 'coveted_require_system_admin()', 'intelligence endpoint must require System Admin');
$contains($endpoint, "(\$_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'", 'intelligence endpoint must be GET-only');
$contains($endpoint, "'Cache-Control: no-store", 'intelligence endpoint must be no-store');
$contains($endpoint, 'coveted_invite_crm_intelligence_for_ids', 'endpoint must use canonical intelligence service');
$contains($endpoint, 'coveted_invite_crm_intelligence_summary', 'endpoint must expose aggregate intelligence');
$contains($endpoint, "'score' => (int)\$intel['score']", 'endpoint must expose deterministic score');
$contains($endpoint, "'reasons' =>", 'endpoint must expose transparent reasons');
$missing($endpoint, 'coveted_ai_chat(', 'CRM intelligence must not call an AI provider');
$missing($endpoint, 'INSERT INTO ', 'CRM intelligence endpoint must not insert records');
$missing($endpoint, 'UPDATE ', 'CRM intelligence endpoint must not update records');
$missing($endpoint, 'DELETE FROM ', 'CRM intelligence endpoint must not delete records');
foreach (['full_name', "'email'", "'phone'", "'message'", "'gender'", 'admin_note', 'social_links_json'] as $piiField) {
    $missing($endpoint, $piiField, 'PII field leaked from intelligence endpoint: ' . $piiField);
}

// Agent receives aggregate intelligence only and turns it into concrete work.
$contains($branding, "require_once __DIR__ . '/invite_crm_intelligence.php';", 'Agent enrichment must load CRM intelligence lazily');
$contains($branding, 'coveted_invite_crm_intelligence_summary()', 'Agent must consume aggregate intelligence');
$contains($branding, "\$crm['intelligence'] = \$intelligence;", 'aggregate intelligence must ride inside existing CRM context');
$contains($branding, "'crm-follow-up-due'", 'follow-up opportunity is missing');
$contains($branding, "'crm-conversion-ready'", 'conversion-ready opportunity is missing');
$contains($branding, "'crm-high-priority'", 'high-priority CRM opportunity is missing');
$contains($branding, "'crm-aging-new'", 'aging-new CRM opportunity is missing');
$contains($branding, "!== 'crm-pipeline'", 'generic CRM opportunity must be replaced by intelligence-specific signals');

// CRM UI stays external/CSP-safe and preserves canonical mutation forms.
$contains($js, "const endpoint = '/api/admin-crm-intelligence.php';", 'CRM intelligence endpoint wiring is missing');
$contains($js, 'credentials: \'same-origin\'', 'CRM intelligence request must remain same-origin');
$contains($js, "cache: 'no-store'", 'CRM intelligence browser request must bypass caches');
$contains($js, 'data-crm-priority-filter', 'priority filter control is missing');
$contains($js, 'data-crm-priority-sort', 'priority sort control is missing');
$contains($js, 'High priority', 'high-priority UI signal is missing');
$contains($js, 'Follow-up due', 'follow-up UI signal is missing');
$contains($js, 'Conversion ready', 'conversion-ready UI signal is missing');
$contains($js, 'textContent = intel.next_action', 'record intelligence rendering must use DOM text');
$contains($js, 'record.hidden = !show', 'client-side priority filtering is missing');
$contains($jsEntry, 'invite-crm-intelligence-v1-20260905', 'CRM intelligence JS cache key is stale');
$contains($cssEntry, 'invite-crm-intelligence-v1.css?v=invite-crm-intelligence-v1-20260905', 'CRM intelligence stylesheet is not loaded');
$contains($css, '.cv-crm-intelligence-summary', 'CRM intelligence summary styles are missing');
$contains($css, '.cv-crm-intelligence-card', 'CRM intelligence record-card styles are missing');
$contains($css, '@media (max-width: 720px)', 'CRM intelligence mobile layout is missing');

fwrite(STDOUT, "Admin CRM intelligence contract verified.\n");
