<?php

declare(strict_types=1);



/**

 * MVP tests for TourSearchDryRunProxy (no HTTP).

 */



$root = dirname(__DIR__, 2)

    . DIRECTORY_SEPARATOR

    . 'core'

    . DIRECTORY_SEPARATOR

    . 'api_gateway'

    . DIRECTORY_SEPARATOR

    . 'production'

    . DIRECTORY_SEPARATOR;



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



$tenant = [

    'sno' => 'dryrun-proxy-sno-1',

    'depID' => 'D1',

    'storeNo' => 'SNO1',

    'store_uid' => 'uid-9',

    'provider_id_no' => 'P1',

];



$client = [

    'keyword' => 'Kyoto',

    'sql' => 'bad',

    'table' => 't',

    'column' => 'c',

    'where' => '1=1',

    'orderBy' => 'x',

    'depID' => 'HACK',

    'storeNo' => 'HACK2',

    'store_uid' => 'HACKUID',

    'provider_id_no' => 'HACKP',

];



$trace = 'trace-dryrun-001';

$out = TourSearchDryRunProxy::dryRun($client, $tenant, $trace);



t($out['service'] === 'tour.search', 'service must be tour.search');

t($out['method'] === 'GET', 'method must be GET');

t($out['path'] === '/api/tour/search', 'path must match registry');

t($out['timeout'] === 30, 'timeout must be 30');

t(isset($out['dryRun']) && $out['dryRun'] === true, 'dryRun must be true');

t(isset($out['httpSent']) && $out['httpSent'] === false, 'httpSent must be false');

t(isset($out['traceId']) && $out['traceId'] === $trace, 'traceId must be preserved');



$q = $out['query'];

t(isset($q['keyword']) && $q['keyword'] === 'Kyoto', 'query must contain keyword');

t(isset($q['sno']) && $q['sno'] === 'dryrun-proxy-sno-1', 'query must contain sno from tenantContext');

t(!isset($q['depID']) && !isset($q['storeNo']) && !isset($q['store_uid']) && !isset($q['provider_id_no']), 'query must not contain internal tenant fields');

t(!isset($q['sql']) && !isset($q['table']) && !isset($q['column']) && !isset($q['where']) && !isset($q['orderBy']), 'forbidden params must be filtered');



if ($failures > 0) {

    fwrite(STDERR, "test_tour_search_dry_run_proxy_mvp failed ({$failures}).\n");

    exit(1);

}



fwrite(STDOUT, "test_tour_search_dry_run_proxy_mvp passed.\n");

exit(0);

