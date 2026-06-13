<?php

declare(strict_types=1);



/**

 * BDS Shared Archive Policy — local config (Phase 6E-2).

 *

 * Separate from Tenant Registry and Platform Drive Registry.

 * Policy default = OFF; explicit enable required per industry / global.

 *

 * @see docs/BATS_DRIVE_GCS_MAPPING.md §18

 */

return [

    'schema_version' => 'bds_shared_archive_policy.v1',



    'defaults' => [

        'promote_requires_explicit_enable' => true,

    ],



    /**

     * @var array<string, array{archive_promote_enabled: bool, maintainer_role: string}>

     */

    'industries' => [

        'travel' => [

            'archive_promote_enabled' => false,

            'maintainer_role' => 'industry_maintainer',

        ],

        'hotel' => [

            'archive_promote_enabled' => false,

            'maintainer_role' => 'industry_maintainer',

        ],

        'restaurant' => [

            'archive_promote_enabled' => false,

            'maintainer_role' => 'industry_maintainer',

        ],

    ],



    /**

     * @var array{archive_promote_enabled: bool, maintainer_role: string}

     */

    'global' => [

        'archive_promote_enabled' => false,

        'maintainer_role' => 'platform_admin',

    ],

];

