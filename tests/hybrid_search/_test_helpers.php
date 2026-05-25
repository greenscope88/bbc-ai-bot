<?php
declare(strict_types=1);

function hybrid_test_assert(bool $cond, string $message): void
{
    global $hybrid_test_failures;
    if (!isset($hybrid_test_failures)) {
        $hybrid_test_failures = 0;
    }
    if (!$cond) {
        ++$hybrid_test_failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    } else {
        fwrite(STDOUT, "OK: {$message}\n");
    }
}

function hybrid_test_finish(string $suite): void
{
    global $hybrid_test_failures;
    $failures = $hybrid_test_failures ?? 0;
    if ($failures > 0) {
        fwrite(STDERR, "\n{$failures} test(s) failed in {$suite}.\n");
        exit(1);
    }
    fwrite(STDOUT, "\nAll {$suite} tests passed.\n");
    exit(0);
}
