<?php

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'conversation'
    . DIRECTORY_SEPARATOR . 'ConversationStateRuntime.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$tz = new DateTimeZone('Asia/Taipei');
$base = new DateTimeImmutable('2026-06-26 14:00:00', $tz);

// --- Owner / Status value objects --------------------------------------------
test_assert(ConversationOwner::isValid('AI') && ConversationOwner::isValid('HUMAN'), 'owner valid values');
test_assert(!ConversationOwner::isValid('ROBOT'), 'owner invalid rejected');
test_assert(ConversationStatus::isValid('WAITING_CUSTOMER'), 'status valid value');
test_assert(!ConversationStatus::isValid('AI_ACTIVE'), 'deprecated AI_ACTIVE not a valid status');
test_assert(count(ConversationStatus::all()) === 5, 'status has 5 values');
test_assert(ConversationStatus::isTerminal('COMPLETED') && ConversationStatus::isTerminal('CLOSED'), 'terminal statuses');
test_assert(!ConversationStatus::isTerminal('ACTIVE'), 'active not terminal');

// --- Defaults: unknown conversation ------------------------------------------
$runtime = ConversationStateRuntime::createForTesting();
test_assert($runtime->getTimeoutMinutes() === 3, 'default timeout 3 minutes');
test_assert($runtime->get('nope') === null, 'get unknown returns null');
test_assert($runtime->resolveEffectiveOwner('nope', $base) === ConversationOwner::AI, 'unknown owner defaults AI');
test_assert($runtime->isHumanHoldActive('nope', $base) === false, 'unknown no human hold');
test_assert($runtime->evaluateAiResume('nope', $base) === false, 'unknown no resume');

// --- loadOrCreate fresh defaults ---------------------------------------------
$fresh = $runtime->loadOrCreate('conv-1');
test_assert($fresh->getOwner() === ConversationOwner::AI, 'fresh owner AI');
test_assert($fresh->getStatus() === ConversationStatus::ACTIVE, 'fresh status ACTIVE');
test_assert($runtime->get('conv-1') === null, 'loadOrCreate does not persist');

// --- Human takeover (CA-005) -------------------------------------------------
$afterTakeover = $runtime->recordHumanAgentMessage('conv-1', $base);
test_assert($afterTakeover->getOwner() === ConversationOwner::HUMAN, 'takeover owner HUMAN');
test_assert($afterTakeover->getStatus() === ConversationStatus::WAITING_CUSTOMER, 'takeover status WAITING_CUSTOMER');
test_assert($afterTakeover->getLastHumanMessageAt() === $base->format(DateTimeInterface::ATOM), 'takeover sets last_human_message_at');
test_assert($runtime->get('conv-1') !== null, 'takeover persisted');

// --- Human hold within 3-minute window ---------------------------------------
test_assert($runtime->isHumanHoldActive('conv-1', $base->modify('+2 minutes')), 'within 3 min hold active');
test_assert($runtime->resolveEffectiveOwner('conv-1', $base->modify('+2 minutes')) === ConversationOwner::HUMAN, 'within 3 min owner HUMAN');

// --- Human hold expires after 3 minutes --------------------------------------
test_assert($runtime->isHumanHoldActive('conv-1', $base->modify('+4 minutes')) === false, 'after 3 min hold expired');
test_assert($runtime->resolveEffectiveOwner('conv-1', $base->modify('+4 minutes')) === ConversationOwner::AI, 'after 3 min effective owner AI');

// --- Sliding window: new human message extends the window --------------------
$runtime->recordHumanAgentMessage('conv-1', $base->modify('+2 minutes'));
test_assert(
    $runtime->isHumanHoldActive('conv-1', $base->modify('+4 minutes')),
    'sliding window extends hold (4 min from base, 2 min from new human msg)'
);

// --- AI Resume three conditions (CA-006) -------------------------------------
// Reset a clean conversation for resume scenarios.
$resumeBase = new DateTimeImmutable('2026-06-26 15:00:00', $tz);
$runtime->recordHumanAgentMessage('conv-2', $resumeBase);

// Condition 2 fails: no customer message yet.
test_assert(
    $runtime->evaluateAiResume('conv-2', $resumeBase->modify('+5 minutes')) === false,
    'resume false when no customer message'
);

// Condition 3 fails: customer messaged but still within 3 min.
$runtime->recordCustomerMessage('conv-2', $resumeBase->modify('+1 minute'));
test_assert(
    $runtime->evaluateAiResume('conv-2', $resumeBase->modify('+2 minutes')) === false,
    'resume false when within 3 min even with customer message'
);

