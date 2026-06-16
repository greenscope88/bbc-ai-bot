<?php

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ConversationStatusResolver.php';

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
$conversationId = 'conv-status-test-1';
$store = [];
$resolver = ConversationStatusResolver::createForTesting($store);

$base = new DateTimeImmutable('2026-06-05 10:00:00', $tz);
test_assert(
    $resolver->resolveStatus($conversationId, $base) === ConversationStatusResolver::STATUS_AI_ACTIVE,
    'default AI_ACTIVE'
);

$resolver->markHumanActive($conversationId, $base);
test_assert(
    $resolver->resolveStatus($conversationId, $base) === ConversationStatusResolver::STATUS_HUMAN_ACTIVE,
    'markHumanActive -> HUMAN_ACTIVE'
);

$withinWindow = $base->modify('+4 minutes');
test_assert(
    $resolver->resolveStatus($conversationId, $withinWindow) === ConversationStatusResolver::STATUS_HUMAN_ACTIVE,
    'within 5 minutes stays HUMAN_ACTIVE'
);

$afterWindow = $base->modify('+6 minutes');
test_assert(
    $resolver->resolveStatus($conversationId, $afterWindow) === ConversationStatusResolver::STATUS_AI_ACTIVE,
    'after 5 minutes returns AI_ACTIVE'
);

$slideBase = new DateTimeImmutable('2026-06-05 11:00:00', $tz);
$resolver->markHumanActive($conversationId, $slideBase);
$resolver->recordHumanAgentMessage($conversationId, 'agent follow-up', $slideBase->modify('+3 minutes'));
$slideCheck = $slideBase->modify('+7 minutes');
test_assert(
    $resolver->resolveStatus($conversationId, $slideCheck) === ConversationStatusResolver::STATUS_HUMAN_ACTIVE,
    'sliding window extends on new human agent message'
);

$resolver->recordCustomerMessage($conversationId, 'customer query', $slideBase);
$messages = $resolver->getRecentMessages($conversationId);
test_assert(count($messages) >= 2, 'customer and human messages recorded');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_conversation_status_resolver (all passed)\n");
exit(0);
