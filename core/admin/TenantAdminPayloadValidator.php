<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantAdminAuthorityReader.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantAdminStoreAuthorityLookup.php';

/**
 * Whitelist payload validation for Admin operations.
 */
final class TenantAdminPayloadValidator
{
    public const OPS = [
        'create_tenant',
        'update_line_oa',
        'enable_bds_upload',
        'enter_line_validation',
        'finalize_tenant_production',
        'deactivate_tenant_production',
    ];

    public const IMMUTABLE = [
        'tenant_key', 'sno', 'storeNo', 'store_uid', 'depID', 'provider_id_no',
        'credential_env_prefix', 'gcs_prefix',
    ];

    public const ACTIVATION_FEATURES = [
        'bats_runtime',
        'aiu_authoritative',
        'grounding_authoritative',
    ];

    /** Client must never submit these as Create authority. */
    public const FORBIDDEN_CREATE_CLIENT_FIELDS = [
        'storeNo', 'depID', 'store_uid', 'provider_id_no', 'tenant_key',
        'display_name', 'company_name', 'credential_env_prefix', 'gcs_prefix',
        'sno', 'fingerprint', 'line_channel_id',
    ];

    /** @var TenantAdminAuthorityReader */
    private $reader;

    public function __construct(TenantAdminAuthorityReader $reader)
    {
        $this->reader = $reader;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok:bool,operation?:string,normalized?:array<string,mixed>,reason?:string}
     */
    public function validate(string $operation, array $payload): array
    {
        $operation = trim($operation);
        if (!in_array($operation, self::OPS, true)) {
            return ['ok' => false, 'reason' => 'unknown_operation'];
        }

        if ($operation !== 'create_tenant') {
            foreach (array_keys($payload) as $field) {
                if ($field !== 'tenant_key' && in_array($field, self::IMMUTABLE, true)) {
                    return ['ok' => false, 'reason' => 'immutable_field:' . $field];
                }
            }
        }

        $unknown = array_diff(array_keys($payload), $this->allowedFields($operation));
        if ($unknown !== []) {
            return ['ok' => false, 'reason' => 'unknown_field:' . implode(',', $unknown)];
        }

        switch ($operation) {
            case 'create_tenant':
                return $this->validateCreate($payload);
            case 'update_line_oa':
                return $this->validateUpdateLine($payload);
            case 'enable_bds_upload':
            case 'finalize_tenant_production':
            case 'deactivate_tenant_production':
                return $this->validateTenantKeyOnly($operation, $payload);
            case 'enter_line_validation':
                return $this->validateEnterValidation($payload);
        }

        return ['ok' => false, 'reason' => 'unknown_operation'];
    }

