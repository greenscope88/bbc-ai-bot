<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'clarification' . DIRECTORY_SEPARATOR . 'ClarificationContract.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'clarification' . DIRECTORY_SEPARATOR . 'ClarificationContractFactory.php';

$failures = 0;

function factory_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$factory = new ClarificationContractFactory();

// destination_unknown → missing destination
$destContract = $factory->create([
    'clarification_required' => true,
    'clarification_reason' => ClarificationContract::REASON_DESTINATION_UNKNOWN,
    'known_entities' => [
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
        'departure' => '台北',
        'destination' => '日本',
        'utterance_noise' => 'should_be_ignored',
    ],
    'tenant' => ['tenant_sno' => '1001', 'company_name' => '旅行蜜'],
    'tone' => ['persona' => 'travel_consultant', 'allow_emoji' => true],
    'trace_id' => 'trace-dest-1',
    'conversation_id' => 'conv-1',
]);

factory_assert($destContract->getSchemaVersion() === 1, 'dest: schema_version');
factory_assert(
    $destContract->getClarificationReason() === ClarificationContract::REASON_DESTINATION_UNKNOWN,
    'dest: reason'
);
factory_assert(
    $destContract->getMissingEntity() === ClarificationContract::ENTITY_DESTINATION,
    'dest: missing_entity'
);
factory_assert($destContract->isSearchExecuted() === false, 'dest: search_executed false');
factory_assert($destContract->getMustAsk() === ['destination'], 'dest: must_ask');
factory_assert(
    in_array('date', $destContract->getMustNotAsk(), true)
    && in_array('departure', $destContract->getMustNotAsk(), true),
    'dest: must_not_ask contains known'
);
factory_assert(!isset($destContract->getKnownEntities()['destination']), 'dest: destination excluded from known');
factory_assert(
    ($destContract->getKnownEntities()['departure'] ?? null) === '台北',
    'dest: departure preserved'
);
factory_assert(!isset($destContract->getKnownEntities()['utterance_noise']), 'dest: unknown keys rejected');

$factIds = array_map(static function (array $f): string {
    return (string) ($f['fact_id'] ?? '');
}, $destContract->getGroundedFacts());
factory_assert(in_array('known.date_from', $factIds, true), 'dest: fact date_from');
factory_assert(in_array('known.departure', $factIds, true), 'dest: fact departure');
factory_assert(!in_array('known.destination', $factIds, true), 'dest: no destination fact');
factory_assert(!in_array('known.destination.0', $factIds, true), 'dest: no destination indexed fact');

// date_required → missing date
$dateContract = $factory->create([
    'clarification_reason' => ClarificationContract::REASON_DATE_REQUIRED,
    'known_entities' => [
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
        'departure' => '台北',
        'destination' => ['大阪', '京都'],
    ],
    'tenant' => ['tenant_sno' => '1001'],
    'tone' => ['persona' => 'travel_consultant', 'allow_emoji' => false],
    'trace_metadata' => ['trace_id' => 'trace-date-1', 'conversation_id' => 'conv-2'],
]);

factory_assert(
    $dateContract->getMissingEntity() === ClarificationContract::ENTITY_DATE,
    'date: missing_entity'
);
factory_assert($dateContract->getMustAsk() === ['date'], 'date: must_ask');
factory_assert(!isset($dateContract->getKnownEntities()['date_from']), 'date: date_from excluded');
factory_assert(!isset($dateContract->getKnownEntities()['date_to']), 'date: date_to excluded');
factory_assert(
    ($dateContract->getKnownEntities()['destination'] ?? null) === ['大阪', '京都'],
    'date: destination list preserved'
);

$dateFactIds = array_map(static function (array $f): string {
    return (string) ($f['fact_id'] ?? '');
}, $dateContract->getGroundedFacts());
factory_assert(in_array('known.destination.0', $dateFactIds, true), 'date: destination.0 fact');
factory_assert(in_array('known.destination.1', $dateFactIds, true), 'date: destination.1 fact');
factory_assert(!in_array('known.date_from', $dateFactIds, true), 'date: no date_from fact');

// empty / null known values skipped
$sparse = $factory->create([
    'clarification_reason' => ClarificationContract::REASON_DESTINATION_UNKNOWN,
    'known_entities' => [
        'date_from' => '',
        'date_to' => null,
        'departure' => '高雄',
        'destination' => [],
    ],
]);
factory_assert($sparse->getKnownEntities() === ['departure' => '高雄'], 'sparse: only non-empty known');
factory_assert(count($sparse->getGroundedFacts()) === 1, 'sparse: one grounded fact');

// unsupported / invalid
foreach (
    [
        ['clarification_reason' => 'price_required'],
        ['clarification_reason' => ''],
        ['clarification_reason' => ClarificationContract::REASON_DATE_REQUIRED, 'clarification_required' => false],
        [],
    ] as $idx => $bad
) {
    $threw = false;
    try {
        $factory->create($bad);
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    factory_assert($threw, "rejects invalid input #{$idx}");
}

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_clarification_contract_factory (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
