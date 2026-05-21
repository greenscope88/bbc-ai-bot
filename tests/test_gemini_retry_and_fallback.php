<?php
declare(strict_types=1);

/**
 * Gemini retry + tour fallback formatter tests (no live HTTP to Gemini).
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'gemini_service.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_fallback_formatter.php';

$lineSep = GeminiTourContextBuilder::LINE_TOUR_ITEM_SEPARATOR;

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

// --- gemini_result_is_retryable ---
test_assert(gemini_result_is_retryable(['ok' => false, 'curl_errno' => 28, 'http_status' => 0]) === true, 'retry: curl timeout errno');
test_assert(gemini_result_is_retryable(['ok' => false, 'curl_errno' => 0, 'http_status' => 503]) === true, 'retry: HTTP 503');
test_assert(gemini_result_is_retryable(['ok' => false, 'curl_errno' => 0, 'http_status' => 500]) === false, 'no retry: HTTP 500');
test_assert(gemini_result_is_retryable(['ok' => false, 'curl_errno' => 0, 'http_status' => 200]) === false, 'no retry: HTTP 200 failure');
test_assert(gemini_result_is_retryable(['ok' => true, 'curl_errno' => 0, 'http_status' => 200]) === false, 'no retry: success');

// --- callGeminiUrl: 503 then success (mock) ---
$calls = 0;
$mock503ThenOk = static function (string $u, array $p) use (&$calls): array {
    ++$calls;
    if ($calls < 2) {
        return ['ok' => false, 'text' => null, 'error' => 'Gemini HTTP/CURL error - status 503', 'response' => null, 'http_status' => 503, 'curl_errno' => 0];
    }
    return ['ok' => true, 'text' => 'OK from mock', 'error' => null, 'response' => [], 'http_status' => 200, 'curl_errno' => 0];
};
$r1 = callGeminiUrl('https://example.test/gemini', [], $mock503ThenOk);
test_assert($r1['ok'] === true, 'mock: final ok');
test_assert(($r1['text'] ?? '') === 'OK from mock', 'mock: text');
test_assert($calls === 2, 'mock: two attempts');

// --- callGeminiUrl: three 503 failures ---
$calls2 = 0;
$mock503x3 = static function (string $u, array $p) use (&$calls2): array {
    ++$calls2;
    return ['ok' => false, 'text' => null, 'error' => 'status 503', 'response' => null, 'http_status' => 503, 'curl_errno' => 0];
};
$r2 = callGeminiUrl('https://example.test/gemini', [], $mock503x3);
test_assert($r2['ok'] === false, 'mock: all fail');
test_assert($calls2 === 3, 'mock: three attempts');

// --- callGeminiUrl: 400 no retry (single call) ---
$calls3 = 0;
$mock400 = static function (string $u, array $p) use (&$calls3): array {
    ++$calls3;
    return ['ok' => false, 'text' => null, 'error' => 'status 400', 'response' => null, 'http_status' => 400, 'curl_errno' => 0];
};
$r3 = callGeminiUrl('https://example.test/gemini', [], $mock400);
test_assert($r3['ok'] === false, 'mock400: fail');
test_assert($calls3 === 1, 'mock400: single attempt');

// --- TourFallbackFormatter ---
$fixture = <<<CTX
【旅遊產品搜尋結果】
本次查詢共找到 2 筆相關行程。

1. 東京測試行程A
   出團日期：2026-06-01
   直售價：NT$33,800 起
   出發地：台北
   行程內頁：https://bonusmee.com/view/cloud/tourdate_dm.php?trsno=1001
   行程表：https://example.com/schedule-a.pdf

{$lineSep}

2. 東京測試行程B
   出團日期：2026-06-10
   直售價：NT$24,888 起
   出發地：高雄

完整搜尋結果：
https://bonusmee.com/view/cloud/cloud_store_tourdate.php?keyword=test

請 Gemini 回覆客人時：
- x
CTX;

$fb = TourFallbackFormatter::formatFromTourContext($fixture);
test_assert(strpos($fb, '東京測試行程A') !== false, 'fallback: title');
test_assert(strpos($fb, '東京測試行程B') !== false, 'fallback: second title');
test_assert(strpos($fb, '出發地：台北') !== false && strpos($fb, '出發地：高雄') !== false, 'fallback: departure lines');
test_assert(strpos($fb, '行程內頁：https://bonusmee.com/view/cloud/tourdate_dm.php?trsno=1001') !== false, 'fallback: detail page url');
test_assert(strpos($fb, '行程表：https://example.com/schedule-a.pdf') !== false, 'fallback: schedule link');
test_assert(substr_count($fb, '行程表：') === 1, 'fallback: schedule only on item A');
test_assert(strpos($fb, "{$lineSep}\n\n2. 東京測試行程B") !== false, 'fallback: separator between items');
test_assert(strpos($fb, '06/01') !== false, 'fallback: date MM/DD');
test_assert(strpos($fb, '2026') === false, 'fallback: no year in reply');
test_assert(strpos($fb, 'NT$33,800') !== false || strpos($fb, '33,800') !== false, 'fallback: price');
test_assert(strpos($fb, 'https://bonusmee.com/view/cloud/cloud_store_tourdate.php') !== false, 'fallback: url');
test_assert(strpos($fb, '完整搜尋結果：') !== false, 'fallback: search label');
test_assert(strpos($fb, 'AI錯誤') === false, 'fallback: no AI error label');
test_assert(strpos($fb, '503') === false, 'fallback: no 503');

// --- TourFallbackFormatter: 價格 / 售價 aliases ---
$fixturePriceAlias = <<<'CTX'
1. 測試B
   出團日期：2026-07-01
   價格：NT$12,000
   出發地：台中

完整搜尋結果：
https://bonusmee.com/view/test

請 Gemini 回覆客人時：
- x
CTX;
$fb2 = TourFallbackFormatter::formatFromTourContext($fixturePriceAlias);
test_assert(strpos($fb2, '測試B') !== false, 'fallback: title price-alias');
test_assert(strpos($fb2, 'NT$12,000') !== false || strpos($fb2, '12,000') !== false, 'fallback: 價格 line');
test_assert(strpos($fb2, '出發地：台中') !== false, 'fallback: departure price-alias');

$fixtureSell = <<<'CTX'
1. 測試C
   出團日期：2026-08-01
   售價：8888
   出發地：未提供

完整搜尋結果：
https://bonusmee.com/x

請 Gemini
CTX;
$fb3 = TourFallbackFormatter::formatFromTourContext($fixtureSell);
test_assert(strpos($fb3, '8888') !== false, 'fallback: 售價 line');
test_assert(strpos($fb3, '出發地：未提供') !== false, 'fallback: departure 售價 fixture');

$fixtureNoPrice = <<<'CTX'
1. 測試D
   出團日期：2026-09-01
   可售數量：未提供
   出發地：未提供

完整搜尋結果：
https://bonusmee.com/y

請 Gemini
CTX;
$fb4 = TourFallbackFormatter::formatFromTourContext($fixtureNoPrice);
test_assert(strpos($fb4, '測試D') !== false, 'fallback: no-price title');
test_assert(strpos($fb4, '直售價：未提供') !== false, 'fallback: 直售價未提供');

// --- callGeminiUrl: immediate success (mock) ---
$calls4 = 0;
$mockOk = static function (string $u, array $p) use (&$calls4): array {
    ++$calls4;
    return ['ok' => true, 'text' => 'first ok', 'error' => null, 'response' => [], 'http_status' => 200, 'curl_errno' => 0];
};
$r4 = callGeminiUrl('https://example.test/gemini', [], $mockOk);
test_assert($r4['ok'] === true && ($r4['text'] ?? '') === 'first ok', 'mock: immediate success');
test_assert($calls4 === 1, 'mock: single call on success');

if ($failures === 0) {
    echo "OK: Gemini retry and tour fallback tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
