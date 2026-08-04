<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantAdminStoreAuthorityLookup.php';

/**
 * Server-side Create selection token store (session-backed in admin.php; array-backed in tests).
 * Never treats client Identity as authority — only token → server record.
 */
final class TenantAdminCreateSelectionStore
{
    /** @var array<string, array<string, mixed>> */
    private $store;

    /**
     * @param array<string, array<string, mixed>> $backend
     */
    public function __construct(array &$backend)
    {
        $this->store = &$backend;
    }

    /**
     * @param array<string, mixed> $identity
     * @return array{ok:bool,selection_token?:string,fingerprint?:string,identity?:array<string,mixed>,reason?:string}
     */
    public function issue(array $identity, string $fingerprint): array
    {
        if ($fingerprint === '' || ($identity['sno'] ?? '') === '') {
            return ['ok' => false, 'reason' => 'selection_incomplete'];
        }
        $token = bin2hex(random_bytes(16));
        $this->store[$token] = [
            'selection_token' => $token,
            'sno' => (string) $identity['sno'],
            'fingerprint' => $fingerprint,
            'identity' => $identity,
            'created_at' => time(),
            'expires_at' => time() + TenantAdminStoreAuthorityLookup::SELECTION_TTL_SECONDS,
        ];

        return [
            'ok' => true,
            'selection_token' => $token,
            'fingerprint' => $fingerprint,
            'identity' => $identity,
        ];
    }

    /**
     * @return array{ok:bool,reason?:string,record?:array<string,mixed>}
     */
    public function get(string $token): array
    {
        $token = trim($token);
        if ($token === '' || !isset($this->store[$token]) || !is_array($this->store[$token])) {
            return ['ok' => false, 'reason' => 'selection_token_invalid'];
        }
        $record = $this->store[$token];
        if ((int) ($record['expires_at'] ?? 0) < time()) {
            unset($this->store[$token]);

            return ['ok' => false, 'reason' => 'selection_token_expired'];
        }

        return ['ok' => true, 'record' => $record];
    }
}
