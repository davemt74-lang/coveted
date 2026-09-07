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
        fwrite(STDERR, "Event Learning / Predictive Planning contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Event Learning / Predictive Planning contract failed: {$label}\n");
        exit(1);
    }
};

$learning = $read('app/event_learning.php');
$opportunities = $read('app/event_opportunities.php');
$learningPage = $read('admin/event-learning.php');
$opportunityPage = $read('admin/event-opportunities.php');
$migration = $read('database/migrations/20260906_event_proposals_playbooks.sql');
$cssIndex = $read('assets/css/coveted.css');
$css = $read('assets/css/event-learning-v1.css');
$nav = $read('assets/js/event-results-nav-v1.js');

foreach ([
    'function coveted_event_learning_dataset',
    'function coveted_event_learning_playbooks',
    'function coveted_event_learning_venues',
    'function coveted_event_learning_groups',
    'function coveted_event_learning_hosts',
    'function coveted_event_learning_benefits',
    'function coveted_event_learning_predictive_plan',
    'function coveted_event_learning_enrich_opportunity',
    'function coveted_event_learning_agent_context',
] as $needle) {
    $contains($learning, $needle, 'derived learning service required: ' . $needle);
}

$contains($learning, "event_proposals", 'learning must use canonical Proposal history');
$contains($learning, "converted_event_id", 'learning must attribute converted Proposals to canonical Events');
$contains($learning, "event_playbooks", 'learning must measure canonical Playbook history');
$contains($learning, "repeat_attendees", 'learning must measure repeat group attendance');
$contains($learning, "reward_issuances", 'learning must measure canonical reward issuance');
$contains($learning, "reward_claims", 'learning must measure canonical claims');
$contains($learning, "event_production_items", 'learning must measure canonical Production execution');
$contains($learning, "fatigue_risk", 'group intelligence must include event-fatigue risk');
$contains($learning, "events >= 5", 'five-plus events must qualify as high confidence');
$contains($learning, "events >= 3", 'three-plus events must qualify as medium confidence');
$contains($learning, "events >= 2", 'two-plus events must qualify as emerging confidence');
$contains($learning, "coveted_system_sample_mode", 'learning must isolate Full System Sample Mode');
$contains($learning, "Host metrics are internal operational context, not a public leaderboard", 'host learning privacy boundary required');
$contains($learning, "Event Learning is read-only predictive evidence", 'Agent authority boundary required');
$contains($learning, "playbook_events']>=2", 'Playbook defaults may only override when repeat Playbook evidence exists');
$missing($learning, 'CREATE TABLE', 'learning must not create runtime schema');
$missing($learning, 'ALTER TABLE', 'learning must not alter runtime schema');
$missing($learning, 'INSERT INTO', 'learning service must remain read-only');
$missing($learning, 'UPDATE ', 'learning service must remain read-only');
$missing($learning, 'DELETE FROM', 'learning service must remain read-only');

$contains($migration, 'playbook_id', 'existing Proposal migration must persist Playbook linkage');
$contains($migration, 'converted_event_id', 'existing Proposal migration must persist converted Event linkage');

$contains($opportunities, "require_once __DIR__ . '/event_learning.php';", 'Opportunity Engine must load Event Learning');
$contains($opportunities, 'coveted_event_learning_enrich_opportunity($admin,$item,$pdo)', 'Opportunity recipes must be refined by predictive learning');
$contains($opportunities, "'learning_confidence'", 'Agent-visible opportunity signals must include learning confidence');
$contains($opportunities, "'predictive_plan'", 'Agent-visible opportunity recipe must include predictive plan');
$contains($opportunities, "'status' => 'draft'", 'predictive planning must still create draft recipes only');
$missing($opportunities, 'CREATE TABLE', 'Opportunity learning must not create runtime schema');
$missing($opportunities, 'ALTER TABLE', 'Opportunity learning must not alter runtime schema');

$contains($opportunityPage, 'Event Learning', 'Opportunity workspace must link to Event Learning');
$contains($opportunityPage, "predictive_plan", 'Opportunity workspace must display predictive evidence');
$contains($opportunityPage, 'Learned plans', 'Opportunity workspace must show learned-plan coverage');
$contains($opportunityPage, 'recommendedPlaybook', 'Opportunity workspace must preselect an evidence-backed Playbook');
$contains($opportunityPage, 'Create Proposal', 'predictive planning must still enter the Proposal workflow');
$contains($opportunityPage, 'coveted_event_proposal_create_from_opportunity', 'predictive planning must use canonical Proposal creation');

$contains($learningPage, 'coveted_require_system_admin()', 'Event Learning workspace must require System Admin');
$contains($learningPage, 'coveted_system_sample_mode($admin, $pdo)', 'Event Learning page must route Sample Mode away from live history');
$contains($learningPage, 'PLAYBOOK PERFORMANCE', 'Playbook performance surface required');
$contains($learningPage, 'VENUE INTELLIGENCE', 'Venue intelligence surface required');
$contains($learningPage, 'GROUP EVENT INTELLIGENCE', 'Group event intelligence surface required');
$contains($learningPage, 'HOST OPERATIONS', 'Host operations intelligence surface required');
$contains($learningPage, 'BENEFIT INTELLIGENCE', 'Benefit intelligence surface required');
$contains($learningPage, 'AGENT LEARNING QUEUE', 'Agent learning queue surface required');
$contains($learningPage, 'Not a public leaderboard', 'Host intelligence must not be a public leaderboard');
$contains($learningPage, 'does not claim per-host check-in accuracy', 'UI must not invent unsupported host check-in attribution');
$contains($learningPage, 'Proposal approval, Event creation/configuration and publishing remain System Admin decisions', 'UI must preserve event authority');

$contains($cssIndex, 'event-learning-v1.css', 'canonical CSS entrypoint must load Event Learning styles');
$contains($css, '.cv-learning-flow', 'Event Learning flow styles required');
$contains($css, '.cv-learning-table', 'Event Learning table styles required');
$contains($nav, 'Event Learning', 'Admin navigation must expose Event Learning');
$contains($nav, 'eventLearningNav', 'Event Learning navigation marker required');
$contains($nav, 'data-system-sample="1"', 'Event Learning navigation must respect Sample Mode');

fwrite(STDOUT, "Event Learning + Predictive Planning contract verified.\n");
