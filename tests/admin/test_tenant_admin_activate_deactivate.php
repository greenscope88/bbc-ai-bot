<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminAuthorityReader.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminPayloadValidator.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminReadinessValidator.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminPreviewService.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminAuthorityWriter.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminAuditLogger.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminApplyService.php';
require_once dirname(__DIR__, 2) . '/core/tenant/LineCredentialResolver.php';

$failures = 0;
$assert = static function (bool $c, string $m) use (&$failures): void {
    if (!$c) { ++$failures; fwrite(STDERR, "FAIL: {$m}\n"); }
};

$root = sys_get_temp_dir() . '/bbc_admin_act_' . bin2hex(random_bytes(4));
mkdir($root . '/config/product_source/tenants/1111111111111111', 0775, true);
mkdir($root . '/logs', 0775, true);
$channel = 'Ut1111111111111111111111111111111';
file_put_contents($root . '/config/tenant_registry.php', "<?php\nreturn ['schema_version'=>1,'global'=>[],'tenants'=>['t1'=>['credential_env_prefix'=>'t1fix','display_name'=>'T','line_channel_id'=>'{$channel}','sno'=>'1111111111111111','depID'=>1,'storeNo'=>1,'store_uid'=>1,'provider_id_no'=>0,'status'=>'disabled','profile'=>[],'features'=>['bats_runtime'=>false,'aiu_authoritative'=>false,'grounding_authoritative'=>false],'gemini_policy'=>[],'source_policy'=>[]]]];\n");
file_put_contents($root . '/config/bds_source_registry.php', "<?php\nreturn ['schema_version'=>'bds_source_registry.v1','tenants'=>['t1'=>['store_no'=>1,'sno'=>'1111111111111111','tenant_name'=>'T','tenant_key'=>'t1','industry_code'=>'travel','enabled'=>true,'private_knowledge_sheet_id'=>'','gcs_prefix'=>'tenants/1111111111111111/']]];\n");
file_put_contents($root . '/config/product_source/tenants/1111111111111111/product_sources.json', json_encode(['schema_version'=>1,'tenant_sno'=>'1111111111111111','tenant_key'=>'t1','default_category'=>'group_tour','enabled_sources'=>[],'updated_at'=>gmdate('c')]));

putenv('LINE_CHANNEL_SECRET__t1fix=test-secret-not-real');
putenv('LINE_CHANNEL_ACCESS_TOKEN__t1fix=test-token-not-real');

$reader = new TenantAdminAuthorityReader($root . '/config/tenant_registry.php', $root . '/config/bds_source_registry.php', $root . '/config/product_source/tenants');
$validator = new TenantAdminPayloadValidator($reader);
$credResolver = static function (string $channelId) use ($channel): array {
    if ($channelId !== $channel) {
        return ['ok' => false, 'tenant_key' => null];
    }
    return ['ok' => true, 'tenant_key' => 't1'];
};
$readiness = new TenantAdminReadinessValidator($reader, $root . '/var/bds/tenants', $root . '/var/bds/uploads/tenants', $root . '/logs/saas_router.log', false, $credResolver);
$preview = new TenantAdminPreviewService($reader, $validator, $readiness);
$apply = new TenantAdminApplyService($reader, new TenantAdminAuthorityWriter($reader), new TenantAdminAuditLogger($root . '/logs/a.log'), $readiness);

$enter = $preview->build('enter_line_validation', [
    'tenant_key' => 't1',
    'bats_runtime' => true,
    'aiu_authoritative' => true,
    'grounding_authoritative' => false,
]);
$assert(($enter['ok'] ?? false) === true, 'enter preview');
$assert(($apply->apply($enter['preview'], 'mask')['ok'] ?? false) === true, 'enter apply');
$row = $reader->getTenantRow('t1');
$assert(($row['status'] ?? '') === 'staging', 'staging');
$assert(($row['features']['bats_runtime'] ?? false) === true, 'bats on');

$fin = $preview->build('finalize_tenant_production', ['tenant_key' => 't1']);
$assert(($fin['ok'] ?? false) === true, 'finalize preview fixture');
$assert(($apply->apply($fin['preview'], 'mask')['ok'] ?? false) === true, 'finalize apply');
$row = $reader->getTenantRow('t1');
$assert(($row['status'] ?? '') === 'enabled', 'enabled');

$de = $preview->build('deactivate_tenant_production', ['tenant_key' => 't1']);
$assert(($apply->apply($de['preview'], 'mask')['ok'] ?? false) === true, 'deactivate');
$row = $reader->getTenantRow('t1');
$assert(($row['status'] ?? '') === 'disabled', 'disabled again');
$assert(($row['features']['bats_runtime'] ?? true) === false, 'bats off');
$assert(($row['sno'] ?? '') === '1111111111111111', 'identity preserved');

putenv('LINE_CHANNEL_SECRET__t1fix');
putenv('LINE_CHANNEL_ACCESS_TOKEN__t1fix');
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($root);
if ($failures > 0) { fwrite(STDERR, "{$failures} failure(s)\n"); exit(1); }
fwrite(STDOUT, "OK: test_tenant_admin_activate_deactivate\n");
exit(0);
