<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminAuthorityReader.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminPayloadValidator.php';

$failures = 0;
$assert = static function (bool $c, string $m) use (&$failures): void {
    if (!$c) { ++$failures; fwrite(STDERR, "FAIL: {$m}\n"); }
};
$root = sys_get_temp_dir() . '/bbc_admin_dest_' . bin2hex(random_bytes(4));
mkdir($root . '/config/product_source/tenants', 0775, true);
$reg = ['schema_version'=>1,'global'=>[],'tenants'=>[
    'a'=>['credential_env_prefix'=>'a','display_name'=>'A','line_channel_id'=>'Ua1111111111111111111111111111111','sno'=>'aaaaaaaaaaaaaaaa','depID'=>1,'storeNo'=>1,'store_uid'=>1,'provider_id_no'=>0,'status'=>'disabled','profile'=>[],'features'=>[],'gemini_policy'=>[],'source_policy'=>[]],
    'b'=>['credential_env_prefix'=>'b','display_name'=>'B','line_channel_id'=>'Ub2222222222222222222222222222222','sno'=>'bbbbbbbbbbbbbbbb','depID'=>1,'storeNo'=>2,'store_uid'=>2,'provider_id_no'=>0,'status'=>'disabled','profile'=>[],'features'=>[],'gemini_policy'=>[],'source_policy'=>[]],
]];
file_put_contents($root . '/config/tenant_registry.php', "<?php\nreturn " . var_export($reg, true) . ";\n");
file_put_contents($root . '/config/bds_source_registry.php', "<?php\nreturn ['schema_version'=>'bds_source_registry.v1','tenants'=>[]];\n");
$reader = new TenantAdminAuthorityReader($root . '/config/tenant_registry.php', $root . '/config/bds_source_registry.php', $root . '/config/product_source/tenants');
$validator = new TenantAdminPayloadValidator($reader);
$channel = 'Ub2222222222222222222222222222222';
$res = $validator->validate('update_line_oa', [
    'tenant_key' => 'a',
    'line_channel_id' => $channel,
    '_bot_identity_source' => 'line_bot_info',
    'bot_identity_fingerprint' => hash('sha256', 'line_bot_user_id|' . $channel),
]);
$assert(($res['ok'] ?? true) === false && ($res['reason'] ?? '') === 'destination_collision', 'collision rejected');
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($root);
if ($failures > 0) { exit(1); }
fwrite(STDOUT, "OK: test_tenant_admin_destination_collision\n");
exit(0);
