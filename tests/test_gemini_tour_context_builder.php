<?php
declare(strict_types=1);

/**
 * Stage 1-B-14 CLI tests for GeminiTourContextBuilder.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'legacy_storefront_crypto.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_detail_url_builder.php';
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
            'departureStr' => '台北',
            'traceId' => 'must-not-appear',
            'depID' => 888,
        ],
        [
            'title' => '東京富士山溫泉五日',
            'tourDate' => '2026-07-18',
            'stock' => 12,
            'price' => 42900,
            'tradeRefund' => 3500,
            'departureStr' => '高雄',
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
test_assert(strpos($text1, '07/10') !== false, '1: date MM/DD item1');
test_assert(strpos($text1, '07/18') !== false, '1: date MM/DD item2');
test_assert(strpos($text1, 'NT$39,900 起') !== false, '1: price format');
test_assert(strpos($text1, '出發地：台北') !== false && strpos($text1, '出發地：高雄') !== false, '1: departure Taipei Kaohsiung');
test_assert(substr_count($text1, '出發地：未提供') >= 1, '1: departure unknown when missing');
test_assert(strpos($text1, '同業後退') === false, '1: no rebate line in listing');
test_assert(strpos($text1, '可售數量') === false, '1: no stock line in listing');
test_assert(strpos($text1, 'https://bonusmee.com/view/cloud') !== false, '1: search url');
test_assert(strpos($text1, 'secret-trace') === false, '1: no traceId value');
test_assert(strpos($text1, '888') === false, '1: no depID value');
test_assert(strpos($text1, '請 Gemini 回覆客人時') !== false, '1: instructions');
$lineSep = GeminiTourContextBuilder::LINE_TOUR_ITEM_SEPARATOR;
test_assert(strpos($text1, $lineSep) !== false, '1: item separators');
test_assert(strpos($text1, "{$lineSep}\n\n2. 東京富士山溫泉五日") !== false, '1: separator before item 2');
test_assert(strpos($text1, "{$lineSep}\n\n" . GeminiTourContextBuilder::SEARCH_URL_LABEL) === false, '1: no separator immediately before search block');
test_assert(strpos($text1, GeminiTourContextBuilder::SEARCH_URL_LABEL) !== false, '1: new search footer label');
test_assert(strpos($text1, '完整搜尋結果：') === false, '1: old search footer label removed');

// 2. maxItems
$text2 = $builder->build($normal, ['maxItems' => 2]);
test_assert(strpos($text2, '東京迪士尼親子五日') !== false, '2: first item');
test_assert(strpos($text2, '東京富士山溫泉五日') !== false, '2: second item');
test_assert(strpos($text2, '第三筆') === false, '2: third item excluded');
test_assert(strpos($text2, "{$lineSep}\n\n2. 東京富士山溫泉五日") !== false, '2: separator between two items');

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

// merge same title → single block with combined MM/DD
$mergePayload = [
    'success' => true,
    'http_status' => 200,
    'pagination' => ['page' => 1, 'pageSize' => 5, 'total' => 2],
    'items' => [
        [
            'title' => '同一行程',
            'tourDate' => '2026-06-01',
            'price' => 10000,
            'departureStr' => '台北',
            'couponNo' => 90001,
        ],
        [
            'title' => '同一行程',
            'tourDate' => '2026-06-10',
            'price' => 10000,
            'departureStr' => '台北',
            'couponNo' => 90001,
        ],
    ],
    'search_url' => 'https://bonusmee.com/merged',
    'error' => null,
];
$textMerge = $builder->build($mergePayload, ['maxItems' => 5]);
test_assert(strpos($textMerge, '06/01') !== false && strpos($textMerge, '06/10') !== false, 'merge: MM/DD combined');
test_assert(preg_match_all('/同一行程/u', $textMerge) === 1, 'merge: single title occurrence in context');
test_assert(strpos($textMerge, '出發地：台北') !== false, 'merge: departure retained');

// merge split: same title, different departureStr → two rows
$splitDep = [
    'success' => true,
    'pagination' => ['total' => 2],
    'items' => [
        ['title' => '京津八日', 'tourDate' => '2026-06-01', 'price' => 40000, 'departureStr' => '台北', 'couponNo' => 70001],
        ['title' => '京津八日', 'tourDate' => '2026-06-02', 'price' => 40000, 'departureStr' => '高雄', 'couponNo' => 70001],
    ],
    'search_url' => 'https://bonusmee.com/split-dep',
];
$textSplitDep = $builder->build($splitDep);
test_assert(preg_match_all('/京津八日/u', $textSplitDep) === 2, 'splitDep: two rows same title diff dep');
test_assert(strpos($textSplitDep, '出發地：台北') !== false && strpos($textSplitDep, '出發地：高雄') !== false, 'splitDep: departures');

// merge split: same title + departure, different couponNo
$splitCoupon = [
    'success' => true,
    'pagination' => ['total' => 2],
    'items' => [
        ['title' => '雙城遊', 'tourDate' => '2026-07-01', 'price' => 42000, 'departureStr' => '台北', 'couponNo' => 80001],
        ['title' => '雙城遊', 'tourDate' => '2026-07-02', 'price' => 43000, 'departureStr' => '台北', 'couponNo' => 80002],
    ],
    'search_url' => 'https://bonusmee.com/split-coupon',
];
$textSplitCoupon = $builder->build($splitCoupon);
test_assert(preg_match_all('/雙城遊/u', $textSplitCoupon) === 2, 'splitCoupon: two rows');

// title prefix departure when departureStr empty
$prefixPayload = [
    'success' => true,
    'pagination' => ['total' => 1],
    'items' => [
        ['title' => '高雄出發│北京自由行五日', 'tourDate' => '2026-05-01', 'price' => 21900, 'couponNo' => 501],
    ],
    'search_url' => 'https://bonusmee.com/prefix',
];
$textPrefix = $builder->build($prefixPayload);
test_assert(strpos($textPrefix, '出發地：高雄') !== false, 'prefix: departure parsed from title');

// apiRawLimit 30 input, max 5 merged display rows (10 distinct single-date rows → cap at 5)
$manyDistinct = ['success' => true, 'pagination' => ['total' => 99], 'items' => [], 'search_url' => 'https://bonusmee.com/many'];
for ($i = 1; $i <= 10; ++$i) {
    $manyDistinct['items'][] = [
        'title' => "獨立行程{$i}",
        'tourDate' => sprintf('2026-06-%02d', $i),
        'price' => 10000 + $i,
        'departureStr' => '台北',
        'couponNo' => 60000 + $i,
    ];
}
$textMany = $builder->build($manyDistinct, ['maxItems' => 5, 'apiRawLimit' => 30]);
test_assert(preg_match_all('/^\d+\.\s+/mu', $textMany) === 5, 'many: at most 5 numbered merged rows');
test_assert(strpos($textMany, '獨立行程6') === false, 'many: sixth merged row excluded');

// Beijing-like: first 5 rows same merge key → one block; sixth distinct → second row (within maxItems 5)
$beijingLike = [
    'success' => true,
    'pagination' => ['total' => 2000],
    'items' => [
        ['title' => '文化交流｜京津雙城', 'tourDate' => '2026/07/12', 'price' => 42800, 'departureStr' => '台北', 'couponNo' => 11651],
        ['title' => '文化交流｜京津雙城', 'tourDate' => '2026/08/23', 'price' => 42800, 'departureStr' => '台北', 'couponNo' => 11651],
        ['title' => '文化交流｜京津雙城', 'tourDate' => '2026/08/31', 'price' => 42800, 'departureStr' => '台北', 'couponNo' => 11651],
        ['title' => '文化交流｜京津雙城', 'tourDate' => '2026/09/28', 'price' => 42800, 'departureStr' => '台北', 'couponNo' => 11651],
        ['title' => '文化交流｜京津雙城', 'tourDate' => '2026/10/19', 'price' => 42800, 'departureStr' => '台北', 'couponNo' => 11651],
        ['title' => '月滿京秋烤肉趴', 'tourDate' => '2026/09/21', 'price' => 46800, 'departureStr' => '台北', 'couponNo' => 11370],
    ],
    'search_url' => 'https://bonusmee.com/bj',
];
$textBj = $builder->build($beijingLike, ['maxItems' => 5, 'apiRawLimit' => 30]);
test_assert(preg_match_all('/^\d+\.\s+/mu', $textBj) === 2, 'beijingLike: merged group + second product → 2 rows');
test_assert(strpos($textBj, '07/12') !== false && strpos($textBj, '10/19') === false, 'beijingLike: fifth date hidden');
test_assert(strpos($textBj, '...更多') !== false, 'beijingLike: more dates suffix');
test_assert(strpos($textBj, '2. 月滿京秋烤肉趴') !== false, 'beijingLike: second product visible');

// 4. missing search_url
$noUrl = $normal;
$noUrl['search_url'] = null;
$text4 = $builder->build($noUrl);
test_assert(strpos($text4, '目前未提供連結') !== false, '4: missing url note');

// 5. missing item fields
$sparse = [
    'success' => true,
    'pagination' => ['total' => 1],
    'items' => [['title' => '僅有名稱']],
    'search_url' => 'https://example.com/a',
];
$text5 = $builder->build($sparse);
test_assert(strpos($text5, '僅有名稱') !== false, '5: title');
test_assert(strpos($text5, GeminiTourContextBuilder::DEPARTURE_DATE_LABEL . '未提供') !== false, '5: missing date');
test_assert(strpos($text5, '直售價：未提供') !== false, '5: missing price');
test_assert(strpos($text5, '出發地：未提供') !== false, '5: missing departure');

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

// detail_url + schLink when storeNo and crypto available
$detailCrypto = new LegacyStorefrontCrypto('testkey8');
$detailBuilder = new TourDetailUrlBuilder($detailCrypto);
$withLinks = [
    'success' => true,
    'pagination' => ['total' => 1],
    'items' => [
        [
            'title' => '連結測試行程',
            'tourDate' => '2026-06-15',
            'price' => 19900,
            'departureStr' => '台北',
            'couponNo' => 88001,
            'tourSeqNo' => 99001,
            'schLink' => 'https://drive.google.com/example-itinerary',
        ],
    ],
    'search_url' => 'https://bonusmee.com/view/cloud/cloud_store_tourdate.php?keyword=link',
];
$textLinks = $builder->build($withLinks, [
    'storeNo' => 6290,
    'detailUrlBuilder' => $detailBuilder,
    'maxItems' => 5,
]);
test_assert(strpos($textLinks, GeminiTourContextBuilder::DETAIL_URL_LABEL) !== false, 'links: detail line');
test_assert(strpos($textLinks, 'tourdate_dm.php') !== false, 'links: detail url path');
test_assert(strpos($textLinks, 'trsno=99001') !== false, 'links: plain trsno in url');
test_assert(strpos($textLinks, '行程表：https://drive.google.com/example-itinerary') !== false, 'links: schLink line');
test_assert(strpos($textLinks, GeminiTourContextBuilder::SEARCH_URL_LABEL) !== false && strpos($textLinks, 'cloud_store_tourdate.php') !== false, 'links: search_url preserved');

// schLink from later merged row when first row has no schLink
$mergeSchLink = [
    'success' => true,
    'pagination' => ['total' => 2],
    'items' => [
        [
            'title' => '合併行程表測試',
            'tourDate' => '2026-05-21',
            'price' => 10000,
            'departureStr' => '台北',
            'couponNo' => 90002,
            'tourSeqNo' => 99002,
        ],
        [
            'title' => '合併行程表測試',
            'tourDate' => '2026-05-28',
            'price' => 10000,
            'departureStr' => '台北',
            'couponNo' => 90002,
            'tourSeqNo' => 99002,
            'schLink' => 'https://example.com/merged-schedule.pdf',
        ],
    ],
    'search_url' => 'https://bonusmee.com/merge-sch',
];
$textMergeSch = $builder->build($mergeSchLink, [
    'storeNo' => 6290,
    'detailUrlBuilder' => $detailBuilder,
    'includeInstructions' => false,
]);
test_assert(strpos($textMergeSch, '行程表：https://example.com/merged-schedule.pdf') !== false, 'mergeSch: schLink from second row');

// exactly 4 dates — no suffix
$fourDates = [
    'success' => true,
    'pagination' => ['total' => 4],
    'items' => [
        ['title' => '四日行程', 'tourDate' => '2026-05-01', 'price' => 1, 'departureStr' => '台北', 'couponNo' => 90101],
        ['title' => '四日行程', 'tourDate' => '2026-05-02', 'price' => 1, 'departureStr' => '台北', 'couponNo' => 90101],
        ['title' => '四日行程', 'tourDate' => '2026-05-03', 'price' => 1, 'departureStr' => '台北', 'couponNo' => 90101],
        ['title' => '四日行程', 'tourDate' => '2026-05-04', 'price' => 1, 'departureStr' => '台北', 'couponNo' => 90101],
    ],
    'search_url' => 'https://bonusmee.com/four',
];
$textFour = $builder->build($fourDates, ['maxItems' => 5, 'includeInstructions' => false]);
test_assert(strpos($textFour, '05/01') !== false && strpos($textFour, '05/04') !== false, 'fourDates: all four shown');
test_assert(strpos($textFour, '...更多') === false, 'fourDates: no suffix at four');

$itemNoSch = $withLinks['items'][0];
$itemNoSch['schLink'] = '';
$noSchLink = [
    'success' => true,
    'pagination' => ['total' => 1],
    'items' => [$itemNoSch],
    'search_url' => $withLinks['search_url'],
];
$textNoSch = $builder->build($noSchLink, [
    'storeNo' => 6290,
    'detailUrlBuilder' => $detailBuilder,
    'includeInstructions' => false,
]);
test_assert(strpos($textNoSch, '行程表：https') === false, 'links: empty schLink omitted');

$itemNoSeq = $withLinks['items'][0];
unset($itemNoSeq['tourSeqNo']);
$missingSeq = [
    'success' => true,
    'pagination' => ['total' => 1],
    'items' => [$itemNoSeq],
    'search_url' => $withLinks['search_url'],
];
$textNoDetail = $builder->build($missingSeq, [
    'storeNo' => 6290,
    'detailUrlBuilder' => $detailBuilder,
    'includeInstructions' => false,
]);
test_assert(strpos($textNoDetail, GeminiTourContextBuilder::DETAIL_URL_LABEL) === false, 'links: missing tourSeqNo no detail');

// schLinks[] — A: couponNo 11849 style (4 links → show all URLs, no names)
$schLinksFour = [
    'success' => true,
    'pagination' => ['total' => 1],
    'items' => [
        [
            'title' => '四表行程',
            'tourDate' => '2026-06-01',
            'price' => 12000,
            'departureStr' => '台北',
            'couponNo' => 11849,
            'tourSeqNo' => 194481,
            'schLinks' => [
                ['schLinkName' => '一日遊行程表', 'schLink' => 'https://agt.tw/sch-1'],
                ['schLinkName' => '二日遊行程表', 'schLink' => 'https://agt.tw/sch-2'],
                ['schLinkName' => '三日遊行程表', 'schLink' => 'https://agt.tw/sch-3'],
                ['schLinkName' => '四日遊行程表', 'schLink' => 'https://agt.tw/sch-4'],
            ],
        ],
    ],
    'search_url' => 'https://bonusmee.com/sch-four',
];
$textSchFour = $builder->build($schLinksFour, ['includeInstructions' => false]);
test_assert(strpos($textSchFour, "   行程表：\n   1. https://agt.tw/sch-1") !== false, 'schLinks A: line 1 url only');
test_assert(strpos($textSchFour, '   2. https://agt.tw/sch-2') !== false, 'schLinks A: line 2 url only');
test_assert(strpos($textSchFour, '   3. https://agt.tw/sch-3') !== false, 'schLinks A: line 3 url only');
test_assert(strpos($textSchFour, '   4. https://agt.tw/sch-4') !== false, 'schLinks A: line 4 url only');
test_assert(strpos($textSchFour, '一日遊行程表') === false && strpos($textSchFour, '另有') === false, 'schLinks A: no names or 另有');
test_assert(strpos($textSchFour, 'https://bonusmee.com/sch-four') !== false, 'schLinks A: search_url preserved');

// schLinks[] — B: couponNo 11665 style (1 link)
$schLinksOne = [
    'success' => true,
    'pagination' => ['total' => 1],
    'items' => [
        [
            'title' => '單表行程',
            'tourDate' => '2026-07-01',
            'price' => 8800,
            'departureStr' => '高雄',
            'couponNo' => 11665,
            'tourSeqNo' => 183360,
            'schLinks' => [
                ['schLinkName' => '', 'schLink' => 'https://agt.tw/single-schedule'],
            ],
        ],
    ],
    'search_url' => 'https://bonusmee.com/sch-one',
];
$textSchOne = $builder->build($schLinksOne, ['includeInstructions' => false]);
test_assert(strpos($textSchOne, '   行程表：https://agt.tw/single-schedule') !== false, 'schLinks B: single url line');
test_assert(strpos($textSchOne, '另有') === false, 'schLinks B: no remaining suffix');

// schLinks[] — C: empty array
$schLinksEmpty = [
    'success' => true,
    'pagination' => ['total' => 1],
    'items' => [
        [
            'title' => '無表行程',
            'tourDate' => '2026-08-01',
            'price' => 5000,
            'departureStr' => '台中',
            'couponNo' => 99999,
            'schLinks' => [],
        ],
    ],
    'search_url' => 'https://bonusmee.com/sch-none',
];
$textSchEmpty = $builder->build($schLinksEmpty, ['includeInstructions' => false]);
test_assert(strpos($textSchEmpty, '行程表') === false, 'schLinks C: no schedule block');

// static formatter: single with name (name omitted in display)
$linesNamed = GeminiTourContextBuilder::formatSchLinksDisplayLines([
    'schLinks' => [['schLinkName' => 'PDF表', 'schLink' => 'https://example.com/p.pdf']],
]);
test_assert(count($linesNamed) === 1 && $linesNamed[0] === '行程表：https://example.com/p.pdf', 'formatSchLinks: url only single');
test_assert(strpos($linesNamed[0], 'PDF表') === false, 'formatSchLinks: no schLinkName');

// merge schLinks from second row
$mergeSchLinksArr = [
    'success' => true,
    'pagination' => ['total' => 2],
    'items' => [
        [
            'title' => '合併多表',
            'tourDate' => '2026-05-21',
            'price' => 10000,
            'departureStr' => '台北',
            'couponNo' => 90003,
            'schLinks' => [
                ['schLinkName' => 'A', 'schLink' => 'https://example.com/a'],
            ],
        ],
        [
            'title' => '合併多表',
            'tourDate' => '2026-05-28',
            'price' => 10000,
            'departureStr' => '台北',
            'couponNo' => 90003,
            'schLinks' => [
                ['schLinkName' => 'B', 'schLink' => 'https://example.com/b'],
            ],
        ],
    ],
    'search_url' => 'https://bonusmee.com/merge-schlinks',
];
$textMergeSchArr = $builder->build($mergeSchLinksArr, ['includeInstructions' => false]);
test_assert(strpos($textMergeSchArr, '   行程表：') !== false, 'mergeSchLinks: multi header');
test_assert(strpos($textMergeSchArr, 'https://example.com/a') !== false && strpos($textMergeSchArr, 'https://example.com/b') !== false, 'mergeSchLinks: both urls');

if ($failures === 0) {
    echo "OK: GeminiTourContextBuilder tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
