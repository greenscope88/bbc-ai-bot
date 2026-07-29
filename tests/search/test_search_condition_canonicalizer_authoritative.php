<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/search/SearchCondition.php';
require_once $root . '/core/search/SearchConditionCanonicalizer.php';
require_once $root . '/core/search/ApiQueryMapper.php';
require_once $root . '/core/product_source/SourceQueryMapper.php';

$failures = 0;
function auth_assert(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$cond = SearchCondition::empty('')->with([
    'destination' => ['荷蘭'],
    'area' => '歐洲',
    'keyword' => '歐洲 荷蘭 賞花 鬱金香',
    'search_keyword_tokens' => ['歐洲', '荷蘭', '賞花', '鬱金香'],
])->flag('bats_intent_mapped');

$canon = SearchConditionCanonicalizer::canonicalizeAuthoritative($cond);
auth_assert($canon['keyword'] === '歐洲 荷蘭 賞花 鬱金香', 'uses projected keyword only');
auth_assert($canon['destination'] === ['荷蘭'], 'destination preserved');
auth_assert($canon['city'] === '荷蘭', 'city from destination');

$empty = SearchCondition::empty('')->with([
    'destination' => ['日本'],
    'keyword' => '',
    'search_keyword_tokens' => [],
])->flag('bats_intent_mapped');
$threw = false;
try {
    SearchConditionCanonicalizer::canonicalizeAuthoritative($empty);
} catch (InvalidArgumentException $e) {
    $threw = true;
    auth_assert(strpos($e->getMessage(), 'invariant') !== false, 'empty keyword message');
}
auth_assert($threw, 'empty keyword throws');

$divergent = SearchCondition::empty('')->with([
    'destination' => ['日本'],
    'keyword' => '日本 溫泉',
    'search_keyword_tokens' => ['日本', '花季'],
])->flag('bats_intent_mapped');
$threwDiv = false;
try {
    SearchConditionCanonicalizer::canonicalizeAuthoritative($divergent);
} catch (InvalidArgumentException $e) {
    $threwDiv = true;
}
auth_assert($threwDiv, 'caseC canonicalizer divergence throws');

$missingTokens = SearchCondition::empty('')->with([
    'destination' => ['日本'],
    'keyword' => '日本 花季',
    'search_keyword_tokens' => [],
])->flag('bats_intent_mapped');
$threwMissing = false;
try {
    SourceQueryMapper::buildHostBKeywordFromSearchCondition($missingTokens);
} catch (InvalidArgumentException $e) {
    $threwMissing = true;
}
auth_assert($threwMissing, 'caseD hostB missing tokens throws');

$multi = SearchCondition::empty('')->with([
    'destination' => ['New York'],
    'keyword' => '美國 New York 百老匯',
    'search_keyword_tokens' => ['美國', 'New York', '百老匯'],
])->flag('bats_intent_mapped');
$hostB = SourceQueryMapper::buildHostBKeywordFromSearchCondition($multi);
auth_assert($hostB === '美國 New York 百老匯', 'caseA hostB keyword full projection');

$internalSpace = SearchCondition::empty('')->with([
    'destination' => ['日本'],
    'keyword' => '日本 親子 同遊 溫泉',
    'search_keyword_tokens' => ['日本', '親子 同遊', '溫泉'],
])->flag('bats_intent_mapped');
$hostB2 = SourceQueryMapper::buildHostBKeywordFromSearchCondition($internalSpace);
auth_assert($hostB2 === '日本 親子 同遊 溫泉', 'caseB hostB keyword full projection');

$noKw = SearchCondition::empty('')->with([
    'destination' => ['東京'],
    'area' => '日本',
    'must_have' => ['蜜月'],
])->flag('bats_intent_mapped');
$threw2 = false;
try {
    SearchConditionCanonicalizer::canonicalizeAuthoritative($noKw);
} catch (InvalidArgumentException $e) {
    $threw2 = true;
}
auth_assert($threw2, 'no fallback from destination/must_have');

$legacy = SearchCondition::empty('東京蜜月')->with([
    'destination' => ['東京'],
    'must_have' => ['蜜月'],
]);
$legacyCanon = SearchConditionCanonicalizer::canonicalize($legacy);
auth_assert(strpos($legacyCanon['keyword'], '蜜月') !== false, 'legacy path retains fallback');

$apiMapper = new ApiQueryMapper();
$pilotSno = '5f99b8d665e8444d';

$destOnly = SearchCondition::empty('')->with([
    'destination' => ['釜山'],
    'keyword' => '釜山',
    'search_keyword_tokens' => ['釜山'],
])->flag('bats_intent_mapped');
$destOnlyApi = $apiMapper->toClientParams($destOnly, [
    'include_sno' => $pilotSno,
    'page' => 1,
    'pageSize' => 30,
]);
auth_assert(array_key_exists('keyword', $destOnlyApi), 'dest-only: Host B keyword present');
auth_assert(($destOnlyApi['keyword'] ?? '') === '釜山', 'dest-only: keyword equals destination token');
auth_assert(($destOnlyApi['destination'] ?? '') === '釜山', 'dest-only: destination on wire');
auth_assert(($destOnlyApi['city'] ?? '') === '釜山', 'dest-only: city on wire');
auth_assert(($destOnlyApi['page'] ?? null) === 1, 'dest-only: page mapping');
auth_assert(($destOnlyApi['pageSize'] ?? null) === 30, 'dest-only: pageSize mapping');
$destOnlyObs = $apiMapper->providerWireObservability($destOnly);
auth_assert(($destOnlyObs['destination_present'] ?? false) === true, 'dest-only: destination_present true');
auth_assert(($destOnlyObs['keyword_present'] ?? false) === true, 'dest-only: obs keyword_present true');
auth_assert(
    ($destOnlyObs['provider_wire_keyword_length'] ?? 0) === mb_strlen((string) $destOnlyApi['keyword'], 'UTF-8'),
    'dest-only: obs keyword length matches final wire'
);
auth_assert(
    ($destOnlyObs['provider_wire_keyword_mode'] ?? '') === SourceQueryMapper::PROVIDER_WIRE_MODE_HOSTB_KEYWORD_FULL_PROJECTION,
    'dest-only: mode is hostb_keyword_full_projection'
);
auth_assert(
    ($destOnlyObs['provider_wire_keyword_mode'] ?? '') !== 'hostb_destination_exclude',
    'dest-only: old destination_exclude mode removed'
);

$fullProjCase = SearchCondition::empty('')->with([
    'destination' => ['日本'],
    'keyword' => '日本 花季',
    'search_keyword_tokens' => ['日本', '花季'],
])->flag('bats_intent_mapped');
$fullProjApi = $apiMapper->toClientParams($fullProjCase, ['include_sno' => $pilotSno]);
auth_assert(($fullProjApi['keyword'] ?? '') === '日本 花季', 'full-proj: Host B keeps destination surface token');
auth_assert(($fullProjApi['destination'] ?? '') === '日本', 'full-proj: destination mapping unchanged');
auth_assert(($fullProjApi['city'] ?? '') === '日本', 'full-proj: city mapping unchanged');
auth_assert(($fullProjApi['keyword'] ?? '') !== '', 'full-proj: keyword must not be empty');
$fullProjObs = $apiMapper->providerWireObservability($fullProjCase);
auth_assert(($fullProjObs['keyword_present'] ?? false) === true, 'full-proj: obs keyword_present true');
auth_assert(
    ($fullProjObs['provider_wire_keyword_length'] ?? 0) === mb_strlen((string) $fullProjApi['keyword'], 'UTF-8'),
    'full-proj: obs keyword length matches final wire'
);
auth_assert(
    ($fullProjObs['provider_wire_keyword_mode'] ?? '') === SourceQueryMapper::PROVIDER_WIRE_MODE_HOSTB_KEYWORD_FULL_PROJECTION,
    'full-proj: hostb_keyword_full_projection mode'
);

$koreaSurface = SearchCondition::empty('')->with([
    'destination' => ['韓國'],
    'keyword' => '韓國 賞花',
    'search_keyword_tokens' => ['韓國', '賞花'],
])->flag('bats_intent_mapped');
$koreaApi = $apiMapper->toClientParams($koreaSurface, ['include_sno' => $pilotSno]);
auth_assert(($koreaApi['keyword'] ?? '') === '韓國 賞花', '3J-2: Host B keyword keeps 韓國 surface token');
auth_assert(($koreaApi['destination'] ?? '') === '韓國', '3J-2: destination mapping unchanged');
auth_assert(($koreaApi['city'] ?? '') === '韓國', '3J-2: city mapping unchanged');
auth_assert(
    SourceQueryMapper::buildHostBKeywordFromSearchCondition($koreaSurface) === '韓國 賞花',
    '3J-2: SourceQueryMapper full projection'
);

$kwOnlyCase = SearchCondition::empty('')->with([
    'keyword' => '日本 花季',
    'search_keyword_tokens' => ['日本', '花季'],
])->flag('bats_intent_mapped');
$kwOnlySrc = SourceQueryMapper::buildSourceKeywordQueryFromSearchCondition($kwOnlyCase);
auth_assert($kwOnlySrc === '日本 花季', 'keyword-only: full scalar');
$kwOnlyObs = $apiMapper->providerWireObservability($kwOnlyCase);
auth_assert(
    ($kwOnlyObs['provider_wire_keyword_mode'] ?? '') === SourceQueryMapper::PROVIDER_WIRE_MODE_KEYWORD_ONLY_FULL,
    'keyword-only: keyword_only_full mode'
);
auth_assert(!array_key_exists('provider_wire_keyword_mode', $fullProjApi), 'observability not in outgoing params');

if ($failures === 0) {
    echo "ALL PASS test_search_condition_canonicalizer_authoritative\n";
    exit(0);
}
fwrite(STDERR, "{$failures} FAILURE(S)\n");
exit(1);
