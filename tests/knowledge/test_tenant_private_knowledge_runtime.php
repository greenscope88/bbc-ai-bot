<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'LocalTenantPrivateKnowledgeProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'TenantPrivateKnowledgeRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'KnowledgeResponseComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'KnowledgeFallbackResolver.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'IndustrySharedKnowledgeRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'LocalIndustrySharedKnowledgeProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'HumanServiceResponseComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiRuntimeIntent.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntimeSelector.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$fixtureRoot = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'travel_b';
$emptyEmailFixtureRoot = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'travel_b_empty_email';
$missingProfileFixtureRoot = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'travel_b_missing_profile';

$composer = new KnowledgeResponseComposer(static function (string $poolKey, int $count): int {
    return 0;
});
$sharedFixtureRoot = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'shared';
$humanComposer = new HumanServiceResponseComposer(static function (string $poolKey, int $count): int {
    return 0;
});
$sharedRuntime = new IndustrySharedKnowledgeRuntime(null, $composer, static function (string $industryCode) use ($sharedFixtureRoot): LocalIndustrySharedKnowledgeProvider {
    return new LocalIndustrySharedKnowledgeProvider($industryCode, $sharedFixtureRoot . DIRECTORY_SEPARATOR . $industryCode);
});
$sharedFallbackResolver = new KnowledgeFallbackResolver($humanComposer, $sharedRuntime);
$provider = new LocalTenantPrivateKnowledgeProvider('5f99b8d665e8444d', $fixtureRoot);
$runtime = new TenantPrivateKnowledgeRuntime($provider, null, $composer);

// 1. phone lookup
$phone = $runtime->handle('請問客服電話');
test_assert(($phone['grounded'] ?? false) === true, '1: phone grounded');
test_assert(strpos($phone['reply_text'], '07-5224856') !== false, '1: phone value');
test_assert(strpos($phone['reply_text'], '旅行蜜優惠客服電話為：') !== false, '1: phone label with company name');
test_assert(strpos($phone['reply_text'], '✈️') !== false, '1: travel consultant closing');

// 2. address lookup
$address = $runtime->handle('請問公司地址');
test_assert(($address['grounded'] ?? false) === true, '2: address grounded');
test_assert(strpos($address['reply_text'], '高雄市三民區綏遠二街101號') !== false, '2: address value');
test_assert(strpos($address['reply_text'], '旅行蜜優惠地址如下：') !== false, '2: address label');

// 3. website lookup
$website = $runtime->handle('請問官網');
test_assert(($website['grounded'] ?? false) === true, '3: website grounded');
test_assert(strpos($website['reply_text'], 'https://www.travel-b.example.com') !== false, '3: website value');
test_assert(strpos($website['reply_text'], '官方網站為：') !== false, '3: website label');

// 4. email lookup
$email = $runtime->handle('請問Email');
test_assert(($email['grounded'] ?? false) === true, '4: email grounded');
test_assert(strpos($email['reply_text'], 'service@travel-b.example.com') !== false, '4: email value');
test_assert(strpos($email['reply_text'], '客服信箱為：') !== false, '4: email label');

// 5. business_hours lookup
$hours = $runtime->handle('請問營業時間');
test_assert(($hours['grounded'] ?? false) === true, '5: business_hours grounded');
test_assert(strpos($hours['reply_text'], '週一至週五') !== false, '5: business_hours value');
test_assert(strpos($hours['reply_text'], '09:00-18:00') !== false, '5: business_hours time');

// 6. line_official lookup
$line = $runtime->handle('請問官方LINE');
test_assert(($line['grounded'] ?? false) === true, '6: line_official grounded');
test_assert(strpos($line['reply_text'], '@travel_b') !== false, '6: line_official value');
test_assert(strpos($line['reply_text'], '官方 LINE 為：') !== false, '6: line_official label');

// 7. summary lookup
$summary = $runtime->handle('你們是做什麼的');
test_assert(($summary['grounded'] ?? false) === true, '7: summary grounded');
test_assert(strpos($summary['reply_text'], '旅行蜜優惠主要提供：') !== false, '7: summary label');
test_assert(strpos($summary['reply_text'], '護照及簽證代辦等服務') !== false, '7: summary value');

