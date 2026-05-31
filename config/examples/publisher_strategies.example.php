<?php
declare(strict_types=1);

/**
 * Publisher strategy config example (Phase 9-B-18).
 *
 * Example only — not loaded by runtime. Copy patterns to future config/publisher_strategies.php.
 *
 * Merge order (future PublisherStrategyResolver):
 *   defaults[channel] + tenants[tenant_sno][channel] (when tenant_override_policy.enabled)
 */
return [
    'defaults' => [
        'line' => [
            'schema_version' => 1,
            'channel' => 'line',
            'strategy_name' => 'line_oa_default_v1',
            'max_items' => 10,
            'message_mode' => 'multi_message_short_text',
            'link_policy' => [
                'mode' => 'short_url_preferred',
                'max_url_length' => 120,
                'allow_secondary_urls' => false,
            ],
            'text_format_policy' => [
                'max_title_length' => 40,
                'max_summary_length' => 120,
                'include_metadata_keys' => ['price_from', 'currency'],
                'truncate_suffix' => '…',
            ],
            'button_policy' => [
                'mode' => 'single_primary_action',
                'max_actions_per_item' => 1,
                'allowed_action_types' => ['open_url'],
            ],
            'fallback_policy' => [
                'on_empty_results' => 'send_keyword_search_link',
                'on_item_overflow' => 'truncate_with_notice',
                'overflow_notice' => '僅顯示前 {max_items} 筆',
            ],
            'payload_schema_version' => 1,
            'tenant_override_policy' => [
                'enabled' => true,
                'override_keys' => ['max_items', 'message_mode', 'link_policy', 'text_format_policy'],
            ],
        ],
        'gemini' => [
            'schema_version' => 1,
            'channel' => 'gemini',
            'strategy_name' => 'gemini_context_default_v1',
            'max_items' => 30,
            'message_mode' => 'structured_context_block',
            'link_policy' => [
                'mode' => 'full_url',
                'max_url_length' => 2048,
                'allow_secondary_urls' => true,
            ],
            'text_format_policy' => [
                'max_title_length' => 200,
                'max_summary_length' => 500,
                'include_metadata_keys' => ['price_from', 'currency', 'hotel_star', 'airline', 'package_type'],
                'truncate_suffix' => '…',
            ],
            'button_policy' => [
                'mode' => 'multi_action',
                'max_actions_per_item' => 3,
                'allowed_action_types' => ['open_url'],
            ],
            'fallback_policy' => [
                'on_empty_results' => 'return_empty_context_block',
                'on_item_overflow' => 'truncate_with_notice',
                'overflow_notice' => 'context truncated to {max_items} items',
            ],
            'payload_schema_version' => 1,
            'tenant_override_policy' => [
                'enabled' => true,
                'override_keys' => ['max_items', 'text_format_policy'],
            ],
        ],
        'web_chat' => [
            'schema_version' => 1,
            'channel' => 'web_chat',
            'strategy_name' => 'web_chat_card_default_v1',
            'max_items' => 15,
            'message_mode' => 'card_list',
            'link_policy' => [
                'mode' => 'full_url',
                'max_url_length' => 2048,
                'allow_secondary_urls' => true,
            ],
            'text_format_policy' => [
                'max_title_length' => 80,
                'max_summary_length' => 240,
                'include_metadata_keys' => ['price_from', 'currency', 'hotel_star'],
                'truncate_suffix' => '…',
            ],
            'button_policy' => [
                'mode' => 'multi_action',
                'max_actions_per_item' => 2,
                'allowed_action_types' => ['open_url'],
            ],
            'fallback_policy' => [
                'on_empty_results' => 'show_empty_state_card',
                'on_item_overflow' => 'truncate_with_notice',
                'overflow_notice' => '顯示前 {max_items} 筆，其餘可載入更多',
            ],
            'payload_schema_version' => 1,
            'tenant_override_policy' => [
                'enabled' => true,
                'override_keys' => ['max_items', 'button_policy'],
            ],
        ],
        'telegram' => [
            'schema_version' => 1,
            'channel' => 'telegram',
            'strategy_name' => 'telegram_markdown_default_v1',
            'max_items' => 10,
            'message_mode' => 'markdown_message',
            'link_policy' => [
                'mode' => 'full_url',
                'max_url_length' => 1024,
                'allow_secondary_urls' => false,
            ],
            'text_format_policy' => [
                'max_title_length' => 100,
                'max_summary_length' => 300,
                'include_metadata_keys' => ['price_from', 'currency'],
                'truncate_suffix' => '…',
            ],
            'button_policy' => [
                'mode' => 'single_primary_action',
                'max_actions_per_item' => 1,
                'allowed_action_types' => ['open_url'],
            ],
            'fallback_policy' => [
                'on_empty_results' => 'send_keyword_search_link',
                'on_item_overflow' => 'truncate_with_notice',
                'overflow_notice' => 'Showing first {max_items} results',
            ],
            'payload_schema_version' => 1,
            'tenant_override_policy' => [
                'enabled' => true,
                'override_keys' => ['max_items', 'text_format_policy'],
            ],
        ],
        'app' => [
            'schema_version' => 1,
            'channel' => 'app',
            'strategy_name' => 'app_structured_default_v1',
            'max_items' => 50,
            'message_mode' => 'structured_json',
            'link_policy' => [
                'mode' => 'deep_link_preferred',
                'max_url_length' => 2048,
                'allow_secondary_urls' => true,
            ],
            'text_format_policy' => [
                'max_title_length' => 120,
                'max_summary_length' => 400,
                'include_metadata_keys' => ['price_from', 'currency', 'hotel_star', 'airline', 'package_type'],
                'truncate_suffix' => '…',
            ],
            'button_policy' => [
                'mode' => 'multi_action',
                'max_actions_per_item' => 3,
                'allowed_action_types' => ['open_url'],
            ],
            'fallback_policy' => [
                'on_empty_results' => 'return_empty_result_set',
                'on_item_overflow' => 'paginate',
                'overflow_notice' => 'page_size={max_items}',
            ],
            'payload_schema_version' => 1,
            'tenant_override_policy' => [
                'enabled' => true,
                'override_keys' => ['max_items', 'link_policy', 'fallback_policy'],
            ],
        ],
    ],

    /**
     * Tenant-specific overrides (example: travel_b).
     * tenant_sno: 5f99b8d665e8444d
     */
    'tenants' => [
        '5f99b8d665e8444d' => [
            'line' => [
                'max_items' => 5,
                'message_mode' => 'multi_message_short_text',
                'link_policy' => [
                    'mode' => 'short_url_preferred',
                    'max_url_length' => 100,
                    'allow_secondary_urls' => false,
                ],
                'text_format_policy' => [
                    'max_title_length' => 36,
                    'max_summary_length' => 100,
                    'include_metadata_keys' => ['price_from', 'currency'],
                    'truncate_suffix' => '…',
                ],
            ],
        ],
    ],
];
