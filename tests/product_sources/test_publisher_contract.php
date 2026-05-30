<?php
declare(strict_types=1);

/**
 * Phase 9-B-16: Publisher contract validation.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'PublisherContract.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'PublisherContractValidator.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$validator = new PublisherContractValidator();

$primaryUrl = 'https://rechoice-travel.agenttour.com.tw/Index.aspx';

$minimalPublisher = [
    'tenant_instance' => 'rechoice_agenttour',
    'source_platform' => 'agenttour',
    'product_category' => 'group_tour',
    'title' => '東京五日遊',
    'primary_url' => $primaryUrl,
];

// Case 1: minimal PublisherContract
try {
    $normalized = $validator->validate($minimalPublisher);
    test_assert($normalized['secondary_urls'] === [], 'case1 secondary_urls default empty');
    test_assert($normalized['actions'] === [], 'case1 actions default empty');
    test_assert($normalized['metadata'] === [], 'case1 metadata default empty');
    test_assert(true, 'case1 minimal PublisherContract PASS');
} catch (PublisherContractException $e) {
    test_assert(false, 'case1 should pass: ' . $e->getMessage());
}

// Case 2: full PublisherContract with actions
$fullPublisher = [
    'schema_version' => 1,
    'tenant_instance' => 'rechoice_agenttour',
    'source_platform' => 'agenttour',
    'product_category' => 'group_tour',
    'title' => '東京五日遊',
    'summary' => '東京精選團體行程',
    'primary_url' => $primaryUrl,
    'secondary_urls' => [
        'https://rechoice-travel.agenttour.com.tw/Tour/List',
    ],
    'actions' => [
        [
            'type' => 'open_url',
            'label' => '查看行程',
            'url' => $primaryUrl,
        ],
    ],
    'metadata' => [
        'price_from' => 28888,
        'currency' => 'TWD',
    ],
];

try {
    $normalized = $validator->validate($fullPublisher);
    test_assert($normalized['summary'] === '東京精選團體行程', 'case2 summary');
    test_assert(count($normalized['actions']) === 1, 'case2 one action');
    test_assert($normalized['actions'][0]['type'] === 'open_url', 'case2 action type');
    test_assert(true, 'case2 full PublisherContract PASS');
} catch (PublisherContractException $e) {
    test_assert(false, 'case2 should pass: ' . $e->getMessage());
}

// Case 3: missing title
try {
    $doc = $minimalPublisher;
    unset($doc['title']);
    $validator->validate($doc);
    test_assert(false, 'case3 should fail on missing title');
} catch (PublisherContractException $e) {
    test_assert(
        $e->getErrorCode() === PublisherContractException::TITLE_REQUIRED,
        'case3 PUBLISHER_CONTRACT_TITLE_REQUIRED'
    );
}

// Case 4: missing primary_url
try {
    $doc = $minimalPublisher;
    unset($doc['primary_url']);
    $validator->validate($doc);
    test_assert(false, 'case4 should fail on missing primary_url');
} catch (PublisherContractException $e) {
    test_assert(
        $e->getErrorCode() === PublisherContractException::PRIMARY_URL_REQUIRED,
        'case4 PUBLISHER_CONTRACT_PRIMARY_URL_REQUIRED'
    );
}

// Case 5: missing tenant_instance
try {
    $doc = $minimalPublisher;
    unset($doc['tenant_instance']);
    $validator->validate($doc);
    test_assert(false, 'case5 should fail on missing tenant_instance');
} catch (PublisherContractException $e) {
    test_assert(
        $e->getErrorCode() === PublisherContractException::TENANT_INSTANCE_REQUIRED,
        'case5 PUBLISHER_CONTRACT_TENANT_INSTANCE_REQUIRED'
    );
}

// Case 6: action missing url
try {
    $validator->validate(array_merge($minimalPublisher, [
        'actions' => [
            [
                'type' => 'open_url',
                'label' => '查看行程',
            ],
        ],
    ]));
    test_assert(false, 'case6 should fail on action missing url');
} catch (PublisherContractException $e) {
    test_assert(
        $e->getErrorCode() === PublisherContractException::ACTION_URL_REQUIRED,
        'case6 PUBLISHER_CONTRACT_ACTION_URL_REQUIRED'
    );
}

// Case 7: metadata for group_tour / hotel / ticket
$metadataCases = [
    [
        'product_category' => 'group_tour',
        'metadata' => ['price_from' => 28888, 'currency' => 'TWD'],
    ],
    [
        'product_category' => 'hotel',
        'metadata' => ['hotel_star' => 5],
    ],
    [
        'product_category' => 'ticket',
        'metadata' => ['airline' => 'CI'],
    ],
];

foreach ($metadataCases as $index => $metaCase) {
    $doc = array_merge($minimalPublisher, [
        'product_category' => $metaCase['product_category'],
        'title' => '測試商品 ' . ($index + 1),
        'metadata' => $metaCase['metadata'],
    ]);

    try {
        $normalized = $validator->validate($doc);
        test_assert($normalized['product_category'] === $metaCase['product_category'], 'case7 category ' . $metaCase['product_category']);
        test_assert($normalized['metadata'] === $metaCase['metadata'], 'case7 metadata preserved');
    } catch (PublisherContractException $e) {
        test_assert(false, 'case7 should pass for ' . $metaCase['product_category'] . ': ' . $e->getMessage());
    }
}
test_assert(true, 'case7 metadata group_tour hotel ticket PASS');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_publisher_contract (all passed)\n");
exit(0);
