<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminAuthorityReader.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminPayloadValidator.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminReadinessValidator.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminPreviewService.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminAuthorityWriter.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminAuditLogger.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminApplyService.php';

$failures = 0;
$assert = static function (bool $c, string $m) use (&$failures): void {
    if (!$c) { ++$failures; fwrite(STDERR, "FAIL: {$m}\n"); }
};
$root = sys_get_temp_dir() . '/bbc_admin_bds_' . bin2hex(random_bytes(4));
mkdir($root . '/config/product_source/tenants/1111111111111111', 0775, true);
mkdir($root . '/logs', 0775, true);
file_put_contents($root . '/config/tenant_registry.php', "<?php\nreturn ['schema_version'=>1,'global'=>[],'tenants'=>['t1'=>['credential_env_prefix'=>'t1','display_name'=>'T','line_channel_id'=>'Ut1111111111111111111111111111111','sno'=>'1111111111111111','depID'=>1,'storeNo'=>1,'store_uid'=>1,'provider_id_no'=>0,'status'=>'disabled','profile'=>[],'features'=>['bats_runtime'=>false,'aiu_authoritative'=>false,'grounding_authoritative'=>false],'gemini_policy'=>[],'source_policy'=>[]]]];\n");
file_put_contents($root . '/config/bds_source_registry.php', "<?php\nreturn ['schema_version'=>'bds_source_registry.v1','tenants'=>['t1'=>['store_no'=>1,'sno'=>'1111111111111111','tenant_name'=>'T','tenant_key'=>'t1','industry_code'=>'travel','enabled'=>false,'private_knowledge_sheet_id'=>'','gcs_prefix'=>'tenants/1111111111111111/']]];\n");
file_put_contents($root . '/config/product_source/tenants/1111111111111111/product_sources.json', json_encode(['schema_version'=>1,'tenant_sno'=>'1111111111111111','tenant_key'=>'t1','default_category'=>'group_tour','enabled_sources'=>[],'updated_at'=>gmdate('c')]));
$reader = new TenantAdminAuthorityReader($root . '/config/tenant_registry.php', $root . '/config/bds_source_registry.php', $root . '/config/product_source/tenants');
$validator = new TenantAdminPayloadValidator($reader);
$readiness = new TenantAdminReadinessValidator($reader, null, null, null, false);
$preview = new TenantAdminPreviewService($reader, $validator, $readiness);
$apply = new TenantAdminApplyService($reader, new TenantAdminAuthorityWriter($reader), new TenantAdminAuditLogger($root . '/logs/a.log'), $readiness);
$built = $preview->build('enable_bds_upload', ['tenant_key'=>'t1']);
$assert(($built['ok'] ?? false) === true, 'preview enable bds');
$res = $apply->apply($built['preview'], 'mask');
$assert(($res['ok'] ?? false) === true, 'apply enable bds');
$bds = $reader->getBdsRow('t1');
$assert(($bds['enabled'] ?? false) === true, 'bds enabled');
$row = $reader->getTenantRow('t1');
$assert(($row['status'] ?? '') === 'disabled', 'line still disabled');
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($root);
if ($failures > 0) { exit(1); }
fwrite(STDOUT, "OK: test_tenant_admin_enable_bds\n");
exit(0);
