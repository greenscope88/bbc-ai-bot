<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ProductLineageObservation.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'published' . DIRECTORY_SEPARATOR . 'PublishedProductSetSelector.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'BbcshopsFlexCarouselRenderer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_detail_url_builder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'legacy_storefront_crypto.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'MockShortUrlProvider.php';

$failures = 0;

function lineage_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/**
 * @return array<string, mixed>
 */
function lineage_host_b_item(int $index, int $couponNo, string $title, string $area, string $date): array
{
    return [
        'title' => $title,
        'price' => 30000 + $index,
        'tourDate' => $date,
        'couponNo' => $couponNo,
        'tourSeqNo' => 2000 + $index,
        'areaNames' => $area,
        'departureStr' => '台北',
        'storeNo' => 168,
        'dmFile' => 'photo' . $index . '.jpg',
        'depID' => 10,
        'couponAttr' => 0,
        'schLinks' => ['https://agt.tw/item' . $index],
        'tourDays' => 5,
    ];
}

/**
 * @param array<string, mixed> $event
 */
function lineage_assert_no_forbidden_keys(array $event): void
{
    $forbidden = [
        'api_key',
        'authorization',
        'reply_token',
        'access_token',
        'user_id',
        'conversation_id',
        'description',
        'raw_response',
        'request_payload',
        'utterance',
        'message_text',
    ];
    $json = json_encode($event, JSON_UNESCAPED_UNICODE);
    lineage_assert($json !== false, 'event must json_encode');
    foreach ($forbidden as $key) {
        lineage_assert(stripos($json, '"' . $key . '"') === false, 'forbidden key present: ' . $key);
    }
}

// Case 1 — Host B per-item lineage
$hostBItems = [
    lineage_host_b_item(0, 1001, '日本賞花行程A', '日本', '2026-10-03'),
    lineage_host_b_item(1, 1002, '日本賞花行程B', '日本', '2026-10-10'),
    lineage_host_b_item(2, 1003, '釜慶秋日童話', '韓國', '2026-10-29'),
    lineage_host_b_item(3, 1004, '北海道楓靡五日', '日本', '2026-10-16'),
];
$hostBEvent = ProductLineageObservation::buildHostBResponseEvent('trace_host_b', $hostBItems, 168);
lineage_assert(($hostBEvent['item_count'] ?? 0) === 4, 'host b item_count=4');
lineage_assert(count($hostBEvent['items'] ?? []) === 4, 'host b items length=4');
lineage_assert(($hostBEvent['items'][0]['response_index'] ?? null) === 0, 'host b first index=0');
lineage_assert(($hostBEvent['items'][0]['stable_key'] ?? '') === 'hostb:168:1001', 'host b stable key');
lineage_assert(($hostBEvent['items'][2]['title'] ?? '') === '釜慶秋日童話', 'host b korea title preserved for trace only');
lineage_assert(($hostBEvent['items'][2]['area'] ?? '') === '韓國', 'host b korea area from response field');
lineage_assert_no_forbidden_keys($hostBEvent);

// Case 2 — Published Product Set lineage
$detailBuilder = new TourDetailUrlBuilder(new LegacyStorefrontCrypto('testkey8'));
$selector = new PublishedProductSetSelector($detailBuilder, new MockShortUrlProvider());
$aggregateRows = [
    lineage_host_b_item(0, 2001, '日本賞花A', '日本', '2026-10-03'),
    array_merge(lineage_host_b_item(0, 2001, '日本賞花A', '日本', '2026-10-17'), ['tourDate' => '2026-10-17']),
    lineage_host_b_item(1, 2002, '日本賞花B', '日本', '2026-10-20'),
    [
        'title' => 'missing coupon',
        'tourDate' => '2026-10-21',
        'tourSeqNo' => 2999,
        'departureStr' => '台北',
        'storeNo' => 168,
        'dmFile' => 'x.jpg',
        'depID' => 10,
        'schLinks' => ['https://agt.tw/miss'],
    ],
];
$publishedSet = $selector->select($aggregateRows, 168);
$trace = $selector->getLastLineageTrace();
lineage_assert(is_array($trace), 'published set trace present');
lineage_assert(($trace['input_count'] ?? 0) === 4, 'published input_count=4');
lineage_assert(($trace['output_count'] ?? 0) === 2, 'published output_count=2');
lineage_assert($publishedSet->getCount() === 2, 'published set count unchanged');
lineage_assert(count($trace['output_keys'] ?? []) === 2, 'published output_keys count=2');
$decisions = $trace['decisions'] ?? [];
lineage_assert(($decisions[0]['outcome'] ?? '') === 'keep', 'first row keep');
lineage_assert(($decisions[1]['outcome'] ?? '') === 'aggregate', 'second row aggregate');
lineage_assert(($decisions[3]['reason_code'] ?? '') === 'missing_coupon_no', 'missing coupon drop reason');
lineage_assert(
    in_array('2026-10-03', $decisions[1]['aggregated_dates'] ?? [], true)
    && in_array('2026-10-17', $decisions[1]['aggregated_dates'] ?? [], true),
    'aggregated dates captured'
);

