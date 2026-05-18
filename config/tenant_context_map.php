<?php
declare(strict_types=1);

/**
 * Staging tenant context map for Host A (TourSearch / API Gateway).
 *
 * provider_id_no must be confirmed by BuySmart / Host B operations before use.
 * Do NOT use mock value 1 — TenantContextResolver rejects placeholder provider_id_no.
 */
return [
    'e1fd133c7e8e45a1' => [
        'depID' => 888,
        'storeNo' => 6290,
        'store_uid' => 6290,
        // BuySmart / Host B: set confirmed provider_id_no when available.
        'provider_id_no' => null,
    ],
];
