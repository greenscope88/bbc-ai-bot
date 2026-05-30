<?php
declare(strict_types=1);

/**
 * Phase 9-B-13: Platform-scoped region keyword mapping.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'RegionKeywordMapper.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function test_has_no_region_code(array $mapped): bool
{
    return !array_key_exists('region_code', $mapped);
}

$mapper = new RegionKeywordMapper();

// Case 1: agenttour + 東京 → J
try {
    $mapped = $mapper->map('agenttour', '東京');
    test_assert($mapped['keyword'] === '東京', 'case1 keyword');
    test_assert($mapped['region_code'] === 'J', 'case1 region_code=J');
    test_assert($mapped['destination'] === '東京', 'case1 destination');
    test_assert(true, 'case1 agenttour 東京 PASS');
} catch (RegionKeywordMapperException $e) {
    test_assert(false, 'case1 should pass: ' . $e->getMessage());
}

// Case 2: agenttour + 泰國 → C
try {
    $mapped = $mapper->map('agenttour', '泰國');
    test_assert($mapped['keyword'] === '泰國', 'case2 keyword');
    test_assert($mapped['region_code'] === 'C', 'case2 region_code=C');
    test_assert(true, 'case2 agenttour 泰國 PASS');
} catch (RegionKeywordMapperException $e) {
    test_assert(false, 'case2 should pass: ' . $e->getMessage());
}

// Case 3: grp + 東京 → keyword only, no region_code
try {
    $mapped = $mapper->map('grp', '東京');
    test_assert($mapped['keyword'] === '東京', 'case3 keyword preserved');
    test_assert(test_has_no_region_code($mapped), 'case3 no region_code');
    test_assert(!$mapper->supportsRegionMapping('grp'), 'case3 grp has no mapping scope');
    test_assert(true, 'case3 grp 東京 PASS');
} catch (RegionKeywordMapperException $e) {
    test_assert(false, 'case3 should pass: ' . $e->getMessage());
}

// Case 4: bbctravel + 東京 → keyword only, no region_code
try {
    $mapped = $mapper->map('bbctravel', '東京');
    test_assert($mapped['keyword'] === '東京', 'case4 keyword preserved');
    test_assert(test_has_no_region_code($mapped), 'case4 no region_code');
    test_assert(!$mapper->supportsRegionMapping('bbctravel'), 'case4 bbctravel has no mapping scope');
    test_assert(true, 'case4 bbctravel 東京 PASS');
} catch (RegionKeywordMapperException $e) {
    test_assert(false, 'case4 should pass: ' . $e->getMessage());
}

// Case 5: agenttour + unknown keyword
try {
    $mapper->map('agenttour', '火星旅遊');
    test_assert(false, 'case5 should fail on unknown keyword');
} catch (RegionKeywordMapperException $e) {
    test_assert(
        $e->getErrorCode() === RegionKeywordMapperException::KEYWORD_NOT_FOUND,
        'case5 error code REGION_KEYWORD_NOT_FOUND'
    );
    test_assert(true, 'case5 agenttour 火星旅遊 FAIL REGION_KEYWORD_NOT_FOUND PASS');
}

// Guard: grp unknown keyword must not use agenttour table
try {
    $mapped = $mapper->map('grp', '火星旅遊');
    test_assert($mapped['keyword'] === '火星旅遊', 'grp unknown keeps keyword');
    test_assert(test_has_no_region_code($mapped), 'grp unknown no region_code');
    test_assert(true, 'grp unknown keyword does not inherit agenttour mapping PASS');
} catch (RegionKeywordMapperException $e) {
    test_assert(false, 'grp unknown should not throw: ' . $e->getMessage());
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "All region keyword mapper tests passed.\n");
exit(0);
