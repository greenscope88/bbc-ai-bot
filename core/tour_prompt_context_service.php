<?php

declare(strict_types=1);



require_once __DIR__ . DIRECTORY_SEPARATOR . 'tour_query_intent_detector.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'tour_search_api_client.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'tenant_context_resolver.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'gemini_tour_context_builder.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchConditionBuilder.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchUrlBuilder.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchFeatureGate.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchDryRunLogger.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchApiDebugLogger.php';



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

            $searchClient = $params['searchClient'] ?? new TourSearchApiClient();

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



            $apiParams = $apiMapper->toClientParams($condition, [

                'page' => 1,

                'pageSize' => $apiPageSize,

                'include_sno' => $sno,

            ]);



            $keyword = isset($apiParams['keyword']) ? trim((string) $apiParams['keyword']) : '';

            if ($keyword === '') {

                $this->writeHybridDryRunLog(

                    $traceId,

                    $sno,

                    $channelId,

                    $userText,

                    $condition,

                    $apiParams,

                    [],

                    'legacy_fallback',

                    'empty_keyword_hybrid',

                    $condition->getParserFlags(),

                    $hybridConfig,

                    $intent

                );



                return null;

            }



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
                $apiParams,
                $requestUrl,
                $apiResult
            );



            $urlBuilder = $params['searchUrlBuilder'] ?? $this->createSearchUrlBuilder();

            $searchUrlParams = $urlBuilder->searchParamsOnly($condition);

            $apiResult['search_url'] = $urlBuilder->build($sno, $condition);



            $storeNo = $this->resolveStoreNoForSno($sno);

            $buildOptions = [

                'maxItems' => $maxItems,

                'apiRawLimit' => $apiPageSize,

            ];

            if ($storeNo !== null) {

                $buildOptions['storeNo'] = $storeNo;

            }



            $this->writeHybridDryRunLog(

                $traceId,

                $sno,

                $channelId,

                $userText,

                $condition,

                $apiParams,

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

}


