<?php
declare(strict_types=1);

/**
 * Staging tenant context map for Host A (TourSearch / API Gateway).
 *
 * Do NOT use mock value 1 — TenantContextResolver rejects placeholder provider_id_no.
 */
return [
    'e1fd133c7e8e45a1' => [
        'depID' => 888,
        'storeNo' => 6290,
        'store_uid' => 6290,
        // provider_id_no = bs_Provider.id_no
        // Source: bs_store.provider_no_dm = 102 → bs_Provider.id_no (verified by Host B read-only SQL JOIN)
        'provider_id_no' => 102,
    ],
];
