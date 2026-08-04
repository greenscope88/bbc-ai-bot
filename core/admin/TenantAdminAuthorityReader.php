<?php
declare(strict_types=1);

/**
 * Read-only Tenant Authority access for Admin forms.
 */
final class TenantAdminAuthorityReader
{
    /** @var string */
    private $tenantRegistryPath;
    /** @var string */
    private $bdsRegistryPath;
    /** @var string */
    private $productSourcesRoot;

    public function __construct(
        ?string $tenantRegistryPath = null,
        ?string $bdsRegistryPath = null,
        ?string $productSourcesRoot = null
    ) {
        $root = dirname(__DIR__, 2);
        $this->tenantRegistryPath = $tenantRegistryPath ?? ($root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'tenant_registry.php');
        $this->bdsRegistryPath = $bdsRegistryPath ?? ($root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bds_source_registry.php');
        $this->productSourcesRoot = $productSourcesRoot ?? ($root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'tenants');
    }

    public function tenantRegistryPath(): string
    {
        return $this->tenantRegistryPath;
    }

    public function bdsRegistryPath(): string
    {
        return $this->bdsRegistryPath;
    }

    public function productSourcesRoot(): string
    {
        return $this->productSourcesRoot;
    }

    /**
     * @return array<string, mixed>
     */
    public function loadTenantRegistry(): array
    {
        return $this->loadPhpArray($this->tenantRegistryPath);
    }

    /**
     * @return array<string, mixed>
     */
    public function loadBdsRegistry(): array
    {
        return $this->loadPhpArray($this->bdsRegistryPath);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTenantRow(string $tenantKey): ?array
    {
        $reg = $this->loadTenantRegistry();
        $tenants = $reg['tenants'] ?? null;
        if (!is_array($tenants) || !isset($tenants[$tenantKey]) || !is_array($tenants[$tenantKey])) {
            return null;
        }
        $row = $tenants[$tenantKey];
        $row['tenant_key'] = $tenantKey;

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBdsRow(string $tenantKey): ?array
    {
        $reg = $this->loadBdsRegistry();
        $tenants = $reg['tenants'] ?? null;
        if (!is_array($tenants) || !isset($tenants[$tenantKey]) || !is_array($tenants[$tenantKey])) {
            return null;
        }
        $row = $tenants[$tenantKey];
        $row['tenant_key'] = $tenantKey;

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchByName(string $nameQuery): array
    {
        $q = mb_strtolower(trim($nameQuery), 'UTF-8');
        if ($q === '') {
            return [];
        }
        $reg = $this->loadTenantRegistry();
        $tenants = is_array($reg['tenants'] ?? null) ? $reg['tenants'] : [];
        $out = [];
        foreach ($tenants as $tenantKey => $row) {
            if (!is_string($tenantKey) || !is_array($row)) {
                continue;
            }
            $display = trim((string) ($row['display_name'] ?? ''));
            $company = trim((string) (($row['profile']['company_name'] ?? '')));
            $hay = mb_strtolower($display . ' ' . $company . ' ' . $tenantKey, 'UTF-8');
            if (mb_strpos($hay, $q, 0, 'UTF-8') === false) {
                continue;
            }
            $out[] = $this->summarizeTenant($tenantKey, $row);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function summarizeTenant(string $tenantKey, array $row): array
    {
        $sno = trim((string) ($row['sno'] ?? ''));
        $bds = $this->getBdsRow($tenantKey);
        $productPath = $this->productSourcesPath($sno);
        $features = is_array($row['features'] ?? null) ? $row['features'] : [];

        return [
            'tenant_key' => $tenantKey,
            'display_name' => (string) ($row['display_name'] ?? ''),
            'sno_masked' => $this->maskSno($sno),
            'sno' => $sno,
            'status' => (string) ($row['status'] ?? 'disabled'),
            'line_channel_id' => (string) ($row['line_channel_id'] ?? ''),
            'storeNo' => (int) ($row['storeNo'] ?? 0),
            'bds_enabled' => is_array($bds) ? (($bds['enabled'] ?? false) === true) : false,
            'product_set_present' => is_file($productPath),
            'features' => [
                'bats_runtime' => ($features['bats_runtime'] ?? false) === true,
                'aiu_authoritative' => ($features['aiu_authoritative'] ?? false) === true,
                'grounding_authoritative' => ($features['grounding_authoritative'] ?? false) === true,
            ],
        ];
    }

    public function productSourcesPath(string $sno): string
    {
        return rtrim($this->productSourcesRoot, '\\/') . DIRECTORY_SEPARATOR . trim($sno) . DIRECTORY_SEPARATOR . 'product_sources.json';
    }

    public function authorityHash(): string
    {
        $payload = [
            'tenant' => $this->fileFingerprint($this->tenantRegistryPath),
            'bds' => $this->fileFingerprint($this->bdsRegistryPath),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function fileFingerprint(string $path): string
    {
        if (!is_file($path)) {
            return 'missing';
        }
        $raw = file_get_contents($path);

        return is_string($raw) ? hash('sha256', $raw) : 'unreadable';
    }

    public function maskSno(string $sno): string
    {
        $sno = trim($sno);
        $len = strlen($sno);
        if ($len <= 8) {
            return str_repeat('*', max(0, $len));
        }

        return substr($sno, 0, 4) . str_repeat('*', $len - 8) . substr($sno, -4);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPhpArray(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Authority file missing: ' . $path);
        }
        clearstatcache(true, $path);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        // Load via a unique temp require path so mutable Authority files are never
        // served from a stale same-path include/opcache hit within one request.
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('Authority file unreadable: ' . $path);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'bbc_auth_');
        if ($tmp === false) {
            throw new \RuntimeException('Authority temp load failed');
        }
        $tmpPhp = $tmp . '.php';
        @unlink($tmp);
        if (file_put_contents($tmpPhp, $raw) === false) {
            throw new \RuntimeException('Authority temp write failed');
        }
        try {
            /** @var mixed $loaded */
            $loaded = require $tmpPhp;
        } finally {
            @unlink($tmpPhp);
        }
        if (!is_array($loaded)) {
            throw new \RuntimeException('Authority file invalid: ' . $path);
        }

        return $loaded;
    }
}
