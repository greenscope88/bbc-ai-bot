<?php
declare(strict_types=1);

/**
 * Phase 1B-B: schedule (行程表) short URLs — Google allowlist only.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'short_url_service.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'gemini_tour_context_builder.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    } else {
        fwrite(STDOUT, "OK: {$message}\n");
    }
}

function env_snapshot(): array
{
    return [
        'SHORT_URL_SCHEDULE_LINKS_ENABLED' => getenv('SHORT_URL_SCHEDULE_LINKS_ENABLED'),
        'SHORT_URL_PUBLIC_BASE' => getenv('SHORT_URL_PUBLIC_BASE'),
    ];
}

/**
 * @param array<string, string|false> $snapshot
 */
function env_restore(array $snapshot): void
{
    foreach ($snapshot as $key => $value) {
        if ($value === false) {
            putenv($key);
            continue;
        }
        putenv($key . '=' . $value);
    }
}

$snap = env_snapshot();
putenv('SHORT_URL_PUBLIC_BASE=https://bbcshops.com/');

$driveUrl = 'https://drive.google.com/file/d/abc123/view';
$docsUrl = 'https://docs.google.com/document/d/xyz/edit';
$agtUrl = 'https://agt.tw/F3IbS5';
$lturlUrl = 'https://lturl.cc/abc';
$agentUrl = 'https://bbc.agenttour.com.tw/D000_Portal/test.aspx';

// 1. Host allowlist
test_assert(ShortUrlService::isEligibleGoogleScheduleUrl($driveUrl), '1: drive.google.com eligible');
test_assert(ShortUrlService::isEligibleGoogleScheduleUrl($docsUrl), '1: docs.google.com eligible');
test_assert(!ShortUrlService::isEligibleGoogleScheduleUrl($agtUrl), '1: agt.tw not eligible');
test_assert(!ShortUrlService::isEligibleGoogleScheduleUrl($lturlUrl), '1: lturl.cc not eligible');
test_assert(!ShortUrlService::isEligibleGoogleScheduleUrl($agentUrl), '1: agenttour not eligible');
test_assert(!ShortUrlService::isEligibleGoogleScheduleUrl(''), '1: empty not eligible');

// 2. Flag off → no shortening
$svcOff = new ShortUrlService(null, 'https://bbcshops.com/', null, false);
test_assert($svcOff->toPublicShortUrlForScheduleLink($driveUrl) === $driveUrl, '2: flag off drive unchanged');
test_assert($svcOff->toPublicShortUrlForScheduleLink($agtUrl) === $agtUrl, '2: flag off agt unchanged');

// 3. Flag on → Google short or fail-open; others unchanged
$svcOn = new ShortUrlService(null, 'https://bbcshops.com/', null, true);
$driveOut = $svcOn->toPublicShortUrlForScheduleLink($driveUrl);
$docsOut = $svcOn->toPublicShortUrlForScheduleLink($docsUrl);
$driveIsShort = (bool) preg_match('#^https://bbcshops\.com/[A-Za-z0-9]+$#', $driveOut);
$docsIsShort = (bool) preg_match('#^https://bbcshops\.com/[A-Za-z0-9]+$#', $docsOut);
test_assert($driveIsShort || $driveOut === $driveUrl, '3: drive → bbcshops short OR fail-open original');
test_assert($docsIsShort || $docsOut === $docsUrl, '3: docs → bbcshops short OR fail-open original');
test_assert($svcOn->toPublicShortUrlForScheduleLink($agtUrl) === $agtUrl, '3: agt.tw never shortened');
test_assert($svcOn->toPublicShortUrlForScheduleLink($lturlUrl) === $lturlUrl, '3: lturl.cc never shortened');
test_assert($svcOn->toPublicShortUrlForScheduleLink('') === '', '3: empty passthrough');

// 4. GeminiTourContextBuilder — per-URL mixed schLinks
$mixedItem = [
    'schLinks' => [
        ['schLinkName' => 'G', 'schLink' => $driveUrl],
        ['schLinkName' => 'A', 'schLink' => $agtUrl],
        ['schLinkName' => 'L', 'schLink' => $lturlUrl],
    ],
];
putenv('SHORT_URL_SCHEDULE_LINKS_ENABLED=1');
$lines = GeminiTourContextBuilder::formatSchLinksDisplayLines($mixedItem);
$joined = implode("\n", $lines);
test_assert(strpos($joined, '行程表：') === 0, '4: multi schedule header');
test_assert(strpos($joined, $agtUrl) !== false, '4: agt.tw preserved in context');
test_assert(strpos($joined, $lturlUrl) !== false, '4: lturl.cc preserved in context');
$hasDriveShort = (bool) preg_match('#https://bbcshops\.com/[A-Za-z0-9]+#', $joined);
$hasDriveLong = strpos($joined, $driveUrl) !== false;
test_assert($hasDriveShort || $hasDriveLong, '4: drive in context as short OR fail-open long');

// 5. Single legacy schLink
$singleItem = ['schLink' => $docsUrl];
$singleLines = GeminiTourContextBuilder::formatSchLinksDisplayLines($singleItem);
test_assert(count($singleLines) === 1, '5: single schedule line');
$singleLine = $singleLines[0];
$singleIsShort = (bool) preg_match('#^行程表：https://bbcshops\.com/[A-Za-z0-9]+$#u', $singleLine);
$singleIsLong = strpos($singleLine, $docsUrl) !== false;
test_assert($singleIsShort || $singleIsLong, '5: docs single line short OR fail-open');

env_restore($snap);

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test(s) failed.\n");
    exit(1);
}

fwrite(STDOUT, "\nAll Phase 1B-B schedule tests passed.\n");
exit(0);
