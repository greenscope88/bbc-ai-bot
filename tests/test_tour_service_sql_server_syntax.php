<?php
declare(strict_types=1);

/**
 * Verify TourService::isServiceSupported SQL uses SQL Server TOP 1 (not MySQL LIMIT).
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

$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_service.php';
$source = is_readable($path) ? (string) file_get_contents($path) : '';

test_assert($source !== '', 'tour_service.php readable');
test_assert(stripos($source, 'SELECT TOP 1 is_supported') !== false, 'isServiceSupported must use SELECT TOP 1 is_supported');
test_assert(stripos($source, 'LIMIT 1') === false, 'tour_service.php must not contain LIMIT 1');

if ($failures === 0) {
    echo "OK: TourService SQL Server syntax check passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
