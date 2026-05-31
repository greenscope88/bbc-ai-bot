<?php
declare(strict_types=1);

/**
 * Phase 9-B-21: PublisherStrategy → ChannelPublishPlan integration test.
 *
 * PublisherContract[] → PublisherStrategyResolver → LinePublisherStrategy → ChannelPublishPlan
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'publisher_strategy' . DIRECTORY_SEPARATOR . 'PublisherStrategyResolver.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'channel_publish_plan' . DIRECTORY_SEPARATOR . 'ChannelPublishPlan.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'channel_publish_plan' . DIRECTORY_SEPARATOR . 'ChannelPublishPlanValidator.php';

$failures = 0;

const TRAVEL_B_TENANT_SNO = '5f99b8d665e8444d';

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/**
 * @param list<array<string, mixed>> $publisherContracts
 */
function runIntegrationPipeline(
    PublisherStrategyResolver $resolver,
    array $publisherContracts,
    ?string $tenantSno = null
): ChannelPublishPlan {
    $strategy = $resolver->resolve('line', $tenantSno);
    $strategyResult = $resolver->getStrategy('line')->applyStrategy($publisherContracts, $strategy);

    $validator = new ChannelPublishPlanValidator();

    return $validator->validate([
        'channel' => $strategyResult['channel'],
        'strategy_name' => $strategyResult['strategy_name'],
        'payload_schema_version' => ChannelPublishPlan::PAYLOAD_SCHEMA_VERSION,
        'items' => $strategyResult['items'],
        'fallback' => $strategyResult['fallback'] ?? null,
        'metadata' => [],
    ]);
}

/**
 * @param mixed $value
 * @param list<string> $path
 * @return list<string>
 */
function collectForbiddenContentViolations($value, array $path = []): array
{
    $forbiddenKeys = ['hero', 'body', 'footer', 'template', 'markdown', 'prompt'];
    $violations = [];

    if (!is_array($value)) {
        return $violations;
    }

    foreach ($value as $key => $nested) {
        $currentPath = array_merge($path, [(string) $key]);
        $pathLabel = implode('.', $currentPath);

        if (is_string($key)) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $forbiddenKeys, true)) {
                $violations[] = 'forbidden key: ' . $pathLabel;
            }
            if ($lowerKey === 'type' && is_string($nested) && strtolower($nested) === 'bubble') {
                $violations[] = 'forbidden flex type at ' . $pathLabel;
            }
        }

        if (is_array($nested)) {
            $violations = array_merge($violations, collectForbiddenContentViolations($nested, $currentPath));
        }
    }

    return $violations;
}

$resolver = new PublisherStrategyResolver();
$planValidator = new ChannelPublishPlanValidator();

// Case 1: basic integration — PublisherContract[] → resolve('line') → ChannelPublishPlan
try {
    $publisherContracts = [
        [
            'title' => '東京五日',
            'summary' => '精選東京行程，含迪士尼與淺草寺',
            'primary_url' => 'https://example.test/tokyo-5d',
        ],
    ];

    $plan = runIntegrationPipeline($resolver, $publisherContracts);
    $document = $plan->toArray();

    test_assert($plan->getChannel() === 'line', 'case1 channel=line');
    test_assert($document['strategy_name'] === 'line_oa_default_v1', 'case1 strategy_name');
    test_assert(count($plan->getItems()) === 1, 'case1 one item');
    test_assert($document['items'][0]['title'] === '東京五日', 'case1 title preserved');
    test_assert($document['items'][0]['primary_url'] === 'https://example.test/tokyo-5d', 'case1 primary_url preserved');
    test_assert($document['payload_schema_version'] === 1, 'case1 payload_schema_version');
    test_assert(true, 'case1 basic integration PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case1 should pass: ' . $e->getMessage());
}

// Case 2: summary truncate (travel_b tenant override)
try {
    $longSummary = str_repeat('精', 150);
    $plan = runIntegrationPipeline($resolver, [
        [
            'title' => '大阪三日',
            'summary' => $longSummary,
            'primary_url' => 'https://example.test/osaka-3d',
        ],
    ], TRAVEL_B_TENANT_SNO);

    $item = $plan->toArray()['items'][0];
    test_assert(mb_strlen((string) $item['summary']) <= 100, 'case2 summary truncated to max 100');
    test_assert(mb_substr((string) $item['summary'], -1) === '…', 'case2 summary truncate suffix applied');
    test_assert(true, 'case2 summary truncate PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case2 should pass: ' . $e->getMessage());
}

