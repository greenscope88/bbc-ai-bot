<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_query_intent_detector.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent_router.php';

$detector = new TourQueryIntentDetector();

$cases = [
  '六月底東京三萬以下' => '東京',
  '東京團' => '東京',
  '歐洲團' => '歐洲',
  '北海道' => '北海道',
  '日本親子團' => '日本',
  '高雄出發東京' => '東京',
];

foreach ($cases as $message => $expectedKeyword) {
    $r = $detector->detect($message);
    hybrid_test_assert(($r['is_tour_query'] ?? false) === true, "tour intent: {$message}");
    hybrid_test_assert(($r['intent'] ?? '') === 'tour_search', "intent field: {$message}");
    hybrid_test_assert(($r['keyword'] ?? '') === $expectedKeyword, "keyword: {$message}");
    hybrid_test_assert(is_string($r['intent_source'] ?? null) && ($r['intent_source'] ?? '') !== '', "intent_source: {$message}");
}

$router = IntentRouter::detect('六月底東京三萬以下');
hybrid_test_assert(($router['intent'] ?? '') === 'tour_query', 'IntentRouter maps to tour_query');

hybrid_test_finish('TourQueryIntentDetector hybrid search');
