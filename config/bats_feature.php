<?php
declare(strict_types=1);

/**
 * BATS feature flag config (Phase 9-B-26B-4B).
 *
 * defaults.mode = disabled keeps all tenants on legacy SaaSRouter flow.
 * Pilot: set a single tenant_sno to dry_run before LINE OA dry-run (Phase 9-B-26C).
 */
return [
    'defaults' => [
        'mode' => 'disabled',
    ],
    // Phase 9-B-26C-6 controlled reply preview gate (safe default OFF).
    'controlled_reply_enabled' => false,
    'controlled_reply_tenants' => [
        '5f99b8d665e8444d',
    ],
    'controlled_reply_keyword_prefix' => 'BATS測試',
    // Phase 9-B-26C-7 controlled real LINE reply test gate (safe default OFF).
    'controlled_real_reply_enabled' => false,
    'controlled_real_reply_tenant_sno' => '5f99b8d665e8444d',
    'controlled_real_reply_tenant_key' => 'travel_b',
    'controlled_real_reply_keyword_prefix' => 'BATS測試',
    'tenants' => [
        // Pilot tenant (dry_run only — no LINE / Gemini from BATS hook in 4B)
        '5f99b8d665e8444d' => [
            'mode' => 'dry_run',
        ],
    ],
    'channels' => [],
];
