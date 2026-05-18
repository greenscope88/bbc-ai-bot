<?php
declare(strict_types=1);

/**
 * Phase 6 Stage 3 — Host B HTTP client skeleton smoke tests.
 * No outbound HTTP, no SQL, no Host B connectivity.
 */

$root = dirname(__DIR__, 3)
    . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'api_gateway'
    . DIRECTORY_SEPARATOR . 'production'
    . DIRECTORY_SEPARATOR;

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bootstrap.php';

require_once $root . 'contracts' . DIRECTORY_SEPARATOR . 'HostBHttpRawResponse.php';
require_once $root . 'contracts' . DIRECTORY_SEPARATOR . 'HostBHttpClientInterface.php';
require_once $root . 'http' . DIRECTORY_SEPARATOR . 'HostBHttpDisabledException.php';
require_once $root . 'http' . DIRECTORY_SEPARATOR . 'HostBGatewayHttpConfig.php';
require_once $root . 'http' . DIRECTORY_SEPARATOR . 'UpstreamErrorMapper.php';
require_once $root . 'http' . DIRECTORY_SEPARATOR . 'HostBResponseNormalizer.php';
require_once $root . 'http' . DIRECTORY_SEPARATOR . 'HostBServiceEndpointMap.php';
require_once $root . 'http' . DIRECTORY_SEPARATOR . 'HostBHttpClient.php';
require_once $root . 'http' . DIRECTORY_SEPARATOR . 'HostBProxyService.php';

$failures = 0;

function t(bool $ok, string $msg): void
{
    global $failures;
    if (!$ok) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

function assertNoForbiddenIpInTree(string $dir): void
{
    $needle = '103.1.222.11';
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        if (substr($path, -4) !== '.php') {
            continue;
        }
        $contents = file_get_contents($path);
        t($contents !== false && strpos($contents, $needle) === false, "Forbidden IP literal must not appear in {$path}");
    }
}

// --- Class load & interface ---
$iface = new ReflectionClass(HostBHttpClientInterface::class);
$send = $iface->getMethod('send');
t($send->getNumberOfParameters() === 4, 'send() must have 4 parameters');
t((string) $send->getReturnType() === HostBHttpRawResponse::class, 'send() return type must be HostBHttpRawResponse');

$clientClass = new ReflectionClass(HostBHttpClient::class);
t($clientClass->implementsInterface(HostBHttpClientInterface::class), 'HostBHttpClient must implement HostBHttpClientInterface');

$clientBody = file_get_contents($root . 'http' . DIRECTORY_SEPARATOR . 'HostBHttpClient.php');
t($clientBody !== false, 'HostBHttpClient.php must be readable');
t(strpos($clientBody, 'curl_') === false, 'HostBHttpClient must not reference curl_*');
t(strpos($clientBody, 'file_get_contents') === false, 'HostBHttpClient must not use file_get_contents');
t(strpos($clientBody, 'fsockopen') === false, 'HostBHttpClient must not use fsockopen');
t(strpos($clientBody, 'stream_socket_client') === false, 'HostBHttpClient must not use stream_socket_client');

// --- Disabled-by-default ---
$disabledCfg = new HostBGatewayHttpConfig(false, '', 3, 10);
$disabledClient = new HostBHttpClient($disabledCfg);
$threwDisabled = false;
try {
    $disabledClient->send('GET', 'https://mock.invalid/example', [], '');
} catch (HostBHttpDisabledException $e) {
    $threwDisabled = true;
}
t($threwDisabled, 'Disabled client must throw HostBHttpDisabledException');

$proxy = new HostBProxyService($disabledCfg, $disabledClient);
$proxyThrew = false;
try {
    $proxy->forward('tour.search', 'GET', [], '');
} catch (HostBHttpDisabledException $e) {
    $proxyThrew = true;
}
t($proxyThrew, 'HostBProxyService must propagate HostBHttpDisabledException when HTTP is disabled');

// --- Enabled flag still blocks real HTTP (skeleton) ---
$enabledCfg = new HostBGatewayHttpConfig(true, 'https://mock.invalid', 3, 10);
$enabledClient = new HostBHttpClient($enabledCfg);
$threwSkeleton = false;
try {
    $enabledClient->send('GET', 'https://mock.invalid/example', [], '');
} catch (RuntimeException $e) {
    $threwSkeleton = strpos($e->getMessage(), 'Phase 6 Stage 3 skeleton') !== false;
}
t($threwSkeleton, 'Enabled client must throw RuntimeException with Phase 6 Stage 3 skeleton message (no HTTP)');

$enabledProxy = new HostBProxyService($enabledCfg, $enabledClient);
$proxySkeleton = false;
try {
    $enabledProxy->forward('tour.search', 'GET', [], '');
} catch (RuntimeException $e) {
    $proxySkeleton = strpos($e->getMessage(), 'Phase 6 Stage 3 skeleton') !== false;
}
t($proxySkeleton, 'Enabled proxy must still hit skeleton RuntimeException (no outbound HTTP)');

// --- Enabled + missing base URL ---
$enabledNoBase = new HostBGatewayHttpConfig(true, '', 3, 10);
$badProxy = new HostBProxyService($enabledNoBase, new HostBHttpClient($enabledNoBase));
$threwNoBase = false;
try {
    $badProxy->forward('tour.search', 'GET', [], '');
} catch (RuntimeException $e) {
    $threwNoBase = strpos($e->getMessage(), 'GATEWAY_HOSTB_BASE_URL') !== false;
}
t($threwNoBase, 'Missing base URL must throw before transport when HTTP is enabled');

// --- UpstreamErrorMapper ---
$m = UpstreamErrorMapper::map(503);
t($m['errorCode'] === 'UPSTREAM_SERVER_ERROR', 'Mapper should classify 5xx');

// --- Response normalizer ---
$dirtyJson = json_encode([
    'ok' => true,
    'stackTrace' => 'at SomeType.cs:line 99',
    'detail' => 'SqlException: timeout',
    'nested' => ['url' => 'http://internal/upstream'],
    'msg' => 'redirect to http://secret.internal/path',
], JSON_THROW_ON_ERROR);

$decoded = HostBResponseNormalizer::decodeJsonBody($dirtyJson, 'trace-xyz');
t(!isset($decoded['stackTrace']), 'Normalizer must drop stackTrace key');
t(isset($decoded['detail']) && $decoded['detail'] === '[redacted]', 'Normalizer must redact SQL-like detail strings');
t(!isset($decoded['nested']['url']), 'Normalizer must remove sensitive nested url key');
t(isset($decoded['msg']) && $decoded['msg'] === '[redacted]', 'Normalizer must redact URL-like string values');

$bad = HostBResponseNormalizer::decodeJsonBody('not-json', 't1');
t(isset($bad['success']) && $bad['success'] === false, 'Invalid JSON must yield failure envelope');

// --- No forbidden IP in production contracts/http ---
assertNoForbiddenIpInTree($root . 'contracts');
assertNoForbiddenIpInTree($root . 'http');

if ($failures > 0) {
    fwrite(STDERR, "Phase 6 Stage 3 Host B HTTP skeleton tests failed ({$failures}).\n");
    exit(1);
}

fwrite(STDOUT, "Phase 6 Stage 3 Host B HTTP skeleton tests passed.\n");
exit(0);
