<?php
declare(strict_types=1);

/**
 * Stage 1-B-18 CLI tests for TourPromptFeatureGate.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_feature_gate.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

// 1. FEATURE_ENABLED = false (production constants)
test_assert(TourPromptFeatureGate::isEnabled(['sno' => 'e1fd133c7e8e45a1']) === false, '1: default gate off');

// 2. sno empty
test_assert(
    TourPromptFeatureGate::check(true, ['e1fd133c7e8e45a1'], [], ['sno' => '']) === false,
    '2: empty sno'
);

// 3. allowlist empty
test_assert(
    TourPromptFeatureGate::check(true, [], [], ['sno' => 'e1fd133c7e8e45a1']) === false,
    '3: empty allowlist'
);

// 4. sno not in allowlist
test_assert(
    TourPromptFeatureGate::check(true, ['allowed-sno'], [], ['sno' => 'other-sno']) === false,
    '4: sno not allowed'
);

// 5. channel allowlist mismatch
test_assert(
    TourPromptFeatureGate::check(
        true,
        ['allowed-sno'],
        ['channel-a'],
        ['sno' => 'allowed-sno', 'channelId' => 'channel-b']
    ) === false,
    '5: channel not allowed'
);

// 6. all conditions match (simulated config via check())
test_assert(
    TourPromptFeatureGate::check(true, ['allowed-sno'], [], ['sno' => 'allowed-sno']) === true,
    '6a: enabled without channel gate'
);
test_assert(
    TourPromptFeatureGate::check(
        true,
        ['allowed-sno'],
        ['channel-a'],
        ['sno' => 'allowed-sno', 'channelId' => 'channel-a']
    ) === true,
    '6b: enabled with channel gate'
);

// 7. must not throw
$threw = false;
try {
    TourPromptFeatureGate::isEnabled(['sno' => null, 'channelId' => 123, 'userId' => []]);
    TourPromptFeatureGate::check(true, ['x'], ['y'], []);
} catch (Throwable $e) {
    $threw = true;
}
test_assert($threw === false, '7: no exception');

if ($failures === 0) {
    echo "OK: TourPromptFeatureGate tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
