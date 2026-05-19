<?php
declare(strict_types=1);

/**
 * Stage 1-B-13 CLI tests for TourQueryIntentDetector.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_query_intent_detector.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/**
 * @param array<string, mixed> $result
 */
function assert_shape(array $result): void
{
    foreach (['is_tour_query', 'keyword', 'confidence', 'reason'] as $key) {
        test_assert(array_key_exists($key, $result), "shape: {$key}");
    }
}

$detector = new TourQueryIntentDetector();

$positive = [
    ['富國島有哪些行程', '富國島'],
    ['日本團', '日本'],
    ['東京五日', '東京'],
    ['有沒有韓國旅遊', '韓國'],
    ['想找沖繩自由行', '沖繩'],
    ['歐洲行程', '歐洲'],
    ['北海道賞雪團', '北海道賞雪'],
    ['越南旅遊推薦', '越南'],
];

foreach ($positive as $idx => $case) {
    [$msg, $expectedKeyword] = $case;
    $r = $detector->detect($msg);
    assert_shape($r);
    $n = $idx + 1;
    test_assert(($r['is_tour_query'] ?? false) === true, "pos {$n}: is_tour_query for {$msg}");
    test_assert(($r['keyword'] ?? '') === $expectedKeyword, "pos {$n}: keyword {$msg}");
    test_assert(($r['confidence'] ?? 0) >= 0.5, "pos {$n}: confidence");
    test_assert(is_string($r['reason'] ?? null) && ($r['reason'] ?? '') !== '', "pos {$n}: reason");
}

$negative = [
    '你好',
    '謝謝',
    '我要查訂單',
    '護照要準備什麼',
    '簽證怎麼辦',
    '今天天氣如何',
];

foreach ($negative as $idx => $msg) {
    $r = $detector->detect($msg);
    assert_shape($r);
    $n = $idx + 1;
    test_assert(($r['is_tour_query'] ?? true) === false, "neg {$n}: not tour for {$msg}");
    test_assert($r['keyword'] === null, "neg {$n}: keyword null");
}

if ($failures === 0) {
    echo "OK: TourQueryIntentDetector tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
