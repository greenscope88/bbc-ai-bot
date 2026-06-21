<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'LocalTenantPrivateKnowledgeProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'TenantPrivateKnowledgeRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'KnowledgeResponseComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'KnowledgeIntentDetector.php';

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

// 10. product_search regression (intent detector only)
$detector = new KnowledgeIntentDetector();
test_assert(
    ($detector->detect('北海道7月')['intent_type'] ?? '') === KnowledgeIntentDetector::INTENT_PRODUCT_SEARCH,
    '10: product_search regression'
);
test_assert(
    ($detector->detect('請問客服電話')['intent_type'] ?? '') === KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY,
    '10: knowledge_query still detected'
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
$noMatchRuntime = new TenantPrivateKnowledgeRuntime($noMatchProvider, null, $composer);
$fallbackCases = [
    15 => '可以帶寵物上飛機嗎？',
    16 => '飛機上可以帶液體嗎？',
    17 => '行李超重怎麼辦？',
];
foreach ($fallbackCases as $caseNumber => $question) {
    $noMatch = $noMatchRuntime->handle($question, ['company_name' => '旅行蜜優惠']);
    test_assert(($noMatch['grounded'] ?? true) === false, "{$caseNumber}: fallback not grounded");
    test_assert(($noMatch['final_route'] ?? '') === 'phase_9c2b3_knowledge_human_service', "{$caseNumber}: human service route");
    test_assert(strpos($noMatch['reply_text'], '人工客服') !== false, "{$caseNumber}: human service reply");
    test_assert(strpos($noMatch['reply_text'], '飛機起飛前二個小時前') === false, "{$caseNumber}: no hallucinated QA fact");
}

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_tenant_private_knowledge_runtime (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
