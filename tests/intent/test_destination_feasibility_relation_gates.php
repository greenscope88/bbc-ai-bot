<?php
declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);

require_once $repoRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'DestinationExecutionGate.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'DestinationFeasibilityContracts.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'ClarificationPolicy.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_search_api_client.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiuDestinationSemanticsNormalizer.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiuProductIntentTranslator.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingResult.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';

$failures = 0;

function gate_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function gate_assert_fail_closed(array $gate, string $label): void
{
    gate_assert(
        $gate['execution_decision'] === DestinationFeasibilityContracts::EXECUTION_FAIL_CLOSED,
        "{$label} execution fail_closed"
    );
    gate_assert($gate['execution_allowed'] === false, "{$label} execution_allowed false");
    gate_assert_zero_call_meta($gate['observability'], $label);
}

function gate_assert_zero_call_meta(array $meta, string $label): void
{
    gate_assert($meta['search_condition_created'] === false, "{$label} search_condition_created false");
    gate_assert($meta['product_source_executed'] === false, "{$label} product_source_executed false");
    gate_assert($meta['host_b_executed'] === false, "{$label} host_b_executed false");
    gate_assert($meta['multi_source_links_built'] === false, "{$label} multi_source_links_built false");
    gate_assert($meta['search_group_count'] === 0, "{$label} search_group_count 0");
}

function candidate(
    string $label,
    string $role,
    string $status,
    string $reason = 'fixture'
): array {
    return [
        'label' => $label,
        'semantic_role' => $role,
        'travel_feasibility' => [
            'status' => $status,
            'feasibility_reason' => $reason,
        ],
    ];
}

/**
 * Normalize → Translate → Gate closed loop from raw Gemini entity payload.
 *
 * @param array<string, mixed> $raw
 * @return array{entities: array<string, mixed>, intent: BatsSearchIntent, gate: array<string, mixed>}
 */
function normalize_translate_gate(array $raw): array
{
    $entities = AiuDestinationSemanticsNormalizer::apply([], $raw);
    $result = AiIntentUnderstandingResult::create(AiIntentCategory::PRODUCT_SEARCH)
        ->setEntities($entities)
        ->setConfidence(0.9);
    $intent = (new AiuProductIntentTranslator())->translate($result);
    $gate = DestinationExecutionGate::evaluate($intent);

    return ['entities' => $entities, 'intent' => $intent, 'gate' => $gate];
}

/**
 * @return array{client: TourSearchApiClient, called: bool}
 */
function mock_client_tracker(): array
{
    $called = false;
    $client = new TourSearchApiClient('https://example.test/search', 5, static function () use (&$called): array {
        $called = true;
        return ['ok' => true, 'http_status' => 200, 'body' => '{}', 'transport_error' => null];
    });

    return ['client' => $client, 'called' => &$called];
}

function assert_tour_zero_call(BatsSearchIntent $intent, string $label): void
{
    $mock = mock_client_tracker();
    $res = (new TourPromptContextService())->buildTourContextResult([
        'userText' => 'gate-test',
        'sno' => 'e1fd133c7e8e45a1',
        'featureEnabled' => true,
        'searchClient' => $mock['client'],
        'authoritativeIntent' => $intent,
    ]);
    gate_assert($res->getSearchCondition() === null, "{$label} no SearchCondition");
    gate_assert($mock['called'] === false, "{$label} Host B zero-call");
    gate_assert(($res->getSearchPolicyMeta()['multi_source_links_built'] ?? true) === false, "{$label} no links");
}

// --- 2G Normalize → Translate → Gate ---

// 1. Japan + season theme → destination only Japan
$japanSeason = normalize_translate_gate([
    'destination' => ['日本'],
    'destination_relation' => 'single',
    'destination_semantics' => [
        candidate('日本', DestinationFeasibilityContracts::ROLE_TRAVEL_DESTINATION, DestinationFeasibilityContracts::FEASIBILITY_EXECUTABLE),
        candidate('花季', DestinationFeasibilityContracts::ROLE_PREFERENCE_OR_DESCRIPTOR, DestinationFeasibilityContracts::FEASIBILITY_EXECUTABLE, 'season'),
    ],
]);
gate_assert($japanSeason['entities']['destination'] === ['日本'], '2G-1 destination Japan only');
gate_assert(
    $japanSeason['gate']['execution_decision'] === DestinationFeasibilityContracts::EXECUTION_ALLOW_SINGLE_SEARCH,
    '2G-1 allow_single_search'
);

