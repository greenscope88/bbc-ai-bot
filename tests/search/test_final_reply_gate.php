<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'FinalReplyGate.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

test_assert(
    FinalReplyGate::maySendAiReply(ConversationStatusResolver::STATUS_AI_ACTIVE) === true,
    'AI_ACTIVE may send'
);

$aiGate = FinalReplyGate::evaluate(ConversationStatusResolver::STATUS_AI_ACTIVE);
test_assert(($aiGate['allowed'] ?? false) === true, 'AI_ACTIVE evaluate allowed');
test_assert(($aiGate['block_reason'] ?? 'x') === '', 'AI_ACTIVE no block reason');

test_assert(
    FinalReplyGate::maySendAiReply(ConversationStatusResolver::STATUS_HUMAN_ACTIVE) === false,
    'HUMAN_ACTIVE may not send'
);

$humanGate = FinalReplyGate::evaluate(ConversationStatusResolver::STATUS_HUMAN_ACTIVE);
test_assert(($humanGate['allowed'] ?? true) === false, 'HUMAN_ACTIVE evaluate blocked');
test_assert(($humanGate['block_reason'] ?? '') === 'human_active', 'HUMAN_ACTIVE block reason');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_final_reply_gate (all passed)\n");
exit(0);
