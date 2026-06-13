<?php
declare(strict_types=1);

/**
 * BDS Phase 6B-2C-2 — BdsPlatformDriveRegistryLoader tests.
 *
 * @see docs/BATS_DATA_SOURCE_REGISTRY.md §6.7
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsPlatformDriveRegistryLoader.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsSourceRegistryLoader.php';

$failures = 0;

const EXPECTED_INDUSTRIES_ROOT = '1EWhnQONx5EQ5GYGd4dx114QoAgXHZU2P';
const EXPECTED_GLOBAL_ROOT = '1OJHWWKkgr9X9nXhHhYfPPIN-p7dnidqh';
const EXPECTED_REGISTRATIONS_ROOT = '17It5q4NQGJHLK5_IXC6PM2iT0dlj0qGG';

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/**
 * @param mixed $value
 */
function reset_platform_registry_cache($value = null): void
{
    $ref = new ReflectionClass(BdsPlatformDriveRegistryLoader::class);
    $prop = $ref->getProperty('registry');
    $prop->setAccessible(true);
    $prop->setValue($value);
}

function with_temp_platform_registry_config(string $phpBody, callable $callback): void
{
    $previousPath = getenv('BDS_PLATFORM_DRIVE_REGISTRY_PATH');
    $tmp = tempnam(sys_get_temp_dir(), 'bds_platform_registry_');
    if ($tmp === false) {
        throw new RuntimeException('Failed to create temp file for platform registry test.');
    }

    $configPath = $tmp . '.php';
    rename($tmp, $configPath);
    file_put_contents($configPath, $phpBody);

    putenv('BDS_PLATFORM_DRIVE_REGISTRY_PATH=' . $configPath);
    reset_platform_registry_cache();

    try {
        $callback($configPath);
    } finally {
        if ($previousPath === false) {
            putenv('BDS_PLATFORM_DRIVE_REGISTRY_PATH');
        } else {
            putenv('BDS_PLATFORM_DRIVE_REGISTRY_PATH=' . $previousPath);
        }
        reset_platform_registry_cache();
        if (is_file($configPath)) {
            unlink($configPath);
        }
    }
}

reset_platform_registry_cache();

$loaded = BdsPlatformDriveRegistryLoader::load();
test_assert(is_array($loaded), 'load() returns array');
test_assert(
    ($loaded['schema_version'] ?? '') === 'bds_platform_drive_registry.v1',
    'schema_version matches SSOT'
);
test_assert(isset($loaded['platform_roots']) && is_array($loaded['platform_roots']), 'load() includes platform_roots');
test_assert(isset($loaded['industries']) && is_array($loaded['industries']), 'load() includes industries map');
test_assert(array_key_exists('global_shared_layer_folder_id', $loaded), 'load() includes global_shared_layer_folder_id');

$roots = BdsPlatformDriveRegistryLoader::getPlatformRoots();
test_assert(
    $roots['industries_root_folder_id'] === EXPECTED_INDUSTRIES_ROOT,
    'industries_root_folder_id matches SSOT'
);
test_assert(
    $roots['global_root_folder_id'] === EXPECTED_GLOBAL_ROOT,
    'global_root_folder_id matches SSOT'
);
test_assert(
    $roots['registrations_root_folder_id'] === EXPECTED_REGISTRATIONS_ROOT,
    'registrations_root_folder_id matches SSOT'
);
test_assert(
    BdsPlatformDriveRegistryLoader::getIndustriesRootFolderId() === EXPECTED_INDUSTRIES_ROOT,
    'getIndustriesRootFolderId() matches'
);
test_assert(
    BdsPlatformDriveRegistryLoader::getGlobalRootFolderId() === EXPECTED_GLOBAL_ROOT,
    'getGlobalRootFolderId() matches'
);
test_assert(
    BdsPlatformDriveRegistryLoader::getRegistrationsRootFolderId() === EXPECTED_REGISTRATIONS_ROOT,
    'getRegistrationsRootFolderId() matches'
);

foreach (['travel', 'hotel', 'restaurant'] as $industryCode) {
    $entry = BdsPlatformDriveRegistryLoader::getIndustryEntry($industryCode);
    test_assert(is_array($entry), "getIndustryEntry({$industryCode}) returns array");
    if (is_array($entry)) {
        test_assert($entry['industry_code'] === $industryCode, "{$industryCode} industry_code matches");
        test_assert($entry['industry_folder_id'] === null, "{$industryCode} industry_folder_id is null (pending)");
        test_assert($entry['shared_layer_folder_id'] === null, "{$industryCode} shared_layer_folder_id is null (pending)");
    }
    test_assert(
        BdsPlatformDriveRegistryLoader::getIndustryFolderId($industryCode) === null,
        "getIndustryFolderId({$industryCode}) is null"
    );
    test_assert(
        BdsPlatformDriveRegistryLoader::getSharedLayerFolderId($industryCode) === null,
        "getSharedLayerFolderId({$industryCode}) is null"
    );
    test_assert(
        BdsPlatformDriveRegistryLoader::hasSharedLayerFolder($industryCode) === false,
        "hasSharedLayerFolder({$industryCode}) is false when null"
    );
}

test_assert(
    BdsPlatformDriveRegistryLoader::getGlobalSharedLayerFolderId() === null,
    'getGlobalSharedLayerFolderId() is null (pending)'
);
test_assert(
    BdsPlatformDriveRegistryLoader::getIndustryEntry('beauty') === null,
    'unknown industry getIndustryEntry returns null'
);
test_assert(
    BdsPlatformDriveRegistryLoader::getIndustryEntry('') === null,
    'empty industry code getIndustryEntry returns null'
);

