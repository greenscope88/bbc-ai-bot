<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
app_config();
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminAuthorityReader.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminPayloadValidator.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminReadinessValidator.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminStoreAuthorityLookup.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminCreateSelectionStore.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminPreviewService.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminAuthorityWriter.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminAuditLogger.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminApplyService.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminLineBotIdentityResolver.php';

$failures = 0;
$assert = static function (bool $c, string $m) use (&$failures): void {
    if (!$c) { ++$failures; fwrite(STDERR, "FAIL: {$m}\n"); }
};

$root = sys_get_temp_dir() . '/bbc_admin_create_' . bin2hex(random_bytes(4));
mkdir($root . '/config/product_source/tenants', 0775, true);
mkdir($root . '/logs', 0775, true);
file_put_contents($root . '/config/tenant_registry.php', "<?php\nreturn ['schema_version'=>1,'global'=>[],'tenants'=>['travel_b'=>['credential_env_prefix'=>'travel_b','display_name'=>'B','line_channel_id'=>'Ub1111111111111111111111111111111','sno'=>'5f99b8d665e8444d','depID'=>888,'storeNo'=>6180,'store_uid'=>6180,'provider_id_no'=>101,'status'=>'enabled','profile'=>[],'features'=>[],'gemini_policy'=>[],'source_policy'=>[]]]];\n");
file_put_contents($root . '/config/bds_source_registry.php', "<?php\nreturn ['schema_version'=>'bds_source_registry.v1','tenants'=>['travel_b'=>['store_no'=>6180,'sno'=>'5f99b8d665e8444d','tenant_name'=>'B','tenant_key'=>'travel_b','industry_code'=>'travel','enabled'=>true,'private_knowledge_sheet_id'=>'','gcs_prefix'=>'tenants/5f99b8d665e8444d/']]];\n");

$lookup = new TenantAdminStoreAuthorityLookup(
    static function (): int { return 888; },
    static function (): string { return 'test-data-key'; },
    static function (int $depId, int $storeNo): ?array {
        if ($storeNo === 7001) {
            return ['storeNo' => 7001, 'depID' => 888, 'storeName' => 'Tenant X Co'];
        }
        return null;
    },
    static function (int $depId): array {
        return [['storeNo' => 7001, 'depID' => 888, 'storeName' => 'Tenant X Co']];
    },
    static function (string $plaintext, string $dataKey): string {
        return $plaintext === '7001' ? 'abcdef0123456789' : 'zzzzzzzzzzzzzzzz';
    },
    static function (string $sno, string $dataKey): ?string {
        return strtolower($sno) === 'abcdef0123456789' ? '7001' : null;
    }
);

$lineOk = 'U' . str_repeat('a', 32);
$credPrefix = 'travel_abcdef0123456789';
putenv('LINE_CHANNEL_SECRET__' . $credPrefix . '=fixture-secret');
putenv('LINE_CHANNEL_ACCESS_TOKEN__' . $credPrefix . '=fixture-token');
$bot = new TenantAdminLineBotIdentityResolver(static function () use ($lineOk) {
    return [
        'http_status' => 200,
        'body' => json_encode(['userId' => $lineOk, 'displayName' => 'X OA', 'basicId' => '@x']),
    ];
});

$selBackend = [];
$selectionStore = new TenantAdminCreateSelectionStore($selBackend);
$reader = new TenantAdminAuthorityReader($root . '/config/tenant_registry.php', $root . '/config/bds_source_registry.php', $root . '/config/product_source/tenants');
$validator = new TenantAdminPayloadValidator($reader);
$readiness = new TenantAdminReadinessValidator($reader, $root . '/var/bds/tenants', $root . '/var/bds/uploads/tenants', $root . '/logs/saas_router.log', false);
$preview = new TenantAdminPreviewService($reader, $validator, $readiness, $lookup, $selectionStore, $bot);
$writer = new TenantAdminAuthorityWriter($reader);
$apply = new TenantAdminApplyService($reader, $writer, new TenantAdminAuditLogger($root . '/logs/a.log'), $readiness, $lookup, $selectionStore, $bot);

$assert($reader->getTenantRow('travel_b') !== null, 'existing travel_b preserved');
$assert(($reader->getTenantRow('travel_b')['credential_env_prefix'] ?? '') === 'travel_b', 'existing prefix unchanged');

$issued = $preview->resolveCreateSelection('abcdef0123456789');
$assert(($issued['ok'] ?? false) === true, 'resolve selection ok');
$assert((string) ($issued['identity']['tenant_key'] ?? '') === 'travel_abcdef0123456789', 'derived tenant_key');

$built = $preview->build('create_tenant', [
    'selection_token' => (string) $issued['selection_token'],
]);
$assert(($built['ok'] ?? false) === true, 'preview create ok');
$assert((int) ($built['preview']['normalized']['provider_id_no'] ?? -1) === 0, 'provider contract');
$assert((string) ($built['preview']['normalized']['tenant_key'] ?? '') === 'travel_abcdef0123456789', 'normalized key');
$assert((string) ($built['preview']['normalized']['line_channel_id'] ?? '') === $lineOk, 'server bot userId');
$public = json_encode($built['public'], JSON_UNESCAPED_UNICODE);
$assert(strpos($public, $lineOk) === false, 'public masks bot userId');

$result = $apply->apply($built['preview'], 'mask');
$assert(($result['ok'] ?? false) === true, 'apply create ok');
$row = $reader->getTenantRow('travel_abcdef0123456789');
$assert(is_array($row) && ($row['status'] ?? '') === 'disabled', 'disabled bundle');
$assert(($row['features']['bats_runtime'] ?? true) === false, 'bats false');
$assert(($row['display_name'] ?? '') === 'Tenant X Co', 'display from store');
$assert(($row['line_channel_id'] ?? '') === $lineOk, 'bot userId written');
$assert(is_file($reader->productSourcesPath('abcdef0123456789')), 'product set exists');

foreach (['storeNo' => 1, 'depID' => 888, 'provider_id_no' => 0, 'store_uid' => 1, 'tenant_key' => 'x', 'sno' => 'abcdef0123456789', 'line_channel_id' => 'U' . str_repeat('c', 32)] as $field => $val) {
    $evil = $preview->build('create_tenant', [
        'selection_token' => (string) $issued['selection_token'],
        $field => $val,
    ]);
    $assert(($evil['ok'] ?? true) === false, "reject client {$field}");
}

$assert($reader->getTenantRow('travel_b') !== null && ($reader->getTenantRow('travel_b')['sno'] ?? '') === '5f99b8d665e8444d', 'existing tenant keys unchanged');

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($root);
putenv('LINE_CHANNEL_SECRET__' . $credPrefix);
putenv('LINE_CHANNEL_ACCESS_TOKEN__' . $credPrefix);

if ($failures > 0) { fwrite(STDERR, "{$failures} failure(s)\n"); exit(1); }
fwrite(STDOUT, "OK: test_tenant_admin_create\n");
exit(0);
