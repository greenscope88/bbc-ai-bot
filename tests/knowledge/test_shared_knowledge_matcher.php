<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'SharedKnowledgeMatcher.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$fixturePath = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'shared'
    . DIRECTORY_SEPARATOR . 'travel' . DIRECTORY_SEPARATOR . 'faq.json';
$raw = file_get_contents($fixturePath);
$document = is_string($raw) ? json_decode($raw, true) : null;
$items = is_array($document) && is_array($document['items'] ?? null) ? $document['items'] : [];

$matcher = new SharedKnowledgeMatcher();

$case1 = $matcher->match('BATS測試國際線多久前到機場', $items);
test_assert($case1 !== null, 'case1: airport timing match found');
test_assert(strpos($case1['grounded_fact'] ?? '', '建議於航班起飛前二至三小時抵達機場辦理報到與安檢。') !== false, 'case1: grounded fact');

$case2 = $matcher->match('BATS測試行李超重怎麼辦', $items);
test_assert($case2 !== null, 'case2: baggage overweight match found');
test_assert(($case2['grounded_fact'] ?? '') === '依航空公司規定加購行李額度。', 'case2: grounded fact');

$case3 = $matcher->match('BATS測試來不及搭機怎麼退票', $items);
test_assert($case3 !== null, 'case3: refund match found');
test_assert(strpos($case3['grounded_fact'] ?? '', '若透過旅行社購票') !== false, 'case3: refund content');
test_assert(($case3['item_id'] ?? '') === 'travel-faq-refund-missed-flight', 'case3: refund item_id');

$case5 = $matcher->match('BATS測試可以帶寵物上飛機嗎', $items);
test_assert($case5 === null, 'case5: pet question no shared match');

$emptyResult = $matcher->match('行李超重怎麼辦', []);
test_assert($emptyResult === null, 'empty items no match');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_shared_knowledge_matcher (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
