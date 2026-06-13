<?php
declare(strict_types=1);

/**
 * BDS Phase 6E-2 — BdsSharedArchivePolicyLoader tests.
 *
 * @see docs/BATS_DRIVE_GCS_MAPPING.md §18
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsSharedArchivePolicyLoader.php';

$failures = 0;

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
function reset_shared_archive_policy_cache($value = null): void
{
    $ref = new ReflectionClass(BdsSharedArchivePolicyLoader::class);
    $prop = $ref->getProperty('policy');
    $prop->setAccessible(true);
    $prop->setValue($value);
}

function with_temp_shared_archive_policy_config(string $phpBody, callable $callback): void
{
    $previousPath = getenv('BDS_SHARED_ARCHIVE_POLICY_PATH');
    $tmp = tempnam(sys_get_temp_dir(), 'bds_shared_archive_policy_');
    if ($tmp === false) {
        throw new RuntimeException('Failed to create temp file for shared archive policy test.');
    }

    $configPath = $tmp . '.php';
    rename($tmp, $configPath);
    file_put_contents($configPath, $phpBody);

    putenv('BDS_SHARED_ARCHIVE_POLICY_PATH=' . $configPath);
    reset_shared_archive_policy_cache();

    try {
        $callback($configPath);
    } finally {
        if ($previousPath === false) {
            putenv('BDS_SHARED_ARCHIVE_POLICY_PATH');
        } else {
            putenv('BDS_SHARED_ARCHIVE_POLICY_PATH=' . $previousPath);
        }
        reset_shared_archive_policy_cache();
        if (is_file($configPath)) {
            unlink($configPath);
        }
    }
}

reset_shared_archive_policy_cache();

$loaded = BdsSharedArchivePolicyLoader::load();
test_assert(is_array($loaded), 'load() returns array');
test_assert(
    ($loaded['schema_version'] ?? '') === BdsSharedArchivePolicyLoader::SCHEMA_VERSION,
    'schema_version matches SSOT'
);
test_assert(
    ($loaded['defaults']['promote_requires_explicit_enable'] ?? false) === true,
    'promote_requires_explicit_enable defaults to true'
);
test_assert(isset($loaded['industries']) && is_array($loaded['industries']), 'load() includes industries policies');
test_assert(isset($loaded['global']) && is_array($loaded['global']), 'load() includes global policy');

test_assert(BdsSharedArchivePolicyLoader::requiresExplicitEnable() === true, 'requiresExplicitEnable is true');

foreach (['travel', 'hotel', 'restaurant'] as $industryCode) {
    $policy = BdsSharedArchivePolicyLoader::getIndustryPolicy($industryCode);
    test_assert(is_array($policy), "getIndustryPolicy({$industryCode}) returns array");
    if (is_array($policy)) {
        test_assert($policy['industry_code'] === $industryCode, "{$industryCode} industry_code matches");
        test_assert($policy['archive_promote_enabled'] === false, "{$industryCode} archive_promote_enabled is false");
        test_assert($policy['maintainer_role'] === 'industry_maintainer', "{$industryCode} maintainer_role matches");
    }
    test_assert(
        BdsSharedArchivePolicyLoader::isIndustryArchiveEnabled($industryCode) === false,
        "isIndustryArchiveEnabled({$industryCode}) is false by default"
    );
}

$globalPolicy = BdsSharedArchivePolicyLoader::getGlobalPolicy();
test_assert($globalPolicy['archive_promote_enabled'] === false, 'global archive_promote_enabled is false');
test_assert($globalPolicy['maintainer_role'] === 'platform_admin', 'global maintainer_role is platform_admin');
test_assert(BdsSharedArchivePolicyLoader::isGlobalArchiveEnabled() === false, 'isGlobalArchiveEnabled is false by default');

test_assert(BdsSharedArchivePolicyLoader::getIndustryPolicy('beauty') === null, 'unknown industry returns null');
test_assert(BdsSharedArchivePolicyLoader::getIndustryPolicy('') === null, 'empty industry returns null');
test_assert(BdsSharedArchivePolicyLoader::isIndustryArchiveEnabled('beauty') === false, 'unknown industry enabled is false');

$allPolicies = BdsSharedArchivePolicyLoader::getAllIndustryPolicies();
test_assert(count($allPolicies) === 3, 'getAllIndustryPolicies returns three industries');

with_temp_shared_archive_policy_config(
    "<?php return [
        'schema_version' => 'bds_shared_archive_policy.v1',
        'defaults' => ['promote_requires_explicit_enable' => true],
        'industries' => [
            'travel' => [
                'archive_promote_enabled' => true,
                'maintainer_role' => 'industry_maintainer',
            ],
        ],
        'global' => [
            'archive_promote_enabled' => true,
            'maintainer_role' => 'platform_admin',
        ],
    ];",
    function (): void {
        test_assert(
            BdsSharedArchivePolicyLoader::isIndustryArchiveEnabled('travel') === true,
            'explicit enabled travel returns true'
        );
        test_assert(
            BdsSharedArchivePolicyLoader::isIndustryArchiveEnabled('hotel') === false,
            'missing industry in temp policy returns false'
        );
        test_assert(
            BdsSharedArchivePolicyLoader::isGlobalArchiveEnabled() === true,
            'explicit enabled global returns true'
        );
    }
);

$invalidSchemaThrown = false;
with_temp_shared_archive_policy_config(
    "<?php return ['schema_version' => 'wrong.version'];",
    function () use (&$invalidSchemaThrown): void {
        try {
            BdsSharedArchivePolicyLoader::load();
        } catch (RuntimeException $e) {
            $invalidSchemaThrown = true;
        }
    }
);
test_assert($invalidSchemaThrown, 'invalid schema_version throws RuntimeException');

with_temp_shared_archive_policy_config(
    '<?php return "not-an-array";',
    function (): void {
        test_assert(
            BdsSharedArchivePolicyLoader::isIndustryArchiveEnabled('travel') === false,
            'non-array config yields disabled industry policy'
        );
        test_assert(
            BdsSharedArchivePolicyLoader::isGlobalArchiveEnabled() === false,
            'non-array config yields disabled global policy'
        );
    }
);

with_temp_shared_archive_policy_config(
    '<?php // missing file',
    function (string $configPath): void {
        unlink($configPath);
        reset_shared_archive_policy_cache();
        test_assert(
            BdsSharedArchivePolicyLoader::isIndustryArchiveEnabled('travel') === false,
            'missing config file yields disabled industry policy'
        );
    }
);

reset_shared_archive_policy_cache();

$policyConfigPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bds_shared_archive_policy.php';
$policyConfig = is_file($policyConfigPath) ? require $policyConfigPath : [];
test_assert(is_array($policyConfig), 'policy config loads');
test_assert(
    ($policyConfig['schema_version'] ?? '') === BdsSharedArchivePolicyLoader::SCHEMA_VERSION,
    'policy config schema_version matches'
);
test_assert(!isset($policyConfig['tenants']), 'policy config has no tenants key');

if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_bds_shared_archive_policy_loader (all passed)\n");
exit(0);
