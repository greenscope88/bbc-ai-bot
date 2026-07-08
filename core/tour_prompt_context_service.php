<?php

declare(strict_types=1);



require_once __DIR__ . DIRECTORY_SEPARATOR . 'tour_query_intent_detector.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'tour_search_api_client.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'tenant_context_resolver.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'gemini_tour_context_builder.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchConditionBuilder.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridDateRequiredGate.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchUrlBuilder.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchFeatureGate.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchDryRunLogger.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchApiDebugLogger.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'TravelBMultiSourceLinkBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ShortUrlProviderInterface.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntentBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntentMapper.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ClarificationPolicy.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'TourPromptContextResult.php';



/**

 * Stage 1-B-17: Build tour search context for Gemini prompt (feature-flagged draft).

 * Phase 2-C: optional Hybrid Smart Search path (config OFF by default).

 */

final class TourPromptContextService

{

    /** Raw page size for TourSearchApiClient (merge up to this many rows, then cap display in context builder). */

    public const DEFAULT_API_PAGE_SIZE = 30;



    /**

     * @param array{

     *   userText?: string,

     *   sno?: string,

     *   channelId?: string|null,

     *   traceId?: string|null,

     *   featureEnabled?: bool,

     *   maxItems?: int,

     *   apiPageSize?: int,

     *   searchClient?: TourSearchApiClient,

     *   intentDetector?: TourQueryIntentDetector,

     *   contextBuilder?: GeminiTourContextBuilder,

     *   hybridSearchConfig?: array<string, mixed>|null,

     *   hybridConditionBuilder?: HybridSearchConditionBuilder,

     *   apiQueryMapper?: ApiQueryMapper,

     *   searchUrlBuilder?: SearchUrlBuilder,

     *   referenceDate?: \DateTimeImmutable

     * } $params

     */

    public function buildTourContextForPrompt(array $params): string

    {

        if (($params['featureEnabled'] ?? false) !== true) {

            return '';

        }



        $userText = trim((string) ($params['userText'] ?? ''));

        if ($userText === '') {

            return '';

        }



        $sno = trim((string) ($params['sno'] ?? ''));

        if ($sno === '') {

            return '';

        }



        try {

            $intentDetector = $params['intentDetector'] ?? new TourQueryIntentDetector();

            $searchClient = $params['searchClient'] ?? null;
            if (!$searchClient instanceof TourSearchApiClient) {
                $hostBBaseUrl = '';
                if (function_exists('app_config_get')) {
                    $hostBBaseUrl = trim((string) app_config_get('gateway.host_b.base_url', ''));
                }
                if ($hostBBaseUrl !== '') {
                    $searchClient = new TourSearchApiClient(rtrim($hostBBaseUrl, '/') . '/api/tour/search');
                } else {
                    $searchClient = new TourSearchApiClient();
                }
            }

            $contextBuilder = $params['contextBuilder'] ?? new GeminiTourContextBuilder();

            $maxItems = isset($params['maxItems']) ? max(1, (int) $params['maxItems']) : 5;

            $apiPageSize = isset($params['apiPageSize'])

                ? max(1, min(100, (int) $params['apiPageSize']))

                : self::DEFAULT_API_PAGE_SIZE;



            $intent = $intentDetector->detect($userText);

            if (($intent['is_tour_query'] ?? false) !== true) {

                return '';

            }



            $channelId = trim((string) ($params['channelId'] ?? ''));

            $traceId = trim((string) ($params['traceId'] ?? ''));

            $hybridConfig = HybridSearchFeatureGate::resolveConfig($params['hybridSearchConfig'] ?? null);

            $hybridEnabled = HybridSearchFeatureGate::isEnabled(

                [

                    'sno' => $sno,

                    'channelId' => $channelId !== '' ? $channelId : null,

                ],

                $params['hybridSearchConfig'] ?? null

            );



            if ($hybridEnabled) {

                $hybridContext = $this->buildHybridContext(

                    $userText,

                    $sno,

                    $channelId,

                    $traceId,

                    $apiPageSize,

                    $maxItems,

                    $searchClient,

                    $contextBuilder,

                    $hybridConfig,

                    $params,

                    $intent

                );

                if ($hybridContext !== null) {

                    return $hybridContext;

                }

            } elseif (($hybridConfig['dry_run_log_enabled'] ?? false) === true && ($hybridConfig['enabled'] ?? false) === true) {

                $this->writeHybridDryRunLog(

                    $traceId,

                    $sno,

                    $channelId,

                    $userText,

                    null,

                    [],

                    [],

                    'legacy',

                    'hybrid_allowlist_blocked',

                    [],

                    $hybridConfig,

                    $intent

                );

            }



            return $this->buildLegacyContext(

                $sno,

                $apiPageSize,

                $maxItems,

                $searchClient,

                $contextBuilder,

                $intent

            );

        } catch (\Throwable $e) {

            return '';

        }

    }



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
     *   intentDetector?: TourQueryIntentDetector,
     *   contextBuilder?: GeminiTourContextBuilder,
     *   hybridSearchConfig?: array<string, mixed>|null,
     *   batsSearchIntentBuilder?: BatsSearchIntentBuilder,
     *   batsSearchIntentMapper?: BatsSearchIntentMapper,
     *   apiQueryMapper?: ApiQueryMapper,
     *   searchUrlBuilder?: SearchUrlBuilder,
     *   authoritativeIntent?: BatsSearchIntent,
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

