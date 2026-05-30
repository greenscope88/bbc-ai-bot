<?php
declare(strict_types=1);

/**
 * Shared config-driven fixtures for multi-source search URL tests (Phase 9-B-14 / 9-B-14.1).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'MultiSourceSearchUrlBuilder.php';

const MULTI_SOURCE_TRAVEL_B_SNO = '5f99b8d665e8444d';

/**
 * @return array{
 *   searchUrlRegistry: SearchUrlBuilderRegistry,
 *   platforms: array<string, array<string, mixed>>,
 *   instances: array<string, array<string, mixed>>,
 *   templates: array<string, array<string, mixed>>
 * }
 */
function create_multi_source_fixtures(): array
{
    $platforms = multi_source_platform_fixtures();
    $instances = multi_source_instance_fixtures();
    $templates = multi_source_template_fixtures();

    $searchUrlRegistry = new SearchUrlBuilderRegistry($platforms, $instances, $templates);

    return [
        'searchUrlRegistry' => $searchUrlRegistry,
        'platforms' => $platforms,
        'instances' => $instances,
        'templates' => $templates,
    ];
}

/**
 * @param list<string> $sourceInstanceKeys
 */
function create_multi_source_builder(array $sourceInstanceKeys): MultiSourceSearchUrlBuilder
{
    $fixtures = create_multi_source_fixtures();
    $sourceRegistry = new MultiSourceSearchUrlBuilderRegistry();
    $sourceRegistry->registerSourceInstances($sourceInstanceKeys);

    return new MultiSourceSearchUrlBuilder(
        $sourceRegistry,
        $fixtures['searchUrlRegistry']
    );
}

/**
 * @param array<string, array<string, mixed>> $baseInstances
 * @return array<string, array<string, mixed>>
 */
function merge_agency_source_instances(array $baseInstances, string $agencyPrefix, string $tenantSno): array
{
    $priority = 200;
    $extra = [];

    $extra[$agencyPrefix . '_grp'] = [
        'source_instance_id' => $agencyPrefix . '_grp_instance',
        'tenant_instance_key' => $agencyPrefix . '_grp',
        'tenant_sno' => $tenantSno,
        'platform_id' => 'ext_search_alpha',
        'identifier_type' => 'query_param',
        'url_template_id' => 'ext_alpha_search_v1',
        'identifier_values' => [
            'product_category' => 'group_tour',
            'channel_id' => $agencyPrefix . '_grp',
        ],
        'enabled' => true,
        'priority' => $priority,
    ];

    $extra[$agencyPrefix . '_bbctravel'] = [
        'source_instance_id' => $agencyPrefix . '_bbctravel_instance',
        'tenant_instance_key' => $agencyPrefix . '_bbctravel',
        'tenant_sno' => $tenantSno,
        'platform_id' => 'bbctravel',
        'identifier_type' => 'query_param',
        'url_template_id' => 'bbctravel_keyword_search_v1',
        'identifier_values' => [
            'product_category' => 'group_tour',
            'GetStore' => $agencyPrefix,
        ],
        'enabled' => true,
        'priority' => $priority + 1,
    ];

    $extra[$agencyPrefix . '_private'] = [
        'source_instance_id' => $agencyPrefix . '_private_instance',
        'tenant_instance_key' => $agencyPrefix . '_private',
        'tenant_sno' => $tenantSno,
        'platform_id' => 'ext_search_beta',
        'identifier_type' => 'affiliate_param',
        'url_template_id' => 'ext_beta_search_v1',
        'identifier_values' => [
            'product_category' => 'group_tour',
            'partner_code' => $agencyPrefix . '_private',
        ],
        'enabled' => true,
        'priority' => $priority + 2,
    ];

    $extra[$agencyPrefix . '_gcs'] = [
        'source_instance_id' => $agencyPrefix . '_gcs_instance',
        'tenant_instance_key' => $agencyPrefix . '_gcs',
        'tenant_sno' => $tenantSno,
        'platform_id' => 'ext_search_gamma',
        'identifier_type' => 'fixed_url',
        'url_template_id' => 'ext_gamma_fixed_v1',
        'identifier_values' => [
            'product_category' => 'group_tour',
            'url' => 'https://catalog.example-gamma.test/' . $agencyPrefix . '/search',
        ],
        'enabled' => true,
        'priority' => $priority + 3,
    ];

    return array_merge($baseInstances, $extra);
}

/**
 * @param list<string> $sourceInstanceKeys
 * @param array<string, array<string, mixed>> $instances
 */
function create_multi_source_builder_with_instances(
    array $sourceInstanceKeys,
    array $instances
): MultiSourceSearchUrlBuilder {
    $fixtures = create_multi_source_fixtures();
    $searchUrlRegistry = new SearchUrlBuilderRegistry(
        $fixtures['platforms'],
        $instances,
        $fixtures['templates']
    );
    $sourceRegistry = new MultiSourceSearchUrlBuilderRegistry();
    $sourceRegistry->registerSourceInstances($sourceInstanceKeys);

    return new MultiSourceSearchUrlBuilder($sourceRegistry, $searchUrlRegistry);
}

/**
 * @return array<string, array<string, mixed>>
 */
