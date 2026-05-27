<?php
declare(strict_types=1);

/**
 * Config-based multi-tenant registry (Phase 2A Stage 1).
 *
 * - Internal keys (travel_a, travel_b, …) are Host A authority; never accept from LINE clients.
 * - line_channel_id is resolved from webhook `destination` on the server only.
 * - sno is the Host B wire authority for tour search; depID/storeNo are Host A internal only.
 *
 * This file is read by ConfigTenantRegistry only. Runtime (saas_router, webhook) is not wired in Stage 1.
 */
return [
    'schema_version' => 1,

    /**
     * Global master switches (emergency kill). Per-tenant features still require tenant.features.*.
     */
    'global' => [
        'hybrid_search_master_enabled' => true,
        'tour_prompt_master_enabled' => true,
    ],

  /**
   * @var array<string, array<string, mixed>> tenants keyed by stable internal tenant_key
   */
    'tenants' => [
        // ---------------------------------------------------------------------
        // travel_a — current staging tenant (baseline; mirrors tenant_context_map)
        // ---------------------------------------------------------------------
        'travel_a' => [
            'display_name' => 'Staging 旅行社 A（BBC AI 驗收基線）',
            'line_channel_id' => 'Ufcedee37a93230a802c30b138f6228f8',
            'sno' => 'e1fd133c7e8e45a1',
            'depID' => 888,
            'storeNo' => 6290,
            'store_uid' => 6290,
            'provider_id_no' => 102,
            'status' => 'enabled',

            'profile' => [
                'company_name' => '旅行社客服',
                'ai_tone' => '親切',
                'travel_specialties' => '綜合旅遊',
                'price_catalog_json' => '{}',
            ],

            'features' => [
                'tour_prompt' => true,
                'hybrid_search' => true,
                'fixed_formatter' => true,
            ],

            'gemini_policy' => [
                'tone' => 'travel_assistant',
                'allow_fixed_formatter' => true,
                'allow_gemini_rewrite' => false,
            ],

            'source_policy' => [
                'host_b_enabled' => true,
                'search_client' => 'gateway_php',
            ],
        ],

        // ---------------------------------------------------------------------
        // travel_b — second agency placeholder (staging; all product features OFF)
        // ---------------------------------------------------------------------
        'travel_b' => [
            'display_name' => 'Staging 旅行社 B（占位）',
            'line_channel_id' => 'U_PLACEHOLDER_TRAVEL_B_CHANNEL',
            'sno' => '00000000-0000-4000-8000-0000000000b1',
            'depID' => 0,
            'storeNo' => 0,
            'store_uid' => 0,
            'provider_id_no' => 0,
            'status' => 'staging',

            'profile' => [
                'company_name' => '旅行社 B 客服',
                'ai_tone' => '親切',
                'travel_specialties' => '綜合旅遊',
                'price_catalog_json' => '{}',
            ],

            'features' => [
                'tour_prompt' => false,
                'hybrid_search' => false,
                'fixed_formatter' => false,
            ],

            'gemini_policy' => [
                'tone' => 'travel_assistant',
                'allow_fixed_formatter' => false,
                'allow_gemini_rewrite' => true,
            ],

            'source_policy' => [
                'host_b_enabled' => false,
                'search_client' => 'gateway_php',
            ],
        ],

        // ---------------------------------------------------------------------
        // travel_c — disabled placeholder (onboarding template; no traffic)
        // ---------------------------------------------------------------------
        'travel_c' => [
            'display_name' => '旅行社 C（停用占位）',
            'line_channel_id' => 'U_PLACEHOLDER_TRAVEL_C_CHANNEL',
            'sno' => '00000000-0000-4000-8000-0000000000c1',
            'depID' => 0,
            'storeNo' => 0,
            'store_uid' => 0,
            'provider_id_no' => 0,
            'status' => 'disabled',

            'profile' => [
                'company_name' => '旅行社 C 客服',
                'ai_tone' => '親切',
                'travel_specialties' => '綜合旅遊',
                'price_catalog_json' => '{}',
            ],

            'features' => [
                'tour_prompt' => false,
                'hybrid_search' => false,
                'fixed_formatter' => false,
            ],

            'gemini_policy' => [
                'tone' => 'travel_assistant',
                'allow_fixed_formatter' => false,
                'allow_gemini_rewrite' => false,
            ],

            'source_policy' => [
                'host_b_enabled' => false,
                'search_client' => 'gateway_php',
            ],
        ],
    ],
];
