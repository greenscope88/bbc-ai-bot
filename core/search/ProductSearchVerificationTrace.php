<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchCondition.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'SourceQueryMapper.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'TravelBMultiSourceLinkBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchUrlBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';

/**
 * Product Search Verification — layer-by-layer trace for manual URL parity checks.
 */
final class ProductSearchVerificationTrace
{
    /**
     * @param array<string, mixed> $searchPolicyMeta
     * @return array<string, mixed>
     */
    public static function build(
        BatsSearchIntent $intent,
        SearchCondition $condition,
        string $tenantSno,
        int $resultCount,
        array $searchPolicyMeta = [],
        ?string $finalSearchUrl = null,
        ?array $productUnderstandingTrace = null
    ): array {
        $document = TravelBMultiSourceLinkBuilder::hybridConditionToSearchDocument($condition);
        $sourceKeywordQuery = isset($document['source_keyword_query'])
            ? trim((string) $document['source_keyword_query'])
            : SourceQueryMapper::buildSourceKeywordQueryFromSearchCondition($condition);

        $url = $finalSearchUrl;
        if ($url === null || trim($url) === '') {
            $url = (new SearchUrlBuilder(false))->build($tenantSno, $condition);
        }

        $apiParams = (new ApiQueryMapper())->toClientParams($condition, ['include_sno' => $tenantSno]);

        $understandingTrace = is_array($productUnderstandingTrace) ? $productUnderstandingTrace : [
            'understanding_source' => isset($searchPolicyMeta['understanding_source']) ? (string) $searchPolicyMeta['understanding_source'] : null,
            'fallback_reason' => isset($searchPolicyMeta['fallback_reason']) ? $searchPolicyMeta['fallback_reason'] : null,
        ];

        return [
            'understanding_source' => $understandingTrace['understanding_source'] ?? null,
            'fallback_reason' => $understandingTrace['fallback_reason'] ?? null,
            'runtime_entity' => $intent->toArray(),
            'search_condition' => $condition->toArray(),
            'source_keyword_query' => $sourceKeywordQuery,
            'api_query_params' => $apiParams,
            'api_keyword' => isset($apiParams['keyword']) ? (string) $apiParams['keyword'] : '',
            'final_search_url' => $url,
            'result_count' => $resultCount,
            'search_policy' => $searchPolicyMeta,
            'verification_layers' => [
                'aiu_runtime_entity' => $intent->toArray(),
                'search_condition' => $condition->toArray(),
                'source_keyword_query' => $sourceKeywordQuery,
                'api_keyword' => isset($apiParams['keyword']) ? (string) $apiParams['keyword'] : '',
                'final_search_url' => $url,
                'product_search_result_count' => $resultCount,
            ],
        ];
    }
}