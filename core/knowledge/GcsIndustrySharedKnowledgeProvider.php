<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'IndustrySharedKnowledgeGcsPathConfig.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'IndustrySharedKnowledgeProviderInterface.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsGcsUploader.php';

/**
 * GCS read provider for industry shared FAQ knowledge (Phase 9-C-2D).
 */
final class GcsIndustrySharedKnowledgeProvider implements IndustrySharedKnowledgeProviderInterface
{
    /** @var string */
    private $industryCode;

    /** @var BdsGcsUploader */
    private $uploader;

    public function __construct(string $industryCode, ?BdsGcsUploader $uploader = null)
    {
        $industryCode = trim($industryCode);
        if ($industryCode === '') {
            throw new InvalidArgumentException('industry_code is required.');
        }

        $this->industryCode = $industryCode;
        $this->uploader = $uploader ?? new BdsGcsUploader();
    }

    public function getIndustryCode(): string
    {
        return $this->industryCode;
    }

    public function fetchFaqDocument(): array
    {
        try {
            $objectPath = IndustrySharedKnowledgeGcsPathConfig::resolveObjectPath($this->industryCode);
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
