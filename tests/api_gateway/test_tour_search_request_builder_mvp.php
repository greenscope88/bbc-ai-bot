<?php

declare(strict_types=1);



/**

 * MVP tests for TourSearchRequestBuilder (dry-run; no HTTP).

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



$failures = 0;



function t(bool $ok, string $msg): void

{

    global $failures;

    if (!$ok) {

        ++$failures;

        fwrite(STDERR, "FAIL: {$msg}\n");

    }

}



function tenantFixture(string $sno = 'tenant-sno-default'): array

{

    return [

        'sno' => $sno,

        'depID' => 'D1',

        'storeNo' => 'SNO1',

        'store_uid' => 'uid-9',

        'provider_id_no' => 'P1',

    ];

}



function assertHostBQueryShape(array $q): void

{

    t(!isset($q['depID']), 'query must not contain depID');

    t(!isset($q['storeNo']), 'query must not contain storeNo');

    t(!isset($q['store_uid']), 'query must not contain store_uid');

    t(!isset($q['provider_id_no']), 'query must not contain provider_id_no');

    t(!isset($q['sql']) && !isset($q['table']) && !isset($q['column']) && !isset($q['where']) && !isset($q['orderBy']), 'dangerous keys must be absent');

}



$tenant = tenantFixture('tenant-sno-fallback');



$client = [

    'keyword' => '  Tokyo  ',

    'destination' => 'Okinawa',

    'sql' => 'DROP TABLE x',

    'table' => 'users',

    'column' => 'password',

    'where' => '1=1',

    'orderBy' => 'id;--',

    'depID' => 'EVIL',

    'storeNo' => 'EVIL2',

    'store_uid' => 'EVILUID',

    'provider_id_no' => 'EVILP',

    'pageSize' => 999,

];



$built = TourSearchRequestBuilder::build($client, $tenant);



t($built['service'] === 'tour.search', 'service must be tour.search');

t($built['method'] === 'GET', 'method must be GET');

t($built['path'] === '/api/tour/search', 'path must match registry');

t($built['timeout'] === 30, 'timeout must be 30 from registry');

$q = $built['query'];

t(isset($q['sno']) && $q['sno'] === 'tenant-sno-fallback', 'sno must come from tenantContext when absent in client');

t(isset($q['keyword']) && $q['keyword'] === 'Tokyo', 'keyword must be trimmed and kept');

t(isset($q['destination']) && $q['destination'] === 'Okinawa', 'destination must be kept');

assertHostBQueryShape($q);

t(isset($q['page']) && $q['page'] === 1, 'default page must be 1 when omitted');

t(isset($q['pageSize']) && $q['pageSize'] === 50, 'pageSize must cap at 50');



$clientWithSno = array_merge($client, ['sno' => '  client-sno-99  ']);

$built2 = TourSearchRequestBuilder::build($clientWithSno, $tenant);

$q2 = $built2['query'];

t($q2['sno'] === 'client-sno-99', 'client sno must win when provided');

assertHostBQueryShape($q2);



$threw = false;

try {

    TourSearchRequestBuilder::build([], ['depID' => 'x', 'storeNo' => 'y', 'store_uid' => 'z']);

} catch (InvalidArgumentException $e) {

    $threw = strpos($e->getMessage(), 'provider_id_no') !== false;

}

t($threw, 'missing tenant internal key must throw InvalidArgumentException');



$threwSno = false;

try {

    TourSearchRequestBuilder::build(

        ['keyword' => 'x'],

        [

            'depID' => 'D',

            'storeNo' => 'S',

            'store_uid' => 'U',

            'provider_id_no' => 'P',

        ]

    );

} catch (InvalidArgumentException $e) {

    $threwSno = strpos($e->getMessage(), 'sno') !== false;

}

t($threwSno, 'missing sno in client and tenantContext must throw');



if ($failures > 0) {

    fwrite(STDERR, "test_tour_search_request_builder_mvp failed ({$failures}).\n");

    exit(1);

}



fwrite(STDOUT, "test_tour_search_request_builder_mvp passed.\n");

exit(0);

