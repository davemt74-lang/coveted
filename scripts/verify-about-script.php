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
        fwrite(STDERR, "About Script contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "About Script contract failed: {$label}\n");
        exit(1);
    }
};

$page = $read('about-script.php');
$css = $read('assets/css/about-script-v1.css');
$imports = $read('assets/css/coveted.css');
$footer = $read('assets/js/legal-footer.js');
$concierge = $read('app/member_concierge.php');
$businessHost = $read('app/business_host_workspace.php');

$contains($page, '<title>About Script ·', 'public page title is required');
$contains($page, 'meta name="description"', 'public page must include a search description');
$contains($page, 'class="cv-about-script"', 'public About Script UI is required');
$contains($page, 'The intelligence layer behind real-world connection.', 'hero must explain the script as a relationship intelligence layer');
$contains($page, 'A social concierge, not another social feed.', 'member social concierge positioning is required');
$contains($page, 'New connections with context', 'member new-connection benefit is required');
$contains($page, 'New social opportunities', 'member opportunity discovery benefit is required');
$contains($page, 'Better event preparation', 'member event-preparation benefit is required');
$contains($page, 'Relationship continuity', 'member relationship continuity benefit is required');
$contains($page, 'Benefits that follow participation', 'member benefits continuity is required');
$contains($page, 'The network can create more than a night out.', 'connection-to-opportunity explanation is required');
$contains($page, 'Turn hosting into a continuing customer relationship.', 'business/partner value section is required');
$contains($page, 'A role-aware host workspace.', 'partner role-based operations explanation is required');
$contains($page, 'Two AI views. One permission system.', 'member/admin AI architecture section is required');
$contains($page, 'Social Concierge', 'member AI must be explicitly named');
$contains($page, 'Operations Agent', 'Admin AI must be explicitly named');
$contains($page, 'The application controls state.', 'canonical application authority must be visible');
$contains($page, 'it does not expose another member’s private timeline', 'member AI privacy boundary must be public-facing');
$contains($page, 'recommendations are not permission', 'Admin AI authority boundary must be public-facing');
$contains($page, 'Built to understand participation without ranking people.', 'outcome-over-ranking principle is required');
$contains($page, 'without public referral leaderboards or member-value scores', 'network growth must not be marketed as a ranking system');
$contains($page, 'assets/js/legal-footer.js', 'public page must load the shared public footer');
$missing($page, 'coveted_require_login(', 'About Script page must not require login');
$missing($page, 'coveted_require_system_admin(', 'About Script page must not require Admin access');
$missing($page, 'INSERT INTO ', 'public explainer must not mutate application data');
$missing($page, 'UPDATE ', 'public explainer must not mutate application data');
$missing($page, 'DELETE FROM ', 'public explainer must not mutate application data');

$contains($imports, 'about-script-v1.css?v=about-script-v1-20260907', 'About Script styles must load through canonical stylesheet');
$contains($css, '.cv-about-agent-demo', 'Social Concierge visual UI is required');
$contains($css, '.cv-about-benefit-grid', 'member benefit card layout is required');
$contains($css, '.cv-about-opportunity-map', 'opportunity network visual is required');
$contains($css, '.cv-about-partner-grid', 'partner benefit layout is required');
$contains($css, '.cv-about-ai-grid', 'member/Admin AI architecture visual is required');
$contains($css, '@media (max-width: 820px)', 'About Script page must be tablet/mobile responsive');
$contains($css, '@media (max-width: 620px)', 'About Script page must support narrow phones');
$contains($css, '@media (prefers-reduced-motion: reduce)', 'About Script page must respect reduced motion');

$contains($footer, "['/about-script.php', 'About Script']", 'About Script must be available from the shared public footer');
$contains($footer, 'landingNav.querySelector', 'landing footer must avoid duplicate links');
$contains($footer, 'aria-label="Footer"', 'non-landing footer navigation must use a generic Footer label');

$contains($concierge, "'What should I attend next?'", 'public social concierge positioning must be backed by current concierge behavior');
$contains($concierge, "'What reconnect opportunities are available to me?'", 'public reconnect positioning must be backed by current concierge behavior');
$contains($concierge, 'coveted_member_concierge_event_recommendations', 'event opportunity positioning must be backed by current concierge recommendations');
$contains($concierge, 'coveted_member_concierge_next_event', 'event preparation positioning must be backed by current concierge next-event context');
$contains($concierge, 'Person-level results include mutual matches only', 'public privacy claim must match canonical mutual reconnect boundary');
$contains($concierge, 'The model itself cannot mutate Coveted.', 'public AI authority claim must match canonical concierge authority');

$contains($businessHost, 'coveted_business_host_events', 'partner event workspace positioning must be backed by canonical host workspace');
$contains($businessHost, 'coveted_business_host_guests', 'partner RSVP/guest context must be backed by canonical host workspace');
$contains($businessHost, 'coveted_business_host_campaigns', 'partner campaign positioning must be backed by canonical host workspace');
$contains($businessHost, 'coveted_business_host_artists', 'partner artist integration positioning must be backed by canonical host workspace');
$contains($businessHost, 'Coveted Admin must assign you check-in access for this event.', 'public partner authority claim must preserve Admin-controlled host access');

fwrite(STDOUT, "Public About Script + social concierge contract verified.\n");
