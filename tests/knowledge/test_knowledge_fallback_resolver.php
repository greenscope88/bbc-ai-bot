<?php

declare(strict_types=1);



require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'

    . DIRECTORY_SEPARATOR . 'KnowledgeFallbackResolver.php';

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'

    . DIRECTORY_SEPARATOR . 'HumanServiceResponseComposer.php';

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

$humanComposer = new HumanServiceResponseComposer(static function (string $poolKey, int $count): int {

    return 0;

});

$sharedRuntime = new IndustrySharedKnowledgeRuntime(null, $composer, static function (string $industryCode) use ($sharedFixtureRoot): LocalIndustrySharedKnowledgeProvider {

    return new LocalIndustrySharedKnowledgeProvider($industryCode, $sharedFixtureRoot . DIRECTORY_SEPARATOR . $industryCode);

});

$resolver = new KnowledgeFallbackResolver($humanComposer, $sharedRuntime);



$result = $resolver->resolve('找不到答案的問題', 'travel_b', '旅行蜜優惠');

test_assert(($result['grounded'] ?? true) === false, 'fallback: not grounded');

test_assert(($result['fallback_layer'] ?? '') === 'human_service', 'fallback: human_service layer');

test_assert(($result['final_route'] ?? '') === 'phase_9c2b3_knowledge_human_service', 'fallback: final_route');

test_assert(strpos((string) ($result['reply_text'] ?? ''), '人工客服') !== false, 'fallback: human service text');

test_assert(strpos((string) ($result['reply_text'] ?? ''), '旅行蜜優惠人工客服') !== false, 'fallback: company name in body');



$sharedCase1 = $resolver->resolve('BATS測試國際線多久前到機場', 'travel_b', '旅行蜜優惠', 'travel');

test_assert(($sharedCase1['grounded'] ?? false) === true, 'shared case1: grounded');

test_assert(($sharedCase1['fallback_layer'] ?? '') === 'industry_shared', 'shared case1: layer');

test_assert(($sharedCase1['final_route'] ?? '') === 'phase_9c2d_industry_shared_runtime', 'shared case1: route');

test_assert(strpos((string) ($sharedCase1['reply_text'] ?? ''), '建議於航班起飛前二至三小時抵達機場辦理報到與安檢。') !== false, 'shared case1: content');



$sharedCase2 = $resolver->resolve('BATS測試行李超重怎麼辦', 'travel_b', '旅行蜜優惠', 'travel');

test_assert(($sharedCase2['final_route'] ?? '') === 'phase_9c2d_industry_shared_runtime', 'shared case2: route');

test_assert(strpos((string) ($sharedCase2['reply_text'] ?? ''), '依航空公司規定加購行李額度。') !== false, 'shared case2: content');



$sharedCase3 = $resolver->resolve('BATS測試來不及搭機怎麼退票', 'travel_b', '旅行蜜優惠', 'travel');

test_assert(($sharedCase3['final_route'] ?? '') === 'phase_9c2d_industry_shared_runtime', 'shared case3: route');

test_assert(strpos((string) ($sharedCase3['reply_text'] ?? ''), '若透過旅行社購票') !== false, 'shared case3: content');



$sharedCase5 = $resolver->resolve('BATS測試可以帶寵物上飛機嗎', 'travel_b', '旅行蜜優惠', 'travel');

test_assert(($sharedCase5['final_route'] ?? '') === 'phase_9c2b3_knowledge_human_service', 'shared case5: human route');

test_assert(($sharedCase5['fallback_layer'] ?? '') === 'human_service', 'shared case5: human layer');



if ($failures === 0) {

    fwrite(STDOUT, "OK: test_knowledge_fallback_resolver (all passed)\n");

    exit(0);

}



fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");

exit(1);

