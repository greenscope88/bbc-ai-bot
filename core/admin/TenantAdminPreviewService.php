<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantAdminAuthorityReader.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantAdminPayloadValidator.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantAdminReadinessValidator.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantAdminStoreAuthorityLookup.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantAdminCreateSelectionStore.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantAdminLineBotIdentityResolver.php';

/**
 * Preview-first Admin planning. Full plan stays server-side (caller session store).
 */
final class TenantAdminPreviewService
{
    public const PREVIEW_TTL_SECONDS = 900;

    /** @var TenantAdminAuthorityReader */
    private $reader;
    /** @var TenantAdminPayloadValidator */
    private $validator;
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
        TenantAdminPayloadValidator $validator,
        TenantAdminReadinessValidator $readiness,
        ?TenantAdminStoreAuthorityLookup $storeLookup = null,
        ?TenantAdminCreateSelectionStore $selectionStore = null,
        ?TenantAdminLineBotIdentityResolver $botIdentity = null
    ) {
        $this->reader = $reader;
        $this->validator = $validator;
        $this->readiness = $readiness;
        $this->storeLookup = $storeLookup;
        $this->selectionStore = $selectionStore;
        $this->botIdentity = $botIdentity ?? new TenantAdminLineBotIdentityResolver();
    }

    /**
     * Resolve Owner sno → server Identity + selection token (for UI read-only display).
     *
     * @return array{ok:bool,reason?:string,selection_token?:string,fingerprint?:string,identity?:array<string,mixed>}
     */
    public function resolveCreateSelection(string $ownerSno): array
    {
        if (!$this->storeLookup instanceof TenantAdminStoreAuthorityLookup
            || !$this->selectionStore instanceof TenantAdminCreateSelectionStore) {
            return ['ok' => false, 'reason' => 'store_authority_unavailable'];
        }
        $resolved = $this->storeLookup->resolveBySno($ownerSno);
        if (!($resolved['ok'] ?? false) || !is_array($resolved['identity'] ?? null)) {
            return ['ok' => false, 'reason' => (string) ($resolved['reason'] ?? 'store_resolve_failed')];
        }
        $fp = (string) ($resolved['fingerprint'] ?? TenantAdminStoreAuthorityLookup::fingerprint($resolved['identity']));

        return $this->selectionStore->issue($resolved['identity'], $fp);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok:bool,reason?:string,preview?:array<string,mixed>,public?:array<string,mixed>}
     */
    public function build(string $operation, array $payload): array
    {
        if ($operation === 'create_tenant') {
            $prepared = $this->prepareCreatePayload($payload);
            if (!($prepared['ok'] ?? false)) {
                return ['ok' => false, 'reason' => (string) ($prepared['reason'] ?? 'identity_resolve_failed')];
            }
            $payload = $prepared['payload'];
        }
        if ($operation === 'update_line_oa') {
            $prepared = $this->prepareUpdateLinePayload($payload);
            if (!($prepared['ok'] ?? false)) {
                return ['ok' => false, 'reason' => (string) ($prepared['reason'] ?? 'bot_identity_resolve_failed')];
            }
            $payload = $prepared['payload'];
        }

        $validated = $this->validator->validate($operation, $payload);
        if (!($validated['ok'] ?? false)) {
            return ['ok' => false, 'reason' => (string) ($validated['reason'] ?? 'invalid_payload')];
        }
        $normalized = $validated['normalized'];
        $tenantKey = (string) ($normalized['tenant_key'] ?? '');

        if ($operation === 'enter_line_validation') {
            $ready = $this->readiness->forEnterLineValidation($tenantKey);
            if (!($ready['ok'] ?? false)) {
                return ['ok' => false, 'reason' => (string) ($ready['reason'] ?? 'not_ready'), 'checks' => $ready['checks'] ?? []];
            }
        }
        if ($operation === 'finalize_tenant_production') {
            $ready = $this->readiness->forFinalize($tenantKey);
            if (!($ready['ok'] ?? false)) {
                return ['ok' => false, 'reason' => (string) ($ready['reason'] ?? 'not_ready'), 'checks' => $ready['checks'] ?? []];
            }
        }
        if ($operation === 'enable_bds_upload') {
            $ready = $this->readiness->preflightIdentity($tenantKey);
            if (!($ready['ok'] ?? false)) {
                return ['ok' => false, 'reason' => (string) ($ready['reason'] ?? 'not_ready'), 'checks' => $ready['checks'] ?? []];
            }
        }

        $previewId = bin2hex(random_bytes(16));
        $hash = $this->reader->authorityHash();
        $preview = [
            'preview_id' => $previewId,
            'operation' => $operation,
            'normalized' => $normalized,
            'precondition_hash' => $hash,
            'created_at' => time(),
            'expires_at' => time() + self::PREVIEW_TTL_SECONDS,
        ];

        return [
            'ok' => true,
            'preview' => $preview,
            'public' => [
                'preview_id' => $previewId,
                'operation' => $operation,
                'tenant_key' => $tenantKey,
                'expires_at' => $preview['expires_at'],
                'diff' => $this->publicDiff($operation, $normalized, $tenantKey),
            ],
        ];
    }

    /**
     * Create Preview client payload may only carry selection_token.
     * LINE Bot userId is derived server-side via Bot Info.
     *
     * @param array<string, mixed> $payload
     * @return array{ok:bool,reason?:string,payload?:array<string,mixed>}
     */
    private function prepareCreatePayload(array $payload): array
    {
        if (array_key_exists('line_channel_id', $payload)) {
            return ['ok' => false, 'reason' => 'client_line_channel_id_forbidden'];
        }
        $reject = $this->validator->rejectClientCreateAuthorityFields($payload);
        if (!($reject['ok'] ?? false)) {
            return $reject;
        }
        if (!$this->storeLookup instanceof TenantAdminStoreAuthorityLookup
            || !$this->selectionStore instanceof TenantAdminCreateSelectionStore) {
            return ['ok' => false, 'reason' => 'store_authority_unavailable'];
        }

        $token = trim((string) ($payload['selection_token'] ?? ''));
        if ($token === '') {
            return ['ok' => false, 'reason' => 'selection_required'];
        }

        $sel = $this->selectionStore->get($token);
        if (!($sel['ok'] ?? false) || !is_array($sel['record'] ?? null)) {
            return ['ok' => false, 'reason' => (string) ($sel['reason'] ?? 'selection_token_invalid')];
        }
        $record = $sel['record'];
        $ownerSno = (string) ($record['sno'] ?? '');
        $expectedFp = (string) ($record['fingerprint'] ?? '');

        $resolved = $this->storeLookup->resolveBySno($ownerSno);
        if (!($resolved['ok'] ?? false) || !is_array($resolved['identity'] ?? null)) {
            return ['ok' => false, 'reason' => (string) ($resolved['reason'] ?? 'store_resolve_failed')];
        }
        $identity = $resolved['identity'];
        $fp = (string) ($resolved['fingerprint'] ?? TenantAdminStoreAuthorityLookup::fingerprint($identity));
        if ($fp !== $expectedFp) {
            return ['ok' => false, 'reason' => 'identity_fingerprint_drift'];
        }

        $tenantKey = (string) $identity['tenant_key'];
        $credPrefix = (string) $identity['credential_env_prefix'];
        $bot = $this->botIdentity->resolveByCredentialEnvPrefix($credPrefix, $tenantKey);
        if (($bot['ok'] ?? false) !== true) {
            return ['ok' => false, 'reason' => (string) ($bot['reason'] ?? 'bot_identity_resolve_failed')];
        }

        $merged = [
            'selection_token' => $token,
            'line_channel_id' => (string) $bot['user_id'],
            'tenant_key' => $tenantKey,
            'display_name' => (string) $identity['display_name'],
            'company_name' => (string) $identity['company_name'],
            'sno' => (string) $identity['sno'],
            'storeNo' => (int) $identity['storeNo'],
            'store_uid' => (int) $identity['store_uid'],
            'depID' => (int) $identity['depID'],
            'provider_id_no' => (int) $identity['provider_id_no'],
            'credential_env_prefix' => $credPrefix,
            'gcs_prefix' => (string) $identity['gcs_prefix'],
            'fingerprint' => $fp,
            'bot_identity_fingerprint' => (string) $bot['fingerprint'],
            'bot_display_name' => (string) ($bot['display_name'] ?? ''),
            'bot_basic_id' => (string) ($bot['basic_id'] ?? ''),
            '_identity_source' => 'store_authority',
            '_bot_identity_source' => 'line_bot_info',
        ];

        return ['ok' => true, 'payload' => $merged];
    }

    /**
     * Update LINE OA: client must not submit line_channel_id.
     *
     * @param array<string, mixed> $payload
     * @return array{ok:bool,reason?:string,payload?:array<string,mixed>}
     */
    private function prepareUpdateLinePayload(array $payload): array
    {
        if (array_key_exists('line_channel_id', $payload)) {
            return ['ok' => false, 'reason' => 'client_line_channel_id_forbidden'];
        }
        $tenantKey = trim((string) ($payload['tenant_key'] ?? ''));
        $row = $this->reader->getTenantRow($tenantKey);
        if ($tenantKey === '' || $row === null) {
            return ['ok' => false, 'reason' => 'tenant_not_found'];
        }
        $credPrefix = trim((string) ($row['credential_env_prefix'] ?? ''));
        if ($credPrefix === '') {
            return ['ok' => false, 'reason' => 'missing_credential_env_prefix'];
        }
        $bot = $this->botIdentity->resolveByCredentialEnvPrefix($credPrefix, $tenantKey);
        if (($bot['ok'] ?? false) !== true) {
            return ['ok' => false, 'reason' => (string) ($bot['reason'] ?? 'bot_identity_resolve_failed')];
        }

        $merged = $payload;
        $merged['tenant_key'] = $tenantKey;
        $merged['line_channel_id'] = (string) $bot['user_id'];
        $merged['bot_identity_fingerprint'] = (string) $bot['fingerprint'];
        $merged['bot_display_name'] = (string) ($bot['display_name'] ?? '');
        $merged['bot_basic_id'] = (string) ($bot['basic_id'] ?? '');
        $merged['_bot_identity_source'] = 'line_bot_info';

        return ['ok' => true, 'payload' => $merged];
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function publicDiff(string $operation, array $normalized, string $tenantKey): array
    {
        $diff = ['operation' => $operation];
        $skip = [
            '_identity_source', '_bot_identity_source', 'selection_token',
            'fingerprint', 'bot_identity_fingerprint',
        ];
        foreach ($normalized as $k => $v) {
            if (in_array($k, $skip, true)) {
                continue;
            }
            if ($k === 'sno' && is_string($v)) {
                $diff[$k] = $this->reader->maskSno($v);
                continue;
            }
            if ($k === 'line_channel_id' && is_string($v)) {
                if ($operation === 'update_line_oa') {
                    $row = $this->reader->getTenantRow($tenantKey);
                    $from = is_array($row) ? trim((string) ($row['line_channel_id'] ?? '')) : '';
                    $diff['line_channel_id'] = [
                        'from_masked' => TenantAdminLineBotIdentityResolver::maskUserId($from),
                        'to_masked' => TenantAdminLineBotIdentityResolver::maskUserId($v),
                    ];
                } else {
                    $diff['line_channel_id_masked'] = TenantAdminLineBotIdentityResolver::maskUserId($v);
                }
                continue;
            }
            $diff[$k] = $v;
        }

        return $diff;
    }
}
