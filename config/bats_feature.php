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
    // Phase 2-B Step 4-B Conversation Runtime shadow probe (safe default OFF).
    // Shadow-only: observes ConversationRuntimeFacade decisions and logs parity
    // against the legacy FinalReplyGate. Never changes reply behavior.
    'conversation_runtime_shadow_enabled' => false,
    'conversation_runtime_shadow_tenant_snos' => [
        '5f99b8d665e8444d', // travel_b pilot
    ],
    // Phase 2-B Step 4-C-1 Dual Gate Compare (safe default OFF).
    // Compare-only: evaluates ConversationReplyGate (Owner-based) alongside the
    // legacy FinalReplyGate and logs parity. Legacy FinalReplyGate remains the
    // sole authority; this never changes reply behavior.
    'conversation_reply_gate_compare_enabled' => false,
    'conversation_reply_gate_compare_tenant_snos' => [
        '5f99b8d665e8444d', // travel_b pilot
    ],
    'tenants' => [
        // Pilot tenant (dry_run only — no LINE / Gemini from BATS hook in 4B)
        '5f99b8d665e8444d' => [
            'mode' => 'dry_run',
        ],
    ],
    'channels' => [],
];
