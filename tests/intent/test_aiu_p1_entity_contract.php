<?php
declare(strict_types=1);

/**
 * AIU v2 P1 Entity Contract — normalize → translate → map → canonicalize → document.
 */

$root = dirname(__DIR__, 2);
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiuSemanticJsonNormalizer.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiuProductIntentTranslator.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingResult.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'BatsSearchIntentMapper.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'SearchConditionCanonicalizer.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source'
    . DIRECTORY_SEPARATOR . 'TravelBMultiSourceLinkBuilder.php';

$failures = 0;

function p1_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/**
 * @param array<string, mixed> $semanticEntities
 * @return array<string, mixed>
 */
function p1_pipeline(array $semanticEntities, string $utterance, bool $clarificationRequired = false): array
{
    $normalizer = new AiuSemanticJsonNormalizer();
    $normalized = $normalizer->normalize([
        'intent' => 'product_search',
        'entities' => $semanticEntities,
        'confidence' => 0.9,
        'clarification' => [
            'required' => $clarificationRequired,
            'reason' => $clarificationRequired ? 'missing_travel_dates' : '',
        ],
    ], $utterance);

    $result = AiIntentUnderstandingResult::create(AiIntentCategory::PRODUCT_SEARCH)
        ->setEntities($normalized['entities'])
        ->setClarification($clarificationRequired, $clarificationRequired ? 'missing_travel_dates' : '');

    $translator = new AiuProductIntentTranslator();
    $bats = $translator->translate($result);
    $mapper = new BatsSearchIntentMapper();
    $condition = $mapper->toSearchCondition($bats);
    $canonical = SearchConditionCanonicalizer::canonicalize(
        $condition ?? SearchCondition::empty($utterance)
    );
    $entityContext = (new ApiQueryMapper())->entityContext(
        $condition ?? SearchCondition::empty($utterance)
    );
    $document = TravelBMultiSourceLinkBuilder::hybridConditionToSearchDocument(
        $condition ?? SearchCondition::empty($utterance)
    );

    return [
        'normalized' => $normalized['entities'],
        'bats' => $bats,
        'condition' => $condition,
        'canonical' => $canonical,
        'entityContext' => $entityContext,
        'document' => $document,
    ];
}

$normalizer = new AiuSemanticJsonNormalizer();
$normEmpty = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => ['destination' => ['東京'], 'date_from' => '2026-08-01'],
    'confidence' => 0.9,
    'clarification' => ['required' => false, 'reason' => ''],
], '東京8月');
p1_assert(array_key_exists('duration_days', $normEmpty['entities']), 'normalizer: duration_days key present');
p1_assert($normEmpty['entities']['duration_days'] === null, 'normalizer: duration_days null when absent');
p1_assert(is_array($normEmpty['entities']['destination']), 'normalizer: destination is array (B0-4)');
p1_assert(!array_key_exists('travel_type', $normEmpty['entities']), 'normalizer: no travel_type');

$normPeople = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => [
        'destination' => ['東京'],
        'date_from' => '2026-08-01',
        'people_count' => 3,
    ],
    'confidence' => 0.9,
    'clarification' => ['required' => false, 'reason' => ''],
], '東京親子三人8月');
p1_assert($normPeople['entities']['people_count'] === 3, 'normalizer: people_count direct map');

$normProduct = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => [
        'destination' => ['北海道'],
        'date_from' => '2026-08-01',
        'product_type' => '自由行',
        'theme' => ['親子'],
    ],
    'confidence' => 0.9,
    'clarification' => ['required' => false, 'reason' => ''],
], '北海道自由行親子8月');
p1_assert(($normProduct['entities']['product_type'] ?? '') === '自由行', 'normalizer: product_type direct map');
p1_assert(in_array('親子', $normProduct['entities']['theme'] ?? [], true), 'normalizer: theme direct map');
p1_assert(!array_key_exists('travel_type', $normProduct['entities']), 'normalizer: no travel_type projection');

