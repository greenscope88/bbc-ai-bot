<?php
declare(strict_types=1);

/**
 * Tenant context + LINE channel → sno mapping (Host A).
 *
 * - Keys starting with LINE Bot channel id (e.g. U...) are used by TenantResolver
 *   when webhook `destination` matches; each row MUST include `sno`.
 * - Keys that are tenant `sno` (UUID) are used by TenantContextResolver for Host B API.
 */
return [
    'Ufcedee37a93230a802c30b138f6228f8' => [
        'sno' => 'e1fd133c7e8e45a1',
        'depID' => 888,
        'storeNo' => 6290,
        'provider_id_no' => 102,
    ],
    'e1fd133c7e8e45a1' => [
        'depID' => 888,
        'storeNo' => 6290,
        'store_uid' => 6290,
        'provider_id_no' => 102,
    ],
];
