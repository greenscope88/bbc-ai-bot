<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchCondition.php';

$ref = SearchCondition::empty('高雄出發東京');
hybrid_test_assert($ref->getIntent() === SearchCondition::INTENT_TOUR_SEARCH, 'empty intent');
hybrid_test_assert($ref->getFreeText() === '高雄出發東京', 'free_text preserved');

$merged = $ref
    ->with(['keyword' => '東京', 'area' => '日本', 'confidence' => 0.5])
    ->flag('area_parsed')
    ->bumpConfidence(0.3);

hybrid_test_assert($merged->getKeyword() === '東京', 'with keyword');
hybrid_test_assert($merged->getArea() === '日本', 'with area');
hybrid_test_assert($merged->getConfidence() === 0.8, 'confidence bump capped');
hybrid_test_assert(($merged->getParserFlags()['area_parsed'] ?? false) === true, 'flag set');

$arr = $merged->toArray();
hybrid_test_assert(is_array($arr['travel_style']) && $arr['keyword'] === '東京', 'toArray structure');

hybrid_test_finish('SearchCondition DTO');