// 2. Mars non_executable
$mars = normalize_translate_gate([
    'destination' => ['火星'],
    'destination_relation' => 'single',
    'destination_semantics' => [
        candidate('火星', DestinationFeasibilityContracts::ROLE_TRAVEL_DESTINATION, DestinationFeasibilityContracts::FEASIBILITY_NON_EXECUTABLE, 'not_travel'),
    ],
]);
gate_assert($mars['entities']['destination'] === ['火星'], '2G-2 destination keeps Mars');
gate_assert($mars['gate']['observability']['semantic_gate_decision'] === DestinationFeasibilityContracts::SEMANTIC_DENY_NON_EXECUTABLE, '2G-2 semantic');
gate_assert($mars['gate']['execution_decision'] === DestinationFeasibilityContracts::EXECUTION_DENY_NON_EXECUTABLE, '2G-2 execution');
assert_tour_zero_call($mars['intent'], '2G-2 mars');

// 3. Tokyo + Mars mixed
$tokyoMars = normalize_translate_gate([
    'destination' => ['東京', '火星'],
    'destination_relation' => 'and',
    'destination_semantics' => [
        candidate('東京', DestinationFeasibilityContracts::ROLE_TRAVEL_DESTINATION, DestinationFeasibilityContracts::FEASIBILITY_EXECUTABLE),
        candidate('火星', DestinationFeasibilityContracts::ROLE_TRAVEL_DESTINATION, DestinationFeasibilityContracts::FEASIBILITY_NON_EXECUTABLE, 'x'),
    ],
]);
gate_assert($tokyoMars['entities']['destination'] === ['東京', '火星'], '2G-3 both travel destinations');
gate_assert($tokyoMars['gate']['execution_decision'] === DestinationFeasibilityContracts::EXECUTION_DENY_MIXED_DESTINATION, '2G-3 mixed');
assert_tour_zero_call($tokyoMars['intent'], '2G-3 mixed tour');

// 4. Tokyo OR Osaka capability deny
$orCities = normalize_translate_gate([
    'destination' => ['東京', '大阪'],
    'destination_relation' => 'or',
    'destination_semantics' => [
        candidate('東京', DestinationFeasibilityContracts::ROLE_TRAVEL_DESTINATION, DestinationFeasibilityContracts::FEASIBILITY_EXECUTABLE),
        candidate('大阪', DestinationFeasibilityContracts::ROLE_TRAVEL_DESTINATION, DestinationFeasibilityContracts::FEASIBILITY_EXECUTABLE),
    ],
]);
gate_assert($orCities['entities']['destination'] === ['東京', '大阪'], '2G-4 destinations');
gate_assert($orCities['gate']['observability']['semantic_gate_decision'] === DestinationFeasibilityContracts::SEMANTIC_ALLOW_OR, '2G-4 allow_or');
gate_assert(
    $orCities['gate']['observability']['capability_gate_decision'] === DestinationFeasibilityContracts::CAPABILITY_UNSUPPORTED_OR,
    '2G-4 capability'
);
gate_assert(
    $orCities['gate']['execution_decision'] === DestinationFeasibilityContracts::EXECUTION_DENY_RELATION_CAPABILITY_UNAVAILABLE,
    '2G-4 capability deny'
);
assert_tour_zero_call($orCities['intent'], '2G-4 or zero-call');

// 5. Single executable
$single = normalize_translate_gate([
    'destination' => ['東京'],
    'destination_relation' => 'single',
    'destination_semantics' => [
        candidate('東京', DestinationFeasibilityContracts::ROLE_TRAVEL_DESTINATION, DestinationFeasibilityContracts::FEASIBILITY_EXECUTABLE),
    ],
]);
gate_assert($single['gate']['execution_decision'] === DestinationFeasibilityContracts::EXECUTION_ALLOW_SINGLE_SEARCH, '2G-5 allow');

// 6. Preference only → normalizer destination empty → gate fail-closed
$prefOnly = normalize_translate_gate([
    'destination' => [],
    'destination_relation' => 'single',
    'destination_semantics' => [
        candidate('花季', DestinationFeasibilityContracts::ROLE_PREFERENCE_OR_DESCRIPTOR, DestinationFeasibilityContracts::FEASIBILITY_EXECUTABLE, 'season'),
    ],
]);
gate_assert($prefOnly['entities']['destination'] === [], '2G-6 no destination projection');
gate_assert_fail_closed($prefOnly['gate'], '2G-6 preference only gate');

// 7. Order independence
$order = normalize_translate_gate([
    'destination' => ['東京', '大阪'],
    'destination_relation' => 'and',
    'destination_semantics' => [
        candidate('東京', DestinationFeasibilityContracts::ROLE_TRAVEL_DESTINATION, DestinationFeasibilityContracts::FEASIBILITY_EXECUTABLE),
        candidate('大阪', DestinationFeasibilityContracts::ROLE_TRAVEL_DESTINATION, DestinationFeasibilityContracts::FEASIBILITY_EXECUTABLE),
    ],
]);
gate_assert($order['entities']['destination'] === ['東京', '大阪'], '2G-7 order preserved projection');
gate_assert($order['gate']['execution_decision'] !== DestinationFeasibilityContracts::EXECUTION_FAIL_CLOSED, '2G-7 not fail_closed');

