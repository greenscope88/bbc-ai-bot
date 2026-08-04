<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
app_config();
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminLineBotIdentityResolver.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminAuthorityReader.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminPayloadValidator.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminReadinessValidator.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminPreviewService.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminAuthorityWriter.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminAuditLogger.php';
require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminApplyService.php';
require_once dirname(__DIR__, 2) . '/core/tenant/ConfigTenantRegistry.php';

$failures = 0;
$assert = static function (bool $c, string $m) use (&$failures): void {
    if (!$c) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$m}\n");
    }
};

$prefix = 'travel_z_botinfo_' . bin2hex(random_bytes(2));
$userId = 'U' . str_repeat('ab', 16);
putenv('LINE_CHANNEL_SECRET__' . $prefix . '=fixture-secret-not-real');
putenv('LINE_CHANNEL_ACCESS_TOKEN__' . $prefix . '=fixture-token-not-real');

$capturedAuth = [];
$transport = static function (string $url, string $accessToken) use ($userId, &$capturedAuth): array {
    $capturedAuth[] = [
        'url' => $url,
        'token_len' => strlen($accessToken),
        'token_is_fixture' => $accessToken === 'fixture-token-not-real',
    ];
    return [
        'http_status' => 200,
        'body' => json_encode([
            'userId' => $userId,
            'displayName' => 'Fixture OA',
            'basicId' => '@fixture_oa',
        ], JSON_UNESCAPED_UNICODE),
    ];
};

$resolver = new TenantAdminLineBotIdentityResolver($transport);
$ok = $resolver->resolveByCredentialEnvPrefix($prefix, 'travel_z');
$assert(($ok['ok'] ?? false) === true, 'bot info 200 parses userId');
$assert(($ok['user_id'] ?? '') === $userId, 'userId exact');
$assert(($ok['fingerprint'] ?? '') === TenantAdminLineBotIdentityResolver::fingerprint($userId), 'fingerprint');
$assert(strpos(json_encode($ok), 'fixture-token-not-real') === false, 'token absent from result');
$assert(($capturedAuth[0]['url'] ?? '') === TenantAdminLineBotIdentityResolver::BOT_INFO_URL, 'fixed URL');

$failHttp = new TenantAdminLineBotIdentityResolver(static function () {
    return ['http_status' => 401, 'body' => '{"message":"unauthorized"}'];
});
$assert(($failHttp->resolveByCredentialEnvPrefix($prefix)['ok'] ?? true) === false, 'http error fail-closed');

$failJson = new TenantAdminLineBotIdentityResolver(static function () {
    return ['http_status' => 200, 'body' => 'not-json'];
});
$assert(($failJson->resolveByCredentialEnvPrefix($prefix)['ok'] ?? true) === false, 'json error fail-closed');

$failMissing = new TenantAdminLineBotIdentityResolver(static function () {
    return ['http_status' => 200, 'body' => '{"displayName":"x"}'];
});
$assert(($failMissing->resolveByCredentialEnvPrefix($prefix)['ok'] ?? true) === false, 'missing userId fail-closed');

$failFmt = new TenantAdminLineBotIdentityResolver(static function () {
    return ['http_status' => 200, 'body' => '{"userId":"not-a-bot-id"}'];
});
$assert(($failFmt->resolveByCredentialEnvPrefix($prefix)['ok'] ?? true) === false, 'format error fail-closed');

// Update path with stub: client line_channel_id rejected; server-derived applied.
$root = sys_get_temp_dir() . '/bbc_admin_botid_' . bin2hex(random_bytes(4));
mkdir($root . '/config/product_source/tenants', 0775, true);
mkdir($root . '/logs', 0775, true);
$old = 'U' . str_repeat('1', 32);
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
            'status' => 'staging',
            'profile' => ['company_name' => 'Z Co'],
            'features' => [
                'bats_runtime' => true,
                'aiu_authoritative' => true,
                'grounding_authoritative' => true,
                'tour_prompt' => false,
                'hybrid_search' => false,
                'fixed_formatter' => false,
            ],
            'gemini_policy' => [],
            'source_policy' => [],
        ],
        'travel_b' => [
            'credential_env_prefix' => 'travel_b',
            'display_name' => 'B',
            'line_channel_id' => 'Ub1111111111111111111111111111111',
            'sno' => '5f99b8d665e8444d',
            'depID' => 888,
            'storeNo' => 6180,
            'store_uid' => 6180,
            'provider_id_no' => 101,
            'status' => 'enabled',
            'profile' => [],
            'features' => [],
            'gemini_policy' => [],
            'source_policy' => [],
        ],
    ],
];
file_put_contents($root . '/config/tenant_registry.php', "<?php\nreturn " . var_export($reg, true) . ";\n");
file_put_contents($root . '/config/bds_source_registry.php', "<?php\nreturn ['schema_version'=>'bds_source_registry.v1','tenants'=>[]];\n");

