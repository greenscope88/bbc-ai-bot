<?php

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'conversation'
    . DIRECTORY_SEPARATOR . 'ConversationMemoryRuntime.php';

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
$now = new DateTimeImmutable('2026-06-26 16:00:00', $tz);

// --- get() on unknown conversation returns null ------------------------------
$runtime = ConversationMemoryRuntime::createForTesting();
test_assert($runtime->get('unknown-conv') === null, 'get unknown returns null');

// --- loadOrCreate returns fresh New card (not yet persisted) ------------------
$fresh = $runtime->loadOrCreate('conv-runtime-1');
test_assert($fresh->getConversationStage() === CustomerMemoryCard::STAGE_NEW, 'loadOrCreate New stage');
test_assert($runtime->get('conv-runtime-1') === null, 'loadOrCreate does not persist');

// --- remember() applies grounded changes and persists ------------------------
$card = $runtime->remember('conv-runtime-1', [
    'current_requirement' => '東京 七月',
    'conversation_stage' => CustomerMemoryCard::STAGE_ACTIVE,
    'outstanding_issues' => ['等待日期', '等待報價'],
    'recently_recommended_products' => 'TOUR-TKO-7',
    'ai_summary' => '客戶詢問東京七月行程。',
], $now);

test_assert($card->getCurrentRequirement() === '東京 七月', 'remember sets requirement');
test_assert($card->getConversationStage() === CustomerMemoryCard::STAGE_ACTIVE, 'remember sets stage');
test_assert($card->getOutstandingIssues() === ['等待日期', '等待報價'], 'remember sets outstanding list');
test_assert($card->getRecentlyRecommendedProducts() === ['TOUR-TKO-7'], 'remember scalar -> list');
test_assert($card->getUpdatedAt() === $now->format(DateTimeInterface::ATOM), 'remember stamps updated_at');

$reloaded = $runtime->get('conv-runtime-1');
test_assert($reloaded !== null, 'remember persisted');
test_assert($reloaded->getAiSummary() === '客戶詢問東京七月行程。', 'persisted ai summary');

// --- remember() merges across turns (does not reset) -------------------------
$runtime->remember('conv-runtime-1', [
    'resolve_outstanding' => '等待日期',
    'completed_items' => '已提供日期建議',
], $now->modify('+1 minute'));

$merged = $runtime->get('conv-runtime-1');
test_assert($merged->getOutstandingIssues() === ['等待報價'], 'merge: resolved issue removed');
test_assert(in_array('等待日期', $merged->getCompletedItems(), true), 'merge: resolved -> completed');
test_assert(in_array('已提供日期建議', $merged->getCompletedItems(), true), 'merge: completed added');
test_assert($merged->getCurrentRequirement() === '東京 七月', 'merge: earlier field retained');

// --- syncConversationOwner mirrors Owner into Human Handoff Status ------------
$runtime->syncConversationOwner('conv-runtime-1', CustomerMemoryCard::HANDOFF_HUMAN, $now->modify('+2 minutes'));
$afterSync = $runtime->get('conv-runtime-1');
test_assert(
    $afterSync->getHumanHandoffStatus() === CustomerMemoryCard::HANDOFF_HUMAN,
    'syncConversationOwner mirrors HUMAN'
);

// --- in-memory isolation: mutating returned card does not affect store --------
$snapshot = $runtime->get('conv-runtime-1');
$snapshot->setCurrentRequirement('TAMPERED');
$again = $runtime->get('conv-runtime-1');
test_assert($again->getCurrentRequirement() === '東京 七月', 'returned card is isolated copy');

// --- forget() deletes -------------------------------------------------------
$runtime->forget('conv-runtime-1');
test_assert($runtime->get('conv-runtime-1') === null, 'forget deletes card');

// --- JSON file repository round-trip -----------------------------------------
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bbc_mem_test_' . uniqid('', true);
$jsonRepo = new JsonFileConversationMemoryRepository($tmpDir);
$fileRuntime = new ConversationMemoryRuntime($jsonRepo, $tz);

$fileRuntime->remember('conv-file-1', [
    'current_requirement' => '北海道 滑雪',
    'conversation_stage' => CustomerMemoryCard::STAGE_RESOLVED,
], $now);

$fileReloaded = $fileRuntime->get('conv-file-1');
test_assert($fileReloaded !== null, 'json repo persisted');
test_assert($fileReloaded->getCurrentRequirement() === '北海道 滑雪', 'json repo round-trip requirement');
test_assert(is_file($tmpDir . DIRECTORY_SEPARATOR . 'conv-file-1.json'), 'json file written');

// cleanup temp files
$fileRuntime->forget('conv-file-1');
if (is_dir($tmpDir)) {
    @rmdir($tmpDir);
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_conversation_memory_runtime (all passed)\n");
exit(0);
