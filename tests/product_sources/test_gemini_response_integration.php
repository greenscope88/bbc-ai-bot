<?php
declare(strict_types=1);

/**
 * Phase 9-B-24: GeminiContextDocument → GeminiResponseContract integration test.
 *
 * Simulates future Gemini reply output without API or HTTP.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'channel_publish_plan' . DIRECTORY_SEPARATOR . 'ChannelPublishPlan.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'channel_publish_plan' . DIRECTORY_SEPARATOR . 'ChannelPublishPlanValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'gemini' . DIRECTORY_SEPARATOR . 'GeminiRenderer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'gemini' . DIRECTORY_SEPARATOR . 'GeminiResponseContract.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'gemini' . DIRECTORY_SEPARATOR . 'GeminiResponseContractValidator.php';

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
        'items' => [],
        'fallback' => null,
        'metadata' => [],
    ], $overrides);

    return (new ChannelPublishPlanValidator())->validate($document);
}

/**
 * Simulates future Gemini Client output from context (no API).
 *
 * @return array<string, mixed>
 */
function simulateGeminiResponse(GeminiContextDocument $context): array
{
    $ctx = $context->toArray();
    $voicePersona = isset($ctx['voice_profile']['persona'])
        ? trim((string) $ctx['voice_profile']['persona'])
        : 'young_female';
    $scope = detectServiceScope((string) $ctx['customer_query'], $ctx['tenant_service_scope']);

    if ($scope === null) {
        return [
            'reply_text' => '抱歉，這個問題超出了我們旅行社的服務範圍，建議您洽詢相關專業單位，謝謝您的理解。',
            'reply_type' => 'out_of_scope',
            'used_fallback' => false,
            'used_service_scope' => null,
            'voice_profile_used' => $voicePersona,
        ];
    }

    if ($ctx['search_results'] === []) {
        return [
            'reply_text' => (string) ($ctx['fallback_policy']['fallback_message'] ?? ''),
            'reply_type' => 'human_agent_fallback',
            'used_fallback' => true,
            'used_service_scope' => $scope,
            'voice_profile_used' => $voicePersona,
        ];
    }

    $lines = ['為您找到以下行程參考 😊 ✈️'];
    foreach ($ctx['search_results'] as $item) {
        $title = isset($item['title']) ? trim((string) $item['title']) : '';
        $summary = isset($item['summary']) && is_string($item['summary']) ? trim($item['summary']) : '';
        $url = isset($item['primary_url']) ? trim((string) $item['primary_url']) : '';
        $line = '📌 ' . $title;
        if ($summary !== '') {
            $line .= ' — ' . $summary;
        }
        if ($url !== '') {
            $line .= "\n" . $url;
        }
        $lines[] = $line;
    }

    return [
        'reply_text' => implode("\n\n", $lines),
        'reply_type' => 'normal_reply',
        'used_fallback' => false,
        'used_service_scope' => $scope,
        'voice_profile_used' => $voicePersona,
    ];
}

/**
 * @param list<string> $tenantServiceScope
 */
function detectServiceScope(string $customerQuery, array $tenantServiceScope): ?string
{
    $query = mb_strtolower($customerQuery);
    $rules = [
        'tour' => ['團', '行程', '旅遊', 'tour'],
        'visa' => ['簽證', 'visa'],
        'passport' => ['護照', 'passport'],
        'ticket' => ['機票', 'ticket'],
        'hotel' => ['飯店', 'hotel', '住宿'],
    ];

    foreach ($rules as $scope => $keywords) {
        if (!in_array($scope, $tenantServiceScope, true)) {
            continue;
        }
        foreach ($keywords as $keyword) {
            if (mb_strpos($query, mb_strtolower($keyword)) !== false) {
                return $scope;
            }
        }
    }

    return null;
}

/**
 * @return GeminiContextDocument
 */
function buildContextFromPlan(ChannelPublishPlan $plan, array $renderContext): GeminiContextDocument
{
    return (new GeminiRenderer())->renderDocument($plan, $renderContext);
}

$renderer = new GeminiRenderer();
$responseValidator = new GeminiResponseContractValidator();

// Case 1: normal tour reply
try {
    $plan = buildGeminiPlan([
        'items' => [
            [
                'title' => '東京五日',
                'summary' => '精選東京行程',
                'primary_url' => 'https://example.test/tokyo',
            ],
        ],
    ]);
    $context = buildContextFromPlan($plan, [
        'customer_query' => '請推薦東京五日團',
        'tenant_name' => 'BBC Travel',
    ]);
    $response = simulateGeminiResponse($context);
    $contract = $responseValidator->validateWithContext($response, $context->toArray());

    test_assert($contract->getReplyType() === 'normal_reply', 'case1 normal_reply');
    test_assert(mb_strpos($contract->getReplyText(), '東京五日') !== false, 'case1 grounded title');
    test_assert($contract->toArray()['used_service_scope'] === 'tour', 'case1 tour scope');
    test_assert(true, 'case1 normal tour reply PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case1 should pass: ' . $e->getMessage());
}

// Case 2: multiple products reply
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
    $context = buildContextFromPlan($plan, [
        'customer_query' => '有沒有日本團體行程',
        'tenant_name' => 'BBC Travel',
    ]);
    $response = simulateGeminiResponse($context);
    $contract = $responseValidator->validateWithContext($response, $context->toArray());

    test_assert(mb_strpos($contract->getReplyText(), '東京五日') !== false, 'case2 first product');
    test_assert(mb_strpos($contract->getReplyText(), '大阪三日') !== false, 'case2 second product');
    test_assert(true, 'case2 multiple products PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case2 should pass: ' . $e->getMessage());
}

