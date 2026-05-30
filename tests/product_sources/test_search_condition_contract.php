<?php
declare(strict_types=1);

/**
 * Phase 9-B-12: Search condition contract validation.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'SearchConditionContract.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'SearchConditionValidator.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$validator = new SearchConditionValidator();

// Case 1: keyword only
try {
    $normalized = $validator->validate(['keyword' => '東京']);
    test_assert($normalized['keyword'] === '東京', 'case1 keyword normalized');
    test_assert(true, 'case1 keyword=東京 PASS');
} catch (SearchConditionContractException $e) {
    test_assert(false, 'case1 should pass: ' . $e->getMessage());
}

// Case 2: full condition
$fullCondition = [
    'schema_version' => 1,
    'keyword' => '東京',
    'product_category' => 'group_tour',
    'destination' => '東京',
    'region_code' => 'J',
    'region_name' => '日本',
    'departure_city' => 'TPE',
    'date_from' => '2026-06-01',
    'date_to' => '2026-06-10',
    'budget_min' => 30000,
    'budget_max' => 80000,
    'platform' => 'agenttour',
    'tenant_instance' => 'rechoice_agenttour',
];

try {
    $normalized = $validator->validate($fullCondition);
    test_assert($normalized['product_category'] === 'group_tour', 'case2 product_category');
    test_assert($normalized['date_from'] === '2026-06-01', 'case2 date_from');
    test_assert(true, 'case2 full condition PASS');
} catch (SearchConditionContractException $e) {
    test_assert(false, 'case2 should pass: ' . $e->getMessage());
}

// Case 3: budget_max < budget_min
try {
    $validator->validate([
        'keyword' => '東京',
        'budget_min' => 50000,
        'budget_max' => 30000,
    ]);
    test_assert(false, 'case3 should fail on budget range');
} catch (SearchConditionContractException $e) {
    test_assert(
        $e->getErrorCode() === SearchConditionContractException::INVALID_BUDGET_RANGE,
        'case3 error code SEARCH_CONDITION_INVALID_BUDGET_RANGE'
    );
}

// Case 4: invalid date range
try {
    $validator->validate([
        'keyword' => '東京',
        'date_from' => '2026-06-10',
        'date_to' => '2026-06-01',
    ]);
    test_assert(false, 'case4 should fail on date range');
} catch (SearchConditionContractException $e) {
    test_assert(
        $e->getErrorCode() === SearchConditionContractException::INVALID_DATE_RANGE,
        'case4 error code SEARCH_CONDITION_INVALID_DATE_RANGE'
    );
}

// Case 5: unknown product_category
try {
    $validator->validate([
        'keyword' => '東京',
        'product_category' => 'unknown_category_xyz',
    ]);
    test_assert(false, 'case5 should fail on product_category');
} catch (SearchConditionContractException $e) {
    test_assert(
        $e->getErrorCode() === SearchConditionContractException::INVALID_PRODUCT_CATEGORY,
        'case5 error code SEARCH_CONDITION_INVALID_PRODUCT_CATEGORY'
    );
}

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_search_condition_contract (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
