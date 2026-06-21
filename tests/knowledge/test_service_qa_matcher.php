<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'ServiceQaMatcher.php';

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
    [
        'qa_id' => 'qa_checkin_intl',
        'question' => '搭機前往國外，航空公司櫃台要多久前辦理報到',
        'answer' => '飛機起飛前二個小時前',
        'category' => '搭機須知',
        'enabled' => true,
        'sort_order' => 10,
    ],
    [
        'qa_id' => 'qa_disabled',
        'question' => '已停用項目',
        'answer' => '不應命中',
        'enabled' => false,
    ],
];

$matcher = new ServiceQaMatcher();
$expectedFact = '飛機起飛前二個小時前';

$passCases = [
    '搭機前多久到機場？',
    '國際線多久前報到？',
    '搭飛機前多久要到機場？',
    '請問搭機報到時間？',
];

foreach ($passCases as $index => $question) {
    $caseNumber = $index + 1;
    $result = $matcher->match($question, $items);
    test_assert($result !== null, "case{$caseNumber}: match found");
    test_assert(($result['grounded_fact'] ?? '') === $expectedFact, "case{$caseNumber}: grounded fact");
    test_assert(($result['qa_id'] ?? '') === 'qa_checkin_intl', "case{$caseNumber}: qa_id");
}

$failCases = [
    '可以帶寵物上飛機嗎？',
    '飛機上可以帶液體嗎？',
    '行李超重怎麼辦？',
];

foreach ($failCases as $index => $question) {
    $caseNumber = $index + 5;
    $result = $matcher->match($question, $items);
    test_assert($result === null, "case{$caseNumber}: no match");
}

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_service_qa_matcher (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
