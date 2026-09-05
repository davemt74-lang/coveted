<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    $content = @file_get_contents($full);
    if ($content === false) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
    return $content;
};

$requireContains = static function (string $content, string $needle, string $label): void {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "Newsletter CRM contract failed: {$label}\n");
        exit(1);
    }
};

$requireMissing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Newsletter CRM contract failed: {$label}\n");
        exit(1);
    }
};

$index = $read('index.php');
$newsletter = $read('app/newsletter.php');
$crm = $read('admin/crm.php');
$cssEntry = $read('assets/css/coveted.css');
$css = $read('assets/css/landing-newsletter-v1.css');

$requireContains($index, "require_once __DIR__ . '/app/newsletter.php';", 'landing must load newsletter service');
$requireContains($index, 'name="action" value="newsletter_signup"', 'landing form action is missing');
$requireContains($index, 'name="csrf_token"', 'landing newsletter form must use CSRF protection');
$requireContains($index, 'name="company"', 'landing newsletter honeypot is missing');
$requireContains($index, 'class="cv-landing-newsletter"', 'newsletter landing section is missing');
$requireContains($index, 'Join our newsletter.', 'newsletter heading is missing');
$requireContains($index, 'Privacy Policy', 'newsletter privacy link is missing');
$requireContains($index, 'Terms of Service', 'newsletter terms link is missing');
$requireMissing($index, '<section class="cv-landing-manifesto">', 'old public manifesto section must be removed');

$requireContains($newsletter, "require_once __DIR__ . '/invite_crm.php';", 'newsletter must use canonical CRM layer');
$requireContains($newsletter, "const COVETED_NEWSLETTER_SOURCE = 'Newsletter signup';", 'newsletter source identity is missing');
$requireContains($newsletter, 'coveted_invite_crm_ensure_schema($pdo);', 'newsletter must use canonical CRM schema');
$requireContains($newsletter, 'INSERT INTO invite_requests', 'newsletter must store signups in Invite CRM');
$requireContains($newsletter, 'coveted_json([])', 'newsletter leads must not invent event interests');
$requireContains($newsletter, 'source_ip_hash', 'newsletter abuse protection is missing');
$requireContains($newsletter, 'DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)', 'newsletter rate limiting is missing');
$requireContains($newsletter, 'newsletter_signup.created', 'newsletter creation audit is missing');
$requireContains($newsletter, 'newsletter_signup.refreshed', 'newsletter repeat opt-in audit is missing');

$requireContains($crm, "$isNewsletter = trim((string)(\$request['how_heard'] ?? '')) === 'Newsletter signup';", 'CRM must identify newsletter signups');
$requireContains($crm, '>Newsletter</span>', 'CRM newsletter badge is missing');
$requireContains($crm, "\$isNewsletter ? 'SIGNED UP' : 'REQUESTED'", 'CRM signup date label is missing');
$requireContains($crm, 'Email newsletter', 'CRM newsletter intent label is missing');
$requireContains($crm, "\$sourceKeys || !empty(\$request['how_heard'])", 'CRM source metadata must show newsletter source');

$requireContains($cssEntry, 'landing-newsletter-v1.css', 'newsletter stylesheet is not loaded');
$requireContains($css, '.cv-landing-newsletter', 'newsletter section styles are missing');
$requireContains($css, '@media (max-width: 560px)', 'newsletter mobile layout contract is missing');

fwrite(STDOUT, "Newsletter CRM contract verified.\n");
