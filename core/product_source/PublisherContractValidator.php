<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductCategoryContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'PublisherContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'PublisherContractException.php';

/**
 * Validates BATS publisher intermediary documents (Phase 9-B-16).
 */
final class PublisherContractValidator
{
    /**
     * @param array<string, mixed> $document
     * @throws PublisherContractException
     */
    public function validate(array $document): array
    {
        $violations = $this->collectViolations($document);
        if ($violations !== []) {
            $this->throwFromViolations($violations);
        }

        return PublisherContract::normalize($document);
    }

    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    public function collectViolations(array $document): array
    {
        $violations = [];

        if (isset($document['schema_version'])) {
            $version = (int) $document['schema_version'];
            if (!in_array($version, PublisherContract::SUPPORTED_SCHEMA_VERSIONS, true)) {
                $violations[] = 'unsupported schema_version';
            }
        }

        $tenantInstance = isset($document['tenant_instance']) ? trim((string) $document['tenant_instance']) : '';
        if ($tenantInstance === '') {
            $violations[] = PublisherContractException::TENANT_INSTANCE_REQUIRED;
        }

        $sourcePlatform = isset($document['source_platform']) ? trim((string) $document['source_platform']) : '';
        if ($sourcePlatform === '') {
            $violations[] = PublisherContractException::SOURCE_PLATFORM_REQUIRED;
        }

        $productCategory = isset($document['product_category']) ? trim((string) $document['product_category']) : '';
        if ($productCategory === '') {
            $violations[] = PublisherContractException::PRODUCT_CATEGORY_REQUIRED;
        } elseif (!ProductCategoryContract::isValidProductCategory($productCategory)) {
            $violations[] = PublisherContractException::INVALID_PRODUCT_CATEGORY;
        }

        $title = isset($document['title']) ? trim((string) $document['title']) : '';
        if ($title === '') {
            $violations[] = PublisherContractException::TITLE_REQUIRED;
        }

        $primaryUrl = isset($document['primary_url']) ? trim((string) $document['primary_url']) : '';
        if ($primaryUrl === '') {
            $violations[] = PublisherContractException::PRIMARY_URL_REQUIRED;
        } elseif (!$this->isValidHttpUrl($primaryUrl)) {
            $violations[] = PublisherContractException::INVALID_URL;
        }

        if (isset($document['secondary_urls'])) {
            if (!is_array($document['secondary_urls'])) {
                $violations[] = PublisherContractException::INVALID_SECONDARY_URLS;
            } else {
                foreach ($document['secondary_urls'] as $url) {
                    $trimmed = is_string($url) ? trim($url) : '';
                    if ($trimmed !== '' && !$this->isValidHttpUrl($trimmed)) {
                        $violations[] = PublisherContractException::INVALID_URL;
                        break;
                    }
                }
            }
        }

        if (isset($document['actions'])) {
            if (!is_array($document['actions'])) {
                $violations[] = PublisherContractException::INVALID_ACTIONS;
            } else {
                $violations = array_merge($violations, $this->collectActionViolations($document['actions']));
            }
        }

        if (isset($document['metadata']) && $document['metadata'] !== null && !is_array($document['metadata'])) {
            $violations[] = PublisherContractException::INVALID_METADATA;
        }

        return $violations;
    }

    /**
     * @param list<mixed> $actions
     * @return list<string>
     */
    private function collectActionViolations(array $actions): array
    {
        $violations = [];

        foreach ($actions as $action) {
            if (!is_array($action)) {
                $violations[] = PublisherContractException::INVALID_ACTIONS;
                continue;
            }

            $url = isset($action['url']) ? trim((string) $action['url']) : '';
            if ($url === '') {
                $violations[] = PublisherContractException::ACTION_URL_REQUIRED;
                continue;
            }

            if (!$this->isValidHttpUrl($url)) {
                $violations[] = PublisherContractException::INVALID_URL;
            }
        }

        return $violations;
    }

    private function isValidHttpUrl(string $url): bool
    {
        if (stripos($url, 'http://') !== 0 && stripos($url, 'https://') !== 0) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * @param list<string> $violations
     */
    private function throwFromViolations(array $violations): void
    {
        $priority = [
            PublisherContractException::TENANT_INSTANCE_REQUIRED,
            PublisherContractException::SOURCE_PLATFORM_REQUIRED,
            PublisherContractException::PRODUCT_CATEGORY_REQUIRED,
            PublisherContractException::TITLE_REQUIRED,
            PublisherContractException::PRIMARY_URL_REQUIRED,
            PublisherContractException::ACTION_URL_REQUIRED,
            PublisherContractException::INVALID_PRODUCT_CATEGORY,
            PublisherContractException::INVALID_METADATA,
            PublisherContractException::INVALID_ACTIONS,
            PublisherContractException::INVALID_SECONDARY_URLS,
            PublisherContractException::INVALID_URL,
        ];

        foreach ($priority as $code) {
            if (in_array($code, $violations, true)) {
                throw new PublisherContractException(
                    $code,
                    'Publisher contract invalid: ' . $code,
                    $violations
                );
            }
        }

        throw new PublisherContractException(
            PublisherContractException::INVALID_INPUT,
            'Publisher contract invalid (' . count($violations) . ' issue(s)).',
            $violations
        );
    }
}
