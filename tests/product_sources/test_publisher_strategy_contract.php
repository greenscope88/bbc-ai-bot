<?php
declare(strict_types=1);

/**
 * Phase 9-B-18.1: Publisher strategy contract validation.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'PublisherStrategyContract.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'PublisherStrategyContractValidator.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$validator = new PublisherStrategyContractValidator();

$lineStrategy = [
    'schema_version' => 1,
    'channel' => 'line',
    'strategy_name' => 'line_oa_default_v1',
    'max_items' => 10,
    'message_mode' => 'multi_message_short_text',
    'link_policy' => [
        'mode' => 'short_url_preferred',
        'max_url_length' => 120,
        'allow_secondary_urls' => false,
    ],
    'text_format_policy' => [
        'max_title_length' => 40,
        'max_summary_length' => 120,
        'include_metadata_keys' => ['price_from', 'currency'],
        'truncate_suffix' => '…',
    ],
    'button_policy' => [
        'mode' => 'single_primary_action',
        'max_actions_per_item' => 1,
        'allowed_action_types' => ['open_url'],
    ],
    'fallback_policy' => [
        'on_empty_results' => 'send_keyword_search_link',
        'on_item_overflow' => 'truncate_with_notice',
        'overflow_notice' => '僅顯示前 {max_items} 筆',
    ],
    'payload_schema_version' => 1,
    'tenant_override_policy' => [
        'enabled' => true,
        'override_keys' => ['max_items', 'message_mode'],
    ],
];

$geminiStrategy = [
    'schema_version' => 1,
    'channel' => 'gemini',
    'strategy_name' => 'gemini_context_default_v1',
    'max_items' => 30,
    'message_mode' => 'structured_context_block',
    'link_policy' => ['mode' => 'full_url'],
    'text_format_policy' => ['max_title_length' => 200],
    'button_policy' => ['mode' => 'multi_action'],
    'fallback_policy' => ['on_empty_results' => 'return_empty_context_block'],
    'payload_schema_version' => 1,
    'tenant_override_policy' => ['enabled' => true, 'override_keys' => ['max_items']],
];

// Case 1: valid LINE strategy
try {
    $contract = $validator->validate($lineStrategy);
    test_assert($contract->getChannel() === 'line', 'case1 channel line');
    test_assert($contract->getMaxItems() === 10, 'case1 max_items');
    test_assert(true, 'case1 valid LINE strategy PASS');
} catch (\InvalidArgumentException $e) {
    test_assert(false, 'case1 should pass: ' . $e->getMessage());
}

// Case 2: valid Gemini strategy
try {
    $contract = $validator->validate($geminiStrategy);
    test_assert($contract->getChannel() === 'gemini', 'case2 channel gemini');
    test_assert($contract->getStrategyName() === 'gemini_context_default_v1', 'case2 strategy_name');
    test_assert(true, 'case2 valid Gemini strategy PASS');
} catch (\InvalidArgumentException $e) {
    test_assert(false, 'case2 should pass: ' . $e->getMessage());
}

// Case 3: invalid channel
$invalidChannel = $lineStrategy;
$invalidChannel['channel'] = 'unknown_channel_xyz';
$violations = $validator->collectViolations($invalidChannel);
test_assert(in_array('invalid channel', $violations, true), 'case3 invalid channel violation');

// Case 4: missing required field
$missingField = $lineStrategy;
unset($missingField['strategy_name']);
$violations = $validator->collectViolations($missingField);
test_assert(in_array('strategy_name is required', $violations, true), 'case4 missing strategy_name');

// Case 5: max_items not positive integer
$badMaxItems = $lineStrategy;
$badMaxItems['max_items'] = 0;
$violations = $validator->collectViolations($badMaxItems);
test_assert(in_array('max_items must be a positive integer', $violations, true), 'case5 max_items violation');

// Case 6: fromArray / toArray round-trip
try {
    $contract = PublisherStrategyContract::fromArray($lineStrategy);
    $roundTrip = $contract->toArray();
    test_assert($roundTrip['channel'] === 'line', 'case6 round-trip channel');
    test_assert($roundTrip['strategy_name'] === 'line_oa_default_v1', 'case6 round-trip strategy_name');
    test_assert($roundTrip['link_policy']['mode'] === 'short_url_preferred', 'case6 round-trip link_policy');
    $rebuilt = PublisherStrategyContract::fromArray($roundTrip);
    test_assert($rebuilt->toArray() === $roundTrip, 'case6 double round-trip');
    test_assert(true, 'case6 fromArray/toArray round-trip PASS');
} catch (\InvalidArgumentException $e) {
    test_assert(false, 'case6 should pass: ' . $e->getMessage());
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_publisher_strategy_contract (all passed)\n");
exit(0);
