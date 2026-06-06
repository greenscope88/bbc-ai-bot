<?php
declare(strict_types=1);

/**
 * BBCTravel Golden Reference v1.0: destination-aware LINE footer UX.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchConditionBuilder.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'DateParser.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'MockShortUrlProvider.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_fallback_formatter.php';

$failures = 0;

function ux_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    } else {
        fwrite(STDOUT, "OK: {$message}\n");
    }
}

$travelBSno = TravelBMultiSourceLinkBuilder::TRAVEL_B_SNO;
$mockProvider = new MockShortUrlProvider();
$multiSourceConfig = [
    'enabled' => true,
    'tenant_sno' => $travelBSno,
    'source_instance_keys' => ['dayitravel_grp', 'dayitravel_bbctravel', 'dayitravel_tourcenter'],
];
$hybridConfig = [
    'enabled' => true,
    'allowed_sno' => [$travelBSno],
    'allowed_channels' => [],
    'dry_run_log_enabled' => false,
];

$mockClient = new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
    $body = json_encode([
        'success' => true,
        'pagination' => ['total' => 1],
        'items' => [
            ['title' => '精選行程', 'tourDate' => '2026-07-10', 'price' => 35000, 'departureStr' => '台北'],
        ],
        'search_url' => 'https://bbcshops.com/TESTMAIN',
        'error' => null,
    ], JSON_UNESCAPED_UNICODE);

    return [
        'ok' => true,
        'http_status' => 200,
        'body' => $body !== false ? $body : '',
        'transport_error' => null,
    ];
});

$service = new TourPromptContextService();

/**
 * @return array{0: string, 1: string}
 */
function build_line_reply(
    TourPromptContextService $service,
    string $userText,
    DateTimeImmutable $referenceDate,
    TourSearchApiClient $mockClient,
    array $hybridConfig,
    array $multiSourceConfig,
    MockShortUrlProvider $mockProvider,
    string $travelBSno
): array {
    $context = $service->buildTourContextForPrompt([
        'userText' => $userText,
        'sno' => $travelBSno,
        'featureEnabled' => true,
        'searchClient' => $mockClient,
        'hybridSearchConfig' => $hybridConfig,
        'travelBMultiSourceLinksConfig' => $multiSourceConfig,
        'multiSourceShortUrlProvider' => $mockProvider,
        'referenceDate' => $referenceDate,
        'includeInstructions' => false,
    ]);

    return [$context, TourFallbackFormatter::formatFromTourContext($context)];
}

function is_http_url_line(string $line): bool
{
    return strpos($line, 'https://') === 0 || strpos($line, 'http://') === 0;
}

function assert_multi_source_url_spacing(string $lineReply, string $multiHeader, string $label): void
{
    $blockStart = strpos($lineReply, $multiHeader);
    ux_assert($blockStart !== false, $label . ': multi-source header found for spacing');
    if ($blockStart === false) {
        return;
    }

    $afterHeader = str_replace("\r\n", "\n", substr($lineReply, $blockStart + strlen($multiHeader)));
    $footerPos = strpos($afterHeader, "\n\n如需更多協助");
    if ($footerPos !== false) {
        $afterHeader = substr($afterHeader, 0, $footerPos);
    }

    $blankThenUrl = strpos($afterHeader, "\n\nhttps://") === 0 || strpos($afterHeader, "\n\nhttp://") === 0;
    ux_assert($blankThenUrl, $label . ': blank line after multi-source header');

    $urlLines = [];
    foreach (explode("\n", trim($afterHeader)) as $line) {
        $line = trim($line);
        if ($line !== '' && is_http_url_line($line)) {
            $urlLines[] = $line;
        }
    }
    ux_assert(count($urlLines) >= 3, $label . ': at least three multi-source urls');
    if (count($urlLines) >= 2) {
        $between = "\n\n" . $urlLines[1];
        ux_assert(strpos($afterHeader, $between) !== false, $label . ': blank line between first and second url');
    }
    if (count($urlLines) >= 3) {
        $between = "\n\n" . $urlLines[2];
        ux_assert(strpos($afterHeader, $between) !== false, $label . ': blank line between second and third url');
    }
}

