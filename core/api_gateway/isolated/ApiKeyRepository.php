<?php
declare(strict_types=1);

/**
 * Phase 4 Stage 2 isolated repository.
 * Mock api_gateway_keys rows only: SHA-256 hex of full key (no plaintext at rest).
 * No SQL, no Host B, not wired to production entry.
 */
final class ApiKeyRepository
{
    private const PREFIX_LEN = 16;

    /** @var list<array<string, mixed>> */
    private array $mockKeys;

    /**
     * @param list<array<string, mixed>>|null $seedRows
     */
    public function __construct(?array $seedRows = null)
    {
        $this->mockKeys = $seedRows ?? $this->defaultSeedRows();
    }

    /**
     * Find a mock key row where the incoming plaintext matches prefix + SHA-256 hash.
     *
     * @return array<string, mixed>|null
     */
    public function findByIncomingPlainKey(string $incomingPlain): ?array
    {
        $plain = trim($incomingPlain);
        if ($plain === '' || strlen($plain) < self::PREFIX_LEN) {
            return null;
        }

        $prefix = substr($plain, 0, self::PREFIX_LEN);
        $hashHex = hash('sha256', $plain);

        foreach ($this->mockKeys as $row) {
            $rowPrefix = (string) ($row['key_prefix'] ?? '');
            if ($rowPrefix === '' || $rowPrefix !== $prefix) {
                continue;
            }
            $stored = (string) ($row['key_hash'] ?? '');
            if ($stored !== '' && hash_equals($stored, $hashHex)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * True if any row shares the 16-char prefix but no row matches the full key hash.
     */
    public function hasPrefixButHashMismatch(string $incomingPlain): bool
    {
        $plain = trim($incomingPlain);
        if ($plain === '' || strlen($plain) < self::PREFIX_LEN) {
            return false;
        }

        $prefix = substr($plain, 0, self::PREFIX_LEN);
        $hashHex = hash('sha256', $plain);
        $prefixHit = false;

        foreach ($this->mockKeys as $row) {
            $rowPrefix = (string) ($row['key_prefix'] ?? '');
            if ($rowPrefix === $prefix) {
                $prefixHit = true;
                $stored = (string) ($row['key_hash'] ?? '');
                if ($stored !== '' && hash_equals($stored, $hashHex)) {
                    return false;
                }
            }
        }

        return $prefixHit;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function defaultSeedRows(): array
    {
        // key_hash = hash('sha256', <full key string>); key_prefix = substr(<full>, 0, 16)
        return [
            [
                'id' => 101,
                'sno' => 'e1fd133c7e8e45a1',
                'key_name' => 'Primary Isolated',
                'key_prefix' => 'bbc_test_e1fdval',
                'key_hash' => '1d049d09a78b15afa040c71f7527dbf4d8e929eb5c11393f626485f87c3d8690',
                'key_status' => 'active',
                'allowed_services' => ['tour.search', 'order.query'],
                'allowed_ips' => [],
                'expires_at' => null,
            ],
            [
                'id' => 102,
                'sno' => 'other000tenant02',
                'key_name' => 'Wrong-tenant key',
                'key_prefix' => 'bbc_test_wrongsn',
                'key_hash' => 'ea25e74a09e43f85ac2016a3ed0ad331afbe3103750ba2c52a64d017ef91a74a',
                'key_status' => 'active',
                'allowed_services' => ['tour.search'],
                'allowed_ips' => [],
                'expires_at' => null,
            ],
            [
                'id' => 103,
                'sno' => 'e1fd133c7e8e45a1',
                'key_name' => 'Expired key',
                'key_prefix' => 'bbc_test_expired',
                'key_hash' => 'adff73068c463ad94532e0685029f1fcae8e8454bd69135ed835c61bc92fac0b',
                'key_status' => 'active',
                'allowed_services' => ['tour.search'],
                'allowed_ips' => [],
                'expires_at' => '2000-01-01T00:00:00Z',
            ],
            [
                'id' => 104,
                'sno' => 'e1fd133c7e8e45a1',
                'key_name' => 'Disabled key',
                'key_prefix' => 'bbc_test_disabld',
                'key_hash' => '070cd49693c33c41944ff38f7487eb2f8a944327130473811f22b7c61d5771f5',
                'key_status' => 'disabled',
                'allowed_services' => ['tour.search'],
                'allowed_ips' => [],
                'expires_at' => null,
            ],
            [
                'id' => 105,
                'sno' => 'e1fd133c7e8e45a1',
                'key_name' => 'Revoked key',
                'key_prefix' => 'bbc_test_revoked',
                'key_hash' => '8926226902458f140181c109a16dff3cac6cc7727c1c98f206d1575f9c10586b',
                'key_status' => 'revoked',
                'allowed_services' => ['tour.search'],
                'allowed_ips' => [],
                'expires_at' => null,
            ],
            [
                'id' => 106,
                'sno' => 'e1fd133c7e8e45a1',
                'key_name' => 'Service-restricted',
                'key_prefix' => 'bbc_test_svconly',
                'key_hash' => '03855bdb9ab45dea8b643bcd9b13930f0b9b3cf14729af0642e5a393e9de774d',
                'key_status' => 'active',
                'allowed_services' => ['order.query'],
                'allowed_ips' => [],
                'expires_at' => null,
            ],
            [
                'id' => 107,
                'sno' => 'e1fd133c7e8e45a1',
                'key_name' => 'IP-restricted',
                'key_prefix' => 'bbc_test_iponly0',
                'key_hash' => '01c56c6410d43d4ef7d7177fd3d8a1124b2853b62717fb0d675a8797d7443725',
                'key_status' => 'active',
                'allowed_services' => ['tour.search'],
                'allowed_ips' => ['203.0.113.10'],
                'expires_at' => null,
            ],
            [
                'id' => 108,
                'sno' => 'e1fd133c7e8e45a1',
                'key_name' => 'Status-expired',
                'key_prefix' => 'bbc_test_statuse',
                'key_hash' => '09e4a9fd70c2deafb5a0c5a2fdfede8d1b92145a46141c0ee7a0616c51d43d29',
                'key_status' => 'expired',
                'allowed_services' => ['tour.search'],
                'allowed_ips' => [],
                'expires_at' => null,
            ],
        ];
    }
}
