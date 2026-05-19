<?php
declare(strict_types=1);

/**
 * Stage 1-B-14 CLI tests for GeminiTourContextBuilder.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'gemini_tour_context_builder.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$builder = new GeminiTourContextBuilder();

$normal = [
    'success' => true,
    'http_status' => 200,
    'traceId' => 'secret-trace',
    'sno' => 'e1fd133c7e8e45a1',
    'keyword' => '東京',
    'pagination' => ['page' => 1, 'pageSize' => 5, 'total' => 12],
    'items' => [
        [
            'title' => '東京迪士尼親子五日',
            'tourDate' => '2026-07-10',
            'stock' => 8,
            'price' => 39900,
            'tradeRefund' => 3000,
            'traceId' => 'must-not-appear',
            'depID' => 888,
        ],
        [
            'title' => '東京富士山溫泉五日',
            'tourDate' => '2026-07-18',
            'stock' => 12,
            'price' => 42900,
            'tradeRefund' => 3500,
        ],
        [
            'title' => '第三筆',
            'tourDate' => '2026-08-01',
            'price' => 10000,
        ],
    ],
    'search_url' => 'https://bonusmee.com/view/cloud/cloud_store_tourdate.php?keyword=%E6%9D%B1%E4%BA%AC',
    'error' => null,
];

// 1. normal context
$text1 = $builder->build($normal);
test_assert(strpos($text1, '【旅遊產品搜尋結果】') !== false, '1: title block');
test_assert(strpos($text1, '共找到 12 筆') !== false, '1: total');
test_assert(strpos($text1, '東京迪士尼親子五日') !== false, '1: item1 name');
test_assert(strpos($text1, 'NT$39,900') !== false, '1: price format');
test_assert(strpos($text1, 'NT$3,000') !== false, '1: rebate format');
test_assert(strpos($text1, 'https://bonusmee.com/view/cloud') !== false, '1: search url');
test_assert(strpos($text1, 'secret-trace') === false, '1: no traceId value');
test_assert(strpos($text1, '888') === false, '1: no depID value');
test_assert(strpos($text1, '請 Gemini 回覆客人時') !== false, '1: instructions');

// 2. maxItems
$text2 = $builder->build($normal, ['maxItems' => 2]);
test_assert(strpos($text2, '東京迪士尼親子五日') !== false, '2: first item');
test_assert(strpos($text2, '東京富士山溫泉五日') !== false, '2: second item');
test_assert(strpos($text2, '第三筆') === false, '2: third item excluded');

// 3. empty results
$empty = [
    'success' => true,
    'http_status' => 200,
    'pagination' => ['page' => 1, 'pageSize' => 5, 'total' => 0],
    'items' => [],
    'search_url' => 'https://example.com/search',
    'error' => null,
];
$text3 = $builder->build($empty);
test_assert(strpos($text3, '目前沒有找到符合條件的行程') !== false, '3: empty message');
test_assert(strpos($text3, 'https://example.com/search') !== false, '3: still has url');

// 4. missing search_url
$noUrl = $normal;
$noUrl['search_url'] = null;
$text4 = $builder->build($noUrl);
test_assert(strpos($text4, '目前未提供搜尋結果連結') !== false, '4: missing url note');

// 5. missing item fields
$sparse = [
    'success' => true,
    'pagination' => ['total' => 1],
    'items' => [['title' => '僅有名稱']],
    'search_url' => 'https://example.com/a',
];
$text5 = $builder->build($sparse);
test_assert(strpos($text5, '僅有名稱') !== false, '5: title');
test_assert(strpos($text5, '出團日期：未提供') !== false, '5: missing date');
test_assert(strpos($text5, '直售價：未提供') !== false, '5: missing price');

// 6. price formatting explicit
test_assert(strpos($text1, 'NT$42,900') !== false, '6: second price');

// 7. sensitive values / leaked payload fields
$leaky = [
    'success' => true,
    'pagination' => ['total' => 1],
    'items' => [
        [
            'title' => '測試行程',
            'api_key' => 'KEY-SECRET',
            'storeNo' => '6290',
            'provider_id_no' => '102',
            'internal_url' => 'http://internal/host',
        ],
    ],
    'search_url' => 'https://example.com/safe',
];
$text7 = $builder->build($leaky);
test_assert(strpos($text7, 'KEY-SECRET') === false, '7: no api_key value');
test_assert(strpos($text7, '6290') === false, '7: no storeNo value');
test_assert(strpos($text7, 'internal/host') === false, '7: no internal_url');

// 8. includeInstructions=false
$text8 = $builder->build($normal, ['includeInstructions' => false]);
test_assert(strpos($text8, '請 Gemini 回覆客人時') === false, '8: no instructions');

// api failure path
$fail = [
    'success' => false,
    'error' => ['code' => 'HOSTB_HTTP_DISABLED', 'message' => 'internal'],
    'traceId' => 'tid-x',
    'items' => [],
];
$textF = $builder->build($fail);
test_assert(strpos($textF, '未能取得可推薦') !== false, 'fail: generic');
test_assert(strpos($textF, 'HOSTB_HTTP_DISABLED') === false, 'fail: no error code leak');

if ($failures === 0) {
    echo "OK: GeminiTourContextBuilder tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
