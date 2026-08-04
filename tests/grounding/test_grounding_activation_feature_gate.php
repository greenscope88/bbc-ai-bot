<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/tenant/ConfigTenantRegistry.php';
require_once dirname(__DIR__, 2) . '/core/grounding/GroundingPipelineRuntime.php';

$failures = 0;
$assert = static function (bool $c, string $m) use (&$failures): void {
    if (!$c) { ++$failures; fwrite(STDERR, "FAIL: {$m}\n"); }
};

$root = sys_get_temp_dir() . '/bbc_gnd_gate_' . bin2hex(random_bytes(4));
mkdir($root, 0775, true);
$reg = [
    'schema_version' => 1,
    'global' => [],
    'tenants' => [
        'on_tenant' => [
            'credential_env_prefix' => 'on_tenant',
            'display_name' => 'ON',
            'line_channel_id' => 'Uo1111111111111111111111111111111',
            'sno' => 'onononononononon',
            'depID' => 1,
            'storeNo' => 1,
            'store_uid' => 1,
            'provider_id_no' => 0,
            'status' => 'enabled',
            'profile' => [],
            'features' => ['grounding_authoritative' => true],
            'gemini_policy' => [],
            'source_policy' => [],
        ],
        'off_tenant' => [
            'credential_env_prefix' => 'off_tenant',
            'display_name' => 'OFF',
            'line_channel_id' => 'Uo2222222222222222222222222222222',
            'sno' => 'offoffoffoffoffo',
            'depID' => 1,
            'storeNo' => 2,
            'store_uid' => 2,
            'provider_id_no' => 0,
            'status' => 'enabled',
            'profile' => [],
            'features' => ['grounding_authoritative' => false],
            'gemini_policy' => [],
            'source_policy' => [],
        ],
        'disabled_tenant' => [
            'credential_env_prefix' => 'disabled_tenant',
            'display_name' => 'DIS',
            'line_channel_id' => 'Uo3333333333333333333333333333333',
            'sno' => 'disdisdisdisdisd',
            'depID' => 1,
            'storeNo' => 3,
            'store_uid' => 3,
            'provider_id_no' => 0,
            'status' => 'disabled',
            'profile' => [],
            'features' => ['grounding_authoritative' => true],
            'gemini_policy' => [],
            'source_policy' => [],
        ],
    ],
];
$path = $root . '/tenant_registry.php';
file_put_contents($path, "<?php\nreturn " . var_export($reg, true) . ";\n");
$registry = new ConfigTenantRegistry($path);
$cfg = [
    GroundingPipelineRuntime::FLAG_ENABLED => true,
    GroundingPipelineRuntime::FLAG_TENANTS => ['should_be_ignored'],
    'tenant_registry' => $registry,
];
$assert(GroundingPipelineRuntime::isAuthoritativeEnabled($cfg, 'onononononononon') === true, 'feature true + enabled');
$assert(GroundingPipelineRuntime::isAuthoritativeEnabled($cfg, 'offoffoffoffoffo') === false, 'feature false fail-closed');
$assert(GroundingPipelineRuntime::isAuthoritativeEnabled($cfg, 'disdisdisdisdisd') === false, 'disabled fail-closed');
$cfgOff = $cfg;
$cfgOff[GroundingPipelineRuntime::FLAG_ENABLED] = false;
$assert(GroundingPipelineRuntime::isAuthoritativeEnabled($cfgOff, 'onononononononon') === false, 'global kill switch');

@unlink($path);
@rmdir($root);
if ($failures > 0) { fwrite(STDERR, "{$failures} failure(s)\n"); exit(1); }
fwrite(STDOUT, "OK: test_grounding_activation_feature_gate\n");
exit(0);