// 8. empty field fallback
$emptyEmailProvider = new LocalTenantPrivateKnowledgeProvider('5f99b8d665e8444d', $emptyEmailFixtureRoot);
$emptyEmailRuntime = new TenantPrivateKnowledgeRuntime($emptyEmailProvider, null, $composer);
$emptyEmail = $emptyEmailRuntime->handle('請問客服信箱');
test_assert(($emptyEmail['grounded'] ?? false) === false, '8: empty field not grounded');
test_assert(($emptyEmail['final_route'] ?? '') === 'phase_9c2b2a_knowledge_runtime_empty_field', '8: empty field route');
test_assert(strpos($emptyEmail['reply_text'], '目前尚未提供相關資訊') !== false, '8: empty field fallback text');
test_assert(strpos($emptyEmail['reply_text'], '請與客服人員聯繫') !== false, '8: empty field contact text');

// 9. missing company_profile fallback
$missingProfileProvider = new LocalTenantPrivateKnowledgeProvider('5f99b8d665e8444d', $missingProfileFixtureRoot);
$missingProfileRuntime = new TenantPrivateKnowledgeRuntime($missingProfileProvider, null, $composer);
$missingProfile = $missingProfileRuntime->handle('請問客服電話');
test_assert(($missingProfile['grounded'] ?? false) === false, '9: missing profile not grounded');
test_assert(($missingProfile['final_route'] ?? '') === 'phase_9c2b2a_knowledge_runtime_missing_profile', '9: missing profile route');
test_assert(strpos($missingProfile['reply_text'], '目前尚未提供相關資訊') !== false, '9: missing profile fallback text');

// 10. AIU Runtime Intent canonical values
test_assert(
    AiRuntimeIntent::KNOWLEDGE_QUERY === 'knowledge_query',
    '10: knowledge_query constant'
);

// 11–14. service_qa acceptance cases
$qaCases = [
    '搭機前多久到機場？',
    '國際線多久前報到？',
    '搭飛機前多久要到機場？',
    '請問搭機報到時間？',
];
foreach ($qaCases as $index => $question) {
    $caseNumber = 11 + $index;
    $qaResult = $runtime->handle($question);
    test_assert(($qaResult['grounded'] ?? false) === true, "{$caseNumber}: service_qa grounded");
    test_assert(($qaResult['query_type'] ?? '') === TenantPrivateKnowledgeRuntime::QUERY_TYPE_SERVICE_QA, "{$caseNumber}: service_qa query_type");
    test_assert(($qaResult['final_route'] ?? '') === 'phase_9c2b3_qa_knowledge_runtime', "{$caseNumber}: service_qa route");
    test_assert(strpos($qaResult['reply_text'], '飛機起飛前二個小時前') !== false, "{$caseNumber}: grounded fact present");
    test_assert(strpos($qaResult['reply_text'], '您好') !== false, "{$caseNumber}: persona opening present");
    test_assert($qaResult['reply_text'] !== '飛機起飛前二個小時前', "{$caseNumber}: not verbatim answer only");
}

// 15–17. no QA match -> human service fallback
$noMatchFixtureRoot = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'travel_b_no_qa_match';
$noMatchProvider = new LocalTenantPrivateKnowledgeProvider('5f99b8d665e8444d', $noMatchFixtureRoot);
$noMatchRuntime = new TenantPrivateKnowledgeRuntime(
    $noMatchProvider,
    null,
    $composer,
    null,
    null,
    null,
    null,
    null,
    $sharedFallbackResolver
);
$fallbackCases = [
    15 => '可以帶寵物上飛機嗎？',
    16 => '飛機上可以帶液體嗎？',
];
foreach ($fallbackCases as $caseNumber => $question) {
    $noMatch = $noMatchRuntime->handle($question, ['company_name' => '旅行蜜優惠', 'tenant_key' => 'travel_b', 'industry_code' => 'travel']);
    test_assert(($noMatch['grounded'] ?? true) === false, "{$caseNumber}: fallback not grounded");
    test_assert(($noMatch['final_route'] ?? '') === 'phase_9c2b3_knowledge_human_service', "{$caseNumber}: human service route");
    test_assert(strpos($noMatch['reply_text'], '人工客服') !== false, "{$caseNumber}: human service reply");
    test_assert(strpos($noMatch['reply_text'], '飛機起飛前二個小時前') === false, "{$caseNumber}: no hallucinated QA fact");
}

$baggageShared = $noMatchRuntime->handle('行李超重怎麼辦？', ['company_name' => '旅行蜜優惠', 'tenant_key' => 'travel_b', 'industry_code' => 'travel']);
test_assert(($baggageShared['grounded'] ?? false) === true, '17: shared baggage grounded');
test_assert(($baggageShared['final_route'] ?? '') === 'phase_9c2d_industry_shared_runtime', '17: shared baggage route');
test_assert(strpos($baggageShared['reply_text'], '依航空公司規定加購行李額度。') !== false, '17: shared baggage content');

