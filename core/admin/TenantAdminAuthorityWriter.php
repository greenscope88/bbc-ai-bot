<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantAdminAuthorityReader.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantAdminPayloadValidator.php';

/**
 * Single Writer for Tenant Admin Authority files.
 */
final class TenantAdminAuthorityWriter
{
    /** @var TenantAdminAuthorityReader */
    private $reader;

    public function __construct(TenantAdminAuthorityReader $reader)
    {
        $this->reader = $reader;
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array{ok:bool,reason?:string,changed_fields?:list<string>}
     */
    public function apply(string $operation, array $normalized, string $expectedHash): array
    {
        if ($this->reader->authorityHash() !== $expectedHash) {
            return ['ok' => false, 'reason' => 'precondition_hash_drift'];
        }

        switch ($operation) {
            case 'create_tenant':
                return $this->createTenant($normalized);
            case 'update_line_oa':
                return $this->updateLineOa($normalized);
            case 'enable_bds_upload':
                return $this->enableBds($normalized);
            case 'enter_line_validation':
                return $this->enterValidation($normalized);
            case 'finalize_tenant_production':
                return $this->finalizeProduction($normalized);
            case 'deactivate_tenant_production':
                return $this->deactivateProduction($normalized);
        }

        return ['ok' => false, 'reason' => 'unknown_operation'];
    }

    /**
     * @param array<string, mixed> $n
     * @return array{ok:bool,reason?:string,changed_fields?:list<string>}
     */
    private function createTenant(array $n): array
    {
        $tenantKey = (string) $n['tenant_key'];
        $sno = (string) $n['sno'];
        $tenantPath = $this->reader->tenantRegistryPath();
        $bdsPath = $this->reader->bdsRegistryPath();
        $productPath = $this->reader->productSourcesPath($sno);

        $tenantBefore = $this->readBytes($tenantPath);
        $bdsBefore = $this->readBytes($bdsPath);
        $productDir = dirname($productPath);

        $tenantReg = $this->reader->loadTenantRegistry();
        $bdsReg = $this->reader->loadBdsRegistry();
        if (isset($tenantReg['tenants'][$tenantKey]) || isset($bdsReg['tenants'][$tenantKey])) {
            // idempotent noop when exact bundle already matches
            if ($this->createAlreadyMatches($n)) {
                return ['ok' => true, 'changed_fields' => [], 'idempotent' => true];
            }

            return ['ok' => false, 'reason' => 'tenant_exists_conflict'];
        }

        $tenantReg['tenants'][$tenantKey] = [
            'credential_env_prefix' => (string) $n['credential_env_prefix'],
            'display_name' => (string) $n['display_name'],
            'line_channel_id' => (string) $n['line_channel_id'],
            'sno' => $sno,
            'depID' => (int) $n['depID'],
            'storeNo' => (int) $n['storeNo'],
            'store_uid' => (int) $n['store_uid'],
            'provider_id_no' => (int) $n['provider_id_no'],
            'status' => 'disabled',
            'profile' => [
                'company_name' => (string) $n['company_name'],
                'ai_tone' => '親切',
                'travel_specialties' => '綜合旅遊',
                'price_catalog_json' => '{}',
            ],
            'features' => [
                'tour_prompt' => false,
                'hybrid_search' => false,
                'fixed_formatter' => false,
                'bats_runtime' => false,
                'aiu_authoritative' => false,
                'grounding_authoritative' => false,
            ],
            'gemini_policy' => [
                'tone' => 'travel_assistant',
                'allow_fixed_formatter' => false,
                'allow_gemini_rewrite' => false,
            ],
            'source_policy' => [
                'host_b_enabled' => false,
                'search_client' => 'gateway_php',
            ],
        ];

        $bdsReg['tenants'][$tenantKey] = [
            'store_no' => (int) $n['storeNo'],
            'sno' => $sno,
            'tenant_name' => (string) $n['display_name'],
            'tenant_key' => $tenantKey,
            'industry_code' => 'travel',
            'enabled' => false,
            'private_knowledge_sheet_id' => '',
            'gcs_prefix' => (string) $n['gcs_prefix'],
        ];

        $productDoc = [
            'schema_version' => 1,
            'tenant_sno' => $sno,
            'tenant_key' => $tenantKey,
            'default_category' => 'group_tour',
            'enabled_sources' => [
                ['source_id' => 'bbcshops', 'priority' => 5, 'product_categories' => ['group_tour']],
                ['source_id' => 'grp', 'priority' => 10, 'product_categories' => ['group_tour']],
                ['source_id' => 'tourcenter', 'priority' => 30, 'product_categories' => ['group_tour']],
            ],
            'updated_at' => gmdate('c'),
        ];

        try {
            $this->atomicWritePhp($tenantPath, $tenantReg);
            $this->atomicWritePhp($bdsPath, $bdsReg);
            if (!is_dir($productDir) && !mkdir($productDir, 0775, true) && !is_dir($productDir)) {
                throw new \RuntimeException('product_dir_create_failed');
            }
            $this->atomicWriteJson($productPath, $productDoc);
        } catch (\Throwable $e) {
            $this->restoreBytes($tenantPath, $tenantBefore);
            $this->restoreBytes($bdsPath, $bdsBefore);
            if (is_file($productPath)) {
                @unlink($productPath);
            }

            return ['ok' => false, 'reason' => 'write_failed:' . $e->getMessage()];
        }

        return [
            'ok' => true,
            'changed_fields' => [
                'tenant_registry.tenants.' . $tenantKey,
                'bds_source_registry.tenants.' . $tenantKey,
                'product_sources.' . $sno,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $n
     */
    private function createAlreadyMatches(array $n): bool
    {
        $row = $this->reader->getTenantRow((string) $n['tenant_key']);
        $bds = $this->reader->getBdsRow((string) $n['tenant_key']);
        if ($row === null || $bds === null) {
            return false;
        }
        if (($row['status'] ?? '') !== 'disabled') {
            return false;
        }
        if (($bds['enabled'] ?? true) === true) {
            return false;
        }

        return trim((string) ($row['sno'] ?? '')) === (string) $n['sno']
            && (int) ($row['storeNo'] ?? 0) === (int) $n['storeNo']
            && trim((string) ($row['line_channel_id'] ?? '')) === (string) $n['line_channel_id'];
    }

    /**
     * @param array<string, mixed> $n
     * @return array{ok:bool,reason?:string,changed_fields?:list<string>}
     */
    private function updateLineOa(array $n): array
    {
        $tenantKey = (string) $n['tenant_key'];
        $path = $this->reader->tenantRegistryPath();
        $before = $this->readBytes($path);
        $reg = $this->reader->loadTenantRegistry();
        if (!isset($reg['tenants'][$tenantKey]) || !is_array($reg['tenants'][$tenantKey])) {
            return ['ok' => false, 'reason' => 'tenant_not_found'];
        }
        $changed = [];
        if (isset($n['line_channel_id'])) {
            $reg['tenants'][$tenantKey]['line_channel_id'] = (string) $n['line_channel_id'];
            $changed[] = 'line_channel_id';
        }
        if (isset($n['display_name'])) {
            $reg['tenants'][$tenantKey]['display_name'] = (string) $n['display_name'];
            $changed[] = 'display_name';
        }
        if (isset($n['company_name'])) {
            if (!isset($reg['tenants'][$tenantKey]['profile']) || !is_array($reg['tenants'][$tenantKey]['profile'])) {
                $reg['tenants'][$tenantKey]['profile'] = [];
            }
            $reg['tenants'][$tenantKey]['profile']['company_name'] = (string) $n['company_name'];
            $changed[] = 'profile.company_name';
        }
        try {
            $this->atomicWritePhp($path, $reg);
        } catch (\Throwable $e) {
            $this->restoreBytes($path, $before);

            return ['ok' => false, 'reason' => 'write_failed'];
        }

        return ['ok' => true, 'changed_fields' => $changed];
    }

    /**
     * @param array<string, mixed> $n
     * @return array{ok:bool,reason?:string,changed_fields?:list<string>}
     */
    private function enableBds(array $n): array
    {
        $tenantKey = (string) $n['tenant_key'];
        $path = $this->reader->bdsRegistryPath();
        $before = $this->readBytes($path);
        $reg = $this->reader->loadBdsRegistry();
        if (!isset($reg['tenants'][$tenantKey]) || !is_array($reg['tenants'][$tenantKey])) {
            return ['ok' => false, 'reason' => 'bds_entry_missing'];
        }
        if (($reg['tenants'][$tenantKey]['enabled'] ?? false) === true) {
            return ['ok' => true, 'changed_fields' => [], 'idempotent' => true];
        }
        $reg['tenants'][$tenantKey]['enabled'] = true;
        try {
            $this->atomicWritePhp($path, $reg);
        } catch (\Throwable $e) {
            $this->restoreBytes($path, $before);

            return ['ok' => false, 'reason' => 'write_failed'];
        }

        return ['ok' => true, 'changed_fields' => ['bds.enabled']];
    }

    /**
     * @param array<string, mixed> $n
     * @return array{ok:bool,reason?:string,changed_fields?:list<string>}
     */
    private function enterValidation(array $n): array
    {
        return $this->writeActivationState(
            (string) $n['tenant_key'],
            'staging',
            [
                'bats_runtime' => (bool) $n['bats_runtime'],
                'aiu_authoritative' => (bool) $n['aiu_authoritative'],
                'grounding_authoritative' => (bool) $n['grounding_authoritative'],
            ]
        );
    }

    /**
     * @param array<string, mixed> $n
     * @return array{ok:bool,reason?:string,changed_fields?:list<string>}
     */
    private function finalizeProduction(array $n): array
    {
        $tenantKey = (string) $n['tenant_key'];
        $path = $this->reader->tenantRegistryPath();
        $before = $this->readBytes($path);
        $reg = $this->reader->loadTenantRegistry();
        if (!isset($reg['tenants'][$tenantKey]) || !is_array($reg['tenants'][$tenantKey])) {
            return ['ok' => false, 'reason' => 'tenant_not_found'];
        }
        if (($reg['tenants'][$tenantKey]['status'] ?? '') === 'enabled') {
            return ['ok' => true, 'changed_fields' => [], 'idempotent' => true];
        }
        $reg['tenants'][$tenantKey]['status'] = 'enabled';
        try {
            $this->atomicWritePhp($path, $reg);
        } catch (\Throwable $e) {
            $this->restoreBytes($path, $before);

            return ['ok' => false, 'reason' => 'write_failed'];
        }

        return ['ok' => true, 'changed_fields' => ['status']];
    }

    /**
     * @param array<string, mixed> $n
     * @return array{ok:bool,reason?:string,changed_fields?:list<string>}
     */
    private function deactivateProduction(array $n): array
    {
        return $this->writeActivationState(
            (string) $n['tenant_key'],
            'disabled',
            [
                'bats_runtime' => false,
                'aiu_authoritative' => false,
                'grounding_authoritative' => false,
            ]
        );
    }

    /**
     * @param array<string, bool> $features
     * @return array{ok:bool,reason?:string,changed_fields?:list<string>}
     */
    private function writeActivationState(string $tenantKey, string $status, array $features): array
    {
        $path = $this->reader->tenantRegistryPath();
        $before = $this->readBytes($path);
        $reg = $this->reader->loadTenantRegistry();
        if (!isset($reg['tenants'][$tenantKey]) || !is_array($reg['tenants'][$tenantKey])) {
            return ['ok' => false, 'reason' => 'tenant_not_found'];
        }
        $row = &$reg['tenants'][$tenantKey];
        if (!isset($row['features']) || !is_array($row['features'])) {
            $row['features'] = [];
        }
        $same = (($row['status'] ?? '') === $status);
        foreach ($features as $k => $v) {
            if (($row['features'][$k] ?? null) !== $v) {
                $same = false;
            }
        }
        if ($same) {
            return ['ok' => true, 'changed_fields' => [], 'idempotent' => true];
        }
        $row['status'] = $status;
        foreach ($features as $k => $v) {
            $row['features'][$k] = $v;
        }
        try {
            $this->atomicWritePhp($path, $reg);
        } catch (\Throwable $e) {
            $this->restoreBytes($path, $before);

            return ['ok' => false, 'reason' => 'write_failed'];
        }

        return ['ok' => true, 'changed_fields' => array_merge(['status'], array_keys($features))];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function atomicWritePhp(string $path, array $data): void
    {
        $content = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($data, true) . ";\n";
        $this->atomicWrite($path, $content);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function atomicWriteJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            throw new \RuntimeException('json_encode_failed');
        }
        $this->atomicWrite($path, $json . "\n");
    }

    private function atomicWrite(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('dir_missing');
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(8));
        if (file_put_contents($tmp, $content) === false) {
            throw new \RuntimeException('tmp_write_failed');
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('rename_failed');
        }
    }

    private function readBytes(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);

        return is_string($raw) ? $raw : null;
    }

    private function restoreBytes(string $path, ?string $bytes): void
    {
        if ($bytes === null) {
            return;
        }
        @file_put_contents($path, $bytes);
    }
}
