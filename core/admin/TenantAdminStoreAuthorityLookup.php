<?php
declare(strict_types=1);

/**
 * Composite Store Identity Authority for Admin Create.
 *
 * Lookup key: Owner-supplied sno (not written until server re-derives exact match).
 * - Prefer reverse decrypt → Store_Get → re-encrypt exact match
 * - Else scan Management-Scope Store_List and match encryptStr(storeNo, _DataKey)
 * - provider_id_no: SYSTEM DERIVED — EXISTING HOST B CONTRACT PROVEN
 * - tenant_key: travel_ + lowercase(full sno) for new tenants only
 */
final class TenantAdminStoreAuthorityLookup
{
    public const HOST_B_CONTRACT_PROVIDER_ID_NO = 0;
    public const SELECTION_TTL_SECONDS = 900;

    /** @var callable():int */
    private $depIdProvider;
    /** @var callable():string */
    private $dataKeyProvider;
    /** @var callable(int,int):(?array<string,mixed>) */
    private $storeFetcher;
    /** @var callable(int):list<array<string,mixed>> */
    private $storeListFetcher;
    /** @var callable(string,string):string */
    private $wireEncryptor;
    /** @var callable(string,string):(?string)|null */
    private $wireDecryptor;

    /**
     * @param callable():int $depIdProvider
     * @param callable():string $dataKeyProvider
     * @param callable(int,int):(?array<string,mixed>) $storeFetcher fn(depID, storeNo) => row|null
     * @param callable(int):list<array<string,mixed>> $storeListFetcher fn(depID) => rows
     * @param callable(string,string):string $wireEncryptor fn(plaintext, dataKey) => wire sno
     * @param (callable(string,string):(?string))|null $wireDecryptor fn(sno, dataKey) => plaintext|null
     */
    public function __construct(
        callable $depIdProvider,
        callable $dataKeyProvider,
        callable $storeFetcher,
        callable $storeListFetcher,
        callable $wireEncryptor,
        ?callable $wireDecryptor = null
    ) {
        $this->depIdProvider = $depIdProvider;
        $this->dataKeyProvider = $dataKeyProvider;
        $this->storeFetcher = $storeFetcher;
        $this->storeListFetcher = $storeListFetcher;
        $this->wireEncryptor = $wireEncryptor;
        $this->wireDecryptor = $wireDecryptor;
    }

    public static function deriveTenantKey(string $sno): string
    {
        return 'travel_' . strtolower(trim($sno));
    }