    /**
     * @return list<string>
     */
    private function allowedFields(string $operation): array
    {
        switch ($operation) {
            case 'create_tenant':
                return [
                    'selection_token',
                    // server-merged only:
                    'line_channel_id', 'tenant_key', 'display_name', 'company_name', 'sno', 'storeNo',
                    'store_uid', 'depID', 'provider_id_no', 'credential_env_prefix',
                    'gcs_prefix', 'fingerprint', '_identity_source',
                    'bot_identity_fingerprint', 'bot_display_name', 'bot_basic_id', '_bot_identity_source',
                ];
            case 'update_line_oa':
                return [
                    'tenant_key', 'display_name', 'company_name',
                    // server-merged Bot Info only:
                    'line_channel_id', 'bot_identity_fingerprint', 'bot_display_name',
                    'bot_basic_id', '_bot_identity_source',
                ];
            case 'enter_line_validation':
                return ['tenant_key', 'bats_runtime', 'aiu_authoritative', 'grounding_authoritative'];
            default:
                return ['tenant_key'];
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok:bool,reason?:string}
     */
    public function rejectClientCreateAuthorityFields(array $payload): array
    {
        foreach (self::FORBIDDEN_CREATE_CLIENT_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                return ['ok' => false, 'reason' => 'client_identity_forbidden:' . $field];
            }
        }

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok:bool,operation?:string,normalized?:array<string,mixed>,reason?:string}
     */
    private function validateCreate(array $payload): array
    {
        $tenantKey = trim((string) ($payload['tenant_key'] ?? ''));
        $displayName = trim((string) ($payload['display_name'] ?? ''));
        $lineChannelId = trim((string) ($payload['line_channel_id'] ?? ''));
        $sno = strtolower(trim((string) ($payload['sno'] ?? '')));
        $storeNo = (int) ($payload['storeNo'] ?? 0);
        $storeUid = array_key_exists('store_uid', $payload) ? (int) $payload['store_uid'] : $storeNo;
        $depId = (int) ($payload['depID'] ?? 0);
        $providerIdNo = (int) ($payload['provider_id_no'] ?? 0);
        $companyName = trim((string) ($payload['company_name'] ?? $displayName));
        $identitySource = trim((string) ($payload['_identity_source'] ?? ''));
        $fingerprint = trim((string) ($payload['fingerprint'] ?? ''));
        $selectionToken = trim((string) ($payload['selection_token'] ?? ''));
        $credPrefix = trim((string) ($payload['credential_env_prefix'] ?? $tenantKey));
        $gcsPrefix = trim((string) ($payload['gcs_prefix'] ?? ('tenants/' . $sno . '/')));

        if ($identitySource !== 'store_authority') {
            return ['ok' => false, 'reason' => 'identity_not_server_derived'];
        }
        if ($selectionToken === '' || $fingerprint === '') {
            return ['ok' => false, 'reason' => 'selection_required'];
        }
        if ($displayName === '' || $sno === '' || $storeNo <= 0 || $depId <= 0) {
            return ['ok' => false, 'reason' => 'incomplete_identity'];
        }
        if ($lineChannelId === '' || !preg_match('/^U[0-9a-fA-F]{32}$/', $lineChannelId)) {
            return ['ok' => false, 'reason' => 'invalid_line_channel_id'];
        }
        if (trim((string) ($payload['_bot_identity_source'] ?? '')) !== 'line_bot_info') {
            return ['ok' => false, 'reason' => 'line_channel_id_not_server_derived'];
        }
        $botFp = trim((string) ($payload['bot_identity_fingerprint'] ?? ''));
        if ($botFp === '' || $botFp !== hash('sha256', 'line_bot_user_id|' . $lineChannelId)) {
            return ['ok' => false, 'reason' => 'bot_identity_fingerprint_mismatch'];
        }
        if (!preg_match('/^[0-9a-f-]{8,64}$/', $sno)) {
            return ['ok' => false, 'reason' => 'invalid_sno'];
        }

        $expectedKey = TenantAdminStoreAuthorityLookup::deriveTenantKey($sno);
        // New Create keys: travel_ + full lowercase sno (hyphens allowed if present in sno).
        if ($tenantKey !== $expectedKey || !preg_match('/^travel_[0-9a-f-]{8,64}$/', $tenantKey)) {
            return ['ok' => false, 'reason' => 'invalid_tenant_key'];
        }
        if ($credPrefix !== $tenantKey) {
            return ['ok' => false, 'reason' => 'credential_prefix_mismatch'];
        }
        if ($gcsPrefix !== ('tenants/' . $sno . '/')) {
            return ['ok' => false, 'reason' => 'gcs_prefix_mismatch'];
        }
        if ($providerIdNo === 1) {
            return ['ok' => false, 'reason' => 'forbidden_mock_provider_id_no'];
        }
        if ($providerIdNo !== TenantAdminStoreAuthorityLookup::HOST_B_CONTRACT_PROVIDER_ID_NO) {
            return ['ok' => false, 'reason' => 'provider_id_no_contract_mismatch'];
        }

        $tenantReg = $this->reader->loadTenantRegistry();
        $bdsReg = $this->reader->loadBdsRegistry();
        $tenants = is_array($tenantReg['tenants'] ?? null) ? $tenantReg['tenants'] : [];
        $bdsTenants = is_array($bdsReg['tenants'] ?? null) ? $bdsReg['tenants'] : [];

        if (isset($tenants[$tenantKey]) || isset($bdsTenants[$tenantKey])) {
            return ['ok' => false, 'reason' => 'tenant_key_exists'];
        }
        foreach ($tenants as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string) ($row['credential_env_prefix'] ?? '')) === $credPrefix) {
                return ['ok' => false, 'reason' => 'credential_prefix_collision'];
            }
            if (strtolower(trim((string) ($row['sno'] ?? ''))) === $sno) {
                return ['ok' => false, 'reason' => 'sno_collision'];
            }
            if ((int) ($row['storeNo'] ?? 0) === $storeNo) {
                return ['ok' => false, 'reason' => 'storeNo_collision'];
            }
            if (trim((string) ($row['line_channel_id'] ?? '')) === $lineChannelId) {
                return ['ok' => false, 'reason' => 'destination_collision'];
            }
        }
        foreach ($bdsTenants as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (strtolower(trim((string) ($row['sno'] ?? ''))) === $sno) {
                return ['ok' => false, 'reason' => 'bds_sno_collision'];
            }
            if ((int) ($row['store_no'] ?? 0) === $storeNo) {
                return ['ok' => false, 'reason' => 'bds_store_no_collision'];
            }
            if (trim((string) ($row['tenant_key'] ?? '')) === $tenantKey) {
                return ['ok' => false, 'reason' => 'bds_tenant_key_collision'];
            }
        }

        return [
            'ok' => true,
            'operation' => 'create_tenant',
            'normalized' => [
                'tenant_key' => $tenantKey,
                'display_name' => $displayName,
                'line_channel_id' => $lineChannelId,
                'sno' => $sno,
                'storeNo' => $storeNo,
                'store_uid' => $storeUid > 0 ? $storeUid : $storeNo,
                'depID' => $depId,
                'provider_id_no' => $providerIdNo,
                'company_name' => $companyName !== '' ? $companyName : $displayName,
                'credential_env_prefix' => $credPrefix,
                'gcs_prefix' => $gcsPrefix,
                'selection_token' => $selectionToken,
                'fingerprint' => $fingerprint,
                '_identity_source' => 'store_authority',
                'bot_identity_fingerprint' => trim((string) ($payload['bot_identity_fingerprint'] ?? '')),
                '_bot_identity_source' => trim((string) ($payload['_bot_identity_source'] ?? '')),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok:bool,operation?:string,normalized?:array<string,mixed>,reason?:string}
     */
    private function validateUpdateLine(array $payload): array
    {
        $tenantKey = trim((string) ($payload['tenant_key'] ?? ''));
        if ($tenantKey === '' || $this->reader->getTenantRow($tenantKey) === null) {
            return ['ok' => false, 'reason' => 'tenant_not_found'];
        }
        foreach (self::IMMUTABLE as $field) {
            if (array_key_exists($field, $payload) && $field !== 'tenant_key') {
                return ['ok' => false, 'reason' => 'immutable_field:' . $field];
            }
        }
        $normalized = ['tenant_key' => $tenantKey];
        if (array_key_exists('line_channel_id', $payload)) {
            if (trim((string) ($payload['_bot_identity_source'] ?? '')) !== 'line_bot_info') {
                return ['ok' => false, 'reason' => 'line_channel_id_not_server_derived'];
            }
            $channel = trim((string) $payload['line_channel_id']);
            if ($channel === '' || !preg_match('/^U[0-9a-fA-F]{32}$/', $channel)) {
                return ['ok' => false, 'reason' => 'invalid_line_channel_id'];
            }
            $botFp = trim((string) ($payload['bot_identity_fingerprint'] ?? ''));
            if ($botFp === '' || $botFp !== hash('sha256', 'line_bot_user_id|' . $channel)) {
                return ['ok' => false, 'reason' => 'bot_identity_fingerprint_mismatch'];
            }
            $tenants = $this->reader->loadTenantRegistry()['tenants'] ?? [];
            if (is_array($tenants)) {
                foreach ($tenants as $key => $row) {
                    if (!is_array($row) || (string) $key === $tenantKey) {
                        continue;
                    }
                    if (trim((string) ($row['line_channel_id'] ?? '')) === $channel) {
                        return ['ok' => false, 'reason' => 'destination_collision'];
                    }
                }
            }
            $normalized['line_channel_id'] = $channel;
            $normalized['bot_identity_fingerprint'] = $botFp;
        }
        if (array_key_exists('display_name', $payload)) {
            $name = trim((string) $payload['display_name']);
            if ($name === '') {
                return ['ok' => false, 'reason' => 'invalid_display_name'];
            }
            $normalized['display_name'] = $name;
        }
        if (array_key_exists('company_name', $payload)) {
            $company = trim((string) $payload['company_name']);
            if ($company === '') {
                return ['ok' => false, 'reason' => 'invalid_company_name'];
            }
            $normalized['company_name'] = $company;
        }
        if (count($normalized) < 2) {
            return ['ok' => false, 'reason' => 'empty_update'];
        }

        return ['ok' => true, 'operation' => 'update_line_oa', 'normalized' => $normalized];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok:bool,operation?:string,normalized?:array<string,mixed>,reason?:string}
     */
    private function validateTenantKeyOnly(string $operation, array $payload): array
    {
        $tenantKey = trim((string) ($payload['tenant_key'] ?? ''));
        if ($tenantKey === '' || $this->reader->getTenantRow($tenantKey) === null) {
            return ['ok' => false, 'reason' => 'tenant_not_found'];
        }
        if ($operation === 'enable_bds_upload' && $this->reader->getBdsRow($tenantKey) === null) {
            return ['ok' => false, 'reason' => 'bds_entry_missing'];
        }

        return [
            'ok' => true,
            'operation' => $operation,
            'normalized' => ['tenant_key' => $tenantKey],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok:bool,operation?:string,normalized?:array<string,mixed>,reason?:string}
     */
    private function validateEnterValidation(array $payload): array
    {
        $base = $this->validateTenantKeyOnly('enter_line_validation', $payload);
        if (!($base['ok'] ?? false)) {
            return $base;
        }
        $normalized = $base['normalized'];
        foreach (self::ACTIVATION_FEATURES as $feature) {
            if (!array_key_exists($feature, $payload)) {
                return ['ok' => false, 'reason' => 'missing_feature:' . $feature];
            }
            $normalized[$feature] = ($payload[$feature] === true || $payload[$feature] === 1 || $payload[$feature] === '1');
        }

        return ['ok' => true, 'operation' => 'enter_line_validation', 'normalized' => $normalized];
    }
}
