<?php
declare(strict_types=1);

/**
 * TourDetailUrlBuilder CLI tests.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'legacy_storefront_crypto.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_detail_url_builder.php';

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
$builder = new TourDetailUrlBuilder($crypto);

$url = $builder->buildDetailUrl(6290, 90001, 555001);
test_assert(is_string($url), 'normal: url produced');
test_assert(strpos((string) $url, 'tourdate_dm.php') !== false, 'normal: detail path');
test_assert(strpos((string) $url, 'openExternalBrowser=1') !== false, 'normal: openExternalBrowser');
test_assert(strpos((string) $url, 'srcPg=CDST') !== false, 'normal: srcPg');
test_assert(strpos((string) $url, 'trsno=555001') !== false, 'normal: plain trsno');
test_assert(strpos((string) $url, 'sno=') !== false && strpos((string) $url, 'cid=') !== false, 'normal: encrypted query params');

$noCoupon = $builder->buildDetailUrl(6290, null, 555001);
test_assert($noCoupon === null, 'missing couponNo');

$noTourSeq = $builder->buildDetailUrl(6290, 90001, '');
test_assert($noTourSeq === null, 'missing tourSeqNo');

$noStore = $builder->buildDetailUrl(0, 90001, 555001);
test_assert($noStore === null, 'missing storeNo');

$noKeyBuilder = new TourDetailUrlBuilder(new LegacyStorefrontCrypto(''));
$noKeyUrl = $noKeyBuilder->buildDetailUrl(6290, 90001, 555001);
test_assert($noKeyUrl === null, 'missing key fail closed');

if ($failures === 0) {
    echo "OK: TourDetailUrlBuilder tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
