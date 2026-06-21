<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'ExternalProductLinkMatcher.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$items = [
    ['link_id' => 'link_tokyo', 'name' => '東京旅遊', 'url' => 'dayitravel.tourcenter.com.tw', 'enabled' => true, 'sort_order' => 10],
];

$matcher = new ExternalProductLinkMatcher();

$listResult = $matcher->match('請問有哪些旅遊商品入口？', $items);
test_assert($listResult !== null, 'case1: list match found');
test_assert(($listResult['match_type'] ?? '') === 'list', 'case1: list match type');
test_assert(strpos($listResult['grounded_fact'] ?? '', '東京旅遊') !== false, 'case1: name present');
test_assert(strpos($listResult['grounded_fact'] ?? '', 'dayitravel.tourcenter.com.tw') !== false, 'case1: url present');

$listResult2 = $matcher->match('請問有哪些商品連結？', $items);
test_assert($listResult2 !== null, 'case2: product links list match found');

$tokyoExact = $matcher->match('東京旅遊', $items);
test_assert($tokyoExact !== null, 'case3: tokyo exact match found');
test_assert(strpos($tokyoExact['grounded_fact'] ?? '', 'dayitravel.tourcenter.com.tw') !== false, 'case3: url present');

$tokyoIntent = $matcher->match('請問有東京行程連結嗎？', $items);
test_assert($tokyoIntent !== null, 'case4: tokyo intent match found');
test_assert(strpos($tokyoIntent['grounded_fact'] ?? '', 'dayitravel.tourcenter.com.tw') !== false, 'case4: url present');

$hokkaidoResult = $matcher->match('請問有北海道商品連結嗎？', $items);
test_assert($hokkaidoResult === null, 'case5: hokkaido no match');

$emptyResult = $matcher->match('請問有哪些商品連結？', []);
test_assert($emptyResult === null, 'case6: empty items no match');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_external_product_link_matcher (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