// Case 3: human_agent fallback
try {
    $plan = buildGeminiPlan(['items' => []]);
    $context = buildContextFromPlan($plan, [
        'customer_query' => '請推薦簽證代辦',
        'tenant_name' => 'BBC Travel',
    ]);
    $response = simulateGeminiResponse($context);
    $contract = $responseValidator->validateWithContext($response, $context->toArray());

    test_assert($contract->getReplyType() === 'human_agent_fallback', 'case3 fallback type');
    test_assert($contract->toArray()['used_fallback'] === true, 'case3 used_fallback');
    test_assert(mb_strpos($contract->getReplyText(), '專人客服') !== false, 'case3 fallback message');
    test_assert(true, 'case3 human_agent fallback PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case3 should pass: ' . $e->getMessage());
}

// Case 4: out_of_scope
try {
    $plan = buildGeminiPlan(['items' => []]);
    $context = buildContextFromPlan($plan, [
        'customer_query' => '請推薦股票投資策略',
        'tenant_name' => 'BBC Travel',
    ]);
    $response = simulateGeminiResponse($context);
    $contract = $responseValidator->validateWithContext($response, $context->toArray());

    test_assert($contract->getReplyType() === 'out_of_scope', 'case4 out_of_scope');
    test_assert($contract->toArray()['used_fallback'] === false, 'case4 no fallback');
    test_assert(mb_strpos($contract->getReplyText(), '服務範圍') !== false, 'case4 polite rejection');
    test_assert(true, 'case4 out_of_scope PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case4 should pass: ' . $e->getMessage());
}

// Case 5: emoji policy
try {
    $plan = buildGeminiPlan([
        'items' => [
            [
                'title' => '京都賞櫻',
                'summary' => '春季限定',
                'primary_url' => 'https://example.test/kyoto',
            ],
        ],
    ]);
    $context = buildContextFromPlan($plan, [
        'customer_query' => '有沒有賞櫻行程',
        'tenant_name' => 'BBC Travel',
    ]);
    $response = simulateGeminiResponse($context);
    $violations = $responseValidator->collectContextViolations($response, $context->toArray());

    test_assert($violations === [], 'case5 emoji policy passes');
    test_assert(mb_strpos($response['reply_text'], '😊') !== false, 'case5 contains allowed emoji');
    test_assert(true, 'case5 emoji policy PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case5 should pass: ' . $e->getMessage());
}

// Case 6: validator schema + anti-hallucination
$schemaViolations = $responseValidator->collectViolations([
    'reply_text' => '',
    'reply_type' => 'invalid_type',
    'used_fallback' => false,
    'used_service_scope' => null,
    'voice_profile_used' => '',
]);
test_assert(in_array('reply_text is required', $schemaViolations, true), 'case6 reply_text required');
test_assert(in_array('invalid reply_type', $schemaViolations, true), 'case6 invalid reply_type');

$plan = buildGeminiPlan([
    'items' => [
        [
            'title' => '東京五日',
            'summary' => '精選東京行程',
            'primary_url' => 'https://example.test/tokyo',
            'metadata' => ['price_from' => 28888],
        ],
    ],
]);
$context = buildContextFromPlan($plan, [
    'customer_query' => '東京團多少錢',
    'tenant_name' => 'BBC Travel',
]);
$hallucinatedResponse = [
    'reply_text' => '東京五日團現在只要 99999 元，保證成團！',
    'reply_type' => 'normal_reply',
    'used_fallback' => false,
    'used_service_scope' => 'tour',
    'voice_profile_used' => 'young_female',
];
$hallucinationViolations = $responseValidator->collectContextViolations(
    $hallucinatedResponse,
    $context->toArray()
);
test_assert(
    in_array('reply_text contains ungrounded price', $hallucinationViolations, true),
    'case6 blocks ungrounded price'
);
test_assert(
    in_array('reply_text contains ungrounded marker: 保證成團', $hallucinationViolations, true),
    'case6 blocks ungrounded marker'
);
test_assert(true, 'case6 validator PASS');

// Case 7: fromArray / toArray round trip
try {
    $plan = buildGeminiPlan([
        'items' => [
            [
                'title' => '北海道雪祭',
                'summary' => '冬季行程',
                'primary_url' => 'https://example.test/hokkaido',
            ],
        ],
    ]);
    $context = buildContextFromPlan($plan, [
        'customer_query' => '北海道旅遊團',
        'tenant_name' => 'BBC Travel',
    ]);
    $response = simulateGeminiResponse($context);
    $contract = $responseValidator->validateWithContext($response, $context->toArray());
    $roundTrip = GeminiResponseContract::fromArray($contract->toArray())->toArray();
    test_assert($roundTrip === $contract->toArray(), 'case7 round-trip');
    test_assert(!array_key_exists('prompt', $roundTrip), 'case7 no prompt');
    test_assert(!array_key_exists('endpoint', $roundTrip), 'case7 no endpoint');
    test_assert(true, 'case7 round trip PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case7 should pass: ' . $e->getMessage());
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_gemini_response_integration (all passed)\n");
exit(0);
