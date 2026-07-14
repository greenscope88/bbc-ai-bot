<?php

declare(strict_types=1);



require_once __DIR__ . DIRECTORY_SEPARATOR . 'tour_search_api_client.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'tenant_context_resolver.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'gemini_tour_context_builder.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridDateRequiredGate.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchUrlBuilder.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchApiDebugLogger.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'TravelBMultiSourceLinkBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ShortUrlProviderInterface.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntentMapper.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ClarificationPolicy.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'TourPromptContextResult.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ProductSearchPolicyRuntime.php';



/**

 * Stage 1-B-17: Build tour search context for Gemini prompt (feature-flagged draft).

 * Phase 2-C: optional Hybrid Smart Search path (config OFF by default).

 */

final class TourPromptContextService

{

    /** Raw page size for TourSearchApiClient (merge up to this many rows, then cap display in context builder). */

    public const DEFAULT_API_PAGE_SIZE = 30;

    /**
     * Phase 9-C-1d-α: structured tour context result (RD-002).
     *
     * @param array{
     *   userText?: string,
     *   sno?: string,
     *   channelId?: string|null,
     *   traceId?: string|null,
     *   featureEnabled?: bool,
     *   maxItems?: int,
     *   apiPageSize?: int,
     *   searchClient?: TourSearchApiClient,
     *   contextBuilder?: GeminiTourContextBuilder,
     *   hybridSearchConfig?: array<string, mixed>|null,
     *   batsSearchIntentMapper?: BatsSearchIntentMapper,
     *   apiQueryMapper?: ApiQueryMapper,
     *   searchUrlBuilder?: SearchUrlBuilder,
     *   authoritativeIntent?: BatsSearchIntent,
     *   productUnderstandingTrace?: array<string, mixed>,
     *   referenceDate?: \DateTimeImmutable
     * } $params
     */
    public function buildTourContextResult(array $params): TourPromptContextResult
    {
        if (($params['featureEnabled'] ?? false) !== true) {
            return TourPromptContextResult::empty();
        }

        $userText = trim((string) ($params['userText'] ?? ''));
        if ($userText === '') {
            return TourPromptContextResult::empty();
        }

        $sno = trim((string) ($params['sno'] ?? ''));
        if ($sno === '') {
            return TourPromptContextResult::empty($userText);
        }

        try {
            $authoritativeIntent = $params['authoritativeIntent'] ?? null;
            $usingAuthoritativeIntent = $authoritativeIntent instanceof BatsSearchIntent;

            // B0 fail-closed: Product execution requires AIU Contract Translation; no legacy re-parse.
            if (!$usingAuthoritativeIntent) {
                return TourPromptContextResult::empty($userText);
            }

            $tourIntent = ['is_tour_query' => true, 'keyword' => $userText];

            $referenceDate = $params['referenceDate'] ?? new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));
            $batsMapper = $params['batsSearchIntentMapper'] ?? new BatsSearchIntentMapper();
            $batsIntent = $authoritativeIntent;

            if ($batsIntent->isClarificationRequired()) {
                return TourPromptContextResult::clarificationRequired(
                    $batsIntent,
                    $this->buildClarificationLegacyContext($batsIntent, $userText)
                );
            }

            $searchCondition = $batsMapper->toSearchCondition($batsIntent);
            if ($searchCondition === null) {
                return TourPromptContextResult::clarificationRequired(
                    $batsIntent->with([
                        'clarification_required' => true,
                        'clarification_reason' => ClarificationPolicy::REASON_DESTINATION_UNKNOWN,
                    ]),
                    ''
                );
            }

            $searchClient = $this->resolveSearchClient($params);
            $contextBuilder = $params['contextBuilder'] ?? new GeminiTourContextBuilder();
            $maxItems = isset($params['maxItems']) ? max(1, (int) $params['maxItems']) : 5;
            $apiPageSize = isset($params['apiPageSize'])
                ? max(1, min(100, (int) $params['apiPageSize']))
                : self::DEFAULT_API_PAGE_SIZE;
            $channelId = trim((string) ($params['channelId'] ?? ''));
            $traceId = trim((string) ($params['traceId'] ?? ''));

            $pipeline = $this->runStructuredSearchPipeline(
                $searchCondition,
                $userText,
                $sno,
                $channelId,
                $traceId,
                $apiPageSize,
                $maxItems,
                $searchClient,
                $contextBuilder,
                $params,
                $tourIntent
            );

            $searchPolicyMeta = isset($pipeline['search_policy']) && is_array($pipeline['search_policy'])
                ? $pipeline['search_policy']
                : [];
            if (isset($params['productUnderstandingTrace']) && is_array($params['productUnderstandingTrace'])) {
                $searchPolicyMeta = array_merge($searchPolicyMeta, $params['productUnderstandingTrace']);
            } else {
                $searchPolicyMeta = array_merge($searchPolicyMeta, [
                    'understanding_source' => 'gemini_aiu',
                ]);
            }

