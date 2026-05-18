<?php
declare(strict_types=1);

/**
 * Stage 1-B-2 CLI tests for TourSearchService.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_search_service.php';

$failures = 0;
$skipped = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function test_skip(string $message): void
{
    global $skipped;
    ++$skipped;
    fwrite(STDOUT, "SKIP: {$message}\n");
}

function result_shape_ok(array $result): bool
{
    foreach (['ok', 'httpStatus', 'errorCode', 'message', 'traceId', 'pagination', 'items', 'search_url'] as $key) {
        if (!array_key_exists($key, $result)) {
            return false;
        }
    }

    return true;
}

$stagingSno = 'e1fd133c7e8e45a1';
$stagingKeyword = '富國島';

// 1. blank keyword -> KEYWORD_REQUIRED
$r1 = TourSearchService::search($stagingSno, '   ');
test_assert(result_shape_ok($r1), '1: result shape');
test_assert(($r1['ok'] ?? true) === false, '1: not ok');
test_assert(($r1['errorCode'] ?? '') === 'KEYWORD_REQUIRED', '1: error code');
test_assert($r1['items'] === [], '1: empty items');

// 2. unknown sno -> TENANT_NOT_FOUND
$r2 = TourSearchService::search('unknown_sno_00000001', $stagingKeyword);
test_assert(($r2['errorCode'] ?? '') === 'TENANT_NOT_FOUND', '2: tenant not found');

// search_url unit check (no HTTP)
$url = TourSearchService::buildSearchUrl($stagingSno, $stagingKeyword);
test_assert(strpos($url, 'sno=' . rawurlencode($stagingSno)) !== false, 'url: contains sno');
test_assert(strpos($url, 'keyword=' . rawurlencode($stagingKeyword)) !== false, 'url: contains keyword');
test_assert(strpos($url, 'openExternalBrowser=1') !== false, 'url: openExternalBrowser');

$httpEnabled = app_config_get('gateway.host_b.http_enabled', false);
$dryRunEnv = getenv('MVP_STAGING_HOSTB_HTTP_DRY_RUN');
$dryRunActive = is_string($dryRunEnv) && $dryRunEnv !== '' && $dryRunEnv !== '0' && strtolower($dryRunEnv) !== 'false';

if (!$httpEnabled) {
    test_skip('GATEWAY_HOSTB_HTTP_ENABLED is not true; skipping live Host B integration tests (3-6).');
} elseif ($dryRunActive) {
    test_skip('MVP_STAGING_HOSTB_HTTP_DRY_RUN is active; skipping live Host B integration tests (3-6).');
} else {
    $r3 = TourSearchService::search($stagingSno, $stagingKeyword, 1, 5, 'test_trace_stage1b2');

    test_assert(result_shape_ok($r3), '3: result shape');
    test_assert(($r3['ok'] ?? false) === true, '3: ok');
    test_assert($r3['errorCode'] === null, '3: errorCode null');
    test_assert((int) ($r3['httpStatus'] ?? 0) === 200, '3: http 200');

    $pg = $r3['pagination'] ?? null;
    test_assert(is_array($pg), '3: pagination array');
    test_assert((int) ($pg['total'] ?? 0) > 0, '3: pagination.total > 0');

    $items = $r3['items'] ?? [];
    test_assert(is_array($items) && $items !== [], '3: items not empty');

    $searchUrl = (string) ($r3['search_url'] ?? '');
    test_assert(strpos($searchUrl, $stagingSno) !== false, '3: search_url has sno');
    test_assert(strpos($searchUrl, rawurlencode($stagingKeyword)) !== false, '3: search_url has keyword');

    $first = $items[0];
    test_assert(isset($first['title']) && $first['title'] !== '', '3: items[0].title present');
    test_assert(($first['couponNo'] ?? 0) > 0, '3: items[0].couponNo');

    if (isset($r3['rawNormalized']) === false) {
        // title maps from couponName when Host B returns standard shape
        test_assert(is_string($first['title']), '5: title is string');
    }
}

if ($failures === 0) {
    $suffix = $skipped > 0 ? " ({$skipped} skipped)" : '';
    echo "OK: TourSearchService tests passed.{$suffix}\n";
    exit(0);
}

echo "DONE with {$failures} failure(s), {$skipped} skipped.\n";
exit(1);
