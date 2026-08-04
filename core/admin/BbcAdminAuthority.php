<?php
declare(strict_types=1);

/**
 * Independent Admin Permission Authority.
 * Identity must come from server-side Session storeNo → encryptStr → wire sno.
 */
final class BbcAdminAuthority
{
    /** @var array<string, mixed> */
    private $config;

    public function __construct(?string $configPath = null)
    {
        $path = $configPath ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bbc_admin_authority.php';
        if (!is_file($path)) {
            throw new \RuntimeException('BBC admin authority config missing.');
        }
        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new \RuntimeException('BBC admin authority config invalid.');
        }
        $this->config = $loaded;
    }

    public function isEnabled(): bool
    {
        return ($this->config['enabled'] ?? false) === true;
    }

    /**
     * @return list<string>
     */
    public function ownerWireSnos(): array
    {
        $raw = $this->config['owner_wire_snos'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $sno) {
            $sno = trim((string) $sno);
            if ($sno !== '') {
                $out[] = $sno;
            }
        }

        return array_values(array_unique($out));
    }

    public function authorizeWireSno(string $wireSno): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $wireSno = trim($wireSno);
        if ($wireSno === '') {
            return false;
        }

        return in_array($wireSno, $this->ownerWireSnos(), true);
    }

    /**
     * @param callable(string,string):string|null $encryptFn encryptStr(storeNo, dataKey)
     */
    public function authorizeFromStoreNo(int $storeNo, string $dataKey, ?callable $encryptFn = null): array
    {
        if ($storeNo <= 0 || trim($dataKey) === '') {
            return ['ok' => false, 'wire_sno' => '', 'reason' => 'incomplete_identity'];
        }
        $fn = $encryptFn ?? 'encryptStr';
        if (!is_callable($fn)) {
            return ['ok' => false, 'wire_sno' => '', 'reason' => 'encrypt_unavailable'];
        }
        $wire = trim((string) $fn((string) $storeNo, $dataKey));
        if ($wire === '') {
            return ['ok' => false, 'wire_sno' => '', 'reason' => 'wire_sno_empty'];
        }
        if (!$this->authorizeWireSno($wire)) {
            return ['ok' => false, 'wire_sno' => $wire, 'reason' => 'not_admin'];
        }

        return ['ok' => true, 'wire_sno' => $wire, 'reason' => 'authorized'];
    }

    public function maskWireSno(string $wireSno): string
    {
        $wireSno = trim($wireSno);
        $len = strlen($wireSno);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }

        return substr($wireSno, 0, 4) . str_repeat('*', $len - 8) . substr($wireSno, -4);
    }
}
