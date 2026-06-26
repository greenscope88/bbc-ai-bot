<?php

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'conversation'
    . DIRECTORY_SEPARATOR . 'CustomerMemoryCard.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

// --- Construction & defaults -------------------------------------------------
$card = CustomerMemoryCard::create('conv-card-1');
test_assert($card->getConversationId() === 'conv-card-1', 'conversation id set');
test_assert($card->getCurrentRequirement() === null, 'default current requirement null');
test_assert($card->getConversationStage() === CustomerMemoryCard::STAGE_NEW, 'default stage New');
test_assert($card->getCompletedItems() === [], 'default completed empty');
test_assert($card->getOutstandingIssues() === [], 'default outstanding empty');
test_assert($card->getHumanHandoffStatus() === CustomerMemoryCard::HANDOFF_AI, 'default handoff AI');
test_assert($card->getAiSummary() === null, 'default ai summary null');
test_assert($card->getRecentlyRecommendedProducts() === [], 'default recommended empty');

// --- Empty conversation id rejected ------------------------------------------
$threw = false;
try {
    CustomerMemoryCard::create('   ');
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
test_assert($threw, 'empty conversation id throws');

// --- 7 core fields populate --------------------------------------------------
$card->setCurrentRequirement('北海道 八月 四萬 親子');
test_assert($card->getCurrentRequirement() === '北海道 八月 四萬 親子', 'current requirement set');

$card->setConversationStage(CustomerMemoryCard::STAGE_ACTIVE);
test_assert($card->getConversationStage() === CustomerMemoryCard::STAGE_ACTIVE, 'stage active');

$card->addCompletedItem('已確認目的地');
$card->addCompletedItem('已確認目的地'); // duplicate ignored
$card->addCompletedItem('  '); // empty ignored
test_assert($card->getCompletedItems() === ['已確認目的地'], 'completed dedupe + skip empty');

$card->addOutstandingIssue('等待報價');
$card->addOutstandingIssue('等待日期');
test_assert($card->getOutstandingIssues() === ['等待報價', '等待日期'], 'outstanding appended');

$card->setHumanHandoffStatus(CustomerMemoryCard::HANDOFF_HUMAN);
test_assert($card->getHumanHandoffStatus() === CustomerMemoryCard::HANDOFF_HUMAN, 'handoff human');

$card->setAiSummary('客戶想要北海道親子八月行程，等待報價。');
test_assert($card->getAiSummary() !== null, 'ai summary set');

$card->addRecommendedProduct('TOUR-001');
$card->addRecommendedProduct('TOUR-002');
test_assert(
    $card->getRecentlyRecommendedProducts() === ['TOUR-001', 'TOUR-002'],
    'recommended appended in order'
);

// --- resolveOutstandingIssue moves issue to completed ------------------------
$card->resolveOutstandingIssue('等待報價');
test_assert(
    $card->getOutstandingIssues() === ['等待日期'],
    'resolve removes from outstanding'
);
test_assert(
    in_array('等待報價', $card->getCompletedItems(), true),
    'resolve adds to completed'
);

// --- recommended product move-to-end semantics ------------------------------
$card->addRecommendedProduct('TOUR-001'); // re-recommend -> moves to end
test_assert(
    $card->getRecentlyRecommendedProducts() === ['TOUR-002', 'TOUR-001'],
    'recommended move-to-end on repeat'
);

// --- Invalid stage / handoff rejected ----------------------------------------
$threw = false;
try {
    $card->setConversationStage('Bogus');
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
test_assert($threw, 'invalid stage throws');

$threw = false;
try {
    $card->setHumanHandoffStatus('ROBOT');
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
test_assert($threw, 'invalid handoff throws');

// --- toArray / fromArray round-trip ------------------------------------------
$card->touch('2026-06-26T16:00:00+08:00');
$array = $card->toArray();
test_assert($array['conversation_id'] === 'conv-card-1', 'toArray conversation id');
test_assert(count($array) === 9, 'toArray has 7 fields + id + updated_at');

$restored = CustomerMemoryCard::fromArray($array);
test_assert($restored->toArray() === $array, 'fromArray round-trip equals toArray');
test_assert($restored->getConversationStage() === CustomerMemoryCard::STAGE_ACTIVE, 'round-trip stage');
test_assert($restored->getUpdatedAt() === '2026-06-26T16:00:00+08:00', 'round-trip updated_at');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_customer_memory_card (all passed)\n");
exit(0);
