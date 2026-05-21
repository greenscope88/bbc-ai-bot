<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'tour_query_intent_detector.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'tour_search_api_client.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'tenant_context_resolver.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'gemini_tour_context_builder.php';

/**
 * Stage 1-B-17: Build tour search context for Gemini prompt (feature-flagged draft).
 */
final class TourPromptContextService
{
    /** Raw page size for TourSearchApiClient (merge up to this many rows, then cap display in context builder). */
    public const DEFAULT_API_PAGE_SIZE = 30;

    /**
     * @param array{
     *   userText?: string,
     *   sno?: string,
     *   featureEnabled?: bool,
     *   maxItems?: int,
     *   apiPageSize?: int,
     *   searchClient?: TourSearchApiClient,
     *   intentDetector?: TourQueryIntentDetector,
     *   contextBuilder?: GeminiTourContextBuilder
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
        } catch (\Throwable $e) {
            return '';
        }
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