// All three conditions true: owner HUMAN + customer new msg after human + >3 min.
test_assert(
    $runtime->evaluateAiResume('conv-2', $resumeBase->modify('+4 minutes')) === true,
    'resume true when all three conditions met'
);

// --- resumeToAi applies the transition ---------------------------------------
$resumed = $runtime->resumeToAi('conv-2', $resumeBase->modify('+4 minutes'));
test_assert($resumed === true, 'resumeToAi returns true');
$conv2 = $runtime->get('conv-2');
test_assert($conv2->getOwner() === ConversationOwner::AI, 'after resume owner AI');
test_assert($conv2->getStatus() === ConversationStatus::ACTIVE, 'after resume status ACTIVE');
test_assert($runtime->evaluateAiResume('conv-2', $resumeBase->modify('+5 minutes')) === false, 'no resume once owner AI');

// resumeToAi is a no-op when conditions not met
$runtime->recordHumanAgentMessage('conv-3', $resumeBase);
test_assert($runtime->resumeToAi('conv-3', $resumeBase->modify('+1 minute')) === false, 'resumeToAi no-op when not eligible');
test_assert($runtime->get('conv-3')->getOwner() === ConversationOwner::HUMAN, 'conv-3 stays HUMAN');

// --- Customer message does not change owner -----------------------------------
$runtime->recordCustomerMessage('conv-3', $resumeBase->modify('+1 minute'));
test_assert($runtime->get('conv-3')->getOwner() === ConversationOwner::HUMAN, 'customer message keeps owner HUMAN');

// --- setStatus validation -----------------------------------------------------
$runtime->setStatus('conv-3', ConversationStatus::COMPLETED, $resumeBase->modify('+10 minutes'));
test_assert($runtime->get('conv-3')->getStatus() === ConversationStatus::COMPLETED, 'setStatus COMPLETED');

$threw = false;
try {
    $runtime->setStatus('conv-3', 'BOGUS', $resumeBase);
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
test_assert($threw, 'setStatus invalid throws');

// terminal status not overwritten by takeover status logic
$runtime->recordHumanAgentMessage('conv-3', $resumeBase->modify('+11 minutes'));
test_assert($runtime->get('conv-3')->getStatus() === ConversationStatus::COMPLETED, 'terminal status preserved on takeover');

// --- In-memory isolation ------------------------------------------------------
$snapshot = $runtime->get('conv-1');
$snapshot->setOwner(ConversationOwner::AI);
test_assert($runtime->get('conv-1')->getOwner() === ConversationOwner::HUMAN, 'returned state is isolated copy');

// --- forget ------------------------------------------------------------------
$runtime->forget('conv-1');
test_assert($runtime->get('conv-1') === null, 'forget deletes state');

// --- DTO round-trip -----------------------------------------------------------
$dto = ConversationState::create('conv-rt');
$dto->setOwner(ConversationOwner::HUMAN)
    ->setStatus(ConversationStatus::WAITING_HUMAN)
    ->setLastHumanMessageAt($base->format(DateTimeInterface::ATOM))
    ->setLastCustomerMessageAt($base->modify('+1 minute')->format(DateTimeInterface::ATOM))
    ->touch($base->format(DateTimeInterface::ATOM));
$array = $dto->toArray();
test_assert(count($array) === 6, 'toArray has 6 keys');
$restored = ConversationState::fromArray($array);
test_assert($restored->toArray() === $array, 'DTO round-trip equals');
test_assert($restored->getLastHumanMessageAtDate() instanceof DateTimeImmutable, 'parse last_human_message_at date');

// --- JSON file repository round-trip ------------------------------------------
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bbc_state_test_' . uniqid('', true);
$jsonRepo = new JsonFileConversationStateRepository($tmpDir);
$fileRuntime = new ConversationStateRuntime($jsonRepo, 3, $tz);

$fileRuntime->recordHumanAgentMessage('conv-file', $base);
$fileRuntime->recordCustomerMessage('conv-file', $base->modify('+1 minute'));
$reloaded = $fileRuntime->get('conv-file');
test_assert($reloaded !== null, 'json state persisted');
test_assert($reloaded->getOwner() === ConversationOwner::HUMAN, 'json round-trip owner');
test_assert(is_file($tmpDir . DIRECTORY_SEPARATOR . 'conv-file.json'), 'json file written');
test_assert($fileRuntime->evaluateAiResume('conv-file', $base->modify('+4 minutes')) === true, 'json-backed resume eval');

$fileRuntime->forget('conv-file');
if (is_dir($tmpDir)) {
    @rmdir($tmpDir);
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_conversation_state_runtime (all passed)\n");
exit(0);