$fullEntity = [
    'destination' => ['東京'],
    'departure' => '台北',
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-31',
    'budget_amount' => 50000,
    'duration_days' => 5,
    'people_count' => 2,
    'product_type' => '自由行',
    'constraint' => ['含早餐', '溫泉'],
    'theme' => ['奢華'],
];
$full = p1_pipeline($fullEntity, '台北出發東京8月五天四夜自由行兩人奢華含早餐溫泉');
p1_assert($full['condition'] !== null, 'p1 full: mapper produces SearchCondition');
p1_assert($full['bats']->getDestination() === ['東京'], 'p1 entity: destination');
p1_assert($full['condition']->getDepartureCity() === '台北', 'p1 entity: departure');
p1_assert($full['condition']->getDateFrom() === '2026-08-01', 'p1 entity: date_from');
p1_assert($full['condition']->getBudgetMax() === 50000, 'p1 entity: budget from budget_amount');
p1_assert($full['condition']->getDuration() === '5', 'p1 entity: duration from duration_days');
p1_assert($full['condition']->getPeopleCount() === 2, 'p1 entity: people_count');
p1_assert($full['condition']->getProductType() === '自由行', 'p1 entity: product_type');
p1_assert(($full['normalized']['constraint'] ?? []) === ['含早餐', '溫泉'], 'p1 normalize: constraint preserved');
p1_assert(in_array('奢華', $full['normalized']['theme'] ?? [], true), 'p1 normalize: theme preserved');

$multi = p1_pipeline([
    'destination' => ['大阪'],
    'date_from' => '2026-09-01',
    'duration_days' => 7,
    'people_count' => 2,
    'product_type' => '跟團',
], '大阪9月七天跟團兩人');
p1_assert($multi['condition'] !== null, 'multi-entity: searchable');
p1_assert($multi['condition']->getProductType() === '跟團', 'multi-entity: product_type direct');
p1_assert($multi['condition']->getPeopleCount() === 2, 'multi-entity: people_count');
p1_assert($multi['condition']->getDuration() === '7', 'multi-entity: duration_days');
p1_assert($multi['document']['product_category'] === 'group_tour', 'multi-entity: 跟團 -> group_tour');

$dateGate = p1_pipeline([
    'destination' => ['東京'],
    'product_type' => '自由行',
], '東京自由行', true);
p1_assert($dateGate['bats']->isClarificationRequired() === true, 'date gate: clarification required');
p1_assert($dateGate['condition'] === null, 'date gate: mapper blocked');

$mustHaveOnly = SearchCondition::empty('溫泉')->with([
    'destination' => '北海道',
    'date_from' => '2026-10-01',
    'must_have' => ['溫泉'],
    'keyword' => null,
]);
$mustDoc = TravelBMultiSourceLinkBuilder::hybridConditionToSearchDocument($mustHaveOnly);
$mustCanon = SearchConditionCanonicalizer::canonicalize($mustHaveOnly);
p1_assert(($mustDoc['source_keyword_query'] ?? '') !== '', 'url no null: document source_keyword_query non-empty');
p1_assert(($mustDoc['destination'] ?? []) === ['北海道'], 'url no null: document destination preserved');
p1_assert(strpos((string) ($mustCanon['keyword'] ?? ''), '溫泉') !== false, 'url no null: canonical keyword includes must_have');

$ctx = p1_pipeline([
    'destination' => ['京都'],
    'date_from' => '2026-11-01',
    'duration_days' => 4,
    'people_count' => 4,
    'product_type' => '迷你團',
    'constraint' => ['含早餐'],
], '京都11月四日迷你團四人含早餐');
p1_assert($ctx['entityContext']['duration'] === '4', 'context retention: entityContext duration from duration_days');
p1_assert($ctx['entityContext']['people_count'] === 4, 'context retention: entityContext people_count');
p1_assert($ctx['entityContext']['product_type'] === '迷你團', 'context retention: entityContext product_type');
p1_assert($ctx['document']['product_category'] === 'mini_group', 'context retention: document product_category');
p1_assert(($ctx['document']['duration'] ?? '') === '4', 'context retention: document duration');
p1_assert(($ctx['normalized']['constraint'] ?? []) === ['含早餐'], 'context retention: normalized constraint');

if ($failures === 0) {
    echo "ALL PASS test_aiu_p1_entity_contract\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_aiu_p1_entity_contract\n");
exit(1);
