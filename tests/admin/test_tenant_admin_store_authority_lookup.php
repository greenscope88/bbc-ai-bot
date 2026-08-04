<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/admin/TenantAdminStoreAuthorityLookup.php';

$failures = 0;
$assert = static function (bool $c, string $m) use (&$failures): void {
    if (!$c) { ++$failures; fwrite(STDERR, "FAIL: {$m}\n"); }
};

$rows = [
    ['storeNo' => 6355, 'depID' => 888, 'storeName' => '家樂福旅行社 總公司'],
    ['storeNo' => 6180, 'depID' => 888, 'storeName' => '旅行蜜優惠'],
];
$encryptMap = [
    '6355' => '5fecdf66e9224bee',
    '6180' => '5f99b8d665e8444d',
];

$lookup = new TenantAdminStoreAuthorityLookup(
    static function (): int { return 888; },
    static function (): string { return 'k'; },
    static function (int $depId, int $storeNo) use ($rows): ?array {
        foreach ($rows as $row) {
            if ((int) $row['storeNo'] === $storeNo && (int) $row['depID'] === $depId) {
                return $row;
            }
        }
        return null;
    },
    static function (int $depId) use ($rows): array {
        return array_values(array_filter($rows, static function (array $r) use ($depId): bool {
            return (int) $r['depID'] === $depId;
        }));
    },
    static function (string $plaintext, string $dataKey) use ($encryptMap): string {
        return $encryptMap[$plaintext] ?? 'nomatch';
    },
    static function (string $sno, string $dataKey) use ($encryptMap): ?string {
        foreach ($encryptMap as $store => $wire) {
            if ($wire === strtolower($sno)) {
                return (string) $store;
            }
        }
        return null;
    }
);

$ok = $lookup->resolveBySno('5fecdf66e9224bee');
$assert(($ok['ok'] ?? false) === true, 'unique sno resolve ok');
$assert((int) ($ok['identity']['storeNo'] ?? 0) === 6355, 'storeNo from reverse');
$assert((int) ($ok['identity']['provider_id_no'] ?? -1) === 0, 'provider_id_no Host B contract');
$assert((string) ($ok['identity']['tenant_key'] ?? '') === 'travel_5fecdf66e9224bee', 'tenant_key derived');
$assert((string) ($ok['identity']['credential_env_prefix'] ?? '') === 'travel_5fecdf66e9224bee', 'cred prefix = tenant_key');
$assert(is_string($ok['fingerprint'] ?? null) && strlen((string) $ok['fingerprint']) === 64, 'fingerprint');

$miss = $lookup->resolveBySno('deadbeefdeadbeef');
$assert(($miss['ok'] ?? true) === false, 'unknown sno fail-closed');

$bad = $lookup->resolveBySno('0');
$assert(($bad['ok'] ?? true) === false, 'invalid sno fail-closed');

// duplicate encrypt targets fail-closed
$dupLookup = new TenantAdminStoreAuthorityLookup(
    static function (): int { return 888; },
    static function (): string { return 'k'; },
    static function (int $depId, int $storeNo): ?array { return null; },
    static function (int $depId): array {
        return [
            ['storeNo' => 1, 'depID' => 888, 'storeName' => 'A'],
            ['storeNo' => 2, 'depID' => 888, 'storeName' => 'B'],
        ];
    },
    static function (string $plaintext, string $dataKey): string { return 'aaaaaaaaaaaaaaaa'; },
    null
);
$dup = $dupLookup->resolveBySno('aaaaaaaaaaaaaaaa');
$assert(($dup['ok'] ?? true) === false && ($dup['reason'] ?? '') === 'store_duplicate_match', 'duplicate fail-closed');

// existing key derivation helper does not rewrite travel_a/b/c/d literals
$assert(TenantAdminStoreAuthorityLookup::deriveTenantKey('5f99b8d665e8444d') === 'travel_5f99b8d665e8444d', 'derive uses full sno');
$assert(TenantAdminStoreAuthorityLookup::deriveTenantKey('5f99b8d665e8444d') !== 'travel_b', 'does not collapse to travel_b');

if ($failures > 0) { fwrite(STDERR, "{$failures} failure(s)\n"); exit(1); }
fwrite(STDOUT, "OK: test_tenant_admin_store_authority_lookup\n");
exit(0);
