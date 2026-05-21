<?php
declare(strict_types=1);

/**
 * Stage 1-B-25: Verify SQL Server PDO DSN includes TrustServerCertificate=yes (ODBC Driver 18).
 * No live DB connection required.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$host = '103.1.222.11';
$database = 'Buysmart';

$dsn = build_sqlsrv_dsn($host, $database);

test_assert(strpos($dsn, 'TrustServerCertificate=1') !== false, 'build_sqlsrv_dsn: TrustServerCertificate=1');
test_assert(strpos($dsn, "Server={$host}") !== false, 'build_sqlsrv_dsn: host present');
test_assert(strpos($dsn, "Database={$database}") !== false, 'build_sqlsrv_dsn: database present');
test_assert(strpos($dsn, 'Password=') === false, 'build_sqlsrv_dsn: must not embed password');
test_assert(strpos($dsn, 'UID=') === false, 'build_sqlsrv_dsn: must not embed user');

$routerPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'saas_router.php';
$routerSource = is_readable($routerPath) ? (string) file_get_contents($routerPath) : '';
test_assert($routerSource !== '', 'saas_router.php readable');
test_assert(strpos($routerSource, 'build_sqlsrv_dsn') !== false, 'saas_router.php uses build_sqlsrv_dsn');
test_assert(strpos($routerSource, 'TrustServerCertificate=') === false, 'saas_router.php must not duplicate DSN flags (use build_sqlsrv_dsn)');

$bootstrapPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';
$bootstrapSource = is_readable($bootstrapPath) ? (string) file_get_contents($bootstrapPath) : '';
test_assert(strpos($bootstrapSource, 'TrustServerCertificate=1') !== false, 'bootstrap.php DSN includes TrustServerCertificate=1');

echo "DSN sample (no credentials): {$dsn}\n\n";

if ($failures === 0) {
    echo "OK: SQL Server DSN TrustServerCertificate tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
