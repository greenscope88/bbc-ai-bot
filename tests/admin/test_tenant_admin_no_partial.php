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
$root = sys_get_temp_dir() . '/bbc_admin_np_' . bin2hex(random_bytes(4));
mkdir($root . '/config/product_source/tenants', 0775, true);
mkdir($root . '/logs', 0775, true);
file_put_contents($root . '/config/tenant_registry.php', "<?php\nreturn ['schema_version'=>1,'global'=>[],'tenants'=>[]];\n");
file_put_contents($root . '/config/bds_source_registry.php', "<?php\nreturn ['schema_version'=>'bds_source_registry.v1','tenants'=>[]];\n");

$lookup = new TenantAdminStoreAuthorityLookup(
    static function (): int { return 888; },
    static function (): string { return 'k'; },
    static function (int $depId, int $storeNo): ?array {
        return $storeNo === 7200 ? ['storeNo' => 7200, 'depID' => 888, 'storeName' => 'Y'] : null;
    },
    static function (int $depId): array {
        return [['storeNo' => 7200, 'depID' => 888, 'storeName' => 'Y']];
    },
    static function (string $plaintext, string $dataKey): string {
        return 'bbbbbbbbbbbbbbbb';
    },
    static function (string $sno, string $dataKey): ?string {
        return '7200';
    }
);
$lineOk = 'U' . str_repeat('b', 32);
$credPrefix = 'travel_bbbbbbbbbbbbbbbb';
putenv('LINE_CHANNEL_SECRET__' . $credPrefix . '=fixture-secret');
putenv('LINE_CHANNEL_ACCESS_TOKEN__' . $credPrefix . '=fixture-token');
$bot = new TenantAdminLineBotIdentityResolver(static function () use ($lineOk) {
    return [
        'http_status' => 200,
        'body' => json_encode(['userId' => $lineOk, 'displayName' => 'Y', 'basicId' => '@y']),
    ];
});
$selBackend = [];
$selectionStore = new TenantAdminCreateSelectionStore($selBackend);
$reader = new TenantAdminAuthorityReader($root . '/config/tenant_registry.php', $root . '/config/bds_source_registry.php', $root . '/config/product_source/tenants');
$validator = new TenantAdminPayloadValidator($reader);
$readiness = new TenantAdminReadinessValidator($reader, null, null, null, false);
$preview = new TenantAdminPreviewService($reader, $validator, $readiness, $lookup, $selectionStore, $bot);
$apply = new TenantAdminApplyService($reader, new TenantAdminAuthorityWriter($reader), new TenantAdminAuditLogger($root . '/logs/a.log'), $readiness, $lookup, $selectionStore, $bot);

$issued = $preview->resolveCreateSelection('bbbbbbbbbbbbbbbb');
$assert(($issued['ok'] ?? false) === true, 'selection ok');
$built = $preview->build('create_tenant', [
    'selection_token' => (string) $issued['selection_token'],
]);
$assert(($built['ok'] ?? false) === true, 'preview create for drift case');
$plan = $built['preview'];
$plan['precondition_hash'] = 'drift';
$res = $apply->apply($plan, 'mask');
$assert(($res['ok'] ?? true) === false, 'hash drift fails');
$assert($reader->getTenantRow('travel_bbbbbbbbbbbbbbbb') === null, 'no partial tenant row');
$assert($reader->getBdsRow('travel_bbbbbbbbbbbbbbbb') === null, 'no partial bds row');

$plan2 = $built['preview'];
$plan2['normalized']['fingerprint'] = str_repeat('f', 64);
$res2 = $apply->apply($plan2, 'mask');
$assert(($res2['ok'] ?? true) === false, 'fingerprint drift fails');
$assert($reader->getTenantRow('travel_bbbbbbbbbbbbbbbb') === null, 'no write on fp drift');

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($root);
putenv('LINE_CHANNEL_SECRET__' . $credPrefix);
putenv('LINE_CHANNEL_ACCESS_TOKEN__' . $credPrefix);
if ($failures > 0) { exit(1); }
fwrite(STDOUT, "OK: test_tenant_admin_no_partial\n");
exit(0);
