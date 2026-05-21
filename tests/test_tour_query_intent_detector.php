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

/** @var list<array{0: string, 1: string}> */
$bareDestinations = [
    ['東京', '東京'],
    ['韓國', '韓國'],
    ['北京', '北京'],
    ['日本', '日本'],
    ['大阪', '大阪'],
    ['沖繩', '沖繩'],
    ['北海道', '北海道'],
    ['首爾', '首爾'],
    ['歐洲', '歐洲'],
    ['越南', '越南'],
];

foreach ($bareDestinations as $idx => $case) {
    [$msg, $expectedKeyword] = $case;
    $r = $detector->detect($msg);
    assert_shape($r);
    $n = $idx + 1;
    test_assert(($r['is_tour_query'] ?? false) === true, "bare {$n}: is_tour_query for {$msg}");
    test_assert(($r['keyword'] ?? '') === $expectedKeyword, "bare {$n}: keyword {$msg}");
    test_assert(($r['reason'] ?? '') === 'bare_destination_name', "bare {$n}: reason bare_destination_name");
}

$positive = [
    ['你們有沒有韓國的行程', '韓國'],
    ['有沒有韓國的行程', '韓國'],
    ['幫我找韓國行程', '韓國'],
    ['請幫我找韓國旅遊', '韓國'],
    ['韓國旅遊', '韓國'],
    ['韓國行程', '韓國'],
    ['你們有沒有東京的行程', '東京'],
    ['你們有沒有大阪五日遊', '大阪五日遊'],
    ['你們有沒有日本旅遊', '日本'],
    ['你們有沒有六月份韓國旅遊的行程', '韓國'],
    ['幫我找一下有沒有東京的行程', '東京'],
    ['幫我看一下有沒有東京的行程', '東京'],
    ['幫我看看有沒有東京的行程', '東京'],
    ['請幫我看一下有沒有東京的行程', '東京'],
    ['請幫我找一下東京行程', '東京'],
    ['幫我查一下東京行程', '東京'],
    ['查一下東京行程', '東京'],
    ['有沒有東京的行程', '東京'],
    ['請幫我找東京行程', '東京'],
    ['有沒有大阪五日遊', '大阪五日遊'],
    ['幫我找一下有沒有東京自由行', '東京自由行'],
    ['你們有沒有東京旅遊', '東京'],
    ['你們有沒有六月份東京旅遊的行程', '東京'],
    ['幫我看看日本旅遊', '日本'],
    ['請幫我找東京行程', '東京'],
    ['幫我找東京行程', '東京'],
    ['請找東京行程', '東京'],
    ['找東京行程', '東京'],
    ['請幫我找大阪五日遊', '大阪五日遊'],
    ['有東京行程嗎', '東京'],
    ['我想找東京行程', '東京'],
    ['請推薦歐洲旅遊', '歐洲'],
    ['東京自由行推薦', '東京自由行'],
    ['火星旅遊', '火星'],
    ['日本旅遊', '日本'],
    ['富國島有哪些行程', '富國島'],
    ['日本團', '日本'],
    ['東京五日', '東京'],
    ['有沒有韓國旅遊', '韓國'],
    ['想找沖繩自由行', '沖繩自由行'],
    ['歐洲行程', '歐洲'],
    ['北海道賞雪團', '北海道賞雪'],
    ['越南旅遊推薦', '越南'],
    ['順便幫我找一下韓國便宜的旅遊', '韓國'],
    ['順便幫我找韓國便宜旅遊', '韓國'],
    ['幫我找一下韓國便宜的旅遊', '韓國'],
    ['韓國便宜旅遊', '韓國'],
    ['韓國便宜的行程', '韓國'],
    ['便宜韓國旅遊', '韓國'],
    ['有沒有便宜的韓國行程', '韓國'],
    ['請幫我找便宜的東京行程', '東京'],
    ['你們有沒有便宜的日本旅遊', '日本'],
    ['順便幫我找一下東京行程', '東京'],
];

foreach ($positive as $idx => $case) {
    [$msg, $expectedKeyword] = $case;
    $r = $detector->detect($msg);
    assert_shape($r);
    $n = $idx + 1;
    test_assert(($r['is_tour_query'] ?? false) === true, "pos {$n}: is_tour_query for {$msg}");
    test_assert(($r['keyword'] ?? '') === $expectedKeyword, "pos {$n}: keyword {$msg}");
    test_assert(($r['keyword'] ?? '') !== '韓韓', "pos {$n}: no 韓韓 artifact for {$msg}");
    test_assert(($r['confidence'] ?? 0) >= 0.5, "pos {$n}: confidence");
    test_assert(is_string($r['reason'] ?? null) && ($r['reason'] ?? '') !== '', "pos {$n}: reason");
}

$negative = [
    '你好',
    '謝謝',
    '早安',
    '晚安',
    '天氣',
    '匯率',
    '訂單',
    '護照',
    '簽證',
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

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent_router.php';

$routerBare = [
    '東京' => 'tour_query',
    '韓國' => 'tour_query',
    '北京' => 'tour_query',
    '你好' => 'general_support',
    '今天天氣如何' => 'weather_query',
    '護照要準備什麼' => 'service_query',
];

foreach ($routerBare as $msg => $expectedIntent) {
    $ir = IntentRouter::detect($msg);
    test_assert(($ir['intent'] ?? '') === $expectedIntent, "router: {$msg} => {$expectedIntent}");
}

if ($failures === 0) {
    echo "OK: TourQueryIntentDetector tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
