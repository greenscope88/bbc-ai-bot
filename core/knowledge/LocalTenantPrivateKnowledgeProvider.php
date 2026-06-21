<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantPrivateKnowledgeGcsPathConfig.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantPrivateKnowledgeProviderInterface.php';

/**
 * Local fixture provider for company_profile knowledge runtime tests.
 */
final class LocalTenantPrivateKnowledgeProvider implements TenantPrivateKnowledgeProviderInterface
{
    /** @var string */
    private $tenantSno;

    /** @var string */
    private $fixtureRoot;

    public function __construct(string $tenantSno, string $fixtureRoot)
    {
        $tenantSno = trim($tenantSno);
        $fixtureRoot = trim($fixtureRoot);
        if ($tenantSno === '' || $fixtureRoot === '') {
            throw new InvalidArgumentException('tenant_sno and fixture_root are required.');
        }
        if (!is_dir($fixtureRoot)) {
            throw new InvalidArgumentException('fixture_root does not exist: ' . $fixtureRoot);
        }

        $this->tenantSno = $tenantSno;
        $this->fixtureRoot = rtrim($fixtureRoot, "/\\");
    }

    public function getTenantSno(): string
    {
        return $this->tenantSno;
    }

    public function fetchCompanyProfileDocument(): array
    {
        return $this->readJsonFile(TenantPrivateKnowledgeGcsPathConfig::COMPANY_PROFILE_FILE);
    }

    public function fetchServiceQaDocument(): array
    {
        return $this->readJsonFile(TenantPrivateKnowledgeGcsPathConfig::SERVICE_QA_FILE);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $filename): array
    {
        $path = $this->fixtureRoot . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
