<?php
declare(strict_types=1);

/**
 * MVP Step 10 — MvpStagingHostBHttpClient (defined in production/HostBHttpClient.php) unit tests.
 * No real HTTP (dry-run env); no SQL; never prints full API key material.
 */

$root = dirname(__DIR__, 2);
$prod = $root
    . DIRECTORY_SEPARATOR
    . 'core'
    . DIRECTORY_SEPARATOR
    . 'api_gateway'
    . DIRECTORY_SEPARATOR
    . 'production'
    . DIRECTORY_SEPARATOR;

require_once $prod . 'HostBHttpClient.php';
require_once $prod . 'HostBOutboundHeaderBuilder.php';

$failures = 0;

function t(bool $ok, string $msg): void
{
    global $failures;
    if (!$ok) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$dryRunPrior = getenv('MVP_STAGING_HOSTB_HTTP_DRY_RUN');
putenv('MVP_STAGING_HOSTB_HTTP_DRY_RUN=1');

register_shutdown_function(static function () use ($dryRunPrior): void {
    if ($dryRunPrior === false) {
        putenv('MVP_STAGING_HOSTB_HTTP_DRY_RUN');
    } else {
        putenv('MVP_STAGING_HOSTB_HTTP_DRY_RUN=' . $dryRunPrior);
    }
});

$src = file_get_contents($prod . 'HostBHttpClient.php');
t($src !== false && strpos($src, 'curl_') === false, 'MVP HostB client file must not use curl_*');

$threwEmpty = false;
try {
    MvpStagingHostBHttpClient::get('', '/api', [], 10);
} catch (InvalidArgumentException $e) {
    $threwEmpty = strpos($e->getMessage(), 'baseUrl') !== false;
}
t($threwEmpty, 'empty baseUrl must throw InvalidArgumentException');

$threwPath = false;
try {
    MvpStagingHostBHttpClient::get('https://mock.invalid', 'api/tour/search', [], 10);
} catch (InvalidArgumentException $e) {
    $threwPath = strpos($e->getMessage(), 'path') !== false;
}
t($threwPath, 'path not starting with / must throw');

$threwHigh = false;
try {
    MvpStagingHostBHttpClient::get('https://mock.invalid', '/api', [], 31);
} catch (InvalidArgumentException $e) {
    $threwHigh = strpos($e->getMessage(), 'timeout') !== false;
}
t($threwHigh, 'timeout > 30 must throw');

$threwLow = false;
try {
    MvpStagingHostBHttpClient::get('https://mock.invalid', '/api', [], 0);
} catch (InvalidArgumentException $e) {
    $threwLow = strpos($e->getMessage(), 'timeout') !== false;
}
t($threwLow, 'timeout < 1 must throw');

$threwFullUrlPath = false;
try {
    MvpStagingHostBHttpClient::get('https://mock.invalid', '/http://evil.com/x', [], 10);
} catch (InvalidArgumentException $e) {
    $threwFullUrlPath = strpos($e->getMessage(), 'full URL') !== false;
}
t($threwFullUrlPath, 'full URL as path must throw');

$out = MvpStagingHostBHttpClient::get(
    'https://mock.invalid',
    '/api/tour/search',
    ['keyword' => 'test'],
    15
);
t(isset($out['httpStatus'], $out['body'], $out['error'], $out['durationMs']), 'result must include httpStatus, body, error, durationMs');
t(strpos((string) $out['error'], 'not executed') !== false, 'mock response must indicate HTTP not executed');

// --- sendGet (dry-run): headers, requestUrl, forbidden stripping, no key in URL ---
$syntheticKey = 'MvpSend_' . bin2hex(random_bytes(6)) . '_hdr';
$trace = 'trace-sendget-001';
$headersFromBuilder = HostBOutboundHeaderBuilder::build(['api_key' => $syntheticKey], $trace);
t(isset($headersFromBuilder['X-API-Key']), 'header builder must produce X-API-Key');

$headersWithForbidden = $headersFromBuilder + [
    'X-BBC-API-Key' => 'must_not_forward_bbc_zz',
    'Authorization' => 'Bearer must_not_forward_auth_zz',
];

$sendOut = MvpStagingHostBHttpClient::sendGet(
    'https://mock.invalid',
    '/api/tour/search',
    ['keyword' => 'tokyo', 'page' => 1, 'pageSize' => 20],
    $headersWithForbidden,
    10
);
t(
    array_key_exists('httpStatus', $sendOut)
    && array_key_exists('body', $sendOut)
    && array_key_exists('error', $sendOut)
    && array_key_exists('durationMs', $sendOut)
    && array_key_exists('requestUrl', $sendOut),
    'sendGet must return full envelope including requestUrl'
);
t($sendOut['error'] === 'MVP_STAGING_HTTP_DRY_RUN', 'dry-run must skip real HTTP');
$ru = (string) $sendOut['requestUrl'];
t(strpos($ru, $syntheticKey) === false, 'requestUrl must not contain X-API-Key value');
t(strpos($ru, 'must_not_forward_bbc_zz') === false, 'requestUrl must not contain stripped X-BBC-API-Key value');
t(strpos($ru, 'must_not_forward_auth_zz') === false, 'requestUrl must not contain stripped Authorization value');
t(strpos($ru, 'keyword=') !== false || strpos($ru, 'keyword%') !== false, 'requestUrl should reflect allowlisted query');
t(!array_key_exists('X-BBC-API-Key', $headersFromBuilder), 'builder must not emit X-BBC-API-Key');

// Sensitive query keys stripped from public URL only (header still used elsewhere by caller; URL clean)
$sendStrip = MvpStagingHostBHttpClient::sendGet(
    'https://mock.invalid',
    '/api/tour/search',
    ['keyword' => 'x', 'api_key' => 'should_not_appear_in_url', 'X-API-Key' => 'also_strip_from_query'],
    $headersFromBuilder,
    10
);
$ru2 = (string) $sendStrip['requestUrl'];
t(strpos($ru2, 'should_not_appear_in_url') === false, 'api_key query param must not appear in requestUrl');
t(strpos($ru2, 'also_strip_from_query') === false, 'x-api-key query param must not appear in requestUrl');

// Missing api_key: HostBOutboundHeaderBuilder blocks before HTTP client (client does not invent key)
$builderThrew = false;
try {
    HostBOutboundHeaderBuilder::build(['api_key' => ''], $trace);
} catch (InvalidArgumentException $e) {
    $builderThrew = strpos($e->getMessage(), 'api_key') !== false;
}
t($builderThrew, 'empty api_key must be rejected by HostBOutboundHeaderBuilder before sendGet');

$sendNoApiHeader = MvpStagingHostBHttpClient::sendGet(
    'https://mock.invalid',
    '/api/tour/search',
    ['keyword' => 'y'],
    ['X-BBC-Trace-Id' => $trace],
    10
);
t($sendNoApiHeader['error'] === 'MVP_STAGING_HTTP_DRY_RUN', 'sendGet without X-API-Key must still dry-run (client does not inject key)');
t(strpos((string) $sendNoApiHeader['requestUrl'], 'keyword=y') !== false || strpos((string) $sendNoApiHeader['requestUrl'], 'keyword%') !== false, 'requestUrl must reflect query without injected api key');

if ($failures > 0) {
    fwrite(STDERR, "test_host_b_http_client_mvp failed ({$failures}).\n");
    exit(1);
}

fwrite(STDOUT, 'test_host_b_http_client_mvp passed (sendGet dry-run; X-API-Key len=' . (string) strlen($syntheticKey) . ", not printed).\n");
exit(0);