// Case 3 — Flex mapping
$renderResult = (new BbcshopsFlexCarouselRenderer())->render($publishedSet);
$flexEvent = ProductLineageObservation::buildFlexCarouselEvent(
    'trace_flex',
    $publishedSet->getProducts(),
    $renderResult->getBubbleFactIds()
);
lineage_assert(($flexEvent['bubble_count'] ?? 0) === 2, 'flex bubble_count=2');
lineage_assert(count($flexEvent['bubbles'] ?? []) === 2, 'flex bubbles length=2');
lineage_assert(($flexEvent['bubbles'][0]['bubble_index'] ?? 0) === 1, 'flex first bubble index=1');
lineage_assert(
    ($flexEvent['bubbles'][0]['stable_key'] ?? '') === ($flexEvent['bubbles'][0]['published_set_key'] ?? ''),
    'flex stable key matches published set key'
);
lineage_assert(
    $renderResult->getBubbleFactIds()[0] === ($flexEvent['bubbles'][0]['stable_key'] ?? ''),
    'flex bubble fact id alignment'
);
lineage_assert_no_forbidden_keys($flexEvent);

// Case 4 — Relevance evidence vocabulary only in tests (no production geo filter)
$koreaIndex = null;
foreach ($hostBEvent['items'] as $item) {
    if (($item['title'] ?? '') === '釜慶秋日童話') {
        $koreaIndex = $item['response_index'] ?? null;
        break;
    }
}
lineage_assert($koreaIndex === 2, 'korea item observable at host b stage index 2');

// Case 5 — Safety regression on emitted payload shape
lineage_assert(($hostBEvent['trace_id'] ?? '') === 'trace_host_b', 'trace id preserved');
lineage_assert(!isset($hostBEvent['request_payload']), 'no request payload in host b event');

// Case 6 — sanitizeEvent must preserve nested lists (builder → sanitize / emit path)
$hostBSanitized = ProductLineageObservation::sanitizeEvent($hostBEvent);
lineage_assert(($hostBSanitized['item_count'] ?? 0) === 4, 'sanitize host b item_count unchanged');
lineage_assert(count($hostBSanitized['items'] ?? []) === 4, 'sanitize host b items non-empty');
lineage_assert(
    ($hostBSanitized['items'][0]['stable_key'] ?? '') === ($hostBEvent['items'][0]['stable_key'] ?? ''),
    'sanitize host b item order/key preserved'
);
lineage_assert(
    ($hostBSanitized['items'][2]['title'] ?? '') === '釜慶秋日童話',
    'sanitize host b title preserved'
);
lineage_assert_no_forbidden_keys($hostBSanitized);

$traceSanitized = ProductLineageObservation::sanitizeEvent(is_array($trace) ? $trace : []);
lineage_assert(($traceSanitized['input_count'] ?? 0) === 4, 'sanitize published input_count');
lineage_assert(($traceSanitized['output_count'] ?? 0) === 2, 'sanitize published output_count');
lineage_assert(count($traceSanitized['input_keys'] ?? []) === count($trace['input_keys'] ?? []), 'sanitize input_keys length');
lineage_assert(count($traceSanitized['output_keys'] ?? []) === 2, 'sanitize output_keys non-empty');
lineage_assert(count($traceSanitized['decisions'] ?? []) === 4, 'sanitize decisions non-empty');
lineage_assert(($traceSanitized['decisions'][0]['outcome'] ?? '') === 'keep', 'sanitize keep outcome');
lineage_assert(($traceSanitized['decisions'][1]['outcome'] ?? '') === 'aggregate', 'sanitize aggregate outcome');
lineage_assert(
    in_array('2026-10-03', $traceSanitized['decisions'][1]['aggregated_dates'] ?? [], true)
    && in_array('2026-10-17', $traceSanitized['decisions'][1]['aggregated_dates'] ?? [], true),
    'sanitize aggregated_dates preserved'
);

$flexSanitized = ProductLineageObservation::sanitizeEvent($flexEvent);
lineage_assert(($flexSanitized['bubble_count'] ?? 0) === 2, 'sanitize flex bubble_count');
lineage_assert(count($flexSanitized['bubbles'] ?? []) === 2, 'sanitize flex bubbles non-empty');
lineage_assert(($flexSanitized['bubbles'][0]['bubble_index'] ?? 0) === 1, 'sanitize bubble index');
lineage_assert(
    ($flexSanitized['bubbles'][0]['stable_key'] ?? '') === ($flexEvent['bubbles'][0]['stable_key'] ?? ''),
    'sanitize flex stable key order'
);
lineage_assert_no_forbidden_keys($flexSanitized);

// Nested forbidden string keys still removed; list indices preserved
$unsafe = [
    'trace_id' => 'safe',
    'items' => [
        [
            'title' => 'ok',
            'api_key' => 'secret',
            'user_id' => 'u1',
            'nested_list' => ['a', 'b'],
        ],
    ],
    'utterance' => 'should-drop',
];
$unsafeSanitized = ProductLineageObservation::sanitizeEvent($unsafe);
lineage_assert(count($unsafeSanitized['items'] ?? []) === 1, 'unsafe list preserved length');
lineage_assert(($unsafeSanitized['items'][0]['title'] ?? '') === 'ok', 'unsafe list item kept');
lineage_assert(!isset($unsafeSanitized['items'][0]['api_key']), 'nested api_key removed');
lineage_assert(!isset($unsafeSanitized['items'][0]['user_id']), 'nested user_id removed');
lineage_assert(!isset($unsafeSanitized['utterance']), 'top-level utterance removed');
lineage_assert(
    ($unsafeSanitized['items'][0]['nested_list'] ?? null) === ['a', 'b'],
    'nested scalar list preserved'
);

if ($failures > 0) {
    fwrite(STDERR, "Product lineage observation tests failed: {$failures}\n");
    exit(1);
}

fwrite(STDOUT, "OK product lineage observation tests\n");