// Case 3: title truncate (travel_b tenant override)
try {
    $longTitle = str_repeat('東', 50);
    $plan = runIntegrationPipeline($resolver, [
        [
            'title' => $longTitle,
            'summary' => '短摘要',
            'primary_url' => 'https://example.test/long-title',
        ],
    ], TRAVEL_B_TENANT_SNO);

    $item = $plan->toArray()['items'][0];
    test_assert(mb_strlen((string) $item['title']) <= 36, 'case3 title truncated to max 36');
    test_assert(true, 'case3 title truncate PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case3 should pass: ' . $e->getMessage());
}

// Case 4: default line max_items=10 keeps 10 items
try {
    $publisherContracts = [];
    for ($i = 0; $i < 10; ++$i) {
        $publisherContracts[] = [
            'title' => '商品 ' . $i,
            'summary' => 'summary ' . $i,
            'primary_url' => 'https://example.test/item/' . $i,
        ];
    }

    $plan = runIntegrationPipeline($resolver, $publisherContracts);
    test_assert(count($plan->getItems()) === 10, 'case4 default max_items keeps 10 items');
    test_assert($plan->toArray()['fallback'] === null, 'case4 no overflow fallback at max_items');
    test_assert(true, 'case4 default max_items PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case4 should pass: ' . $e->getMessage());
}

// Case 5: travel_b tenant override — 10 items limited to 5
try {
    $publisherContracts = [];
    for ($i = 0; $i < 10; ++$i) {
        $publisherContracts[] = [
            'title' => 'travel_b item ' . $i,
            'summary' => 'summary ' . $i,
            'primary_url' => 'https://example.test/travel-b/' . $i,
        ];
    }

    $plan = runIntegrationPipeline($resolver, $publisherContracts, TRAVEL_B_TENANT_SNO);
    $document = $plan->toArray();

    test_assert(count($plan->getItems()) === 5, 'case5 travel_b max_items=5 keeps 5 items');
    test_assert($document['items'][0]['title'] === 'travel_b item 0', 'case5 first item preserved');
    test_assert($document['items'][4]['title'] === 'travel_b item 4', 'case5 fifth item preserved');
    test_assert(isset($document['fallback']) && is_array($document['fallback']), 'case5 overflow fallback present');
    test_assert(
        ($document['fallback']['type'] ?? '') === 'truncate_with_notice',
        'case5 fallback type truncate_with_notice'
    );
    test_assert(true, 'case5 travel_b max_items PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case5 should pass: ' . $e->getMessage());
}

// Case 6: tenant override policy values applied via resolver
try {
    $defaultStrategy = $resolver->resolve('line');
    $travelBStrategy = $resolver->resolve('line', TRAVEL_B_TENANT_SNO);

    test_assert($defaultStrategy->getMaxItems() === 10, 'case6 default max_items=10');
    test_assert($travelBStrategy->getMaxItems() === 5, 'case6 travel_b max_items=5');
    test_assert(
        (int) ($defaultStrategy->toArray()['text_format_policy']['max_title_length'] ?? 0) === 40,
        'case6 default title length 40'
    );
    test_assert(
        (int) ($travelBStrategy->toArray()['text_format_policy']['max_title_length'] ?? 0) === 36,
        'case6 travel_b title length 36'
    );
    test_assert(true, 'case6 tenant override PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case6 should pass: ' . $e->getMessage());
}

// Case 7: output must not contain Flex JSON / prompt / markdown fields
try {
    $publisherContracts = [];
    for ($i = 0; $i < 6; ++$i) {
        $publisherContracts[] = [
            'title' => '輸出檢查 ' . $i,
            'summary' => 'summary ' . $i,
            'primary_url' => 'https://example.test/output/' . $i,
        ];
    }

    $plan = runIntegrationPipeline($resolver, $publisherContracts, TRAVEL_B_TENANT_SNO);
    $document = $plan->toArray();
    $encoded = json_encode($document, JSON_UNESCAPED_UNICODE);

    test_assert($encoded !== false, 'case7 plan json encodable');
    test_assert(
        stripos((string) $encoded, 'bubble') === false,
        'case7 no flex bubble in output'
    );
    test_assert(
        stripos((string) $encoded, 'prompt') === false,
        'case7 no prompt in output'
    );
    test_assert(
        stripos((string) $encoded, 'markdown') === false,
        'case7 no markdown in output'
    );
    test_assert(collectForbiddenContentViolations($document) === [], 'case7 no forbidden keys in plan tree');
    test_assert($planValidator->collectViolations($document) === [], 'case7 plan passes validator after integration');
    test_assert(true, 'case7 forbidden content scan PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case7 should pass: ' . $e->getMessage());
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_publisher_strategy_to_channel_publish_plan (all passed)\n");
exit(0);