function assert_footer_ux(string $lineReply, string $destination, string $label): void
{
    $searchHeader = '🔎 更多【' . $destination . '】行程 & 出團日：';
    $multiHeader = '🌏 更多【' . $destination . '】行程也可參考：';

    ux_assert(strpos($lineReply, '我是旅遊 AI 客服，以下為您整理最新的出團資訊：') !== false, $label . ': greeting uses 客服');
    ux_assert(strpos($lineReply, '我是旅遊 AI 助理') === false, $label . ': no legacy 助理 greeting');
    ux_assert(strpos($lineReply, $searchHeader) !== false, $label . ': search header with destination');
    ux_assert(strpos($lineReply, $multiHeader) !== false, $label . ': multi-source header with destination');
    ux_assert(strpos($lineReply, GeminiTourContextBuilder::SEARCH_URL_LABEL) === false, $label . ': no legacy search label');
    ux_assert(strpos($lineReply, '更多商品來源') === false, $label . ': no legacy multi-source header');
    ux_assert(strpos($lineReply, "grp：\n") === false && strpos($lineReply, "grp：") === false, $label . ': no grp platform label');
    ux_assert(strpos($lineReply, "bbctravel：") === false, $label . ': no bbctravel platform label');
    ux_assert(strpos($lineReply, "tourcenter：") === false, $label . ': no tourcenter platform label');
    ux_assert(strpos($lineReply, 'dayitravel.grp.com.tw') !== false, $label . ': grp URL present');
    ux_assert(strpos($lineReply, 'ClassifyProduct.aspx') !== false, $label . ': grp ClassifyProduct.aspx');
    ux_assert(strpos($lineReply, 'Tour/Search') === false, $label . ': no legacy grp Tour/Search');
    ux_assert(strpos($lineReply, 'https://bbcshops.com/') !== false, $label . ': short URL present');
    ux_assert(strpos($lineReply, 'dayitourcenter.com.tw') !== false, $label . ': tourcenter URL present');

    $searchPos = strpos($lineReply, $searchHeader);
    $multiPos = strpos($lineReply, $multiHeader);
    ux_assert($searchPos !== false && $multiPos !== false && $searchPos < $multiPos, $label . ': search block before multi-source block');
    assert_multi_source_url_spacing($lineReply, $multiHeader, $label);
}

// Case 1: 大阪近期
$ref1 = new DateTimeImmutable('2026-06-06', new DateTimeZone('Asia/Taipei'));
[, $line1] = build_line_reply($service, '大阪近期', $ref1, $mockClient, $hybridConfig, $multiSourceConfig, $mockProvider, $travelBSno);
assert_footer_ux($line1, '大阪', 'case1 大阪近期');

// Case 2: 東京本月
$ref2 = new DateTimeImmutable('2026-06-06', new DateTimeZone('Asia/Taipei'));
[, $line2] = build_line_reply($service, '東京本月', $ref2, $mockClient, $hybridConfig, $multiSourceConfig, $mockProvider, $travelBSno);
assert_footer_ux($line2, '東京', 'case2 東京本月');

// Case 3: 北海道暑假
$ref3 = new DateTimeImmutable('2026-06-05', new DateTimeZone('Asia/Taipei'));
[, $line3] = build_line_reply($service, '北海道暑假', $ref3, $mockClient, $hybridConfig, $multiSourceConfig, $mockProvider, $travelBSno);
assert_footer_ux($line3, '北海道', 'case3 北海道暑假');

// Case 4: 高雄東京近期 — grp ignores departure; bbctravel still short URL
$ref4 = new DateTimeImmutable('2026-06-06', new DateTimeZone('Asia/Taipei'));
[, $line4] = build_line_reply($service, '高雄東京近期', $ref4, $mockClient, $hybridConfig, $multiSourceConfig, $mockProvider, $travelBSno);
assert_footer_ux($line4, '東京', 'case4 高雄東京近期');
ux_assert(strpos($line4, '/khh/') === false, 'case4 高雄東京近期: grp LINE footer no khh');
ux_assert(strpos($line4, 'http://dayitravel.grp.com.tw/ClassifyProduct.aspx') !== false, 'case4 高雄東京近期: grp http ClassifyProduct');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_line_search_footer_ux\n");
exit(0);
