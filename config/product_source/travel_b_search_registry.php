<?php
declare(strict_types=1);

/**
 * Phase 9-B-27: production SearchUrlBuilder registry for travel_b pilot sources.
 *
 * @return array{
 *   platforms: array<string, array<string, mixed>>,
 *   instances: array<string, array<string, mixed>>,
 *   templates: array<string, array<string, mixed>>
 * }
 */
return [
    'platforms' => [
        'agenttour' => [
            'platform_id' => 'agenttour',
            'platform_name' => 'AgentTour',
            'platform_domain' => 'agenttour.com.tw',
            'platform_type' => 'external_search',
            'supported_categories' => ['group_tour', 'fit'],
            'identifier_type' => 'subdomain',
            'adapter' => 'StubProductSourceAdapter',
            'url_template_id' => 'agenttour_group_tour_entry_v1',
        ],
        'grp' => [
            'platform_id' => 'grp',
            'platform_name' => 'GRP',
            'platform_domain' => 'grp.com.tw',
            'platform_type' => 'external_search',
            'supported_categories' => ['group_tour'],
            'identifier_type' => 'query_param',
            'identifier_param_keys' => ['GetStore'],
            'adapter' => 'StubProductSourceAdapter',
            'url_template_id' => 'grp_keyword_search_v1',
        ],
        'bbctravel' => [
            'platform_id' => 'bbctravel',
            'platform_name' => 'BBCTravel',
            'platform_domain' => 'bbctravel.com.tw',
            'platform_type' => 'external_search',
            'supported_categories' => ['group_tour'],
            'identifier_type' => 'query_param',
            'identifier_param_keys' => ['GetStore'],
            'adapter' => 'StubProductSourceAdapter',
            'url_template_id' => 'bbctravel_keyword_search_v1',
        ],
    ],
    'instances' => [
        'rechoice_agenttour' => [
            'source_instance_id' => 'travel_b_agenttour_rechoice',
            'tenant_instance_key' => 'rechoice_agenttour',
            'tenant_sno' => '5f99b8d665e8444d',
            'platform_id' => 'agenttour',
            'identifier_type' => 'subdomain',
            'url_template_id' => 'agenttour_group_tour_entry_v1',
            'identifier_values' => [
                'tenant_subdomain' => 'rechoice-travel',
                'product_category' => 'group_tour',
                'region_code' => 'C',
                'link_params' => '0000107929248116',
            ],
            'enabled' => true,
            'priority' => 10,
        ],
        'dayitravel_grp' => [
            'source_instance_id' => 'dayitravel_grp_instance',
            'tenant_instance_key' => 'dayitravel_grp',
            'tenant_sno' => '5f99b8d665e8444d',
            'platform_id' => 'grp',
            'identifier_type' => 'query_param',
            'url_template_id' => 'grp_keyword_search_v1',
            'identifier_values' => [
                'product_category' => 'group_tour',
                'GetStore' => 'dayitravel',
            ],
            'enabled' => true,
            'priority' => 20,
        ],
        'dayitravel_bbctravel' => [
            'source_instance_id' => 'dayitravel_bbctravel_instance',
            'tenant_instance_key' => 'dayitravel_bbctravel',
            'tenant_sno' => '5f99b8d665e8444d',
            'platform_id' => 'bbctravel',
            'identifier_type' => 'query_param',
            'url_template_id' => 'bbctravel_keyword_search_v1',
            'identifier_values' => [
                'product_category' => 'group_tour',
                'GetStore' => 'dayitravel',
            ],
            'enabled' => true,
            'priority' => 30,
        ],
    ],
    'templates' => [
        'agenttour_group_tour_entry_v1' => [
            'template_id' => 'agenttour_group_tour_entry_v1',
            'identifier_type' => 'subdomain',
            'scheme' => 'https',
            'host_pattern' => '{tenant_subdomain}.{platform_domain}',
            'path' => '/Index.aspx',
            'query_template' => [
                'WebArea' => 'D10T2',
                'BizType' => 'Tour',
                'RegionCode' => '{region_code}',
                'LinkParams' => '{link_params}',
            ],
            'required_identifier_keys' => [
                'tenant_subdomain',
                'region_code',
                'link_params',
            ],
        ],
        'grp_keyword_search_v1' => [
            'template_id' => 'grp_keyword_search_v1',
            'identifier_type' => 'query_param',
            'scheme' => 'https',
            'host_pattern' => 'www.{platform_domain}',
            'path' => '/Tour/Search',
            'query_param_keys' => ['GetStore'],
        ],
        'bbctravel_keyword_search_v1' => [
            'template_id' => 'bbctravel_keyword_search_v1',
            'identifier_type' => 'query_param',
            'scheme' => 'https',
            'host_pattern' => 'www.{platform_domain}',
            'path' => '/search/tour',
            'query_param_keys' => ['GetStore'],
        ],
    ],
];
