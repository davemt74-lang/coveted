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
        fwrite(STDERR, "Member mobile navigation contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Member mobile navigation contract failed: {$label}\n");
        exit(1);
    }
};

$js = $read('assets/js/member-mobile-navigation-v1.js');
$css = $read('assets/css/member-mobile-navigation-v1.css');
$loader = $read('assets/js/coveted.js');
$imports = $read('assets/css/coveted.css');
$memberJs = $read('assets/js/member-v2.js');
$memberCss = $read('assets/css/member-v2.css');
$publicJs = $read('assets/js/public-mobile-header-v2.js');

$contains($loader, "member-mobile-navigation-v1.js?v=member-mobile-navigation-v1-20260907", 'member navigation behavior must be loaded after the canonical member shell');
$contains($imports, 'member-mobile-navigation-v1.css?v=member-mobile-navigation-v1-20260907', 'member navigation styles must be loaded after member-v2 styles');

$contains($js, "const appTopbar = document.querySelector('.cv-app-topbar')", 'behavior must be restricted to signed-in app shell pages');
$contains($js, "body:not(.cv-admin-body) .cv-header", 'Admin shell must not be repurposed as the member drawer');
$contains($js, "className = 'cv-member-menu-toggle'", 'mobile hamburger button is required');
$contains($js, "setAttribute('aria-controls', drawer.id)", 'hamburger must identify the controlled drawer');
$contains($js, "setAttribute('aria-expanded', 'false')", 'hamburger must expose collapsed state');
$contains($js, "setAttribute('aria-expanded', shouldOpen ? 'true' : 'false')", 'hamburger expanded state must stay synchronized');
$contains($js, "className = 'cv-member-menu-close'", 'drawer close button is required');
$contains($js, "className = 'cv-member-nav-backdrop'", 'modal backdrop is required');
$contains($js, "appTopbar.querySelectorAll('details[open]')", 'opening navigation must close any open account/topbar menu');
$contains($js, "event.key === 'Escape'", 'Escape must close the drawer');
$contains($js, "event.key !== 'Tab'", 'drawer must implement keyboard focus containment');
$contains($js, "nav.addEventListener('click'", 'choosing a navigation item must close the drawer');
$contains($js, "window.addEventListener('resize', syncViewport", 'desktop/mobile viewport transitions must reset the drawer safely');
$contains($js, "mobileQuery.addEventListener('change', syncViewport)", 'media-query transitions must reset drawer state');
$contains($js, "window.requestAnimationFrame(() => toggle.focus())", 'closing the drawer must restore focus to the hamburger');
$contains($js, "nav.querySelector('[aria-current=\"page\"]')", 'opening the drawer should prioritize the current page navigation item');

$contains($css, '@media (max-width: 820px)', 'drawer must use the existing member mobile breakpoint');
$contains($css, 'transform: translate3d(-104%, 0, 0)', 'closed drawer must remain off canvas');
$contains($css, 'body.cv-member-v2-active.cv-member-nav-open .cv-header', 'open state must slide the member drawer on canvas');
$contains($css, 'width: min(86vw, 320px)', 'drawer must remain usable on narrow screens without covering all context');
$contains($css, 'height: 100dvh', 'drawer must follow the mobile dynamic viewport');
$contains($css, 'body.cv-member-v2-active.cv-member-nav-open', 'open state must be scoped to the signed-in member shell');
$contains($css, 'overflow: hidden', 'open drawer must lock background scrolling');
$contains($css, '.cv-member-nav-backdrop', 'backdrop styles are required');
$contains($css, 'grid-template-columns: 1fr', 'mobile primary navigation must be vertical instead of horizontally scrolling');
$contains($css, 'min-height: 48px', 'mobile nav links must have touch-friendly targets');
$contains($css, 'env(safe-area-inset-top)', 'drawer must respect mobile safe areas');
$contains($css, '@media (prefers-reduced-motion: reduce)', 'drawer transitions must respect reduced motion');

$contains($memberCss, 'width: 228px', 'desktop member rail width must remain intact');
$contains($memberCss, 'left: 228px', 'desktop member topbar/sidebar relationship must remain intact');
$contains($memberJs, "['Groups', '/groups.php']", 'mobile drawer must reuse the canonical current member navigation set');
$contains($memberJs, "['Reconnect', '/reconnect.php']", 'Reconnect must remain in canonical member navigation');

$contains($publicJs, "if (!document.body.classList.contains('cv-public-home')) return;", 'signed-out public mobile navigation must remain separately scoped');
$missing($js, 'cv-public-mobile-menu', 'member navigation must not mutate the public mobile menu implementation');
$missing($js, 'innerHTML = `<script', 'member navigation must not inject inline executable script');

fwrite(STDOUT, "Member mobile slideout navigation contract verified.\n");
