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

        'grp' => [

            'platform_id' => 'grp',

            'platform_name' => 'GRP',

            'platform_domain' => 'grp.com.tw',

            'platform_type' => 'external_search',

            'supported_categories' => ['group_tour'],

            'identifier_type' => 'subdomain',

            'adapter' => 'StubProductSourceAdapter',

            'url_template_id' => 'grp_dayitravel_classify_v1',

        ],

        'bbctravel' => [

            'platform_id' => 'bbctravel',

            'platform_name' => 'BBCTravel',

            'platform_domain' => 'bbctravel.com.tw',

            'platform_type' => 'external_search',

            'supported_categories' => ['group_tour'],

            'identifier_type' => 'subdomain',

            'adapter' => 'StubProductSourceAdapter',

            'url_template_id' => 'bbctravel_dayitravel_searchlist_v1',

        ],

        'tourcenter' => [

            'platform_id' => 'tourcenter',

            'platform_name' => 'TourCenter',

            'platform_domain' => 'tourcenter.com.tw',

            'platform_type' => 'external_search',

            'supported_categories' => ['group_tour'],

            'identifier_type' => 'subdomain',

            'adapter' => 'StubProductSourceAdapter',

            'url_template_id' => 'tourcenter_dayitravel_search_v1',

        ],

    ],

    'instances' => [

        'dayitravel_grp' => [

            'source_instance_id' => 'dayitravel_grp_instance',

            'tenant_instance_key' => 'dayitravel_grp',

            'tenant_sno' => '5f99b8d665e8444d',

            'platform_id' => 'grp',

            'identifier_type' => 'subdomain',

            'url_template_id' => 'grp_dayitravel_classify_v1',

            'identifier_values' => [

                'tenant_subdomain' => 'dayitravel',

                'product_category' => 'group_tour',

            ],

            'enabled' => true,

            'priority' => 10,

        ],

        'dayitravel_bbctravel' => [

            'source_instance_id' => 'dayitravel_bbctravel_instance',

            'tenant_instance_key' => 'dayitravel_bbctravel',

            'tenant_sno' => '5f99b8d665e8444d',

            'platform_id' => 'bbctravel',

            'identifier_type' => 'subdomain',

            'url_template_id' => 'bbctravel_dayitravel_searchlist_v1',

            'identifier_values' => [

                'tenant_subdomain' => 'dayitravel',

                'product_category' => 'group_tour',

            ],

            'enabled' => true,

            'priority' => 20,

        ],

        'dayitravel_tourcenter' => [

            'source_instance_id' => 'dayitravel_tourcenter_instance',

            'tenant_instance_key' => 'dayitravel_tourcenter',

            'tenant_sno' => '5f99b8d665e8444d',

            'platform_id' => 'tourcenter',

            'identifier_type' => 'subdomain',

            'url_template_id' => 'tourcenter_dayitravel_search_v1',

            'identifier_values' => [

                'tenant_subdomain' => 'dayitravel',

                'product_category' => 'group_tour',

                'arrive_id' => '',

                'keywords_city' => '',

            ],

            'enabled' => true,

            'priority' => 30,

        ],

    ],

    'templates' => [

        'grp_dayitravel_classify_v1' => [

            'template_id' => 'grp_dayitravel_classify_v1',

            'identifier_type' => 'subdomain',

            'scheme' => 'http',

            'host_pattern' => '{tenant_subdomain}.{platform_domain}',

            'path' => '/ClassifyProduct.aspx',

            'query_template' => [

                'l' => 'l',

                'RadDatePicker1' => '{date_from}',

                'RadDatePicker2' => '{date_to}',

                'tp' => '{keyword}',

            ],

            'required_identifier_keys' => [

                'tenant_subdomain',

                'keyword',

            ],

        ],

        'grp_dayitravel_subdomain_v1' => [

            'template_id' => 'grp_dayitravel_subdomain_v1',

            'deprecated' => true,

            'identifier_type' => 'subdomain',

            'scheme' => 'https',

            'host_pattern' => '{tenant_subdomain}.{platform_domain}',

            'path' => '/Tour/Search',

            'query_template' => [

                'GetStore' => 'dayitravel',

            ],

            'required_identifier_keys' => [

                'tenant_subdomain',

            ],

        ],

        'bbctravel_dayitravel_searchlist_v1' => [

            'template_id' => 'bbctravel_dayitravel_searchlist_v1',

            'identifier_type' => 'subdomain',

            'scheme' => 'https',

            'host_pattern' => '{tenant_subdomain}.{platform_domain}',

            'path' => '/searchlist/{departure_path_code}/',

            'query_template' => [

                'q' => '{keyword}',

                'datefrom' => '{date_from}',

                'dateto' => '{date_to}',

                'order' => '1',

                'standby' => '1',

            ],

            'required_identifier_keys' => [

                'tenant_subdomain',

                'keyword',

                'departure_path_code',

            ],

        ],

        

        'tourcenter_dayitravel_search_v1' => [

            'template_id' => 'tourcenter_dayitravel_search_v1',

            'identifier_type' => 'subdomain',

            'scheme' => 'https',

            'host_pattern' => '{tenant_subdomain}.{platform_domain}',

            'path' => '/travel/search',

            'query_template' => [

                'DepartureID' => '{departure_id}',

                'ArriveID' => '{arrive_id}',

                'GoDateStart' => '{date_from}',

                'GoDateEnd' => '{date_to}',

                'TravelType' => '0',

                'Keywords' => '{keyword}',

                'KeywordsCity' => '{keywords_city}',

            ],

            'required_identifier_keys' => [

                'tenant_subdomain',

                'keyword',

            ],

            'allow_empty_query_keys' => [

                'DepartureID',

                'ArriveID',

                'Keywords',

                'KeywordsCity',

            ],

        ],


        'tourcenter_dayitourcenter_entry_v1' => [

            'template_id' => 'tourcenter_dayitourcenter_entry_v1',

            'deprecated' => true,

            'identifier_type' => 'custom',

            'url_pattern' => 'https://dayitourcenter.com.tw/',

        ],

    ],

];

