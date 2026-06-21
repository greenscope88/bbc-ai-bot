<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'KnowledgeIntentDetector.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$detector = new KnowledgeIntentDetector();

/**
 * @param array{intent_type: string} $result
 */
function assert_shape(array $result): void
{
    test_assert(isset($result['intent_type']) && is_string($result['intent_type']), 'shape: intent_type');
}

/** @var list<array{0: string, 1: string, 2?: string}> */
$cases = [
    // product_search
    ['近期東京', KnowledgeIntentDetector::INTENT_PRODUCT_SEARCH, 'product_search'],
    ['北海道7月', KnowledgeIntentDetector::INTENT_PRODUCT_SEARCH, 'product_search'],
    ['暑假親子日本', KnowledgeIntentDetector::INTENT_PRODUCT_SEARCH, 'product_search'],
    ['高雄出發北海道7月', KnowledgeIntentDetector::INTENT_PRODUCT_SEARCH, 'product_search-legacy'],
    ['東京五萬內7月', KnowledgeIntentDetector::INTENT_PRODUCT_SEARCH, 'product_search-legacy'],

    // knowledge_query
    ['請問客服電話', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'knowledge_query'],
    ['請問公司地址', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'knowledge_query'],
    ['請問護照費用多少', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'knowledge_query'],
    ['請問有哪些服務', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'knowledge_query-legacy'],
    ['請問可以刷卡嗎', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'knowledge_query'],
    ['請問台胞證多少', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'knowledge_query-legacy'],
    ['請問取消規定', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'knowledge_query-legacy'],

    // external product links -> knowledge_query (Phase 9-C-2B-6A)
    ['請問有哪些旅遊商品入口？', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'external_product_links'],
    ['請問有哪些商品連結？', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'external_product_links'],
    ['有哪些商品入口？', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'external_product_links'],
    ['有哪些商品連結？', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'external_product_links'],
    ['東京行程連結', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'external_product_links'],
    ['東京旅遊入口', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'external_product_links'],
    ['東京旅遊商品入口', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'external_product_links'],
    ['請問東京旅遊連結', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'external_product_links'],

    // regression: company / qa / price / service / fallback
    ['請問營業時間', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'knowledge_regression'],
    ['國際線多久前報到', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'knowledge_regression'],
    ['護照費用多少', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'knowledge_regression'],
    ['有哪些服務', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'knowledge_regression'],
    ['有代訂房嗎', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'knowledge_regression'],
    ['可以帶寵物上飛機嗎', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'knowledge_regression'],

    // product_search regression
    ['東京暑假', KnowledgeIntentDetector::INTENT_PRODUCT_SEARCH, 'product_search_regression'],
    ['大阪自由行', KnowledgeIntentDetector::INTENT_PRODUCT_SEARCH, 'product_search_regression'],
    ['日本賞楓', KnowledgeIntentDetector::INTENT_PRODUCT_SEARCH, 'product_search_regression'],

    // ambiguous
    ['我要去日本', KnowledgeIntentDetector::INTENT_AMBIGUOUS, 'ambiguous'],
    ['想出去玩', KnowledgeIntentDetector::INTENT_AMBIGUOUS, 'ambiguous'],
    ['有推薦嗎', KnowledgeIntentDetector::INTENT_AMBIGUOUS, 'ambiguous'],
    ['日本怎麼安排', KnowledgeIntentDetector::INTENT_AMBIGUOUS, 'ambiguous'],
    ['幫我看看', KnowledgeIntentDetector::INTENT_AMBIGUOUS, 'ambiguous-legacy'],
    ['我想旅遊', KnowledgeIntentDetector::INTENT_AMBIGUOUS, 'ambiguous-legacy'],

    // knowledge_query (non-travel topics route to Future Knowledge Runtime)
    ['幫我買股票', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'knowledge_query-non-travel'],
    ['幫我寫程式', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'knowledge_query-non-travel'],
    ['今天台積電股價', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'knowledge_query-non-travel'],
    ['幫我訂外送', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'knowledge_query-non-travel'],
    ['幫我看病', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'knowledge_query-non-travel'],
    ['買房貸款怎麼辦', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'knowledge_query-non-travel'],
];

foreach ($cases as $index => $case) {
    [$message, $expectedIntent, $label] = $case;
    $result = $detector->detect($message);
    assert_shape($result);
    $n = $index + 1;
    test_assert(
        ($result['intent_type'] ?? '') === $expectedIntent,
        "{$label} case {$n}: {$message} => {$expectedIntent}"
    );
}

if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_knowledge_intent_detector (all passed)\n");
exit(0);
