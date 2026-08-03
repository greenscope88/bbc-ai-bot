<?php
declare(strict_types=1);

/**
 * BDS Source Registry — local config (Phase 6A / Phase 6B-2B Drive fields).
 *
 * Aligns with BATS_DATA_SOURCE_REGISTRY.md §7.5 fields.
 * Runtime loads entries by tenant_key or sno; no hardcode in pipeline code.
 *
 * @see docs/BATS_DATA_SOURCE_REGISTRY.md
 * @see docs/BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md §Phase 6A.5
 */
return [
    'schema_version' => 'bds_source_registry.v1',

    /**
     * @var array<string, array<string, mixed>> keyed by tenant_key (tenant_name)
     */
    'tenants' => [
        'travel_b' => [
            'store_no' => 6180,
            'sno' => '5f99b8d665e8444d',
            'tenant_name' => 'travel_b',
            'tenant_key' => 'travel_b',
            'industry_code' => 'travel',
            'enabled' => true,
            'private_knowledge_sheet_id' => '1al59g7_h_VmeZiL3K0LZpbTf9GdWj5PL92WFSCs1kP8',
            // industries/travel/tenants/travel_b/01_Private_Layer/ (§6.5 SSOT)
            'private_knowledge_folder_id' => '17wrq-rrvc7ezclhWlbvdTKxHSf_Hw8pi',
            'gcs_prefix' => 'tenants/5f99b8d665e8444d/',
        ],
        'travel_d' => [
            'store_no' => 6355,
            'sno' => '5fecdf66e9224bee',
            'tenant_name' => '家樂福旅行社 總公司',
            'tenant_key' => 'travel_d',
            'industry_code' => 'travel',
            'enabled' => true,
            // Upload-mode tenant; no Google Sheet pipeline configured (BDS v1 upload mode only).
            'private_knowledge_sheet_id' => '',
            'gcs_prefix' => 'tenants/5fecdf66e9224bee/',
        ],
    ],
];
