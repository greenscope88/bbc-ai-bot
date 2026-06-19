<?php
declare(strict_types=1);

/**
 * Phase 9-C-2A.1: TravelConsultantPersonaFormatter tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'recommendation' . DIRECTORY_SEPARATOR . 'TravelConsultantPersonaFormatter.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'recommendation' . DIRECTORY_SEPARATOR . 'TravelConsultantPersonaRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_fallback_formatter.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'gemini_tour_context_builder.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function buildSampleTourContext(): string
{
    $builder = new GeminiTourContextBuilder();

    return $builder->build([
        'success' => true,
        'pagination' => ['total' => 1],
        'items' => [
            [
                'title' => '東京五日精選',
                'tourDate' => '2026-07-10',
                'price' => 39900,
                'departureStr' => '台北',
            ],
        ],
        'search_url' => 'https://example.test/tokyo-search',
        'keyword' => '東京',
    ], [
        'includeInstructions' => false,
        'storeNo' => 6290,
    ]);
}

// Case 1: opening generated
$openingFormatter = TravelConsultantPersonaFormatter::createWithFixedIndex(0);
$openingText = $openingFormatter->pickOpeningText();
test_assert($openingText !== '', 'case1 opening generated');
test_assert(mb_strpos($openingText, '您好') !== false, 'case1 opening contains greeting');

// Case 2: closing generated
$closingFormatter = TravelConsultantPersonaFormatter::createWithFixedIndex(2);
$closingText = $closingFormatter->pickClosingText();
test_assert($closingText !== '', 'case2 closing generated');
test_assert(mb_strpos($closingText, '偏好') !== false || mb_strpos($closingText, '推薦') !== false, 'case2 closing content');

// Case 3: random pool works
$seenOpenings = [];
$seenClosings = [];
for ($i = 0; $i < 30; ++$i) {
    $randomFormatter = TravelConsultantPersonaFormatter::createRandomized();
    $seenOpenings[$randomFormatter->pickOpeningText()] = true;
    $seenClosings[$randomFormatter->pickClosingText()] = true;
}
test_assert(count($seenOpenings) > 1, 'case3 opening pool varies');
test_assert(count($seenClosings) > 1, 'case3 closing pool varies');
test_assert(count(TravelConsultantPersonaFormatter::openingPool()) === 5, 'case3 opening pool size');
test_assert(count(TravelConsultantPersonaFormatter::closingPool()) === 5, 'case3 closing pool size');

// Case 4: emojis present but limited
$emojiFormatter = TravelConsultantPersonaFormatter::createWithFixedIndex(4);
$emojiOpening = $emojiFormatter->pickOpeningText();
$emojiClosing = TravelConsultantPersonaFormatter::createWithFixedIndex(3)->pickClosingText();
test_assert($emojiFormatter->countEmojis($emojiOpening) >= 1, 'case4 opening has emoji');
test_assert($emojiFormatter->countEmojis($emojiOpening) <= 5, 'case4 opening emoji limit');
test_assert($emojiFormatter->countEmojis($emojiClosing) <= 5, 'case4 closing emoji limit');
test_assert($emojiFormatter->countEmojis($emojiOpening . "\n" . $emojiClosing) <= 5, 'case4 combined emoji limit');

// Case 5: product list unchanged in TourFallbackFormatter
$callCount = 0;
$pickerFormatter = TravelConsultantPersonaFormatter::createWithIndexPicker(
    static function (int $count) use (&$callCount): int {
        ++$callCount;

        return $callCount === 1 ? 1 : 0;
    }
);

$formatted = TourFallbackFormatter::formatFromTourContext(buildSampleTourContext(), $pickerFormatter);
test_assert(mb_strpos($formatted, '🚩 東京五日精選') !== false, 'case5 product title unchanged');
test_assert(mb_strpos($formatted, '📅 最近出團：') !== false, 'case5 departure label unchanged');
test_assert(mb_strpos($formatted, '💰 售價：') !== false, 'case5 price label unchanged');
test_assert(mb_strpos($formatted, '🛫 出發地：') !== false, 'case5 origin label unchanged');
test_assert(mb_strpos($formatted, '哈囉 👋') !== false, 'case5 personalized opening');
test_assert(mb_strpos($formatted, '如需更多協助') !== false, 'case5 personalized closing');
test_assert(mb_strpos($formatted, '我是旅遊 AI 客服，以下為您整理最新的出團資訊：') === false, 'case5 old opening removed');
test_assert(mb_strpos($formatted, '如需更多協助，歡迎再告訴我們。') === false, 'case5 old closing removed');

// Case 6: pilot persona runtime keeps product body unchanged with new opening/closing
$runtime = new TravelConsultantPersonaRuntime(TravelConsultantPersonaFormatter::createWithFixedIndex(3));
$runtimeReply = $runtime->composeProductRecommendation([
    'result_count' => 2,
    'top_products' => [
        ['title' => '東京近期團', 'display_emoji' => '✈️'],
        ['title' => '東京深度五日', 'display_emoji' => '♨️'],
    ],
    'primary_url' => 'https://example.test/tokyo',
    'recommendation_reason' => '依您提到的「東京」整理近期熱門行程。',
    'preference_hints' => ['親子旅遊'],
]);
test_assert(mb_strpos($runtimeReply, '✈️ 東京近期團') !== false, 'case6 product line unchanged');
test_assert(mb_strpos($runtimeReply, 'https://example.test/tokyo') !== false, 'case6 primary url unchanged');
test_assert(mb_strpos($runtimeReply, '很高興為您服務') !== false, 'case6 personalized opening');
test_assert(mb_strpos($runtimeReply, '有任何問題都歡迎再與我聯繫') !== false, 'case6 personalized closing');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_travel_consultant_persona_formatter (all passed)\n");
exit(0);
