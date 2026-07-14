<?php
declare(strict_types=1);

/**
 * Phase 2-F Step 2-F-1 — GroundingContextBuilder unit tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'grounding' . DIRECTORY_SEPARATOR . 'GroundingAssemblyContext.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'grounding' . DIRECTORY_SEPARATOR . 'GroundingContextBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'RuntimeType.php';

$failures = 0;

function gcb_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/**
 * @param array<string, mixed> $overrides
 */
function gcb_context(array $overrides = []): GroundingAssemblyContext
{
    $defaults = [
        'trace_id' => 'trace-gcb',
        'conversation_id' => 'conv-gcb',
        'tenant_sno' => '1001',
        'customer_query' => '北海道 8月 五天',
        'runtime_type' => RuntimeType::PRODUCT_SEARCH,
        'source_type' => 'product_search',
        'runtime_result' => ['reply_text' => 'x', 'grounded' => false],
        'dispatch_result' => [],
    ];

    return GroundingAssemblyContext::fromArray(array_merge($defaults, $overrides));
}

$builder = new GroundingContextBuilder();

// --- Hokkaido full entity ---
$full = $builder->build(gcb_context([
    'aiu_projection' => [
        'entities' => [
            'destination' => '北海道',
            'travel_dates' => '8月',
            'duration' => '五天',
        ],
    ],
]));
gcb_assert(($full['destination'] ?? '') === '北海道', 'hokkaido destination');
gcb_assert(($full['travel_dates'] ?? '') === '8月', 'hokkaido travel_dates');
gcb_assert(($full['duration'] ?? '') === '五天', 'hokkaido duration');
gcb_assert(
    ($full['current_requirement'] ?? '') === '北海道 8月 五天',
    'hokkaido current_requirement merged'
);

// --- Memory P1 wins over AIU ---
$memoryWins = $builder->build(gcb_context([
    'memory_snapshot' => [
        'destination' => '東京',
        'current_requirement' => '東京 3月',
    ],
    'aiu_projection' => [
        'entities' => ['destination' => '北海道'],
    ],
]));
gcb_assert(($memoryWins['destination'] ?? '') === '東京', 'memory P1 wins destination');
gcb_assert(($memoryWins['current_requirement'] ?? '') === '東京 3月', 'memory current_requirement preserved');

// --- Partial entities ---
$augOnly = $builder->build(gcb_context([
    'customer_query' => '北海道 8月',
    'aiu_projection' => ['entities' => ['destination' => '北海道', 'travel_dates' => '8月']],
]));
gcb_assert(!isset($augOnly['duration']), 'aug only: no duration');
gcb_assert(($augOnly['destination'] ?? '') === '北海道', 'aug only destination');

$daysOnly = $builder->build(gcb_context([
    'customer_query' => '北海道 五天',
    'aiu_projection' => ['entities' => ['destination' => '北海道', 'duration' => '五天']],
]));
gcb_assert(!isset($daysOnly['travel_dates']), 'days only: no travel_dates');

$datesDays = $builder->build(gcb_context([
    'customer_query' => '8月 五天',
    'aiu_projection' => ['entities' => ['travel_dates' => '8月', 'duration' => '五天']],
]));
gcb_assert(!isset($datesDays['destination']), 'dates+days: no destination');

// --- List slots memory only ---
$listOnly = $builder->build(gcb_context([
    'memory_snapshot' => ['outstanding_issues' => ['確認人數']],
    'aiu_projection' => ['entities' => ['outstanding_issues' => ['ignored']]],
]));
gcb_assert(
    isset($listOnly['outstanding_issues']) && $listOnly['outstanding_issues'] === ['確認人數'],
    'outstanding_issues from memory only'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_grounding_context_builder.php (" . (8) . " checks)\n");
exit(0);
