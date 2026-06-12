<?php
declare(strict_types=1);

/**
 * BDS Platform Drive Registry — local config (Phase 6B-2C-2).
 *
 * Platform layer only; separate from config/bds_source_registry.php (Tenant Registry).
 *
 * @see docs/BATS_DATA_SOURCE_REGISTRY.md §6.7
 */
return [
    'schema_version' => 'bds_platform_drive_registry.v1',

    /**
     * @var array<string, string> Platform root folder IDs (bbcshops88@gmail.com)
     */
    'platform_roots' => [
        'industries_root_folder_id' => '1EWhnQONx5EQ5GYGd4dx114QoAgXHZU2P',
        'global_root_folder_id' => '1OJHWWKkgr9X9nXhHhYfPPIN-p7dnidqh',
        'registrations_root_folder_id' => '17It5q4NQGJHLK5_IXC6PM2iT0dlj0qGG',
    ],
];