// 18–22. special_prices acceptance cases
$priceCases = [
    18 => ['question' => '請問護照費用多少？', 'item' => '護照代辦', 'price' => '1600'],
    19 => ['question' => '護照代辦多少錢？', 'item' => '護照代辦', 'price' => '1600'],
    20 => ['question' => '台胞證費用多少？', 'item' => '台胞證', 'price' => '1900'],
    21 => ['question' => '泰國簽證多少錢？', 'item' => '泰國簽證', 'price' => '1200'],
    22 => ['question' => '日本簽證多少錢？', 'fallback' => true],
];
foreach ($priceCases as $caseNumber => $case) {
    $priceResult = $runtime->handle($case['question']);
    if (!empty($case['fallback'])) {
        test_assert(($priceResult['grounded'] ?? true) === false, "{$caseNumber}: japan visa fallback not grounded");
        test_assert(($priceResult['final_route'] ?? '') === 'phase_9c2b3_knowledge_human_service', "{$caseNumber}: japan visa human service route");
        test_assert(strpos($priceResult['reply_text'], '1200') === false, "{$caseNumber}: no thailand visa price hallucination");
        continue;
    }

    test_assert(($priceResult['grounded'] ?? false) === true, "{$caseNumber}: special_prices grounded");
    test_assert(($priceResult['query_type'] ?? '') === TenantPrivateKnowledgeRuntime::QUERY_TYPE_SPECIAL_PRICES, "{$caseNumber}: special_prices query_type");
    test_assert(($priceResult['final_route'] ?? '') === 'phase_9c2b4_special_prices_runtime', "{$caseNumber}: special_prices route");
    test_assert(strpos($priceResult['reply_text'], $case['item']) !== false, "{$caseNumber}: item_name present");
    test_assert(strpos($priceResult['reply_text'], $case['price']) !== false, "{$caseNumber}: price present");
}

// 23–25. regression after special_prices
$phoneAgain = $runtime->handle('請問客服電話');
test_assert(strpos($phoneAgain['reply_text'], '07-5224856') !== false, '23: phone regression');

$qaAgain = $runtime->handle('國際線多久前報到？');
test_assert(strpos($qaAgain['reply_text'], '飛機起飛前二個小時前') !== false, '24: service_qa regression');

$petFallback = $noMatchRuntime->handle('可以帶寵物上飛機嗎？', ['company_name' => '旅行蜜優惠']);
test_assert(($petFallback['final_route'] ?? '') === 'phase_9c2b3_knowledge_human_service', '25: pet fallback regression');

// 26–36. service_items acceptance cases
$serviceItemCases = [
    26 => [
        'question' => '請問你們有哪些服務？',
        'contains' => ['機票代訂', '代訂房', '租車服務', '代辦簽證', '企業員工旅遊'],
    ],
    27 => [
        'question' => '你們有代訂房嗎？',
        'contains' => ['代訂房'],
    ],
    28 => [
        'question' => '你們有租車嗎？',
        'contains' => ['租車服務'],
    ],
    29 => [
        'question' => '你們有辦簽證嗎？',
        'contains' => ['代辦簽證'],
    ],
    30 => [
        'question' => '你們有企業旅遊嗎？',
        'contains' => ['企業員工旅遊'],
    ],
    31 => [
        'question' => '你們有哪些代辦服務？',
        'contains' => ['代辦簽證', '機票代訂', '代訂房'],
    ],
    32 => [
        'question' => '你們有美國簽證嗎？',
        'fallback' => true,
    ],
];
foreach ($serviceItemCases as $caseNumber => $case) {
    $serviceResult = $runtime->handle($case['question']);
    if (!empty($case['fallback'])) {
        test_assert(($serviceResult['grounded'] ?? true) === false, "{$caseNumber}: service_items fallback not grounded");
        test_assert(($serviceResult['final_route'] ?? '') === 'phase_9c2b3_knowledge_human_service', "{$caseNumber}: service_items human service route");
        test_assert(strpos($serviceResult['reply_text'], '代辦簽證') === false, "{$caseNumber}: no visa item hallucination");
        continue;
    }

    test_assert(($serviceResult['grounded'] ?? false) === true, "{$caseNumber}: service_items grounded");
    test_assert(($serviceResult['query_type'] ?? '') === TenantPrivateKnowledgeRuntime::QUERY_TYPE_SERVICE_ITEMS, "{$caseNumber}: service_items query_type");
    test_assert(($serviceResult['final_route'] ?? '') === 'phase_9c2b5_service_items_runtime', "{$caseNumber}: service_items route");
    foreach ($case['contains'] as $needle) {
        test_assert(strpos($serviceResult['reply_text'], $needle) !== false, "{$caseNumber}: contains {$needle}");
    }
}