$orOsakaTokyo = normalize_translate_gate([
    'destination' => ['大阪', '東京'],
    'destination_relation' => 'or',
    'destination_semantics' => [
        candidate('大阪', DestinationFeasibilityContracts::ROLE_TRAVEL_DESTINATION, DestinationFeasibilityContracts::FEASIBILITY_EXECUTABLE),
        candidate('東京', DestinationFeasibilityContracts::ROLE_TRAVEL_DESTINATION, DestinationFeasibilityContracts::FEASIBILITY_EXECUTABLE),
    ],
]);
gate_assert($orOsakaTokyo['entities']['destination'] === ['大阪', '東京'], '2I-6 osaka-tokyo order');

$parityOrder = normalize_translate_gate([
    'destination' => ['大阪', '東京'],
    'destination_relation' => 'and',
    'destination_semantics' => [
        candidate('大阪', DestinationFeasibilityContracts::ROLE_TRAVEL_DESTINATION, DestinationFeasibilityContracts::FEASIBILITY_EXECUTABLE),
        candidate('東京', DestinationFeasibilityContracts::ROLE_TRAVEL_DESTINATION, DestinationFeasibilityContracts::FEASIBILITY_EXECUTABLE),
    ],
]);
$parityIntent = $parityOrder['intent']->with(['destination' => ['東京', '大阪']]);
gate_assert(
    DestinationExecutionGate::evaluate($parityIntent)['execution_decision'] !== DestinationFeasibilityContracts::EXECUTION_FAIL_CLOSED,
    '2I-7 parity ignores destination order'
);

// 8. Mismatch after translate (tamper destination)
$tamper = $japanSeason['intent']->with(['destination' => ['日本', '花季']]);
gate_assert_fail_closed(DestinationExecutionGate::evaluate($tamper), '2G-8 parity mismatch');

// 9–10. Illegal role / feasibility at normalize
$badRoleThrew = false;
try {
    AiuDestinationSemanticsNormalizer::apply([], [
        'destination' => ['X'],
        'destination_relation' => 'single',
        'destination_semantics' => [
            ['label' => 'X', 'semantic_role' => 'not_a_role', 'travel_feasibility' => ['status' => 'executable', 'feasibility_reason' => 'x']],
        ],
    ]);
} catch (\InvalidArgumentException $e) {
    $badRoleThrew = AiuDestinationSemanticsNormalizer::reasonCodeFromMessage($e->getMessage())
        === AiuDestinationSemanticsNormalizer::REASON_INVALID_ROLE;
}
gate_assert($badRoleThrew, '2G-9 illegal role normalizer');

$badFeasThrew = false;
try {
    AiuDestinationSemanticsNormalizer::apply([], [
        'destination' => ['X'],
        'destination_relation' => 'single',
        'destination_semantics' => [
            ['label' => 'X', 'semantic_role' => 'travel_destination', 'travel_feasibility' => ['status' => 'bad', 'feasibility_reason' => 'x']],
        ],
    ]);
} catch (\InvalidArgumentException $e) {
    $badFeasThrew = AiuDestinationSemanticsNormalizer::reasonCodeFromMessage($e->getMessage())
        === AiuDestinationSemanticsNormalizer::REASON_INVALID_FEASIBILITY_STATUS;
}
gate_assert($badFeasThrew, '2G-10 illegal feasibility normalizer');

// 11. Zero travel-destination roles (covered by 2G-6)

// 12. fail-closed tour zero-call
assert_tour_zero_call($prefOnly['intent'], '2G-12 pref fail-closed');

// 13. 2E regression
$zero = new BatsSearchIntent('', BatsSearchIntent::INTENT_TOUR_SEARCH, [], [], null, '2026-07-01', '2026-07-10', null, null, null, null, null, null, null, [], [], false, null, 0.9, '', []);
gate_assert_fail_closed(DestinationExecutionGate::evaluate($zero), '2E zero-zero');
$legacy = new BatsSearchIntent('', BatsSearchIntent::INTENT_TOUR_SEARCH, ['東京'], [], null, '2026-07-01', '2026-07-10', null, null, null, null, null, null, null, [], [], false, null, 0.9, 'single', []);
gate_assert_fail_closed(DestinationExecutionGate::evaluate($legacy), '2E legacy no semantics');

// 14. Gate source scan
$gateSrc = file_get_contents($repoRoot . '/core/search/DestinationExecutionGate.php');
if ($gateSrc !== false) {
    gate_assert(strpos($gateSrc, 'allCandidateLabelsFromSemantics') === false, '2G no all-candidate parity');
    gate_assert(strpos($gateSrc, 'roleAwareDestinationLabels') !== false, '2G role-aware parity');
    gate_assert(strpos($gateSrc, 'allowedSingle') === false, '2G no allowedSingle');
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed.\n");
    exit(1);
}

fwrite(STDOUT, "OK: destination feasibility relation gates\n");
