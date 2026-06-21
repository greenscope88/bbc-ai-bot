<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantPrivateKnowledgeGcsPathConfig.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantPrivateKnowledgeProviderInterface.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsGcsUploader.php';

/**
 * GCS read provider for company_profile knowledge (Phase 9-C-2B-2A).
 */
final class GcsTenantPrivateKnowledgeProvider implements TenantPrivateKnowledgeProviderInterface
{
    /** @var string */
    private $tenantSno;

    /** @var BdsGcsUploader */
    private $uploader;

    public function __construct(string $tenantSno, ?BdsGcsUploader $uploader = null)
    {
        $tenantSno = trim($tenantSno);
        if ($tenantSno === '') {
            throw new InvalidArgumentException('tenant_sno is required.');
        }

        $this->tenantSno = $tenantSno;
        $this->uploader = $uploader ?? new BdsGcsUploader();
    }

    public function getTenantSno(): string
    {
        return $this->tenantSno;
    }

    public function fetchCompanyProfileDocument(): array
    {
        return $this->fetchDocument(TenantPrivateKnowledgeGcsPathConfig::COMPANY_PROFILE_FILE);
    }

    public function fetchServiceQaDocument(): array
    {
        return $this->fetchDocument(TenantPrivateKnowledgeGcsPathConfig::SERVICE_QA_FILE);
    }

    public function fetchSpecialPricesDocument(): array
    {
        return $this->fetchDocument(TenantPrivateKnowledgeGcsPathConfig::SPECIAL_PRICES_FILE);
    }

    public function fetchServiceItemsDocument(): array
    {
        return $this->fetchDocument(TenantPrivateKnowledgeGcsPathConfig::SERVICE_ITEMS_FILE);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDocument(string $filename): array
    {
        try {
            $objectPath = TenantPrivateKnowledgeGcsPathConfig::resolveObjectPath($this->tenantSno, $filename);
            $response = $this->uploader->getObject($objectPath);
            if (($response['status'] ?? 0) !== 200) {
                return [];
            }

            $decoded = json_decode((string) ($response['body'] ?? ''), true);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
