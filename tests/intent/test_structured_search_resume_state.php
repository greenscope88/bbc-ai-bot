<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeIdentity.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeState.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeStateFactory.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiIntentUnderstandingResult.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiIntentCategory.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiuClarificationReasonContract.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeDispositionContract.php';

$failures = 0;
function assert_true(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$identity = StructuredSearchResumeIdentity::fromParts('t1', 'OA', 'U1');
$ref = new DateTimeImmutable('2026-07-16 12:00:00', new DateTimeZone('Asia/Taipei'));
$result = new AiIntentUnderstandingResult(AiIntentCategory::PRODUCT_SEARCH);
$result->setEntities([
    'destination' => ['日本'],
    'date_from' => null,
    'date_to' => null,
])->setClarification(true, AiuClarificationReasonContract::AIU_MISSING_TRAVEL_DATES);

$state = StructuredSearchResumeStateFactory::fromValidatedClarification(
    $identity,
    $result,
    'EVT-1',
    StructuredSearchResumeState::OP_CREATE,
    $ref,
    'trace-1',
    1
);
assert_true($state->getMissingEntity() === 'date', 'missing_entity derived from closed reason');
assert_true($state->getAiuClarificationReason() === 'missing_travel_dates', 'persist AIU reason only');
assert_true($state->getKnownEntities()['destination'] === ['日本'], 'known destination retained');
assert_true($state->getLastEventOperation() === 'create', 'create operation');

$round = StructuredSearchResumeStateFactory::fromDocument($state->toArray());
assert_true($round->getStateVersion() === 1, 'document round-trip');

$bad = $state->toArray();
$bad['known_entities']['keyword'] = 'x';
try {
    StructuredSearchResumeStateFactory::fromDocument($bad);
    assert_true(false, 'unknown known_entities key must reject');
} catch (InvalidArgumentException $e) {
    assert_true(strpos($e->getMessage(), 'unknown key') !== false, 'unknown key message');
}

$bad2 = $state->toArray();
unset($bad2['last_event_id']);
try {
    StructuredSearchResumeStateFactory::fromDocument($bad2);
    assert_true(false, 'missing required key must reject');
} catch (InvalidArgumentException $e) {
    assert_true(true, 'missing key rejected');
}

assert_true(
    AiuClarificationReasonContract::missingEntityForAiuReason('missing_destination') === 'destination',
    'destination mapping'
);

try {
    StructuredSearchResumeDispositionContract::assertValidForPriorState('', true);
    assert_true(false, 'missing disposition with prior must fail');
} catch (InvalidArgumentException $e) {
    assert_true($e->getMessage() === StructuredSearchResumeDispositionContract::FAILURE_MISSING, 'missing disp');
}

StructuredSearchResumeDispositionContract::assertValidForPriorState('continue_pending', true);
StructuredSearchResumeDispositionContract::assertValidForPriorState('', false);
StructuredSearchResumeDispositionContract::assertValidForPriorState('new_request', false);
try {
    StructuredSearchResumeDispositionContract::assertValidForPriorState('continue_pending', false);
    assert_true(false, 'continue without prior must fail');
} catch (InvalidArgumentException $e) {
    assert_true(true, 'inconsistent disposition rejected');
}

if ($failures === 0) {
    echo "ALL PASS test_structured_search_resume_state\n";
    exit(0);
}
echo "FAILED {$failures}\n";
exit(1);
