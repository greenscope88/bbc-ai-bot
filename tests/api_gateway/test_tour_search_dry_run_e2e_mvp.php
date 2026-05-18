<?php

declare(strict_types=1);



/**

 * MVP Step 5 — Dry-run E2E controlled runner for tour.search (no HTTP, no SQL, no audit write).

 *

 * Simulated chain:

 * clientParams → tenantContext → ServiceRegistry → TourSearchRequestBuilder → TourSearchDryRunProxy → response

 */



$root = dirname(__DIR__, 2)

    . DIRECTORY_SEPARATOR

    . 'core'

    . DIRECTORY_SEPARATOR

    . 'api_gateway'

    . DIRECTORY_SEPARATOR

    . 'production'

    . DIRECTORY_SEPARATOR;



require_once $root . 'ServiceRegistry.php';

require_once $root . 'TourSearchRequestBuilder.php';

require_once $root . 'TourSearchDryRunProxy.php';



$failures = 0;



function t(bool $ok, string $msg): void

{

    global $failures;

    if (!$ok) {

        ++$failures;

        fwrite(STDERR, "FAIL: {$msg}\n");

    }

}



$clientParams = [

    'keyword' => '東京',

    'page' => 1,

    'pageSize' => 20,

    'sql' => 'DROP TABLE test',

    'depID' => 'CLIENT_SHOULD_NOT_OVERRIDE',

    'storeNo' => 'CLIENT_STORE',

];



$tenantContext = [

    'sno' => 'e1fd133c7e8e45a1',

    'depID' => 'D001',

    'storeNo' => 'S001',

    'store_uid' => 'U001',

    'provider_id_no' => 'P001',

];



$traceId = 'mvp-dry-run-trace-001';



$out = TourSearchDryRunProxy::dryRun($clientParams, $tenantContext, $traceId);



t($out['service'] === 'tour.search', 'service must be tour.search');

t($out['method'] === 'GET', 'method must be GET');

t($out['path'] === '/api/tour/search', 'path must be /api/tour/search');

t($out['timeout'] === 30, 'timeout must be 30');

t(isset($out['dryRun']) && $out['dryRun'] === true, 'dryRun must be true');

t(isset($out['httpSent']) && $out['httpSent'] === false, 'httpSent must be false');

t(isset($out['traceId']) && $out['traceId'] === $traceId, 'traceId must match');



$q = $out['query'];

t(isset($q['keyword']) && $q['keyword'] === '東京', 'keyword must be 東京');

t(isset($q['sno']) && $q['sno'] === 'e1fd133c7e8e45a1', 'sno must come from tenantContext for Host B query');

t(!isset($q['sql']), 'sql must be filtered out');

t(!isset($q['depID']), 'depID must not be on Host B query (client spoof filtered)');

t(!isset($q['storeNo']), 'storeNo must not be on Host B query');

t(!isset($q['store_uid']), 'store_uid must not be on Host B query');

t(!isset($q['provider_id_no']), 'provider_id_no must not be on Host B query');



// Chain smoke: registry still resolves tour.search

$tSpec = ServiceRegistry::get('tour.search');

t($tSpec !== null && isset($tSpec['path']), 'ServiceRegistry must resolve tour.search in this E2E');



if ($failures > 0) {

    fwrite(STDERR, "test_tour_search_dry_run_e2e_mvp failed ({$failures}).\n");

    exit(1);

}



fwrite(STDOUT, "test_tour_search_dry_run_e2e_mvp passed.\n");

exit(0);

