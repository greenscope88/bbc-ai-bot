<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'SpecialPriceMatcher.php';

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
        'price_id' => 'price_1',
        'item_name' => '護照代辦',
        'price_amount' => 1600,
        'enabled' => true,
        'sort_order' => 10,
    ],
    [
        'price_id' => 'price_2',
        'item_name' => '台胞證',
        'price_amount' => 1900,
        'enabled' => true,
        'sort_order' => 20,
    ],
    [
        'price_id' => 'price_3',
        'item_name' => '泰國簽證',
        'price_amount' => 1200,
        'enabled' => true,
        'sort_order' => 30,
    ],
    [
        'price_id' => 'price_disabled',
        'item_name' => '已停用項目',
        'price_amount' => 999,
        'enabled' => false,
    ],
];

$matcher = new SpecialPriceMatcher();

$passCases = [
    ['question' => '請問護照費用多少？', 'item_name' => '護照代辦', 'price' => '1600'],
    ['question' => '護照代辦多少錢？', 'item_name' => '護照代辦', 'price' => '1600'],
    ['question' => '台胞證費用多少？', 'item_name' => '台胞證', 'price' => '1900'],
    ['question' => '泰國簽證多少錢？', 'item_name' => '泰國簽證', 'price' => '1200'],
    ['question' => '辦護照要多少', 'item_name' => '護照代辦', 'price' => '1600'],
];

foreach ($passCases as $index => $case) {
    $caseNumber = $index + 1;
    $result = $matcher->match($case['question'], $items);
    test_assert($result !== null, "case{$caseNumber}: match found");
    test_assert(($result['item_name'] ?? '') === $case['item_name'], "case{$caseNumber}: item_name");
    test_assert(strpos((string) ($result['grounded_fact'] ?? ''), $case['price']) !== false, "case{$caseNumber}: price in grounded fact");
}

$failCases = [
    '日本簽證多少錢？',
    '可以帶寵物上飛機嗎？',
    '飛機上可以帶液體嗎？',
    '行李超重怎麼辦？',
];

foreach ($failCases as $index => $question) {
    $caseNumber = $index + 6;
    $result = $matcher->match($question, $items);
    test_assert($result === null, "case{$caseNumber}: no match for {$question}");
}

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_special_price_matcher (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
