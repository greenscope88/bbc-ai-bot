<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'ServiceItemsMatcher.php';

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
    ['service_id' => 'svc_1', 'name' => '機票代訂', 'enabled' => true, 'sort_order' => 10],
    ['service_id' => 'svc_2', 'name' => '代訂房', 'enabled' => true, 'sort_order' => 20],
    ['service_id' => 'svc_3', 'name' => '租車服務', 'enabled' => true, 'sort_order' => 30],
    ['service_id' => 'svc_4', 'name' => '代辦簽證', 'enabled' => true, 'sort_order' => 40],
    ['service_id' => 'svc_5', 'name' => '企業員工旅遊', 'enabled' => true, 'sort_order' => 50],
];

$matcher = new ServiceItemsMatcher();

$listResult = $matcher->match('請問你們有哪些服務？', $items);
test_assert($listResult !== null, 'list: match found');
test_assert(count($listResult['service_names'] ?? []) === 5, 'list: all services listed');

$singleCases = [
    ['question' => '你們有代訂房嗎？', 'name' => '代訂房'],
    ['question' => '你們有租車嗎？', 'name' => '租車服務'],
    ['question' => '你們有辦簽證嗎？', 'name' => '代辦簽證'],
    ['question' => '你們有企業旅遊嗎？', 'name' => '企業員工旅遊'],
];
foreach ($singleCases as $index => $case) {
    $caseNumber = $index + 1;
    $result = $matcher->match($case['question'], $items);
    test_assert($result !== null, "case{$caseNumber}: single match found");
    test_assert(($result['service_names'][0] ?? '') === $case['name'], "case{$caseNumber}: service name");
}

$agencyResult = $matcher->match('你們有哪些代辦服務？', $items);
test_assert($agencyResult !== null, 'case5: agency list match found');
foreach (['代辦簽證', '機票代訂', '代訂房'] as $needle) {
    test_assert(in_array($needle, $agencyResult['service_names'] ?? [], true), "case5: contains {$needle}");
}

$usVisaResult = $matcher->match('你們有美國簽證嗎？', $items);
test_assert($usVisaResult === null, 'case6: us visa no match');

$emptyResult = $matcher->match('請問你們有哪些服務？', []);
test_assert($emptyResult === null, 'empty items no match');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_service_items_matcher (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
