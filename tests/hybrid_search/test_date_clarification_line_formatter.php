<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GeminiDerivedSearchConditionFixtures.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchCondition.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'date_clarification_line_formatter.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridDateRequiredGate.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_line_reply_composer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';

$travelBSno = TravelBMultiSourceLinkBuilder::TRAVEL_B_SNO;
$ref = new DateTimeImmutable('2026-06-05', new DateTimeZone('Asia/Taipei'));

function clarification_context(SearchCondition $condition, string $message): string
{
    return HybridDateRequiredGate::buildClarificationContext($condition, $message);
}

function assert_customer_clarification(string $reply, string $label): void
{
    hybrid_test_assert(strpos($reply, HybridDateRequiredGate::CLARIFICATION_MARKER) === false, $label . ': no engineering marker');
    hybrid_test_assert(strpos($reply, '【日期澄清】') === false, $label . ': no engineering heading');
    hybrid_test_assert(strpos($reply, 'date_from') === false, $label . ': no engineering field names');
    hybrid_test_assert(strpos($reply, '您好 😊') === 0, $label . ': greeting');
    hybrid_test_assert(strpos($reply, '我會依照您的出發時間幫您查詢適合的行程。') !== false, $label . ': closing');
    hybrid_test_assert(strpos($reply, 'bonusmee.com') === false, $label . ': no search URL');
    hybrid_test_assert(strpos($reply, 'bbctravel.com.tw') === false, $label . ': no bbctravel URL');
}

$tokyoCtx = clarification_context(GeminiDerivedSearchConditionFixtures::tokyoDestinationOnly(), '東京');
$tokyoReply = DateClarificationLineFormatter::formatFromTourContext($tokyoCtx);
assert_customer_clarification($tokyoReply, '東京');
hybrid_test_assert(strpos($tokyoReply, '請問您預計什麼時候出發【東京】呢？') !== false, '東京: destination prompt');
hybrid_test_assert(strpos($tokyoReply, '📅 近期東京') !== false, '東京: example 近期東京');
hybrid_test_assert(strpos($tokyoReply, '📅 東京6月底') !== false, '東京: example 東京6月底');

$osakaCtx = clarification_context(GeminiDerivedSearchConditionFixtures::osakaDestinationOnly(), '大阪');
$osakaReply = DateClarificationLineFormatter::formatFromTourContext($osakaCtx);
assert_customer_clarification($osakaReply, '大阪');
hybrid_test_assert(strpos($osakaReply, '請問您預計什麼時候出發【大阪】呢？') !== false, '大阪: destination prompt');

$hokkaidoCtx = clarification_context(
    SearchCondition::empty('北海道')->with(['destination' => ['北海道'], 'keyword' => '北海道']),
    '北海道'
);
$hokkaidoReply = DateClarificationLineFormatter::formatFromTourContext($hokkaidoCtx);
assert_customer_clarification($hokkaidoReply, '北海道');
hybrid_test_assert(strpos($hokkaidoReply, '請問您預計什麼時候出發【北海道】呢？') !== false, '北海道: destination prompt');

$freeCtx = clarification_context(GeminiDerivedSearchConditionFixtures::freeTravelGeneric(), '自由行');
$freeReply = DateClarificationLineFormatter::formatFromTourContext($freeCtx);
assert_customer_clarification($freeReply, '自由行');
hybrid_test_assert(strpos($freeReply, '請問您預計什麼時候出發呢？') !== false, '自由行: generic prompt');
hybrid_test_assert(strpos($freeReply, '【自由行】') === false, '自由行: no bracket destination');

$composed = TourLineReplyComposer::resolve(
    'prompt',
    $tokyoCtx,
    static function (): array {
        return ['ok' => true, 'text' => 'Gemini should not run'];
    }
);
hybrid_test_assert($composed['used_fixed_tour_list'] === false, 'composer: clarification not fixed tour list');
hybrid_test_assert($composed['used_tour_fallback'] === false, 'composer: clarification not fallback');
assert_customer_clarification($composed['reply_text'], 'composer 東京');

$GLOBALS['clarify_api_calls'] = 0;
$mockClient = new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
    ++$GLOBALS['clarify_api_calls'];

    return ['ok' => true, 'http_status' => 200, 'body' => '{"success":true,"items":[]}', 'transport_error' => null];
});
$service = new TourPromptContextService();
$result = $service->buildTourContextResult([
    'userText' => '東京',
    'sno' => $travelBSno,
    'featureEnabled' => true,
    'searchClient' => $mockClient,
    'referenceDate' => $ref,
    'authoritativeIntent' => GeminiDerivedSearchConditionFixtures::tokyoClarifyIntent(),
]);
$ctx = $result->getLegacyContext();
$e2e = TourLineReplyComposer::resolve('prompt', $ctx, static fn (): array => ['ok' => true, 'text' => 'skip']);
hybrid_test_assert($GLOBALS['clarify_api_calls'] === 0, 'e2e 東京: no API');
assert_customer_clarification($e2e['reply_text'], 'e2e 東京');

hybrid_test_finish('Date Clarification LINE formatter Phase C-2');
