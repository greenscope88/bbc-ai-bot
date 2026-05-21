<?php
declare(strict_types=1);

/**
 * LegacyStorefrontCrypto CLI tests (uses fixed test key only; never prints key).
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'legacy_storefront_crypto.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$testKey = 'testkey8';
$crypto = new LegacyStorefrontCrypto($testKey);

test_assert($crypto->isConfigured() === true, 'configured with test key');

$hex1 = $crypto->encryptString('90001');
test_assert(is_string($hex1) && $hex1 !== '', 'encrypt produces non-empty hex');
test_assert(preg_match('/^[0-9a-f]+$/i', (string) $hex1) === 1, 'encrypt output is hex');
test_assert(strlen((string) $hex1) % 2 === 0, 'hex length even');

$hex2 = $crypto->encryptString('90001');
test_assert($hex1 === $hex2, 'encrypt is deterministic');

$emptyKey = new LegacyStorefrontCrypto('');
test_assert($emptyKey->isConfigured() === false, 'empty key not configured');
test_assert($emptyKey->encryptString('1') === null, 'empty key returns null');

if ($failures === 0) {
    echo "OK: LegacyStorefrontCrypto tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
