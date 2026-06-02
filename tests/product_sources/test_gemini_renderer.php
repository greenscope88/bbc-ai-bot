<?php
declare(strict_types=1);

/**
 * Phase 9-B-23: GeminiRenderer context builder tests.
 *
 * ChannelPublishPlan → GeminiRenderer → GeminiContextDocument
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'channel_publish_plan' . DIRECTORY_SEPARATOR . 'ChannelPublishPlan.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'channel_publish_plan' . DIRECTORY_SEPARATOR . 'ChannelPublishPlanValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'gemini' . DIRECTORY_SEPARATOR . 'GeminiRenderer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'gemini' . DIRECTORY_SEPARATOR . 'GeminiContextDocumentValidator.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function buildGeminiPlan(array $overrides = []): ChannelPublishPlan
{
    $document = array_merge([
        'channel' => 'gemini',
        'strategy_name' => 'gemini_context_default_v1',
        'payload_schema_version' => 1,
        'items' => [
            [
                'title' => '東京五日',
                'summary' => '精選東京行程',
                'primary_url' => 'https://example.test/tokyo',
                'metadata' => ['price_from' => 28888],
            ],
        ],
        'fallback' => null,
        'metadata' => [],
    ], $overrides);

    return (new ChannelPublishPlanValidator())->validate($document);
}

function baseRenderContext(array $overrides = []): array
{
    return array_merge([
        'customer_query' => '請推薦東京五日團',
        'tenant_name' => 'BBC Travel',
    ], $overrides);
}

$renderer = new GeminiRenderer();
$documentValidator = new GeminiContextDocumentValidator();

// Case 1: valid plan
try {
    $plan = buildGeminiPlan();
    $document = $renderer->renderDocument($plan, baseRenderContext());

    test_assert($document->getSearchResults()[0]['title'] === '東京五日', 'case1 search result title');
    test_assert($document->toArray()['customer_query'] === '請推薦東京五日團', 'case1 customer_query');
    test_assert($document->toArray()['tenant_name'] === 'BBC Travel', 'case1 tenant_name');
    test_assert($document->toArray()['voice_profile']['persona'] === 'young_female', 'case1 default persona');
    test_assert($document->toArray()['guard_policy']['grounding_required'] === true, 'case1 grounding_required');
    test_assert(true, 'case1 valid plan PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case1 should pass: ' . $e->getMessage());
}

// Case 2: multiple items
try {
    $plan = buildGeminiPlan([
        'items' => [
            [
                'title' => '東京五日',
                'summary' => 'summary 1',
                'primary_url' => 'https://example.test/tokyo',
            ],
            [
                'title' => '大阪三日',
                'summary' => 'summary 2',
                'primary_url' => 'https://example.test/osaka',
            ],
        ],
    ]);
    $document = $renderer->renderDocument($plan, baseRenderContext());

    test_assert(count($document->getSearchResults()) === 2, 'case2 two search results');
    test_assert($document->getSearchResults()[1]['title'] === '大阪三日', 'case2 second item');
    test_assert(true, 'case2 multiple items PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case2 should pass: ' . $e->getMessage());
}

// Case 3: empty items
try {
    $plan = buildGeminiPlan(['items' => []]);
    $document = $renderer->renderDocument($plan, baseRenderContext());

    test_assert($document->getSearchResults() === [], 'case3 empty search_results');
    test_assert($document->toArray()['fallback_policy']['mode'] === 'human_agent', 'case3 fallback policy present');
    test_assert(true, 'case3 empty items PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case3 should pass: ' . $e->getMessage());
}

// Case 4: tenant metadata override via plan metadata
try {
    $plan = buildGeminiPlan([
        'metadata' => [
            'tenant_name' => 'Travel B 旅行社',
            'voice_profile' => [
                'persona' => 'senior_consultant',
                'tone' => ['calm', 'professional'],
            ],
            'tenant_service_scope' => ['tour', 'visa'],
        ],
    ]);
    $document = $renderer->renderDocument($plan, [
        'customer_query' => '請推薦簽證服務',
    ]);

    test_assert($document->toArray()['tenant_name'] === 'Travel B 旅行社', 'case4 tenant_name from plan metadata');
    test_assert($document->toArray()['voice_profile']['persona'] === 'senior_consultant', 'case4 persona override');
    test_assert($document->toArray()['tenant_service_scope'] === ['tour', 'visa'], 'case4 service scope override');
    test_assert(true, 'case4 tenant metadata PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case4 should pass: ' . $e->getMessage());
}

// Case 5: validator
$violations = $documentValidator->collectViolations([
    'customer_query' => '',
    'search_results' => [],
    'tenant_name' => '',
    'voice_profile' => [],
    'tenant_service_scope' => [],
    'guard_policy' => [],
    'fallback_policy' => [],
]);
test_assert(in_array('customer_query is required', $violations, true), 'case5 customer_query required');
test_assert(in_array('tenant_name is required', $violations, true), 'case5 tenant_name required');
test_assert(in_array('voice_profile is required', $violations, true), 'case5 voice_profile required');

$promptViolations = $documentValidator->collectViolations([
    'prompt' => 'system prompt here',
    'customer_query' => 'test',
    'search_results' => [],
    'tenant_name' => 'BBC Travel',
    'voice_profile' => [
        'persona' => 'young_female',
        'tone' => ['warm'],
        'constraints' => ['不誇大'],
        'emoji_policy' => ['allowed_emojis' => ['😊']],
    ],
    'tenant_service_scope' => ['tour'],
    'guard_policy' => [
        'grounding_required' => true,
        'allow_hallucination' => false,
        'strict_data_mode' => true,
    ],
    'fallback_policy' => [
        'mode' => 'human_agent',
        'fallback_message' => '請稍候',
    ],
]);
test_assert(in_array('forbidden top-level key: prompt', $promptViolations, true), 'case5 prompt forbidden');
test_assert(true, 'case5 validator PASS');

// Case 6: fromArray / toArray round trip
try {
    $plan = buildGeminiPlan();
    $document = $renderer->renderDocument($plan, baseRenderContext());
    $roundTrip = GeminiContextDocument::fromArray($document->toArray())->toArray();
    test_assert($roundTrip === $document->toArray(), 'case6 round-trip');
    test_assert(true, 'case6 fromArray/toArray PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case6 should pass: ' . $e->getMessage());
}

// Case 7: no HTTP / Gemini API / prompt / replyToken
try {
    $plan = buildGeminiPlan();
    $document = $renderer->render($plan, baseRenderContext());
    $encoded = json_encode($document, JSON_UNESCAPED_UNICODE);

    test_assert($encoded !== false, 'case7 json encodable');
    test_assert(!array_key_exists('prompt', $document), 'case7 no prompt');
    test_assert(!array_key_exists('system_prompt', $document), 'case7 no system_prompt');
    test_assert(!array_key_exists('replyToken', $document), 'case7 no replyToken');
    test_assert(!array_key_exists('endpoint', $document), 'case7 no endpoint');
    test_assert(!array_key_exists('headers', $document), 'case7 no headers');
    test_assert(stripos((string) $encoded, 'curl') === false, 'case7 no curl in output');
    test_assert(stripos((string) $encoded, 'generativelanguage.googleapis.com') === false, 'case7 no gemini api url');
    test_assert($documentValidator->collectViolations($document) === [], 'case7 passes validator');
    test_assert(true, 'case7 forbidden transport/prompt keys PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case7 should pass: ' . $e->getMessage());
}

// Case 8: renderPrompts payload for dry-run
try {
    $payload = $renderer->renderPrompts(
        [
            [
                'source_platform' => 'bbcshops',
                'tenant_instance' => 'travel_b',
                'product_category' => 'group_tour',
                'result_count' => 10,
            ],
            [
                'source_platform' => 'agenttour',
                'tenant_instance' => 'travel_b_agenttour',
                'product_category' => 'group_tour',
                'result_count' => 8,
            ],
            [
                'source_platform' => 'grp',
                'tenant_instance' => 'travel_b_grp',
                'product_category' => 'group_tour',
                'result_count' => 12,
            ],
        ],
        [
            'source_count' => 3,
            'result_count' => 30,
        ],
        [
            'trace_id' => 'trace-render-8',
            'tenant_sno' => 'cccccccccccccccc',
        ]
    );

    test_assert(isset($payload['system_prompt']) && trim((string) $payload['system_prompt']) !== '', 'case8 system_prompt exists');
    test_assert(isset($payload['user_prompt']) && trim((string) $payload['user_prompt']) !== '', 'case8 user_prompt exists');
    test_assert(($payload['render_metadata']['trace_id'] ?? '') === 'trace-render-8', 'case8 trace_id');
    test_assert(($payload['render_metadata']['source_count'] ?? 0) === 3, 'case8 source_count');
    test_assert(($payload['render_metadata']['result_count'] ?? 0) === 30, 'case8 result_count');
    test_assert(mb_strpos((string) $payload['system_prompt'], '不得捏造價格、庫存、成團狀態') !== false, 'case8 anti-hallucination rule');
    test_assert(mb_strpos((string) $payload['user_prompt'], '未提供的商品明細') !== false, 'case8 no extra details rule');
    test_assert(mb_strpos((string) $payload['user_prompt'], 'price_from') === false, 'case8 no hidden product details');
    test_assert(true, 'case8 renderPrompts PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case8 should pass: ' . $e->getMessage());
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_gemini_renderer (all passed)\n");
exit(0);
