<?php
declare(strict_types=1);

/**
 * HostBResponseNormalizer: preserve tour schedule URLs; redact other secrets.
 */

$root = dirname(__DIR__, 2);
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'api_gateway' . DIRECTORY_SEPARATOR . 'production' . DIRECTORY_SEPARATOR . 'http' . DIRECTORY_SEPARATOR . 'HostBResponseNormalizer.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_search_service.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'gemini_tour_context_builder.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_fallback_formatter.php';

$failures = 0;

function t(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$scheduleUrl = 'https://agt.tw/example-schedule.pdf';
$hostBJson = json_encode([
    'status' => 'success',
    'data' => [
        [
            'couponNo' => 11849,
            'couponName' => '釜山測試',
            'price' => 14900,
            'tourDate' => '2026-09-05',
            'tourSeqNo' => 194477,
            'departureStr' => '台北',
            'schLink' => $scheduleUrl,
            'schLinks' => [
                ['schLinkName' => '表一', 'schLink' => 'https://agt.tw/sch-1'],
                ['schLinkName' => '表二', 'schLink' => 'https://agt.tw/sch-2'],
                ['schLinkName' => '表三', 'schLink' => 'https://agt.tw/sch-3'],
                ['schLinkName' => '表四', 'schLink' => 'https://agt.tw/sch-4'],
            ],
        ],
    ],
    'api_key' => 'must-not-leak-key',
    'token' => 'must-not-leak-token',
    'msg' => 'see http://internal.example/secret',
], JSON_THROW_ON_ERROR);

$decoded = HostBResponseNormalizer::decodeJsonBody($hostBJson, 'norm-test-1');
$row = $decoded['data'][0] ?? [];
t(($row['schLink'] ?? '') === $scheduleUrl, '1: schLink URL preserved');
t(is_array($row['schLinks'] ?? null) && count($row['schLinks']) === 4, '2: schLinks array preserved');
t(($row['schLinks'][0]['schLink'] ?? '') === 'https://agt.tw/sch-1', '3: schLinks[0].schLink preserved');
t(!isset($decoded['api_key']), '4: api_key key removed');
t(!isset($decoded['token']), '5: token key removed');
t(($decoded['msg'] ?? '') === '[redacted]', '6: generic URL string still redacted');

// Gemini + fallback from preserved schedule URLs
$apiShape = [
    'success' => true,
    'items' => [
        [
            'title' => '釜山測試',
            'price' => 14900,
            'tourDate' => '2026-09-05',
            'couponNo' => 11849,
            'tourSeqNo' => 194477,
            'departureStr' => '台北',
            'schLinks' => $row['schLinks'],
        ],
    ],
    'search_url' => 'https://bonusmee.com/view/cloud/cloud_store_tourdate.php?keyword=test',
];
$ctx = (new GeminiTourContextBuilder())->build($apiShape, ['includeInstructions' => false, 'maxItems' => 5]);
t(strpos($ctx, '行程表：') !== false, '7: context has 行程表 block');
t(strpos($ctx, 'https://agt.tw/sch-1') !== false, '8: context has schedule URL');
t(strpos($ctx, '   3. https://agt.tw/sch-3') !== false, '9: context has all schedule urls');
t(strpos($ctx, '另有') === false, '9: no 另有 N 筆');
t(strpos($ctx, 'https://bonusmee.com/view/cloud/cloud_store_tourdate.php') !== false, '10: search_url preserved');

$fb = TourFallbackFormatter::formatFromTourContext($ctx);
t(strpos($fb, '行程表：') !== false, '11: fallback has 行程表');
t(strpos($fb, '   4. https://agt.tw/sch-4') !== false, '12: fallback has fourth schedule url');
t(strpos($fb, '另有') === false, '12: fallback no 另有 N 筆');

if ($failures === 0) {
    echo "OK: HostBResponseNormalizer schedule URL tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