    /**
     * @param array<string, mixed> $identity
     */
    public static function fingerprint(array $identity): string
    {
        $canonical = [
            'sno' => (string) ($identity['sno'] ?? ''),
            'storeNo' => (int) ($identity['storeNo'] ?? 0),
            'store_uid' => (int) ($identity['store_uid'] ?? 0),
            'depID' => (int) ($identity['depID'] ?? 0),
            'provider_id_no' => (int) ($identity['provider_id_no'] ?? -1),
            'tenant_key' => (string) ($identity['tenant_key'] ?? ''),
            'credential_env_prefix' => (string) ($identity['credential_env_prefix'] ?? ''),
            'display_name' => (string) ($identity['display_name'] ?? ''),
            'company_name' => (string) ($identity['company_name'] ?? ''),
            'gcs_prefix' => (string) ($identity['gcs_prefix'] ?? ''),
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array{ok:bool,reason?:string,identity?:array<string,mixed>,fingerprint?:string}
     */
    public function resolveBySno(string $ownerSno): array
    {
        $ownerSno = strtolower(trim($ownerSno));
        if ($ownerSno === '' || !preg_match('/^[0-9a-f-]{8,64}$/', $ownerSno)) {
            return ['ok' => false, 'reason' => 'invalid_sno'];
        }

        $depId = (int) (($this->depIdProvider)());
        if ($depId <= 0) {
            return ['ok' => false, 'reason' => 'management_scope_unavailable'];
        }
        $dataKey = (string) (($this->dataKeyProvider)());
        if ($dataKey === '') {
            return ['ok' => false, 'reason' => 'wire_key_unavailable'];
        }

        $matches = [];

        if ($this->wireDecryptor !== null) {
            $plain = ($this->wireDecryptor)($ownerSno, $dataKey);
            if (is_string($plain) && preg_match('/^\d+$/', trim($plain))) {
                $candidateStoreNo = (int) trim($plain);
                if ($candidateStoreNo > 0) {
                    $row = ($this->storeFetcher)($depId, $candidateStoreNo);
                    if (is_array($row)) {
                        $built = $this->buildIdentityFromRow($row, $depId, $dataKey, $ownerSno);
                        if (($built['ok'] ?? false) === true) {
                            $matches[] = $built['identity'];
                        } elseif (($built['reason'] ?? '') === 'sno_mismatch') {
                            // decrypt candidate did not re-encrypt to owner sno — ignore, try list scan
                        } else {
                            return ['ok' => false, 'reason' => (string) ($built['reason'] ?? 'store_resolve_failed')];
                        }
                    }
                }
            }
        }

        if ($matches === []) {
            $rows = ($this->storeListFetcher)($depId);
            if (!is_array($rows)) {
                return ['ok' => false, 'reason' => 'store_list_unavailable'];
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $built = $this->buildIdentityFromRow($row, $depId, $dataKey, $ownerSno);
                if (($built['ok'] ?? false) === true) {
                    $matches[] = $built['identity'];
                }
            }
        }

        if ($matches === []) {
            return ['ok' => false, 'reason' => 'store_not_found'];
        }
        if (count($matches) > 1) {
            return ['ok' => false, 'reason' => 'store_duplicate_match'];
        }

        $identity = $matches[0];
        $fp = self::fingerprint($identity);

        return [
            'ok' => true,
            'identity' => $identity,
            'fingerprint' => $fp,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{ok:bool,reason?:string,identity?:array<string,mixed>}
     */
    private function buildIdentityFromRow(array $row, int $depId, string $dataKey, string $ownerSno): array
    {
        $storeNo = (int) ($row['storeNo'] ?? $row['StoreNo'] ?? $row['uid'] ?? 0);
        $rowDepId = (int) ($row['depID'] ?? $row['DepID'] ?? 0);
        if ($storeNo <= 0) {
            return ['ok' => false, 'reason' => 'identity_incomplete'];
        }
        if ($rowDepId > 0 && $rowDepId !== $depId) {
            return ['ok' => false, 'reason' => 'store_scope_mismatch'];
        }
        if ($this->isStoreDisabled($row)) {
            return ['ok' => false, 'reason' => 'store_disabled'];
        }

        $derivedSno = strtolower(trim((string) (($this->wireEncryptor)((string) $storeNo, $dataKey))));
        if ($derivedSno === '' || !preg_match('/^[0-9a-f-]{8,64}$/', $derivedSno)) {
            return ['ok' => false, 'reason' => 'wire_sno_invalid'];
        }
        if ($derivedSno !== $ownerSno) {
            return ['ok' => false, 'reason' => 'sno_mismatch'];
        }

        $displayName = trim((string) ($row['storeName'] ?? $row['StoreName'] ?? $row['name'] ?? ''));
        if ($displayName === '') {
            return ['ok' => false, 'reason' => 'identity_incomplete'];
        }

        $tenantKey = self::deriveTenantKey($derivedSno);
        $gcsPrefix = 'tenants/' . $derivedSno . '/';

        return [
            'ok' => true,
            'identity' => [
                'sno' => $derivedSno,
                'storeNo' => $storeNo,
                'store_uid' => $storeNo,
                'depID' => $depId,
                'provider_id_no' => self::HOST_B_CONTRACT_PROVIDER_ID_NO,
                'provider_id_no_source' => 'existing_host_b_contract',
                'tenant_key' => $tenantKey,
                'credential_env_prefix' => $tenantKey,
                'display_name' => $displayName,
                'company_name' => $displayName,
                'gcs_prefix' => $gcsPrefix,
                'bds_tenant_key' => $tenantKey,
                'bds_store_no' => $storeNo,
                'bds_sno' => $derivedSno,
                'bds_gcs_prefix' => $gcsPrefix,
                'product_set_tenant_sno' => $derivedSno,
                'product_set_tenant_key' => $tenantKey,
                '_identity_source' => 'store_authority',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isStoreDisabled(array $row): bool
    {
        if (array_key_exists('enabled', $row) && ($row['enabled'] === false || $row['enabled'] === 0 || $row['enabled'] === '0')) {
            return true;
        }
        $flag = $row['disabled'] ?? $row['Disabled'] ?? null;
        if ($flag === true || $flag === 1 || $flag === '1') {
            return true;
        }
        $reserve = $row['reserveDelDate'] ?? null;
        if (is_string($reserve) && trim($reserve) !== '') {
            $ts = strtotime($reserve);
            if (is_int($ts) && $ts > 0 && $ts <= time()) {
                return true;
            }
        }

        return false;
    }
}
