<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'PublisherContractValidator.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ResultToPublisherMapperException.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SourceSearchResultValidator.php';

/**
 * Maps SourceSearchResultContract documents to PublisherContract (Phase 9-B-17).
 *
 * Contract → Contract only. Does not emit LINE, Gemini, Telegram, or HTML payloads.
 */
final class ResultToPublisherMapper
{
    public const DEFAULT_ACTION_TYPE = 'open_url';

    public const DEFAULT_ACTION_LABEL = '查看內容';

    private SourceSearchResultValidator $sourceValidator;

    private PublisherContractValidator $publisherValidator;

    public function __construct(
        ?SourceSearchResultValidator $sourceValidator = null,
        ?PublisherContractValidator $publisherValidator = null
    ) {
        $this->sourceValidator = $sourceValidator ?? new SourceSearchResultValidator();
        $this->publisherValidator = $publisherValidator ?? new PublisherContractValidator();
    }

    /**
     * @param array<string, mixed> $sourceResult
     * @return array<string, mixed> normalized PublisherContract document
     */
    public function map(array $sourceResult): array
    {
        $searchUrl = isset($sourceResult['search_url']) ? trim((string) $sourceResult['search_url']) : '';
        if ($searchUrl === '') {
            throw new ResultToPublisherMapperException(
                ResultToPublisherMapperException::URL_REQUIRED,
                'search_url is required for result to publisher mapping'
            );
        }

        try {
            $normalizedSource = $this->sourceValidator->validate($sourceResult);
        } catch (SourceSearchResultContractException $e) {
            throw new ResultToPublisherMapperException(
                ResultToPublisherMapperException::INVALID_SOURCE,
                'Invalid source search result: ' . $e->getMessage(),
                $e->getViolations()
            );
        }

        $publisherDocument = $this->buildPublisherDocument($normalizedSource, $searchUrl);

        try {
            return $this->publisherValidator->validate($publisherDocument);
        } catch (PublisherContractException $e) {
            throw new ResultToPublisherMapperException(
                ResultToPublisherMapperException::INVALID_PUBLISHER,
                'Invalid publisher document after mapping: ' . $e->getMessage(),
                $e->getViolations()
            );
        }
    }

    /**
     * @param array<string, mixed> $sourceResult
     * @return array<string, mixed>
     */
    private function buildPublisherDocument(array $sourceResult, string $searchUrl): array
    {
        $secondaryUrls = [];
        if (isset($sourceResult['detail_url']) && is_string($sourceResult['detail_url'])) {
            $detailUrl = trim($sourceResult['detail_url']);
            if ($detailUrl !== '' && $detailUrl !== $searchUrl) {
                $secondaryUrls[] = $detailUrl;
            }
        }

        $publisher = [
            'tenant_instance' => $sourceResult['tenant_instance'],
            'source_platform' => $sourceResult['source_platform'],
            'product_category' => $sourceResult['product_category'],
            'title' => $sourceResult['title'],
            'primary_url' => $searchUrl,
            'secondary_urls' => $secondaryUrls,
            'actions' => [
                [
                    'type' => self::DEFAULT_ACTION_TYPE,
                    'label' => self::DEFAULT_ACTION_LABEL,
                    'url' => $searchUrl,
                ],
            ],
            'metadata' => isset($sourceResult['metadata']) && is_array($sourceResult['metadata'])
                ? $sourceResult['metadata']
                : [],
        ];

        if (array_key_exists('summary', $sourceResult)) {
            $publisher['summary'] = $sourceResult['summary'];
        }

        if (isset($sourceResult['schema_version'])) {
            $publisher['schema_version'] = (int) $sourceResult['schema_version'];
        }

        return $publisher;
    }
}