$emptyItemsFixtureRoot = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'travel_b_empty_service_items';
$emptyItemsProvider = new LocalTenantPrivateKnowledgeProvider('5f99b8d665e8444d', $emptyItemsFixtureRoot);
$emptyItemsRuntime = new TenantPrivateKnowledgeRuntime($emptyItemsProvider, null, $composer);
$emptyItemsResult = $emptyItemsRuntime->handle('請問你們有哪些服務？');
test_assert(($emptyItemsResult['grounded'] ?? true) === false, '33: empty service_items fallback not grounded');
test_assert(($emptyItemsResult['final_route'] ?? '') === 'phase_9c2b3_knowledge_human_service', '33: empty service_items human service route');

// 34–40. regression after service_items
test_assert(strpos($runtime->handle('請問客服電話')['reply_text'], '07-5224856') !== false, '34: phone regression');
test_assert(strpos($runtime->handle('請問公司地址')['reply_text'], '高雄市三民區綏遠二街101號') !== false, '35: address regression');
test_assert(strpos($runtime->handle('國際線多久前報到？')['reply_text'], '飛機起飛前二個小時前') !== false, '36: service_qa regression');
test_assert(strpos($runtime->handle('請問護照費用多少？')['reply_text'], '1600') !== false, '37: special_prices regression');
$japanPrice = $runtime->handle('日本簽證多少錢？');
test_assert(($japanPrice['final_route'] ?? '') === 'phase_9c2b3_knowledge_human_service', '38: japan visa price fallback regression');
$petFallbackAfterItems = $noMatchRuntime->handle('可以帶寵物上飛機嗎？', ['company_name' => '旅行蜜優惠']);
test_assert(($petFallbackAfterItems['final_route'] ?? '') === 'phase_9c2b3_knowledge_human_service', '39: pet fallback regression');
test_assert(
    AiRuntimeIntent::PRODUCT_SEARCH === 'product_search',
    '40: product_search constant'
);

// 41–46. external_product_links acceptance cases
$linkCases = [
    41 => [
        'question' => '請問有哪些旅遊商品入口？',
        'contains' => ['東京旅遊', 'dayitravel.tourcenter.com.tw'],
    ],
    42 => [
        'question' => '請問有哪些商品連結？',
        'contains' => ['東京旅遊', 'dayitravel.tourcenter.com.tw'],
    ],
    43 => [
        'question' => '東京旅遊',
        'contains' => ['東京旅遊', 'dayitravel.tourcenter.com.tw'],
    ],
    44 => [
        'question' => '請問有東京行程連結嗎？',
        'contains' => ['東京旅遊', 'dayitravel.tourcenter.com.tw'],
    ],
    45 => [
        'question' => '請問有北海道商品連結嗎？',
        'fallback' => true,
    ],
];
foreach ($linkCases as $caseNumber => $case) {
    $linkResult = $runtime->handle($case['question']);
    if (!empty($case['fallback'])) {
        test_assert(($linkResult['grounded'] ?? true) === false, "{$caseNumber}: external_product_links fallback not grounded");
        test_assert(($linkResult['final_route'] ?? '') === 'phase_9c2b3_knowledge_human_service', "{$caseNumber}: external_product_links human service route");
        test_assert(strpos($linkResult['reply_text'], 'dayitravel.tourcenter.com.tw') === false, "{$caseNumber}: no hallucinated product link url");
        continue;
    }

    test_assert(($linkResult['grounded'] ?? false) === true, "{$caseNumber}: external_product_links grounded");
    test_assert(
        ($linkResult['query_type'] ?? '') === TenantPrivateKnowledgeRuntime::QUERY_TYPE_EXTERNAL_PRODUCT_LINKS,
        "{$caseNumber}: external_product_links query_type"
    );
    test_assert(($linkResult['final_route'] ?? '') === 'phase_9c2b6_external_product_links_runtime', "{$caseNumber}: external_product_links route");
    foreach ($case['contains'] as $needle) {
        test_assert(strpos($linkResult['reply_text'], $needle) !== false, "{$caseNumber}: contains {$needle}");
    }
}