$reader = new TenantAdminAuthorityReader($root . '/config/tenant_registry.php', $root . '/config/bds_source_registry.php', $root . '/config/product_source/tenants');
$validator = new TenantAdminPayloadValidator($reader);
$readiness = new TenantAdminReadinessValidator($reader, null, null, null, false);
$bot = new TenantAdminLineBotIdentityResolver($transport);
$preview = new TenantAdminPreviewService($reader, $validator, $readiness, null, null, $bot);
$apply = new TenantAdminApplyService($reader, new TenantAdminAuthorityWriter($reader), new TenantAdminAuditLogger($root . '/logs/a.log'), $readiness, null, null, $bot);

$rejectClient = $preview->build('update_line_oa', [
    'tenant_key' => 'travel_z',
    'line_channel_id' => 'U' . str_repeat('9', 32),
]);
$assert(($rejectClient['ok'] ?? true) === false, 'client line_channel_id rejected');
$assert(($rejectClient['reason'] ?? '') === 'client_line_channel_id_forbidden', 'reject reason');

$built = $preview->build('update_line_oa', ['tenant_key' => 'travel_z']);
$assert(($built['ok'] ?? false) === true, 'preview update server-derived');
$assert(($built['preview']['normalized']['line_channel_id'] ?? '') === $userId, 'plan keeps full userId');
$publicJson = json_encode($built['public'], JSON_UNESCAPED_UNICODE);
$assert(strpos($publicJson, $userId) === false, 'public masks full userId');
$assert(strpos($publicJson, 'fixture-token-not-real') === false, 'public no token');
$assert(isset($built['public']['diff']['line_channel_id']['to_masked']), 'public has masked to');

$beforeB = $reader->getTenantRow('travel_b');
$res = $apply->apply($built['preview'], 'mask');
$assert(($res['ok'] ?? false) === true, 'apply update');
$row = $reader->getTenantRow('travel_z');
$assert(($row['line_channel_id'] ?? '') === $userId, 'new destination written');
$assert(($row['status'] ?? '') === 'staging', 'status unchanged');
$assert(!empty($row['features']['bats_runtime']), 'bats unchanged');
$assert(($row['credential_env_prefix'] ?? '') === $prefix, 'prefix unchanged');
$afterB = $reader->getTenantRow('travel_b');
$assert(($beforeB['line_channel_id'] ?? '') === ($afterB['line_channel_id'] ?? ''), 'travel_b unchanged');

$registry = new ConfigTenantRegistry($root . '/config/tenant_registry.php');
$assert($registry->resolveByChannel($old) === null, 'old destination fail-closed');
$assert($registry->resolveByChannel($userId) !== null, 'new destination resolves');

// drift → zero write
$driftBot = new TenantAdminLineBotIdentityResolver(static function () {
    return [
        'http_status' => 200,
        'body' => json_encode(['userId' => 'U' . str_repeat('cd', 16), 'displayName' => 'X', 'basicId' => '@x']),
    ];
});
$previewDrift = new TenantAdminPreviewService($reader, $validator, $readiness, null, null, $bot);
$built2 = $previewDrift->build('update_line_oa', ['tenant_key' => 'travel_z']);
$assert(($built2['ok'] ?? false) === true, 'second preview ok');
$applyDrift = new TenantAdminApplyService($reader, new TenantAdminAuthorityWriter($reader), new TenantAdminAuditLogger($root . '/logs/a.log'), $readiness, null, null, $driftBot);
$before = $reader->getTenantRow('travel_z')['line_channel_id'] ?? '';
$driftRes = $applyDrift->apply($built2['preview'], 'mask');
$assert(($driftRes['ok'] ?? true) === false, 'drift apply rejected');
$assert(($reader->getTenantRow('travel_z')['line_channel_id'] ?? '') === $before, 'drift zero write');

$audit = (string) file_get_contents($root . '/logs/a.log');
$assert(strpos($audit, 'fixture-token-not-real') === false, 'audit no token');
$assert(strpos($audit, 'fixture-secret-not-real') === false, 'audit no secret');

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
@rmdir($root);

putenv('LINE_CHANNEL_SECRET__' . $prefix);
putenv('LINE_CHANNEL_ACCESS_TOKEN__' . $prefix);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s)\n");
    exit(1);
}
fwrite(STDOUT, "OK: test_tenant_admin_line_bot_identity\n");
exit(0);
