<?php
declare(strict_types=1);

/**
 * Phase 9-B-19: Publisher strategy resolver + LINE strategy skeleton.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'publisher_strategy' . DIRECTORY_SEPARATOR . 'PublisherStrategyResolver.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$resolver = new PublisherStrategyResolver();

// Case 1: resolve line default
try {
    $strategy = $resolver->resolve('line');
    test_assert($strategy->getChannel() === 'line', 'case1 channel line');
    test_assert($strategy->getMaxItems() === 10, 'case1 default max_items=10');
    test_assert($strategy->getStrategyName() === 'line_oa_default_v1', 'case1 strategy_name');
    test_assert(true, 'case1 resolve line default PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case1 should pass: ' . $e->getMessage());
}

// Case 2: resolve tenant override (travel_b)
try {
    $strategy = $resolver->resolve('line', '5f99b8d665e8444d');
    test_assert($strategy->getMaxItems() === 5, 'case2 tenant override max_items=5');
    $policy = $strategy->toArray()['text_format_policy'];
    test_assert((int) ($policy['max_title_length'] ?? 0) === 36, 'case2 tenant override title length');
    test_assert(true, 'case2 resolve tenant override PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case2 should pass: ' . $e->getMessage());
}

// Case 3: LinePublisherStrategy + max_items
$longTitle = str_repeat('東', 50);
$publisherContracts = [];
for ($i = 0; $i < 8; ++$i) {
    $publisherContracts[] = [
        'title' => $longTitle . $i,
        'summary' => 'summary ' . $i,
        'primary_url' => 'https://example.test/item/' . $i,
    ];
}

try {
    $strategy = $resolver->resolve('line', '5f99b8d665e8444d');
    $lineStrategy = $resolver->getStrategy('line');
    $result = $lineStrategy->applyStrategy($publisherContracts, $strategy);

    test_assert(count($result['items']) === 5, 'case3 max_items limits to 5 items');
    test_assert(isset($result['fallback']) && is_array($result['fallback']), 'case3 overflow fallback present');
    test_assert(true, 'case3 LinePublisherStrategy max_items PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case3 should pass: ' . $e->getMessage());
}

// Case 4: truncate title length
try {
    $strategy = $resolver->resolve('line', '5f99b8d665e8444d');
    $result = $resolver->getStrategy('line')->applyStrategy([
        [
            'title' => str_repeat('A', 100),
            'summary' => str_repeat('B', 200),
            'primary_url' => 'https://example.test/very-long-url-' . str_repeat('x', 200),
        ],
    ], $strategy);

    $item = $result['items'][0];
    test_assert(mb_strlen((string) $item['title']) <= 36, 'case4 title truncated to max 36');
    test_assert(mb_strlen((string) $item['summary']) <= 100, 'case4 summary truncated to max 100');
    test_assert(mb_strlen((string) $item['url']) <= 100, 'case4 url truncated to max 100');
    test_assert(true, 'case4 truncate PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case4 should pass: ' . $e->getMessage());
}

// Case 5: unsupported channel
try {
    $resolver->resolve('gemini');
    test_assert(false, 'case5 should fail on unsupported channel');
} catch (\RuntimeException $e) {
    test_assert(
        strpos($e->getMessage(), 'Unsupported publisher strategy channel') !== false,
        'case5 unsupported channel exception'
    );
    test_assert(true, 'case5 unsupported channel PASS');
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_publisher_strategy_resolver (all passed)\n");
exit(0);