function multi_source_platform_fixtures(): array
{
    return [
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
        'ext_search_alpha' => [
            'platform_id' => 'ext_search_alpha',
            'platform_name' => 'Ext Alpha',
            'platform_domain' => 'example-alpha.test',
            'platform_type' => 'external_search',
            'supported_categories' => ['group_tour', 'private_group'],
            'identifier_type' => 'query_param',
            'identifier_param_keys' => ['channel_id'],
            'adapter' => 'StubProductSourceAdapter',
            'url_template_id' => 'ext_alpha_search_v1',
        ],
        'ext_search_beta' => [
            'platform_id' => 'ext_search_beta',
            'platform_name' => 'Ext Beta',
            'platform_domain' => 'example-beta.test',
            'platform_type' => 'external_search',
            'supported_categories' => ['group_tour', 'fit'],
            'identifier_type' => 'affiliate_param',
            'identifier_param_keys' => ['partner_code'],
            'adapter' => 'StubProductSourceAdapter',
            'url_template_id' => 'ext_beta_search_v1',
        ],
        'ext_search_gamma' => [
            'platform_id' => 'ext_search_gamma',
            'platform_name' => 'Ext Gamma',
            'platform_domain' => 'example-gamma.test',
            'platform_type' => 'custom_website',
            'supported_categories' => ['group_tour', 'hotel'],
            'identifier_type' => 'fixed_url',
            'adapter' => 'StubProductSourceAdapter',
            'url_template_id' => 'ext_gamma_fixed_v1',
        ],
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function multi_source_instance_fixtures(): array
{
    $travelBSno = MULTI_SOURCE_TRAVEL_B_SNO;

    return [
        'rechoice_agenttour' => [
            'source_instance_id' => 'travel_b_agenttour_rechoice',
            'tenant_instance_key' => 'rechoice_agenttour',
            'tenant_sno' => $travelBSno,
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
            'tenant_sno' => $travelBSno,
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
            'tenant_sno' => $travelBSno,
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
        'travel_b_grp' => [
            'source_instance_id' => 'travel_b_grp_instance',
            'tenant_instance_key' => 'travel_b_grp',
            'tenant_sno' => $travelBSno,
            'platform_id' => 'ext_search_alpha',
            'identifier_type' => 'query_param',
            'url_template_id' => 'ext_alpha_search_v1',
            'identifier_values' => [
                'product_category' => 'group_tour',
                'channel_id' => 'travel_b_grp',
            ],
            'enabled' => true,
            'priority' => 40,
        ],
        'travel_b_bbctravel' => [
            'source_instance_id' => 'travel_b_bbctravel_instance',
            'tenant_instance_key' => 'travel_b_bbctravel',
            'tenant_sno' => $travelBSno,
            'platform_id' => 'bbctravel',
            'identifier_type' => 'query_param',
            'url_template_id' => 'bbctravel_keyword_search_v1',
            'identifier_values' => [
                'product_category' => 'group_tour',
                'GetStore' => 'travel_b',
            ],
            'enabled' => true,
            'priority' => 45,
        ],
        'travel_b_private' => [
            'source_instance_id' => 'travel_b_private_instance',
            'tenant_instance_key' => 'travel_b_private',
            'tenant_sno' => $travelBSno,
            'platform_id' => 'ext_search_beta',
            'identifier_type' => 'affiliate_param',
            'url_template_id' => 'ext_beta_search_v1',
            'identifier_values' => [
                'product_category' => 'group_tour',
                'partner_code' => 'travel_b_private',
            ],
            'enabled' => true,
            'priority' => 50,
        ],
        'travel_b_gcs' => [
            'source_instance_id' => 'travel_b_gcs_instance',
            'tenant_instance_key' => 'travel_b_gcs',
            'tenant_sno' => $travelBSno,
            'platform_id' => 'ext_search_gamma',
            'identifier_type' => 'fixed_url',
            'url_template_id' => 'ext_gamma_fixed_v1',
            'identifier_values' => [
                'product_category' => 'group_tour',
                'url' => 'https://catalog.example-gamma.test/travel-b/search',
            ],
            'enabled' => true,
            'priority' => 60,
        ],
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function multi_source_template_fixtures(): array
{
    return [
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
        'ext_alpha_search_v1' => [
            'template_id' => 'ext_alpha_search_v1',
            'identifier_type' => 'query_param',
            'scheme' => 'https',
            'host_pattern' => 'search.{platform_domain}',
            'path' => '/group',
            'query_param_keys' => ['channel_id'],
        ],
        'ext_beta_search_v1' => [
            'template_id' => 'ext_beta_search_v1',
            'identifier_type' => 'affiliate_param',
            'scheme' => 'https',
            'host_pattern' => 'partner.{platform_domain}',
            'path' => '/offers',
            'query_param_keys' => ['partner_code'],
        ],
        'ext_gamma_fixed_v1' => [
            'template_id' => 'ext_gamma_fixed_v1',
            'identifier_type' => 'fixed_url',
        ],
    ];
}

/**
 * @return list<string>
 */
function travel_b_source_instance_keys(): array
{
    return [
        'travel_b_grp',
        'travel_b_bbctravel',
        'travel_b_private',
        'travel_b_gcs',
    ];
}
