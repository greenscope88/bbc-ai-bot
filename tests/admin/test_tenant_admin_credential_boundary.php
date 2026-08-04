<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminAuthorityReader.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminReadinessValidator.php';

$failures = 0;
$assert = static function (bool $c, string $m) use (&$failures): void {
    if (!$c) { ++$failures; fwrite(STDERR, "FAIL: {$m}\n"); }
};
$root = sys_get_temp_dir() . '/bbc_admin_cred_' . bin2hex(random_bytes(4));
mkdir($root . '/config/product_source/tenants', 0775, true);
file_put_contents($root . '/config/tenant_registry.php', "<?php\nreturn ['schema_version'=>1,'global'=>[],'tenants'=>['t1'=>['credential_env_prefix'=>'t1_fixture','display_name'=>'T','line_channel_id'=>'Ut1111111111111111111111111111111','sno'=>'1111111111111111','depID'=>1,'storeNo'=>1,'store_uid'=>1,'provider_id_no'=>0,'status'=>'disabled','profile'=>[],'features'=>[],'gemini_policy'=>[],'source_policy'=>[]]]];\n");
file_put_contents($root . '/config/bds_source_registry.php', "<?php\nreturn ['schema_version'=>'bds_source_registry.v1','tenants'=>[]];\n");
$reader = new TenantAdminAuthorityReader($root . '/config/tenant_registry.php', $root . '/config/bds_source_registry.php', $root . '/config/product_source/tenants');

$missingResolver = static function (string $channelId): array {
    return [
        'ok' => false,
        'tenant_key' => 't1',
        'channel_secret' => null,
        'channel_access_token' => null,
        'errorCode' => 'MISSING_CREDENTIALS',
        'registry_hit' => true,
        'missing_keys' => ['LINE_CHANNEL_SECRET__t1_fixture'],
    ];
};
$readiness = new TenantAdminReadinessValidator($reader, null, null, null, false, $missingResolver);
$st = $readiness->credentialStatus('t1');
$assert(($st['status'] ?? '') === 'missing', 'missing when resolver reports MISSING_CREDENTIALS');
$assert(!isset($st['secret']) && !isset($st['token']) && !isset($st['channel_secret']), 'no secret fields returned');

$okResolver = static function (string $channelId): array {
    return [
        'ok' => true,
        'tenant_key' => 't1',
        'channel_secret' => 'SECRETS_MUST_NOT_LEAK',
        'channel_access_token' => 'TOKENS_MUST_NOT_LEAK',
        'errorCode' => null,
        'registry_hit' => true,
        'missing_keys' => [],
    ];
};
$readinessOk = new TenantAdminReadinessValidator($reader, null, null, null, false, $okResolver);
$stOk = $readinessOk->credentialStatus('t1');
$assert(($stOk['status'] ?? '') === 'configured', 'configured when resolver ok');
$encoded = json_encode($stOk);
$assert(is_string($encoded) && strpos($encoded, 'SECRETS_MUST_NOT_LEAK') === false, 'secret not in status payload');
$assert(strpos($encoded, 'TOKENS_MUST_NOT_LEAK') === false, 'token not in status payload');

@unlink($root . '/config/tenant_registry.php');
@unlink($root . '/config/bds_source_registry.php');
@rmdir($root . '/config/product_source/tenants');
@rmdir($root . '/config/product_source');
@rmdir($root . '/config');
@rmdir($root);
if ($failures > 0) { exit(1); }
fwrite(STDOUT, "OK: test_tenant_admin_credential_boundary\n");
exit(0);
