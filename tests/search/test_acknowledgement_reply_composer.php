<?php

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'AcknowledgementReplyComposer.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$composer = new AcknowledgementReplyComposer();
$pool = $composer->getCandidatePool();

test_assert(count($pool) >= 3, 'pool has at least 3 candidates');
test_assert(count($pool) === count(array_unique($pool)), 'pool messages are unique');

$composed = $composer->compose('seed-ack-1');
test_assert($composed !== '', 'composed text not empty');
test_assert(
    AcknowledgementReplyComposer::isQueryInProgressSemantic($composed),
    'composed text matches query-in-progress semantic'
);

$composed2 = $composer->compose('seed-ack-1');
test_assert($composed === $composed2, 'same seed yields same acknowledgement');

$composed3 = $composer->compose('seed-ack-2');
test_assert($composed3 !== '', 'alternate seed not empty');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_acknowledgement_reply_composer (all passed)\n");
exit(0);
