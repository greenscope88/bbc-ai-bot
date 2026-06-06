<?php
declare(strict_types=1);

/**
 * TourLineReplyComposer: fixed LINE list (scheme C) + Gemini/fallback paths.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridDateRequiredGate.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchConditionBuilder.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'DateParser.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_line_reply_composer.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'gemini_tour_context_builder.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_feature_gate.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . 'ConfigTenantRegistry.php';

$failures = 0;
$lineSep = GeminiTourContextBuilder::LINE_TOUR_ITEM_SEPARATOR;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function buildMultiSchContext(): string
{
    $builder = new GeminiTourContextBuilder();
    return $builder->build([
        'success' => true,
        'pagination' => ['total' => 1],
        'items' => [
            [
                'title' => '釜山四表',
                'tourDate' => '2026-09-05',
                'price' => 14900,
                'departureStr' => '台北',
                'couponNo' => 11849,
                'tourSeqNo' => 194477,
                'schLinks' => [
                    ['schLinkName' => '表一', 'schLink' => 'https://agt.tw/sch-1'],
                    ['schLinkName' => '表二', 'schLink' => 'https://agt.tw/sch-2'],
                    ['schLinkName' => '表三', 'schLink' => 'https://agt.tw/sch-3'],
                    ['schLinkName' => '表四', 'schLink' => 'https://agt.tw/sch-4'],
                ],
            ],
        ],
        'search_url' => 'https://bonusmee.com/view/cloud/cloud_store_tourdate.php?keyword=test',
    ], [
        'includeInstructions' => false,
        'storeNo' => 6290,
    ]);
}

function assertNewLabels(string $text, string $label): void
{
    test_assert(strpos($text, '🚩 ') !== false, $label . ': product flag with space');
    test_assert(strpos($text, '1. 🚩') === false, $label . ': no numbered product prefix');
    test_assert(strpos($text, '📅 最近出團：') !== false, $label . ': 最近出團');
    test_assert(strpos($text, '💰 售價：') !== false, $label . ': 售價 label');
    test_assert(strpos($text, '💰直售價：') === false, $label . ': no 直售價 label');
    test_assert(strpos($text, GeminiTourContextBuilder::SEARCH_URL_LABEL) !== false, $label . ': footer');
    test_assert(strpos($text, '──────────────') !== false, $label . ': item separator');
    test_assert(strpos($text, '━━━━━━━━━━━━━━━━━━━') === false, $label . ': no heavy footer divider');
    test_assert(strpos($text, "   📅") === false, $label . ': no indent before emoji');
    test_assert(strpos($text, '出團日期：') === false, $label . ': no 出團日期');
    test_assert(strpos($text, '行程內頁：') === false, $label . ': no 行程內頁');
    test_assert(strpos($text, '更多參考行程及出團日期：') === false, $label . ': no old footer');
    test_assert(strpos($text, '另有') === false, $label . ': no 另有');
    test_assert(strpos($text, '精彩行程點我') === false, $label . ': no schLinkName label');
}

$ctx = buildMultiSchContext();
$geminiCalled = false;
$fixed = TourLineReplyComposer::resolve('prompt', $ctx, static function () use (&$geminiCalled): array {
    $geminiCalled = true;
    return ['ok' => true, 'text' => "出團日期：bad\n行程內頁：bad\n另有 2 筆行程表"];
});

test_assert($fixed['used_fixed_tour_list'] === true, 'fixed: uses system list');
test_assert($fixed['used_tour_fallback'] === false, 'fixed: not fallback');
test_assert($fixed['ai_ok'] === true, 'fixed: ai_ok');
test_assert($geminiCalled === false, 'fixed: gemini not called when context present');
assertNewLabels($fixed['reply_text'], 'fixed');
test_assert(strpos($fixed['reply_text'], '🚩 釜山四表') !== false, 'fixed: title with flag');
test_assert(strpos($fixed['reply_text'], '1. https://agt.tw/sch-1') !== false, 'fixed: sch url 1');
test_assert(strpos($fixed['reply_text'], '2. https://agt.tw/sch-2') !== false, 'fixed: sch url 2');
test_assert(strpos($fixed['reply_text'], '3. https://agt.tw/sch-3') !== false, 'fixed: sch url 3');
test_assert(strpos($fixed['reply_text'], '4. https://agt.tw/sch-4') !== false, 'fixed: sch url 4');
test_assert(strpos($fixed['reply_text'], '表一') === false, 'fixed: no schLinkName');

$geminiCalled2 = false;
$busy = TourLineReplyComposer::resolve('prompt', '', static function () use (&$geminiCalled2): array {
    $geminiCalled2 = true;
    return ['ok' => false, 'text' => null];
});
test_assert($geminiCalled2 === true, 'empty ctx: gemini attempted');
test_assert(strpos($busy['reply_text'], '忙碌') !== false, 'empty ctx: busy message when gemini fails');
test_assert($busy['used_tour_fallback'] === false, 'empty ctx: no tour fallback');

$noCtx = TourLineReplyComposer::resolve('prompt', '', static fn (): array => ['ok' => true, 'text' => '一般回覆']);
test_assert($noCtx['reply_text'] === '一般回覆', 'no context: gemini text');
test_assert($noCtx['used_fixed_tour_list'] === false, 'no context: no fixed list');

// Stage 2: allowFixedFormatter=false skips formatter even when context exists
$geminiCalledGateOff = false;
$gateOff = TourLineReplyComposer::resolve(
    'prompt',
    $ctx,
    static function () use (&$geminiCalledGateOff): array {
        $geminiCalledGateOff = true;
        return ['ok' => true, 'text' => 'Gemini 回覆（無固定清單）'];
    },
    false
);
test_assert($gateOff['used_fixed_tour_list'] === false, 'gate off: no fixed list');
test_assert($gateOff['used_tour_fallback'] === false, 'gate off: no formatter fallback');
test_assert($geminiCalledGateOff === true, 'gate off: gemini called');
test_assert($gateOff['reply_text'] === 'Gemini 回覆（無固定清單）', 'gate off: gemini text');
test_assert(strpos($gateOff['reply_text'], '🚩 ') === false, 'gate off: no formatter emoji layout');

$geminiFailGateOff = false;
$gateOffBusy = TourLineReplyComposer::resolve(
    'prompt',
    $ctx,
    static function () use (&$geminiFailGateOff): array {
        $geminiFailGateOff = true;
        return ['ok' => false, 'text' => null];
    },
    false
);
test_assert($geminiFailGateOff === true, 'gate off fail: gemini attempted');
test_assert(strpos($gateOffBusy['reply_text'], '忙碌') !== false, 'gate off fail: busy message not formatter');
test_assert($gateOffBusy['used_tour_fallback'] === false, 'gate off fail: no formatter fallback');

// Registry-backed gate expectations (travel_a ON, travel_b OFF, miss → true)
$registry = new ConfigTenantRegistry();
$travelASno = 'e1fd133c7e8e45a1';
$travelBSno = '5f99b8d665e8444d';
test_assert(
    TourPromptFeatureGate::isFixedFormatterEnabled(['sno' => $travelASno], $registry) === true,
    'registry travel_a fixed_formatter ON'
);
test_assert(
    TourPromptFeatureGate::isFixedFormatterEnabled(['sno' => $travelBSno], $registry) === true,
    'registry travel_b fixed_formatter ON'
);
test_assert(
    TourPromptFeatureGate::isFixedFormatterEnabled(['sno' => 'unknown-sno-not-in-registry'], $registry) === true,
    'registry miss fixed_formatter legacy true'
);

$ref = new DateTimeImmutable('2026-06-05', new DateTimeZone('Asia/Taipei'));
$hybridBuilder = new HybridSearchConditionBuilder(new DateParser($ref));
$tokyoCondition = $hybridBuilder->parse('東京', ['reference_date' => $ref, 'merge_legacy_keyword' => true]);
$clarifyCtx = HybridDateRequiredGate::buildClarificationContext($tokyoCondition, '東京');
$clarify = TourLineReplyComposer::resolve('prompt', $clarifyCtx, static fn (): array => ['ok' => true, 'text' => 'gemini']);
test_assert(strpos($clarify['reply_text'], HybridDateRequiredGate::CLARIFICATION_MARKER) === false, 'clarify: no engineering marker');
test_assert(strpos($clarify['reply_text'], '請問您預計什麼時候出發【東京】呢？') !== false, 'clarify: customer prompt');
test_assert($clarify['used_fixed_tour_list'] === false, 'clarify: not tour list');
test_assert($clarify['used_tour_fallback'] === false, 'clarify: not fallback');

if ($failures === 0) {
    echo "OK: TourLineReplyComposer tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
