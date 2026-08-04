<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantAdminAuthorityReader.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantAdminAuthorityWriter.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantAdminAuditLogger.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantAdminReadinessValidator.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantAdminPreviewService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantAdminStoreAuthorityLookup.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantAdminCreateSelectionStore.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantAdminLineBotIdentityResolver.php';

/**
 * Single Apply Owner for BBC Tenant Admin mutations.
 */
final class TenantAdminApplyService
{
    /** @var TenantAdminAuthorityReader */
    private $reader;
    /** @var TenantAdminAuthorityWriter */
    private $writer;
    /** @var TenantAdminAuditLogger */
    private $audit;
    /** @var TenantAdminReadinessValidator */
    private $readiness;
    /** @var TenantAdminStoreAuthorityLookup|null */
    private $storeLookup;
    /** @var TenantAdminCreateSelectionStore|null */
    private $selectionStore;
    /** @var TenantAdminLineBotIdentityResolver */
    private $botIdentity;

    public function __construct(
        TenantAdminAuthorityReader $reader,
        TenantAdminAuthorityWriter $writer,
        TenantAdminAuditLogger $audit,
        TenantAdminReadinessValidator $readiness,
        ?TenantAdminStoreAuthorityLookup $storeLookup = null,
        ?TenantAdminCreateSelectionStore $selectionStore = null,
        ?TenantAdminLineBotIdentityResolver $botIdentity = null
    ) {
        $this->reader = $reader;
        $this->writer = $writer;
        $this->audit = $audit;
        $this->readiness = $readiness;
        $this->storeLookup = $storeLookup;
        $this->selectionStore = $selectionStore;
        $this->botIdentity = $botIdentity ?? new TenantAdminLineBotIdentityResolver();
    }

