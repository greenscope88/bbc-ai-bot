<?php
declare(strict_types=1);

/**
 * Phase 9-B-26B-2: GeminiClient skeleton tests.
 *
 * GeminiContextDocument → GeminiClient → GeminiResponseContract (no API).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'channel_publish_plan' . DIRECTORY_SEPARATOR . 'ChannelPublishPlan.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'channel_publish_plan' . DIRECTORY_SEPARATOR . 'ChannelPublishPlanValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'gemini' . DIRECTORY_SEPARATOR . 'GeminiRenderer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'integration' . DIRECTORY_SEPARATOR . 'GeminiClient.php';

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

function buildContextFromPlan(ChannelPublishPlan $plan, array $renderContext): GeminiContextDocument
{
    return (new GeminiRenderer())->renderDocument($plan, $renderContext);
}

$client = new GeminiClient();

// Case 1: valid context
try {
    $context = buildContextFromPlan(buildGeminiPlan([
        'items' => [
            [
                'title' => '東京五日',
                'summary' => '精選東京行程',
                'primary_url' => 'https://example.test/tokyo',
            ],
        ],
    ]), [
        'customer_query' => '請推薦東京五日團',
        'tenant_name' => 'BBC Travel',
    ]);
    $response = $client->generateResponse($context);

    test_assert($response->getReplyType() === 'normal_reply', 'case1 normal_reply');
    test_assert(mb_strpos($response->getReplyText(), '東京五日') !== false, 'case1 grounded title');
    test_assert(true, 'case1 valid context PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case1 should pass: ' . $e->getMessage());
}

// Case 2: empty search results
try {
    $context = buildContextFromPlan(buildGeminiPlan(['items' => []]), [
        'customer_query' => '請推薦簽證代辦',
        'tenant_name' => 'BBC Travel',
    ]);
    $response = $client->generateResponse($context);

    test_assert($response->getReplyType() === 'human_agent_fallback', 'case2 human_agent_fallback');
    test_assert($response->toArray()['used_fallback'] === true, 'case2 used_fallback');
    test_assert(true, 'case2 empty search results PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case2 should pass: ' . $e->getMessage());
}

// Case 3: human fallback message
try {
    $context = buildContextFromPlan(buildGeminiPlan(['items' => []]), [
        'customer_query' => '請推薦簽證服務',
        'tenant_name' => 'BBC Travel',
    ]);
    $response = $client->generateMockResponse($context);

    test_assert(mb_strpos($response->getReplyText(), '專人客服') !== false, 'case3 fallback message');
    test_assert(true, 'case3 human fallback PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case3 should pass: ' . $e->getMessage());
}

// Case 4: out_of_scope
try {
    $context = buildContextFromPlan(buildGeminiPlan(['items' => []]), [
        'customer_query' => '請推薦股票投資策略',
        'tenant_name' => 'BBC Travel',
    ]);
    $response = $client->generateResponse($context);

    test_assert($response->getReplyType() === 'out_of_scope', 'case4 out_of_scope');
    test_assert(mb_strpos($response->getReplyText(), '服務範圍') !== false, 'case4 polite rejection');
    test_assert(true, 'case4 out_of_scope PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case4 should pass: ' . $e->getMessage());
}

// Case 5: voice profile
try {
    $context = buildContextFromPlan(buildGeminiPlan([
        'items' => [
            [
                'title' => '京都賞櫻',
                'summary' => '春季限定',
                'primary_url' => 'https://example.test/kyoto',
            ],
        ],
        'metadata' => [
            'voice_profile' => [
                'persona' => 'senior_consultant',
            ],
        ],
    ]), [
        'customer_query' => '有沒有賞櫻行程',
        'tenant_name' => 'Travel B',
    ]);
    $response = $client->generateResponse($context);

    test_assert($response->toArray()['voice_profile_used'] === 'senior_consultant', 'case5 voice profile used');
    test_assert(true, 'case5 voice profile PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case5 should pass: ' . $e->getMessage());
}

// Case 6: mock response explicit
try {
    $context = buildContextFromPlan(buildGeminiPlan([
        'items' => [
            [
                'title' => '北海道雪祭',
                'summary' => '冬季行程',
                'primary_url' => 'https://example.test/hokkaido',
            ],
        ],
    ]), [
        'customer_query' => '北海道旅遊團',
        'tenant_name' => 'BBC Travel',
    ]);
    $mock = $client->generateMockResponse($context);
    $viaGenerate = $client->generateResponse($context);

    test_assert($mock->toArray() === $viaGenerate->toArray(), 'case6 mock equals generateResponse skeleton');
    test_assert(true, 'case6 mock response PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case6 should pass: ' . $e->getMessage());
}

// Case 7: round trip
try {
    $context = buildContextFromPlan(buildGeminiPlan([
        'items' => [
            [
                'title' => '大阪三日',
                'summary' => '精選大阪',
                'primary_url' => 'https://example.test/osaka',
            ],
        ],
    ]), [
        'customer_query' => '大阪團體行程',
        'tenant_name' => 'BBC Travel',
    ]);
    $response = $client->generateResponse($context);
    $roundTrip = GeminiResponseContract::fromArray($response->toArray())->toArray();
    $encoded = json_encode($roundTrip, JSON_UNESCAPED_UNICODE);

    test_assert($roundTrip === $response->toArray(), 'case7 round-trip');
    test_assert($encoded !== false, 'case7 json encodable');
    test_assert(stripos((string) $encoded, 'curl') === false, 'case7 no curl');
    test_assert(stripos((string) $encoded, 'generativelanguage.googleapis.com') === false, 'case7 no gemini api url');
    test_assert(true, 'case7 round trip PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case7 should pass: ' . $e->getMessage());
}

// Case 8: prompt payload mock path
try {
    $response = $client->generateResponseFromPrompt(
        [
            'system_prompt' => 'mock system prompt',
            'user_prompt' => 'mock user prompt',
            'render_metadata' => [
                'trace_id' => 'trace-client-8',
                'tenant_sno' => 'cccccccccccccccc',
                'source_count' => 3,
                'result_count' => 30,
                'renderer_version' => 'gemini_prompt_renderer_v1',
            ],
        ],
        []
    );
    $doc = $response->toArray();
    $encoded = json_encode($doc, JSON_UNESCAPED_UNICODE);

    test_assert($response instanceof GeminiResponseContract, 'case8 returns GeminiResponseContract');
    test_assert(($doc['reply_text'] ?? '') !== '', 'case8 reply_text non-empty');
    test_assert(!array_key_exists('apiKey', $doc), 'case8 no apiKey');
    test_assert(!array_key_exists('accessToken', $doc), 'case8 no accessToken');
    test_assert(!array_key_exists('replyToken', $doc), 'case8 no replyToken');
    test_assert(!array_key_exists('secret', $doc), 'case8 no secret');
    test_assert(stripos((string) $encoded, 'generativelanguage.googleapis.com') === false, 'case8 no gemini endpoint');
    test_assert(stripos((string) $encoded, 'curl') === false, 'case8 no curl');
    test_assert(true, 'case8 prompt payload mock PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case8 should pass: ' . $e->getMessage());
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_gemini_client (all passed)\n");
exit(0);
