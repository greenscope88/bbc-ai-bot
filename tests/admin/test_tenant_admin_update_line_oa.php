<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
app_config();
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminAuthorityReader.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminPayloadValidator.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminReadinessValidator.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminPreviewService.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminAuthorityWriter.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminAuditLogger.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminApplyService.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminLineBotIdentityResolver.php';
require_once dirname(__DIR__, 2) . '/core/tenant/ConfigTenantRegistry.php';

$failures = 0;
$assert = static function (bool $c, string $m) use (&$failures): void {
    if (!$c) { ++$failures; fwrite(STDERR, "FAIL: {$m}\n"); }
};

$root = sys_get_temp_dir() . '/bbc_admin_upd_' . bin2hex(random_bytes(4));
mkdir($root . '/config/product_source/tenants', 0775, true);
mkdir($root . '/logs', 0775, true);
$prefix = 'travel_z';
$old = 'U' . str_repeat('1', 32);
$new = 'U' . str_repeat('2', 32);
putenv('LINE_CHANNEL_SECRET__' . $prefix . '=fixture-secret');
putenv('LINE_CHANNEL_ACCESS_TOKEN__' . $prefix . '=fixture-token');
$reg = [
    'schema_version' => 1,
    'global' => [],
    'tenants' => [
        'travel_z' => [
            'credential_env_prefix' => $prefix,
            'display_name' => 'Z',
            'line_channel_id' => $old,
            'sno' => 'zzzzzzzzzzzzzzzz',
            'depID' => 888,
            'storeNo' => 7100,
            'store_uid' => 7100,
            'provider_id_no' => 0,
            'status' => 'disabled',
            'profile' => ['company_name' => 'Z Co'],
            'features' => ['bats_runtime' => false, 'aiu_authoritative' => false, 'grounding_authoritative' => false],
            'gemini_policy' => [],
            'source_policy' => [],
        ],
    ],
];
file_put_contents($root . '/config/tenant_registry.php', "<?php\nreturn " . var_export($reg, true) . ";\n");
file_put_contents($root . '/config/bds_source_registry.php', "<?php\nreturn ['schema_version'=>'bds_source_registry.v1','tenants'=>[]];\n");

$bot = new TenantAdminLineBotIdentityResolver(static function () use ($new) {
    return [
        'http_status' => 200,
        'body' => json_encode(['userId' => $new, 'displayName' => 'Z OA', 'basicId' => '@z']),
    ];
});
$reader = new TenantAdminAuthorityReader($root . '/config/tenant_registry.php', $root . '/config/bds_source_registry.php', $root . '/config/product_source/tenants');
$validator = new TenantAdminPayloadValidator($reader);
$readiness = new TenantAdminReadinessValidator($reader, null, null, null, false);
$preview = new TenantAdminPreviewService($reader, $validator, $readiness, null, null, $bot);
$apply = new TenantAdminApplyService($reader, new TenantAdminAuthorityWriter($reader), new TenantAdminAuditLogger($root . '/logs/a.log'), $readiness, null, null, $bot);

$built = $preview->build('update_line_oa', ['tenant_key' => 'travel_z', 'display_name' => 'Z2']);
$assert(($built['ok'] ?? false) === true, 'preview update');
$res = $apply->apply($built['preview'], 'mask');
$assert(($res['ok'] ?? false) === true, 'apply update');

$row = $reader->getTenantRow('travel_z');
$assert(($row['line_channel_id'] ?? '') === $new, 'new destination written');
$assert(($row['credential_env_prefix'] ?? '') === 'travel_z', 'prefix unchanged');
$assert(($row['display_name'] ?? '') === 'Z2', 'display updated');
$registry = new ConfigTenantRegistry($root . '/config/tenant_registry.php');
$assert($registry->resolveByChannel($old) === null, 'old destination fail-closed');
$assert($registry->resolveByChannel($new) !== null, 'new destination resolves');

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($root);
putenv('LINE_CHANNEL_SECRET__' . $prefix);
putenv('LINE_CHANNEL_ACCESS_TOKEN__' . $prefix);
if ($failures > 0) { fwrite(STDERR, "{$failures} failure(s)\n"); exit(1); }
fwrite(STDOUT, "OK: test_tenant_admin_update_line_oa\n");
exit(0);
