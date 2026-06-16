<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'TourPromptContextResult.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ClarificationPolicy.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$intent = new BatsSearchIntent('北海道7月', BatsSearchIntent::INTENT_TOUR_SEARCH, '北海道');
$condition = SearchCondition::empty('北海道7月')->with(['destination' => '北海道', 'keyword' => '北海道']);

// Case 1: empty()
$empty = TourPromptContextResult::empty();
test_assert($empty->getIntent()->getFreeText() === '', 'case1: empty free_text');
test_assert($empty->getSearchCondition() === null, 'case1: empty search_condition');
test_assert($empty->getSearchResults() === [], 'case1: empty search_results');
test_assert($empty->getLegacyContext() === '', 'case1: empty legacy_context');
test_assert($empty->isClarificationRequired() === false, 'case1: clarification false');
test_assert($empty->getClarificationReason() === null, 'case1: no clarification_reason');

// Case 2: clarificationRequired()
$clarifyIntent = BatsSearchIntent::empty('北海道')->with([
    'destination' => '北海道',
    'clarification_required' => true,
    'clarification_reason' => ClarificationPolicy::REASON_DATE_REQUIRED,
]);
$clarify = TourPromptContextResult::clarificationRequired($clarifyIntent, '【日期澄清】');
test_assert($clarify->isClarificationRequired() === true, 'case2: clarification_required');
test_assert($clarify->getClarificationReason() === ClarificationPolicy::REASON_DATE_REQUIRED, 'case2: reason');
test_assert($clarify->getSearchCondition() === null, 'case2: null search_condition');
test_assert($clarify->getSearchResults() === [], 'case2: empty search_results');
test_assert($clarify->getLegacyContext() === '【日期澄清】', 'case2: legacy_context');

// Case 3: searchable()
$searchable = TourPromptContextResult::searchable(
    $intent,
    $condition,
    [['title' => '北海道夏季團']],
    'legacy context text'
);
test_assert($searchable->isClarificationRequired() === false, 'case3: searchable');
test_assert($searchable->getSearchCondition() !== null, 'case3: search_condition');
test_assert($searchable->getSearchResults()[0]['title'] === '北海道夏季團', 'case3: search_results');
test_assert($searchable->getLegacyContext() === 'legacy context text', 'case3: legacy_context');

// Case 4: toArray contract keys
$arr = $searchable->toArray();
foreach ([
    'intent',
    'search_condition',
    'search_results',
    'legacy_context',
    'clarification_required',
    'clarification_reason',
] as $key) {
    test_assert(array_key_exists($key, $arr), 'case4: key ' . $key);
}
test_assert(is_array($arr['intent']), 'case4: intent array');
test_assert(is_array($arr['search_condition']), 'case4: search_condition array');
test_assert(is_array($arr['search_results']), 'case4: search_results array');
test_assert(is_string($arr['legacy_context']), 'case4: legacy_context string');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_tour_prompt_context_result (all passed)\n");
exit(0);