    /**
     * @param array<string, mixed> $previewPlan server-side preview record
     * @return array{ok:bool,reason?:string,changed_fields?:list<string>}
     */
    public function apply(array $previewPlan, string $adminWireSnoMasked): array
    {
        $operation = trim((string) ($previewPlan['operation'] ?? ''));
        $normalized = $previewPlan['normalized'] ?? null;
        $hash = (string) ($previewPlan['precondition_hash'] ?? '');
        $expiresAt = (int) ($previewPlan['expires_at'] ?? 0);
        if ($operation === '' || !is_array($normalized) || $hash === '') {
            return ['ok' => false, 'reason' => 'invalid_preview'];
        }
        if ($expiresAt > 0 && time() > $expiresAt) {
            return ['ok' => false, 'reason' => 'preview_expired'];
        }
        if (($previewPlan['consumed'] ?? false) === true) {
            return ['ok' => false, 'reason' => 'preview_replay'];
        }

        $tenantKey = (string) ($normalized['tenant_key'] ?? '');
        if ($operation === 'create_tenant') {
            $recheck = $this->reverifyCreateIdentity($normalized);
            if (!($recheck['ok'] ?? false)) {
                return ['ok' => false, 'reason' => (string) ($recheck['reason'] ?? 'identity_authority_drift')];
            }
            $botRecheck = $this->reverifyBotIdentity(
                (string) ($normalized['credential_env_prefix'] ?? ''),
                $tenantKey,
                (string) ($normalized['line_channel_id'] ?? ''),
                (string) ($normalized['bot_identity_fingerprint'] ?? '')
            );
            if (!($botRecheck['ok'] ?? false)) {
                return ['ok' => false, 'reason' => (string) ($botRecheck['reason'] ?? 'bot_identity_drift')];
            }
            unset(
                $normalized['_identity_source'],
                $normalized['selection_token'],
                $normalized['fingerprint'],
                $normalized['_bot_identity_source'],
                $normalized['bot_identity_fingerprint'],
                $normalized['bot_display_name'],
                $normalized['bot_basic_id']
            );
        }
        if ($operation === 'update_line_oa') {
            $row = $this->reader->getTenantRow($tenantKey);
            if ($row === null) {
                return ['ok' => false, 'reason' => 'tenant_not_found'];
            }
            $credPrefix = trim((string) ($row['credential_env_prefix'] ?? ''));
            $botRecheck = $this->reverifyBotIdentity(
                $credPrefix,
                $tenantKey,
                (string) ($normalized['line_channel_id'] ?? ''),
                (string) ($normalized['bot_identity_fingerprint'] ?? '')
            );
            if (!($botRecheck['ok'] ?? false)) {
                return ['ok' => false, 'reason' => (string) ($botRecheck['reason'] ?? 'bot_identity_drift')];
            }
            // Re-check destination uniqueness at Apply time.
            $channel = (string) ($normalized['line_channel_id'] ?? '');
            $tenants = $this->reader->loadTenantRegistry()['tenants'] ?? [];
            if (is_array($tenants)) {
                foreach ($tenants as $key => $trow) {
                    if (!is_array($trow) || (string) $key === $tenantKey) {
                        continue;
                    }
                    if (trim((string) ($trow['line_channel_id'] ?? '')) === $channel) {
                        return ['ok' => false, 'reason' => 'destination_collision'];
                    }
                }
            }
            unset(
                $normalized['_bot_identity_source'],
                $normalized['bot_identity_fingerprint'],
                $normalized['bot_display_name'],
                $normalized['bot_basic_id']
            );
        }
        if ($operation === 'finalize_tenant_production') {
            $ready = $this->readiness->forFinalize($tenantKey);
            if (!($ready['ok'] ?? false)) {
                return ['ok' => false, 'reason' => (string) ($ready['reason'] ?? 'finalize_not_ready')];
            }
        }
        if ($operation === 'enter_line_validation') {
            $ready = $this->readiness->forEnterLineValidation($tenantKey);
            if (!($ready['ok'] ?? false)) {
                return ['ok' => false, 'reason' => (string) ($ready['reason'] ?? 'enter_validation_not_ready')];
            }
        }

        $result = $this->writer->apply($operation, $normalized, $hash);
        if (($result['ok'] ?? false) === true) {
            $this->audit->write([
                'operation' => $operation,
                'admin_wire_sno_masked' => $adminWireSnoMasked,
                'tenant_key' => $tenantKey,
                'changed_fields' => $result['changed_fields'] ?? [],
                'result' => 'success',
                'summary' => !empty($result['idempotent']) ? 'idempotent_noop' : 'applied',
            ]);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array{ok:bool,reason?:string}
     */
    private function reverifyCreateIdentity(array $normalized): array
    {
        if (!$this->storeLookup instanceof TenantAdminStoreAuthorityLookup
            || !$this->selectionStore instanceof TenantAdminCreateSelectionStore) {
            return ['ok' => false, 'reason' => 'store_authority_unavailable'];
        }
        $token = trim((string) ($normalized['selection_token'] ?? ''));
        $expectedFp = trim((string) ($normalized['fingerprint'] ?? ''));
        $sel = $this->selectionStore->get($token);
        if (!($sel['ok'] ?? false) || !is_array($sel['record'] ?? null)) {
            return ['ok' => false, 'reason' => (string) ($sel['reason'] ?? 'selection_token_invalid')];
        }
        $record = $sel['record'];
        $ownerSno = (string) ($record['sno'] ?? '');
        if ($ownerSno === '' || $ownerSno !== (string) ($normalized['sno'] ?? '')) {
            return ['ok' => false, 'reason' => 'identity_authority_drift:sno'];
        }
        if ($expectedFp === '' || $expectedFp !== (string) ($record['fingerprint'] ?? '')) {
            return ['ok' => false, 'reason' => 'identity_fingerprint_drift'];
        }

        $resolved = $this->storeLookup->resolveBySno($ownerSno);
        if (!($resolved['ok'] ?? false) || !is_array($resolved['identity'] ?? null)) {
            return ['ok' => false, 'reason' => (string) ($resolved['reason'] ?? 'store_resolve_failed')];
        }
        $identity = $resolved['identity'];
        $fp = (string) ($resolved['fingerprint'] ?? TenantAdminStoreAuthorityLookup::fingerprint($identity));
        if ($fp !== $expectedFp) {
            return ['ok' => false, 'reason' => 'identity_fingerprint_drift'];
        }

        $fields = [
            'storeNo', 'store_uid', 'depID', 'sno', 'provider_id_no',
            'tenant_key', 'credential_env_prefix', 'display_name', 'company_name', 'gcs_prefix',
        ];
        foreach ($fields as $field) {
            if ((string) ($identity[$field] ?? '') !== (string) ($normalized[$field] ?? '')) {
                return ['ok' => false, 'reason' => 'identity_authority_drift:' . $field];
            }
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok:bool,reason?:string}
     */
    private function reverifyBotIdentity(
        string $credentialEnvPrefix,
        string $tenantKey,
        string $expectedUserId,
        string $expectedFingerprint
    ): array {
        $bot = $this->botIdentity->resolveByCredentialEnvPrefix($credentialEnvPrefix, $tenantKey);
        if (($bot['ok'] ?? false) !== true) {
            return ['ok' => false, 'reason' => (string) ($bot['reason'] ?? 'bot_identity_resolve_failed')];
        }
        $userId = (string) ($bot['user_id'] ?? '');
        $fp = (string) ($bot['fingerprint'] ?? '');
        if ($userId === '' || $userId !== $expectedUserId) {
            return ['ok' => false, 'reason' => 'bot_identity_drift'];
        }
        if ($fp === '' || $fp !== $expectedFingerprint || $fp !== TenantAdminLineBotIdentityResolver::fingerprint($userId)) {
            return ['ok' => false, 'reason' => 'bot_identity_fingerprint_drift'];
        }

        return ['ok' => true];
    }
}