$allIndustries = BdsPlatformDriveRegistryLoader::getAllIndustryEntries();
test_assert(count($allIndustries) === 3, 'getAllIndustryEntries returns three industries');
test_assert(isset($allIndustries['travel'], $allIndustries['hotel'], $allIndustries['restaurant']), 'expected industry keys present');

with_temp_platform_registry_config(
    "<?php return [
        'schema_version' => 'bds_platform_drive_registry.v1',
        'platform_roots' => [],
        'industries' => [
            'travel' => [
                'industry_folder_id' => 'ind_root_travel',
                'shared_layer_folder_id' => 'shared_travel_001',
            ],
        ],
        'global' => ['shared_layer_folder_id' => 'global_shared_001'],
    ];",
    function (): void {
        test_assert(
            BdsPlatformDriveRegistryLoader::getSharedLayerFolderId('travel') === 'shared_travel_001',
            'non-null shared_layer_folder_id resolves'
        );
        test_assert(
            BdsPlatformDriveRegistryLoader::hasSharedLayerFolder('travel') === true,
            'hasSharedLayerFolder true when folder id set'
        );
        test_assert(
            BdsPlatformDriveRegistryLoader::getGlobalSharedLayerFolderId() === 'global_shared_001',
            'global shared_layer_folder_id resolves'
        );
    }
);

with_temp_platform_registry_config(
    "<?php return ['schema_version' => 'test.v0', 'platform_roots' => []];",
    function (): void {
        $roots = BdsPlatformDriveRegistryLoader::getPlatformRoots();
        test_assert($roots['industries_root_folder_id'] === null, 'missing platform_roots keys return null');
        test_assert($roots['global_root_folder_id'] === null, 'missing global_root_folder_id returns null');
        test_assert($roots['registrations_root_folder_id'] === null, 'missing registrations_root_folder_id returns null');
    }
);

with_temp_platform_registry_config(
    "<?php return ['schema_version' => 'test.v0', 'platform_roots' => [
        'industries_root_folder_id' => null,
        'global_root_folder_id' => '',
        'registrations_root_folder_id' => '  ',
    ]];",
    function (): void {
        test_assert(
            BdsPlatformDriveRegistryLoader::getIndustriesRootFolderId() === null,
            'null industries_root_folder_id normalizes to null'
        );
        test_assert(
            BdsPlatformDriveRegistryLoader::getGlobalRootFolderId() === null,
            'empty global_root_folder_id normalizes to null'
        );
        test_assert(
            BdsPlatformDriveRegistryLoader::getRegistrationsRootFolderId() === null,
            'whitespace-only registrations_root_folder_id normalizes to null'
        );
    }
);

with_temp_platform_registry_config(
    '<?php return "not-an-array";',
    function (): void {
        $roots = BdsPlatformDriveRegistryLoader::getPlatformRoots();
        test_assert($roots['industries_root_folder_id'] === null, 'non-array config yields null roots');
    }
);

with_temp_platform_registry_config(
    '<?php // missing file content simulation via empty return',
    function (string $configPath): void {
        unlink($configPath);
        reset_platform_registry_cache();
        $roots = BdsPlatformDriveRegistryLoader::getPlatformRoots();
        test_assert($roots['industries_root_folder_id'] === null, 'missing config file yields null roots');
    }
);

reset_platform_registry_cache();

$tenantEntry = BdsSourceRegistryLoader::loadByTenantKey('travel_b');
test_assert(is_array($tenantEntry), 'tenant loader still resolves travel_b');
if (is_array($tenantEntry)) {
    test_assert(
        !array_key_exists('industries_root_folder_id', $tenantEntry),
        'tenant entry does not expose industries_root_folder_id'
    );
    test_assert(
        !array_key_exists('global_root_folder_id', $tenantEntry),
        'tenant entry does not expose global_root_folder_id'
    );
    test_assert(
        !array_key_exists('registrations_root_folder_id', $tenantEntry),
        'tenant entry does not expose registrations_root_folder_id'
    );
    test_assert(
        $tenantEntry['private_knowledge_folder_id'] === '17wrq-rrvc7ezclhWlbvdTKxHSf_Hw8pi',
        'tenant private_knowledge_folder_id unchanged'
    );
}

$tenantConfigPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bds_source_registry.php';
$tenantConfig = is_file($tenantConfigPath) ? require $tenantConfigPath : [];
test_assert(is_array($tenantConfig) && isset($tenantConfig['tenants']), 'tenant config has tenants');
if (is_array($tenantConfig) && isset($tenantConfig['tenants']['travel_b']) && is_array($tenantConfig['tenants']['travel_b'])) {
    $travelB = $tenantConfig['tenants']['travel_b'];
    test_assert(
        !array_key_exists('industries_root_folder_id', $travelB),
        'tenant config travel_b has no industries_root_folder_id'
    );
    test_assert(
        !array_key_exists('shared_drive_folder_id', $travelB),
        'tenant config does not introduce shared_drive_folder_id'
    );
    test_assert(
        !array_key_exists('private_drive_folder_id', $travelB),
        'tenant config does not introduce private_drive_folder_id'
    );
}

$platformConfigPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bds_platform_drive_registry.php';
$platformConfig = is_file($platformConfigPath) ? require $platformConfigPath : [];
test_assert(is_array($platformConfig), 'platform config loads');
test_assert(isset($platformConfig['platform_roots']) && is_array($platformConfig['platform_roots']), 'platform config has platform_roots only scope');
test_assert(isset($platformConfig['industries']) && is_array($platformConfig['industries']), 'platform config has industries map');
test_assert(isset($platformConfig['global']) && is_array($platformConfig['global']), 'platform config has global section');
test_assert(!isset($platformConfig['tenants']), 'platform config still has no tenants key');

if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_bds_platform_drive_registry_loader (all passed)\n");
exit(0);
