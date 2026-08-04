<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminAuthorityReader.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminPayloadValidator.php';

$failures = 0;
$assert = static function (bool $c, string $m) use (&$failures): void {
    if (!$c) { ++$failures; fwrite(STDERR, "FAIL: {$m}\n"); }
};
$root = sys_get_temp_dir() . '/bbc_admin_immut_' . bin2hex(random_bytes(4));
mkdir($root . '/config/product_source/tenants', 0775, true);
file_put_contents($root . '/config/tenant_registry.php', "<?php\nreturn ['schema_version'=>1,'global'=>[],'tenants'=>['t1'=>['credential_env_prefix'=>'t1','display_name'=>'T','line_channel_id'=>'Ut1111111111111111111111111111111','sno'=>'1111111111111111','depID'=>1,'storeNo'=>1,'store_uid'=>1,'provider_id_no'=>0,'status'=>'disabled','profile'=>[],'features'=>[],'gemini_policy'=>[],'source_policy'=>[]]]];\n");
file_put_contents($root . '/config/bds_source_registry.php', "<?php\nreturn ['schema_version'=>'bds_source_registry.v1','tenants'=>[]];\n");
$reader = new TenantAdminAuthorityReader($root . '/config/tenant_registry.php', $root . '/config/bds_source_registry.php', $root . '/config/product_source/tenants');
$validator = new TenantAdminPayloadValidator($reader);
$channel = 'Ut2222222222222222222222222222222';
$res = $validator->validate('update_line_oa', [
    'tenant_key' => 't1',
    'sno' => '1111111111111111',
    'line_channel_id' => $channel,
    '_bot_identity_source' => 'line_bot_info',
    'bot_identity_fingerprint' => hash('sha256', 'line_bot_user_id|' . $channel),
]);
$assert(($res['ok'] ?? true) === false, 'immutable sno rejected even if same');
$assert(strpos((string) ($res['reason'] ?? ''), 'immutable_field') === 0, 'immutable reason');
@unlink($root . '/config/tenant_registry.php');
@unlink($root . '/config/bds_source_registry.php');
@rmdir($root . '/config/product_source/tenants');
@rmdir($root . '/config/product_source');
@rmdir($root . '/config');
@rmdir($root);
if ($failures > 0) { exit(1); }
fwrite(STDOUT, "OK: test_tenant_admin_identity_immutable\n");
exit(0);
