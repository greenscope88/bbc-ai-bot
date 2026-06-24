<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'IndustrySharedKnowledgeRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'LocalIndustrySharedKnowledgeProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'KnowledgeResponseComposer.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$sharedFixtureRoot = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'shared';
$composer = new KnowledgeResponseComposer(static function (string $poolKey, int $count): int {
    return 0;
});
$runtime = new IndustrySharedKnowledgeRuntime(null, $composer, static function (string $industryCode) use ($sharedFixtureRoot): LocalIndustrySharedKnowledgeProvider {
    return new LocalIndustrySharedKnowledgeProvider($industryCode, $sharedFixtureRoot . DIRECTORY_SEPARATOR . $industryCode);
});

$case1 = $runtime->handle('BATS測試國際線多久前到機場', 'travel');
test_assert($case1 !== null, 'case1: shared runtime hit');
test_assert(($case1['grounded'] ?? false) === true, 'case1: grounded');
test_assert(($case1['final_route'] ?? '') === 'phase_9c2d_industry_shared_runtime', 'case1: route');
test_assert(strpos($case1['reply_text'] ?? '', '建議於航班起飛前二至三小時抵達機場辦理報到與安檢。') !== false, 'case1: reply content');

$case2 = $runtime->handle('BATS測試行李超重怎麼辦', 'travel');
test_assert($case2 !== null, 'case2: shared runtime hit');
test_assert(strpos($case2['reply_text'] ?? '', '依航空公司規定加購行李額度。') !== false, 'case2: reply content');

$case3 = $runtime->handle('BATS測試來不及搭機怎麼退票', 'travel');
test_assert($case3 !== null, 'case3: shared runtime hit');
test_assert(strpos($case3['reply_text'] ?? '', '若透過旅行社購票') !== false, 'case3: refund reply content');
test_assert(($case3['shared_item_id'] ?? '') === 'travel-faq-refund-missed-flight', 'case3: shared_item_id');

$case5 = $runtime->handle('BATS測試可以帶寵物上飛機嗎', 'travel');
test_assert($case5 === null, 'case5: shared runtime miss');

$unknownIndustry = $runtime->handle('行李超重怎麼辦', '');
test_assert($unknownIndustry === null, 'empty industry_code miss');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_industry_shared_knowledge_runtime (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
