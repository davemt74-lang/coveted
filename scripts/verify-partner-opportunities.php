<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . ltrim($path, '/'));
    if ($content === false) {
        fwrite(STDERR, "Missing Partner Opportunities file: {$path}\n");
        exit(1);
    }
    return $content;
};
$contains = static function (string $content, string $needle, string $label): void {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "Partner Opportunities contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Partner Opportunities contract failed: {$label}\n");
        exit(1);
    }
};

$service = $read('app/partner_opportunities.php');
$api = $read('api/partner-opportunities.php');
$ui = $read('assets/js/partner-opportunities-v1.js');
$loader = $read('assets/js/coveted.js');
$branding = $read('app/site_branding.php');
$workflow = $read('.github/workflows/php-lint.yml');

$contains($service, 'function coveted_partner_opportunities_for_business', 'business-scoped canonical recommendation service must exist');
$contains($service, 'coveted_business_actor_can_view', 'business-scoped recommendations must enforce resource visibility');
$contains($service, 'coveted_venue_relationships_for_business', 'recommendations must derive from canonical venue relationships');
$contains($service, 'daily_event_opportunities', 'recommendations must include Daily Event state');
$contains($service, 'business_claim_codes', 'recommendations must evaluate partner check-in readiness');
$contains($service, "trigger_key IN ('return_visit','guest_return')", 'recommendations must understand existing return value programs');
$contains($service, "'restore_checkin'", 'upcoming Daily Event check-in gap must be surfaced');
$contains($service, "'schedule_next_daily'", 'successful relationship must support next-Daily-Event recommendation');
$contains($service, "'review_partner_status'", 'relationship maturity review must be surfaced');
$contains($service, "'raise_future_threshold'", 'repeated threshold outperformance must be surfaced without altering history');
$contains($service, "'create_return_value'", 'verified visits without return value must be surfaced');
$contains($service, "'reengage_dormant'", 'dormant established partners must be surfaced');
$contains($service, 'Recommendations are read-only', 'service must state the non-mutating action policy');
$contains($service, 'function coveted_partner_opportunities_agent_context', 'same recommendation service must feed Admin Agent context');

foreach (['INSERT INTO ', 'UPDATE venue_relationships', 'DELETE FROM ', 'coveted_daily_event_create(', 'coveted_venue_relationship_update('] as $needle) {
    $missing($service, $needle, 'recommendation service must remain read-only');
}

$contains($api, 'coveted_require_user()', 'API must require an authenticated user');
$contains($api, 'coveted_business_resolve_context', 'API must resolve the scoped business through canonical authority');
$contains($api, 'coveted_partner_opportunities_for_business', 'API must use canonical recommendation service');
$contains($api, "hash_equals((string)(\$item['group_ref']", 'relationship filters must compare canonical refs safely');

$contains($ui, "window.location.pathname !== '/venue-relationships.php'", 'UI must only activate on Venue Relationships');
$contains($ui, "'/api/partner-opportunities.php'", 'UI must load the scoped recommendation API');
$contains($ui, 'textContent', 'stored labels and recommendation text must render as text, not trusted HTML');
$contains($ui, 'data-partner-opportunities', 'UI must prevent duplicate opportunity surfaces');
$contains($ui, 'Recommendations never change relationship or event state automatically.', 'UI must preserve the authority boundary');
$missing($ui, 'innerHTML', 'opportunity UI must not inject server strings through innerHTML');

$contains($loader, '/assets/js/partner-opportunities-v1.js', 'application loader must activate partner opportunity UI');
$contains($branding, "require_once __DIR__ . '/partner_opportunities.php';", 'Admin Agent enrichment must load the shared partner opportunity service');
$contains($branding, 'coveted_partner_opportunities_agent_context(', 'Admin Agent must consume canonical partner opportunity context');
$contains($branding, "'category' => 'Partners'", 'partner recommendations must join the Admin Agent opportunity list');
$contains($branding, "'partner_opportunities'", 'partner opportunity snapshot must be preserved in Agent operations context');

$contains($workflow, 'php scripts/verify-partner-opportunities.php', 'Partner Opportunities contract must run in CI');
$contains($workflow, 'node --check assets/js/partner-opportunities-v1.js', 'Partner Opportunities JavaScript must be syntax-checked in CI');

fwrite(STDOUT, "Partner Opportunities contract verified.\n");
