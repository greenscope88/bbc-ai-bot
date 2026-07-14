<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GeminiDerivedSearchConditionFixtures.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridDateRequiredGate.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'TravelBMultiSourceLinkBuilder.php';

$ref = new DateTimeImmutable('2026-06-05', new DateTimeZone('Asia/Taipei'));
$gate = new HybridDateRequiredGate();
$multiBuilder = new TravelBMultiSourceLinkBuilder();
$sno = TravelBMultiSourceLinkBuilder::TRAVEL_B_SNO;

function departure_bbctravel_url(
    TravelBMultiSourceLinkBuilder $multiBuilder,
    SearchCondition $condition,
    string $sno
): string {
    foreach ($multiBuilder->buildFromHybridCondition($condition, $sno) as $row) {
        if (($row['platform'] ?? '') === 'bbctravel') {
            return (string) ($row['search_url'] ?? '');
        }
    }

    return '';
}

$c1 = GeminiDerivedSearchConditionFixtures::kaohsiungTokyoLateJune();
$u1 = departure_bbctravel_url($multiBuilder, $c1, $sno);
hybrid_test_assert($c1->getDepartureCity() === '高雄', 'case1: departure_city 高雄');
hybrid_test_assert(strpos($u1, '/searchlist/khh/') !== false, 'case1: bbctravel /khh/');

$c2 = GeminiDerivedSearchConditionFixtures::tainanTokyoLateJune();
$u2 = departure_bbctravel_url($multiBuilder, $c2, $sno);
hybrid_test_assert($c2->getDepartureCity() === '台南', 'case2: departure_city 台南');
hybrid_test_assert(strpos($u2, '/searchlist/tnn/') !== false, 'case2: bbctravel /tnn/');

$c3 = GeminiDerivedSearchConditionFixtures::taichungTokyoLateJune();
$u3 = departure_bbctravel_url($multiBuilder, $c3, $sno);
hybrid_test_assert($c3->getDepartureCity() === '台中', 'case3: departure_city 台中');
hybrid_test_assert(strpos($u3, '/searchlist/RMG/') !== false, 'case3: bbctravel /RMG/');

$c4 = GeminiDerivedSearchConditionFixtures::tokyoLateJuneOnly();
$u4 = departure_bbctravel_url($multiBuilder, $c4, $sno);
hybrid_test_assert($c4->getDepartureCity() === null, 'case4: departure_city null');
hybrid_test_assert(strpos($u4, '/searchlist/all/') !== false, 'case4: bbctravel /all/');

$c5 = GeminiDerivedSearchConditionFixtures::tokyoDestinationOnly();
hybrid_test_assert(!$gate->evaluate($c5)->allowsSearch(), 'case5: gate blocks 東京 without dates');
hybrid_test_assert($c5->getDepartureCity() === null, 'case5: departure_city null');

$GLOBALS['departure_gate_api_calls'] = 0;
$mockClient = new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
    ++$GLOBALS['departure_gate_api_calls'];

    return [
        'ok' => true,
        'http_status' => 200,
        'body' => '{"success":true,"pagination":{"total":0},"items":[]}',
        'transport_error' => null,
    ];
});

$service = new TourPromptContextService();
$result5 = $service->buildTourContextResult([
    'userText' => '東京',
    'sno' => $sno,
    'featureEnabled' => true,
    'searchClient' => $mockClient,
    'referenceDate' => $ref,
    'authoritativeIntent' => GeminiDerivedSearchConditionFixtures::tokyoClarifyIntent(),
]);
$ctx5 = $result5->getLegacyContext();
hybrid_test_assert(strpos($ctx5, HybridDateRequiredGate::CLARIFICATION_MARKER) !== false, 'case5: date clarification context');
hybrid_test_assert($GLOBALS['departure_gate_api_calls'] === 0, 'case5: no Host B API call');
hybrid_test_assert(strpos($ctx5, 'dayitravel.bbctravel.com.tw/searchlist') === false, 'case5: no bbctravel search URL');

$osakaRecent = GeminiDerivedSearchConditionFixtures::recentOsaka();
$osakaRecentUrl = departure_bbctravel_url($multiBuilder, $osakaRecent, $sno);
hybrid_test_assert($osakaRecent->getKeyword() === '大阪', '大阪近期: keyword 大阪');
hybrid_test_assert($osakaRecent->getDateFrom() === '2026-06-06' && $osakaRecent->getDateTo() === '2026-08-05', '大阪近期: fuzzy dates');
hybrid_test_assert($gate->evaluate($osakaRecent)->allowsSearch(), '大阪近期: gate PASS');
hybrid_test_assert(strpos($osakaRecentUrl, '/searchlist/all/') !== false, '大阪近期: bbctravel /all/');
hybrid_test_assert(strpos($osakaRecentUrl, 'datefrom=2026-06-06') !== false, '大阪近期: datefrom');
hybrid_test_assert(strpos($osakaRecentUrl, 'dateto=2026-08-05') !== false, '大阪近期: dateto');

hybrid_test_finish('BBCTravel departure mapping Phase C-1B');
