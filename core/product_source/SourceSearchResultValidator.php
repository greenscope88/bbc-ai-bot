<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductCategoryContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SourceSearchResultContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SourceSearchResultContractException.php';

/**
 * Validates BATS unified source search result documents (Phase 9-B-15).
 */
final class SourceSearchResultValidator
{
    /**
     * @param array<string, mixed> $document
     * @throws SourceSearchResultContractException
     */
    public function validate(array $document): array
    {
        $violations = $this->collectViolations($document);
        if ($violations !== []) {
            $this->throwFromViolations($violations);
        }

        return SourceSearchResultContract::normalize($document);
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
            if (!in_array($version, SourceSearchResultContract::SUPPORTED_SCHEMA_VERSIONS, true)) {
                $violations[] = 'unsupported schema_version';
            }
        }

        $sourcePlatform = isset($document['source_platform']) ? trim((string) $document['source_platform']) : '';
        if ($sourcePlatform === '') {
            $violations[] = SourceSearchResultContractException::SOURCE_PLATFORM_REQUIRED;
        }

        $tenantInstance = isset($document['tenant_instance']) ? trim((string) $document['tenant_instance']) : '';
        if ($tenantInstance === '') {
            $violations[] = SourceSearchResultContractException::TENANT_INSTANCE_REQUIRED;
        }

        $productCategory = isset($document['product_category']) ? trim((string) $document['product_category']) : '';
        if ($productCategory === '') {
            $violations[] = SourceSearchResultContractException::PRODUCT_CATEGORY_REQUIRED;
        } elseif (!ProductCategoryContract::isValidProductCategory($productCategory)) {
            $violations[] = SourceSearchResultContractException::INVALID_PRODUCT_CATEGORY;
        }

        $title = isset($document['title']) ? trim((string) $document['title']) : '';
        if ($title === '') {
            $violations[] = SourceSearchResultContractException::TITLE_REQUIRED;
        }

        $searchUrl = isset($document['search_url']) ? trim((string) $document['search_url']) : '';
        if ($searchUrl === '') {
            $violations[] = SourceSearchResultContractException::URL_REQUIRED;
        } elseif (!$this->isValidHttpUrl($searchUrl)) {
            $violations[] = SourceSearchResultContractException::INVALID_URL;
        }

        if (isset($document['detail_url'])) {
            $detailUrl = trim((string) $document['detail_url']);
            if ($detailUrl !== '' && !$this->isValidHttpUrl($detailUrl)) {
                $violations[] = SourceSearchResultContractException::INVALID_URL;
            }
        }

        if (isset($document['metadata']) && $document['metadata'] !== null && !is_array($document['metadata'])) {
            $violations[] = SourceSearchResultContractException::INVALID_METADATA;
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
            SourceSearchResultContractException::SOURCE_PLATFORM_REQUIRED,
            SourceSearchResultContractException::TENANT_INSTANCE_REQUIRED,
            SourceSearchResultContractException::PRODUCT_CATEGORY_REQUIRED,
            SourceSearchResultContractException::TITLE_REQUIRED,
            SourceSearchResultContractException::URL_REQUIRED,
            SourceSearchResultContractException::INVALID_PRODUCT_CATEGORY,
            SourceSearchResultContractException::INVALID_METADATA,
            SourceSearchResultContractException::INVALID_URL,
        ];

        foreach ($priority as $code) {
            if (in_array($code, $violations, true)) {
                throw new SourceSearchResultContractException(
                    $code,
                    'Source search result contract invalid: ' . $code,
                    $violations
                );
            }
        }

        throw new SourceSearchResultContractException(
            SourceSearchResultContractException::INVALID_INPUT,
            'Source search result contract invalid (' . count($violations) . ' issue(s)).',
            $violations
        );
    }
}
