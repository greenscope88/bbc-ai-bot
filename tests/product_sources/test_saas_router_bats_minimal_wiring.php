<?php
declare(strict_types=1);

/**
 * Phase 9-B-26C-0: Minimal wiring verification (read-only).
 *
 * This test does NOT execute SaaSRouter::handleEvent() to avoid triggering
 * legacy LINE/Gemini/SQL side effects. It verifies wiring via source markers.
 */

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'saas_router.php';
$src = @file_get_contents($path);
if (!is_string($src) || $src === '') {
    fwrite(STDERR, "FAIL: cannot read saas_router.php\n");
    exit(1);
}

// Must exist: trace functions and their calls in handleEvent.
$traceFnPos = strpos($src, 'private static function traceBatsHookReadOnly');
$traceFn2Pos = strpos($src, 'private static function traceBatsHookAfterTenantReadOnly');
$callPos = strpos($src, 'self::traceBatsHookReadOnly(');
$callPos2 = strpos($src, 'self::traceBatsHookAfterTenantReadOnly(');
test_assert($traceFnPos !== false, 'traceBatsHookReadOnly defined');
test_assert($traceFn2Pos !== false, 'traceBatsHookAfterTenantReadOnly defined');
test_assert($callPos !== false, 'traceBatsHookReadOnly called from handleEvent');
test_assert($callPos2 !== false, 'traceBatsHookAfterTenantReadOnly called after TenantResolver');

// Ensure wiring happens after signature verification.
$sigPos = strpos($src, 'verifySignature');
test_assert($sigPos !== false && $sigPos < $callPos, 'trace call after signature verify');

// Ensure existing hello/weather early returns remain before tenant-resolve/BATS post-resolve trace.
$helloPos = strpos($src, "if (\$userMessage === '你好')");
$weatherPos = strpos($src, "weather_query");
$resolvePos = strpos($src, 'TenantResolver::resolve');
test_assert($helloPos !== false && $weatherPos !== false && $resolvePos !== false, 'hello/weather/resolve markers exist');
test_assert($helloPos < $resolvePos, 'hello path remains before tenant resolve');
test_assert($weatherPos < $resolvePos, 'weather path remains before tenant resolve');

// Ensure trace-only: no intercept return in trace helpers.
$traceBlockStart = $traceFnPos;
$traceBlockEnd = strpos($src, 'private static function traceBatsHookAfterTenantReadOnly');
if ($traceBlockEnd !== false && $traceBlockEnd > $traceBlockStart) {
    $block = substr($src, $traceBlockStart, $traceBlockEnd - $traceBlockStart);
    test_assert(stripos($block, 'return [') === false, 'traceBatsHookReadOnly does not return arrays');
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_saas_router_bats_minimal_wiring (all passed)\n");
exit(0);

