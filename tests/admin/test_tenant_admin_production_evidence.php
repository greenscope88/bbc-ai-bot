<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminAuthorityReader.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminReadinessValidator.php';

$failures = 0;
$assert = static function (bool $c, string $m) use (&$failures): void {
    if (!$c) { ++$failures; fwrite(STDERR, "FAIL: {$m}\n"); }
};
$root = sys_get_temp_dir() . '/bbc_admin_ev_' . bin2hex(random_bytes(4));
$sno = '1111111111111111';
mkdir($root . "/config/product_source/tenants/{$sno}", 0775, true);
mkdir($root . "/var/bds/tenants/{$sno}/reports", 0775, true);
mkdir($root . '/logs', 0775, true);
file_put_contents($root . '/config/tenant_registry.php', "<?php\nreturn ['schema_version'=>1,'global'=>[],'tenants'=>['t1'=>['credential_env_prefix'=>'t1ev','display_name'=>'T','line_channel_id'=>'Ut1111111111111111111111111111111','sno'=>'{$sno}','depID'=>1,'storeNo'=>1,'store_uid'=>1,'provider_id_no'=>0,'status'=>'staging','profile'=>[],'features'=>['bats_runtime'=>true,'aiu_authoritative'=>true,'grounding_authoritative'=>false],'gemini_policy'=>[],'source_policy'=>[]]]];\n");
file_put_contents($root . '/config/bds_source_registry.php', "<?php\nreturn ['schema_version'=>'bds_source_registry.v1','tenants'=>['t1'=>['store_no'=>1,'sno'=>'{$sno}','tenant_name'=>'T','tenant_key'=>'t1','industry_code'=>'travel','enabled'=>true,'private_knowledge_sheet_id'=>'','gcs_prefix'=>'tenants/{$sno}/']]];\n");
file_put_contents($root . "/config/product_source/tenants/{$sno}/product_sources.json", json_encode(['schema_version'=>1,'tenant_sno'=>$sno,'tenant_key'=>'t1','default_category'=>'group_tour','enabled_sources'=>[],'updated_at'=>gmdate('c')]));
file_put_contents($root . "/var/bds/tenants/{$sno}/reports/sync_report.json", json_encode([
    'sync_id' => 'SYNC-20260803-000001',
    'status' => 'success',
    'tenant_sno' => $sno,
    'dry_run' => false,
    'finished_at' => gmdate('c'),
    'uploaded_objects' => ["tenants/{$sno}/knowledge/service_qa.json"],
]));
$now = date('Y-m-d H:i:s');
file_put_contents($root . '/logs/saas_router.log',
    "[{$now}][phase_9c1_gate_decision] {\"tenant_sno\":\"{$sno}\",\"final_route\":\"phase_9c1_structured_pilot\"}\n" .
    "[{$now}][phase_9c1_structured_pilot_reply] {\"tenant_sno\":\"{$sno}\",\"runtime_source\":\"aiu\",\"line_http_status\":200,\"ok\":true,\"line_reply\":{\"ok\":true,\"http_code\":200}}\n" .
    "[{$now}][phase_9c2b_knowledge_runtime_reply] {\"tenant_sno\":\"{$sno}\",\"knowledge_grounded\":true,\"grounded_source_type\":\"tenant_private\",\"line_reply\":{\"ok\":true,\"http_code\":200}}\n"
);
putenv('LINE_CHANNEL_SECRET__t1ev=test-secret-not-real');
putenv('LINE_CHANNEL_ACCESS_TOKEN__t1ev=test-token-not-real');

$reader = new TenantAdminAuthorityReader($root . '/config/tenant_registry.php', $root . '/config/bds_source_registry.php', $root . '/config/product_source/tenants');
$credResolver = static function (string $channelId): array {
    return ['ok' => true, 'tenant_key' => 't1'];
};
$readiness = new TenantAdminReadinessValidator($reader, $root . '/var/bds/tenants', $root . '/var/bds/uploads/tenants', $root . '/logs/saas_router.log', true, $credResolver);
$fin = $readiness->forFinalize('t1');
$assert(($fin['ok'] ?? false) === true, 'finalize evidence pass');

// cross-tenant evidence must not satisfy other sno
file_put_contents($root . "/var/bds/tenants/{$sno}/reports/sync_report.json", json_encode([
    'sync_id' => 'SYNC-X', 'status' => 'success', 'tenant_sno' => 'othertenant00001', 'dry_run' => false,
    'finished_at' => gmdate('c'), 'uploaded_objects' => ['tenants/othertenant00001/knowledge/service_qa.json'],
]));
$fin2 = $readiness->forFinalize('t1');
$assert(($fin2['ok'] ?? true) === false, 'cross-tenant sync rejected');

putenv('LINE_CHANNEL_SECRET__t1ev');
putenv('LINE_CHANNEL_ACCESS_TOKEN__t1ev');
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($root);
if ($failures > 0) { fwrite(STDERR, "{$failures} failure(s)\n"); exit(1); }
fwrite(STDOUT, "OK: test_tenant_admin_production_evidence\n");
exit(0);