            // AIU v2 Last Mile: skip legacy TourQueryIntentDetector when authoritative
            // Contract Translation is supplied — dispatch already decided by AIU.
            if (!$usingAuthoritativeIntent) {
                $intentDetector = $params['intentDetector'] ?? new TourQueryIntentDetector();
                $tourIntent = $intentDetector->detect($userText);
                if (($tourIntent['is_tour_query'] ?? false) !== true) {
                    return TourPromptContextResult::empty($userText);
                }
            } else {
                $tourIntent = ['is_tour_query' => true, 'keyword' => $userText];
            }

            $referenceDate = $params['referenceDate'] ?? new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));
            $batsMapper = $params['batsSearchIntentMapper'] ?? new BatsSearchIntentMapper();

            // AIU v2 Last Mile: when authoritativeIntent is supplied (Contract Translation
            // from AiIntentUnderstandingResult), skip legacy BatsSearchIntentBuilder parse.
            if ($usingAuthoritativeIntent) {
                $batsIntent = $authoritativeIntent;
            } else {
                $batsBuilder = $params['batsSearchIntentBuilder'] ?? new BatsSearchIntentBuilder();
                $batsIntent = $batsBuilder->parse($userText, [
                    'reference_date' => $referenceDate,
                    'merge_legacy_keyword' => true,
                ]);
            }

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

            return TourPromptContextResult::searchable(
                $batsIntent,
                $searchCondition,
                $pipeline['search_results'],
                $pipeline['legacy_context']
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
            'keyword' => $destination,
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
        $hybridConfig = HybridSearchFeatureGate::resolveConfig($params['hybridSearchConfig'] ?? null);
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
        $searchUrlParams = $urlBuilder->searchParamsOnly($condition);
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

        $this->writeHybridDryRunLog(
            $traceId,
            $sno,
            $channelId,
            $userText,
            $condition,
            $apiParamsInternal,
            $searchUrlParams,
            'hybrid',
            null,
            $condition->getParserFlags(),
            $hybridConfig,
            $tourIntent
        );

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

        return [
            'legacy_context' => $contextBuilder->build($apiResult, $buildOptions),
            'search_results' => array_values($items),
        ];
    }



    /**

     * @param array<string, mixed> $params

     */

    private function buildHybridContext(

        string $userText,

        string $sno,

        string $channelId,

        string $traceId,

        int $apiPageSize,

        int $maxItems,

        TourSearchApiClient $searchClient,

        GeminiTourContextBuilder $contextBuilder,

        array $hybridConfig,

        array $params,

        array $intent

    ): ?string {

        try {

            $referenceDate = $params['referenceDate'] ?? new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));

            $conditionBuilder = $params['hybridConditionBuilder'] ?? new HybridSearchConditionBuilder();

            $apiMapper = $params['apiQueryMapper'] ?? new ApiQueryMapper();



            $condition = $conditionBuilder->parse($userText, [

                'reference_date' => $referenceDate,

                'merge_legacy_keyword' => true,

            ]);

            $keywordProbe = $condition->getKeyword();
            if ($keywordProbe === null || trim($keywordProbe) === '') {
                $keywordProbe = $condition->getDestination();
            }
            if ($keywordProbe === null || trim($keywordProbe) === '') {
                $keywordProbe = $condition->getArea();
            }

            if ($keywordProbe === null || trim((string) $keywordProbe) === '') {
                $this->writeHybridDryRunLog(

                    $traceId,

                    $sno,

                    $channelId,

                    $userText,

                    $condition,

                    [],

                    [],

                    'legacy_fallback',

                    'empty_keyword_hybrid',

                    $condition->getParserFlags(),

                    $hybridConfig,

                    $intent

                );

                return null;
            }

            $dateGate = isset($params['hybridDateRequiredGate']) && $params['hybridDateRequiredGate'] instanceof HybridDateRequiredGate
                ? $params['hybridDateRequiredGate']
                : new HybridDateRequiredGate();
            $dateGateResult = $dateGate->evaluate($condition);
            if ($dateGateResult->requiresDateClarification()) {
                $this->writeHybridDryRunLog(

                    $traceId,

                    $sno,

                    $channelId,

                    $userText,

                    $condition,

                    [],

                    [],

                    'date_clarification',

                    $dateGateResult->getReasonCode(),

                    $condition->getParserFlags(),

                    $hybridConfig,

                    $intent

                );

                return HybridDateRequiredGate::buildClarificationContext($condition, $userText);
            }

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

            $searchUrlParams = $urlBuilder->searchParamsOnly($condition);

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



            $this->writeHybridDryRunLog(

                $traceId,

                $sno,

                $channelId,

                $userText,

                $condition,

                $apiParamsInternal,

                $searchUrlParams,

                'hybrid',

                null,

                $condition->getParserFlags(),

                $hybridConfig,

                $intent

            );



            return $contextBuilder->build($apiResult, $buildOptions);

        } catch (\Throwable $e) {

            $this->writeHybridDryRunLog(

                $traceId,

                $sno,

                $channelId,

                $userText,

                null,

                [],

                [],

                'legacy_fallback',

                'hybrid_exception',

                [],

                $hybridConfig,

                $intent

            );



            return null;

        }

    }



    /**
     * @param array<string, mixed> $intent TourQueryIntentDetector::detect() result
     */
    private function buildLegacyContext(

        string $sno,

        int $apiPageSize,

        int $maxItems,

        TourSearchApiClient $searchClient,

        GeminiTourContextBuilder $contextBuilder,

        array $intent

    ): string {

        if (($intent['is_tour_query'] ?? false) !== true) {

            return '';

        }



        $keyword = is_string($intent['keyword'] ?? null) ? trim((string) $intent['keyword']) : '';

        if ($keyword === '') {

            return '';

        }



        $apiResult = $searchClient->search($sno, $keyword, 1, $apiPageSize);



        $storeNo = $this->resolveStoreNoForSno($sno);

        $buildOptions = [

            'maxItems' => $maxItems,

            'apiRawLimit' => $apiPageSize,

        ];

        if ($storeNo !== null) {

            $buildOptions['storeNo'] = $storeNo;

        }



        return $contextBuilder->build($apiResult, $buildOptions);

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



    /**

     * @param array<string, string|int> $apiParams

     * @param array<string, string> $searchUrlParams

     * @param array<string, bool> $parserFlags

     * @param array{enabled: bool, allowed_sno: list<string>, allowed_channels: list<string>, dry_run_log_enabled: bool} $hybridConfig
     * @param array<string, mixed>|null $intentSnapshot

     */

    private function writeHybridDryRunLog(

        string $traceId,

        string $sno,

        string $channelId,

        string $message,

        ?SearchCondition $condition,

        array $apiParams,

        array $searchUrlParams,

        string $flow,

        ?string $fallbackReason,

        array $parserFlags,

        array $hybridConfig,

        ?array $intentSnapshot = null

    ): void {

        if (($hybridConfig['dry_run_log_enabled'] ?? false) !== true) {

            return;

        }



        $entry = [

            'trace_id' => $traceId !== '' ? $traceId : null,

            'sno' => $sno,

            'channel' => $channelId !== '' ? $channelId : null,

            'message' => $message,

            'flow' => $flow,

            'fallback_reason' => $fallbackReason,

            'search_condition' => $condition !== null ? $condition->toArray() : null,

            'api_params' => $apiParams,

            'search_url_params' => $searchUrlParams,

            'parser_flags' => $parserFlags,

        ];

        if (is_array($intentSnapshot)) {
            $entry['intent'] = $intentSnapshot['intent'] ?? null;
            $entry['intent_source'] = $intentSnapshot['intent_source'] ?? null;
            $entry['matched_lexicon'] = $intentSnapshot['matched_lexicon'] ?? null;
            $entry['matched_date'] = $intentSnapshot['matched_date'] ?? false;
            $entry['matched_area'] = $intentSnapshot['matched_area'] ?? null;
            $entry['matched_budget'] = $intentSnapshot['matched_budget'] ?? false;
            $entry['intent_reason'] = $intentSnapshot['reason'] ?? null;
        }



        HybridSearchDryRunLogger::log($entry);

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