            return TourPromptContextResult::searchable(
                $batsIntent,
                $searchCondition,
                $pipeline['search_results'],
                $pipeline['legacy_context'],
                $searchPolicyMeta
            );
        } catch (\Throwable $e) {
            return TourPromptContextResult::empty($userText);
        }
    }



    /**
     * @param array<string, mixed> $params
     */
    private function resolveSearchClient(array $params): TourSearchApiClient
    {
        $searchClient = $params['searchClient'] ?? null;
        if ($searchClient instanceof TourSearchApiClient) {
            return $searchClient;
        }

        $hostBBaseUrl = '';
        if (function_exists('app_config_get')) {
            $hostBBaseUrl = trim((string) app_config_get('gateway.host_b.base_url', ''));
        }
        if ($hostBBaseUrl !== '') {
            return new TourSearchApiClient(rtrim($hostBBaseUrl, '/') . '/api/tour/search');
        }

        return new TourSearchApiClient();
    }

    private function buildClarificationLegacyContext(BatsSearchIntent $intent, string $userText): string
    {
        if ($intent->getClarificationReason() !== ClarificationPolicy::REASON_DATE_REQUIRED) {
            return '';
        }

        $destination = $intent->getDestination();
        $probe = SearchCondition::empty($userText)->with([
            'destination' => $destination,
            'keyword' => $destination !== [] ? $destination[0] : null,
            'date_from' => $intent->getDateFrom(),
            'date_to' => $intent->getDateTo(),
        ]);

        return HybridDateRequiredGate::buildClarificationContext($probe, $userText);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $tourIntent
     * @return array{legacy_context: string, search_results: list<array<string, mixed>>}
     */
    private function runStructuredSearchPipeline(
        SearchCondition $condition,
        string $userText,
        string $sno,
        string $channelId,
        string $traceId,
        int $apiPageSize,
        int $maxItems,
        TourSearchApiClient $searchClient,
        GeminiTourContextBuilder $contextBuilder,
        array $params,
        array $tourIntent
    ): array {
        $apiMapper = $params['apiQueryMapper'] ?? new ApiQueryMapper();

        $apiParamsInternal = $apiMapper->toClientParams($condition, [
            'page' => 1,
            'pageSize' => $apiPageSize,
            'include_sno' => $sno,
        ]);

        $apiParams = $apiMapper->toHostBParams($condition, [
            'page' => 1,
            'pageSize' => $apiPageSize,
            'include_sno' => $sno,
        ]);

        $apiResult = $searchClient->searchWithParams(
            $sno,
            $apiParams,
            $traceId !== '' ? $traceId : null
        );

        $requestUrl = $searchClient->buildRequestUrlFromParams(
            $sno,
            $apiParams,
            (int) ($apiParams['page'] ?? 1),
            (int) ($apiParams['pageSize'] ?? $apiPageSize),
            $traceId !== '' ? $traceId : null
        );

        HybridSearchApiDebugLogger::logSearchRoundtrip(
            $traceId,
            $sno,
            $condition->toArray(),
            $apiParamsInternal,
            $requestUrl,
            $apiResult
        );

        $urlBuilder = $params['searchUrlBuilder'] ?? $this->createSearchUrlBuilder();
        $apiResult['search_url'] = $this->resolveSearchUrlForApiResult($urlBuilder, $sno, $condition, $apiResult);

        $storeNo = $this->resolveStoreNoForSno($sno);
        $buildOptions = [
            'maxItems' => $maxItems,
            'apiRawLimit' => $apiPageSize,
        ];
        if ($storeNo !== null) {
            $buildOptions['storeNo'] = $storeNo;
        }

        $multiSourceConfig = isset($params['travelBMultiSourceLinksConfig']) && is_array($params['travelBMultiSourceLinksConfig'])
            ? $params['travelBMultiSourceLinksConfig']
            : null;
        if (TravelBMultiSourceLinkBuilder::isEnabledForSno($sno, $multiSourceConfig)) {
            $linkBuilder = isset($params['travelBMultiSourceLinkBuilder'])
                && $params['travelBMultiSourceLinkBuilder'] instanceof TravelBMultiSourceLinkBuilder
                ? $params['travelBMultiSourceLinkBuilder']
                : new TravelBMultiSourceLinkBuilder($multiSourceConfig);
            $multiSourceLinks = $linkBuilder->buildFromHybridCondition($condition, $sno);
            $multiSourceLinks = $this->applyBbctravelMultiSourceShortUrls($multiSourceLinks, $params);
            if ($multiSourceLinks !== []) {
                $buildOptions['multi_source_links'] = $multiSourceLinks;
            }
        }

        $items = $apiResult['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        $listingSearchUrl = isset($apiResult['search_url']) ? trim((string) $apiResult['search_url']) : '';
        if ($listingSearchUrl !== '') {
            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (!isset($item['primary_url']) && !isset($item['search_url']) && !isset($item['url'])) {
                    $item['search_url'] = $listingSearchUrl;
                }
                $items[$index] = $item;
            }
        }

        $policyRuntime = isset($params['productSearchPolicyRuntime']) && $params['productSearchPolicyRuntime'] instanceof ProductSearchPolicyRuntime
            ? $params['productSearchPolicyRuntime']
            : new ProductSearchPolicyRuntime();
        $searchPolicy = $policyRuntime->apply($condition, array_values($items));
        $primaryItems = $searchPolicy['primary_results'] ?? array_values($items);

        $apiResultForContext = $apiResult;
        $apiResultForContext['items'] = $primaryItems;

        return [
            'legacy_context' => $contextBuilder->build($apiResultForContext, $buildOptions),
            'search_results' => array_values($primaryItems),
            'search_policy' => $searchPolicy,
        ];
    }



    private function createSearchUrlBuilder(): SearchUrlBuilder

    {

        $shortEnabled = false;

        if (function_exists('app_config_get')) {

            $shortEnabled = filter_var(app_config_get('short_url.enabled', false), FILTER_VALIDATE_BOOLEAN);

        }



        return new SearchUrlBuilder($shortEnabled);

    }

    /** @var list<string> */
    private const MULTI_SOURCE_SHORT_URL_PLATFORMS = ['bbctravel', 'grp', 'tourcenter'];

    /**
     * Multi-source search URLs (bbctravel / grp / tourcenter) → bbcshops short URLs before Gemini context.
     * Fail-open: ShortUrlService errors leave the original long URL unchanged.
     *
     * @param list<array{platform: string, search_url: string}> $links
     * @param array<string, mixed> $params optional multiSourceShortUrlProvider (tests)
     * @return list<array{platform: string, search_url: string}>
     */
    private function applyBbctravelMultiSourceShortUrls(array $links, array $params = []): array
    {
        if ($links === []) {
            return $links;
        }

        $provider = $params['multiSourceShortUrlProvider'] ?? null;
        $shortEnabled = false;
        if ($provider === null && function_exists('app_config_get')) {
            $shortEnabled = filter_var(app_config_get('short_url.enabled', false), FILTER_VALIDATE_BOOLEAN);
        }
        if ($provider === null && !$shortEnabled) {
            return $links;
        }

        $shortService = null;
        if ($provider === null) {
            $shortPath = __DIR__ . DIRECTORY_SEPARATOR . 'short_url_service.php';
            if (!is_file($shortPath)) {
                return $links;
            }
            require_once $shortPath;
            $shortService = new ShortUrlService();
        }

        $out = [];
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $platform = isset($link['platform']) ? trim((string) $link['platform']) : '';
            if ($platform === '' || !in_array($platform, self::MULTI_SOURCE_SHORT_URL_PLATFORMS, true)) {
                $out[] = $link;
                continue;
            }
            $longUrl = isset($link['search_url']) ? trim((string) $link['search_url']) : '';
            if ($longUrl === '') {
                $out[] = $link;
                continue;
            }
            $context = [
                'source_id' => $platform,
                'short_url_domain' => 'bbcshops.com',
                'domain_namespace' => 'bbcshops',
                'product_category' => 'group_tour',
            ];
            if ($provider instanceof ShortUrlProviderInterface) {
                $link['search_url'] = $provider->shortenSearchUrl($longUrl, $context);
            } else {
                $link['search_url'] = $shortService->toPublicShortUrl($longUrl);
            }
            $out[] = $link;
        }

        return $out;
    }

    private function resolveStoreNoForSno(string $sno): ?int
    {
        try {
            $resolver = new TenantContextResolver();
            $resolved = $resolver->resolve($sno);
            if (($resolved['ok'] ?? false) !== true) {
                return null;
            }

            $ctx = $resolved['tenantContext'] ?? null;

            if (!is_array($ctx) || !array_key_exists('storeNo', $ctx)) {

                return null;

            }



            $v = $ctx['storeNo'];

            if (is_int($v) && $v > 0) {

                return $v;

            }



            if (is_string($v) && preg_match('/^\d+$/', trim($v)) === 1) {

                $n = (int) trim($v);



                return $n > 0 ? $n : null;

            }

        } catch (\Throwable $e) {

            return null;

        }



        return null;

    }



    /**
     * Keyword search URL when results exist; clean storefront listing URL when empty.
     */
    private function resolveSearchUrlForApiResult(
        SearchUrlBuilder $urlBuilder,
        string $sno,
        SearchCondition $condition,
        array $apiResult
    ): string {
        $items = $apiResult['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        $total = 0;
        $pagination = $apiResult['pagination'] ?? null;
        if (is_array($pagination) && isset($pagination['total'])) {
            $total = (int) $pagination['total'];
        }

        if ($total <= 0 && $items === []) {
            return $urlBuilder->buildStorefrontListingUrl($sno);
        }

        return $urlBuilder->build($sno, $condition);
    }

}