$emptyLinksFixtureRoot = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'travel_b_empty_external_product_links';
$emptyLinksProvider = new LocalTenantPrivateKnowledgeProvider('5f99b8d665e8444d', $emptyLinksFixtureRoot);
$emptyLinksRuntime = new TenantPrivateKnowledgeRuntime($emptyLinksProvider, null, $composer);
$emptyLinksResult = $emptyLinksRuntime->handle('請問有哪些商品連結？');
test_assert(($emptyLinksResult['grounded'] ?? true) === false, '46: empty external_product_links fallback not grounded');
test_assert(($emptyLinksResult['final_route'] ?? '') === 'phase_9c2b3_knowledge_human_service', '46: empty external_product_links human service route');
test_assert(strpos($emptyLinksResult['reply_text'], 'dayitravel.tourcenter.com.tw') === false, '46: no hallucinated product link url');

// 47–54. regression after external_product_links
test_assert(strpos($runtime->handle('請問客服電話')['reply_text'], '07-5224856') !== false, '47: phone regression');
test_assert(strpos($runtime->handle('國際線多久前報到？')['reply_text'], '飛機起飛前二個小時前') !== false, '48: service_qa regression');
test_assert(strpos($runtime->handle('請問護照費用多少？')['reply_text'], '1600') !== false, '49: special_prices regression');
test_assert(strpos($runtime->handle('請問你們有哪些服務？')['reply_text'], '代訂房') !== false, '50: service_items list regression');
test_assert(strpos($runtime->handle('你們有代訂房嗎？')['reply_text'], '代訂房') !== false, '51: service_items single regression');
$japanPriceAfterLinks = $runtime->handle('日本簽證多少錢？');
test_assert(($japanPriceAfterLinks['final_route'] ?? '') === 'phase_9c2b3_knowledge_human_service', '52: japan visa price fallback regression');
$petFallbackAfterLinks = $noMatchRuntime->handle('可以帶寵物上飛機嗎？', ['company_name' => '旅行蜜優惠']);
test_assert(($petFallbackAfterLinks['final_route'] ?? '') === 'phase_9c2b3_knowledge_human_service', '53: pet fallback regression');
test_assert(
    AiRuntimeIntent::HUMAN_SERVICE_REQUEST === 'human_service_request',
    '54: human_service_request constant'
);

// 55–59. industry shared runtime acceptance (tenant miss -> L2 shared / human)
$sharedAcceptanceRuntime = new TenantPrivateKnowledgeRuntime(
    $noMatchProvider,
    null,
    $composer,
    null,
    null,
    null,
    null,
    null,
    $sharedFallbackResolver
);
$sharedCase1 = $sharedAcceptanceRuntime->handle('BATS測試國際線多久前到機場', ['tenant_key' => 'travel_b', 'industry_code' => 'travel']);
test_assert(($sharedCase1['grounded'] ?? false) === true, '55: shared airport timing grounded');
test_assert(($sharedCase1['final_route'] ?? '') === 'phase_9c2d_industry_shared_runtime', '55: shared airport timing route');
test_assert(strpos($sharedCase1['reply_text'], '建議於航班起飛前二至三小時抵達機場辦理報到與安檢。') !== false, '55: shared airport timing content');

$sharedCase3 = $sharedAcceptanceRuntime->handle('BATS測試來不及搭機怎麼退票', ['tenant_key' => 'travel_b', 'industry_code' => 'travel']);
test_assert(($sharedCase3['final_route'] ?? '') === 'phase_9c2d_industry_shared_runtime', '56: shared refund route');
test_assert(strpos($sharedCase3['reply_text'], '若透過旅行社購票') !== false, '56: shared refund content');

$tenantPriority = $runtime->handle('國際線多久前報到？');
test_assert(($tenantPriority['final_route'] ?? '') === 'phase_9c2b3_qa_knowledge_runtime', '57: tenant QA priority route');
test_assert(strpos($tenantPriority['reply_text'], '飛機起飛前二個小時前') !== false, '57: tenant QA priority content');
test_assert(strpos($tenantPriority['reply_text'], '建議於航班起飛前二至三小時') === false, '57: no shared downgrade');

$sharedCase5 = $sharedAcceptanceRuntime->handle('BATS測試可以帶寵物上飛機嗎', ['tenant_key' => 'travel_b', 'company_name' => '旅行蜜優惠', 'industry_code' => 'travel']);
test_assert(($sharedCase5['final_route'] ?? '') === 'phase_9c2b3_knowledge_human_service', '58: pet human service route');
test_assert(($sharedCase5['fallback_layer'] ?? '') === 'human_service', '58: pet human service layer');

test_assert(strpos($runtime->handle('請問客服電話')['reply_text'], '07-5224856') !== false, '59: phone regression after shared');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_tenant_private_knowledge_runtime (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
