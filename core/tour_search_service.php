<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tenant_context_resolver.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'short_url_service.php';

$prodRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'api_gateway' . DIRECTORY_SEPARATOR . 'production';
require_once $prodRoot . DIRECTORY_SEPARATOR . 'ServiceRegistry.php';
require_once $prodRoot . DIRECTORY_SEPARATOR . 'TourSearchRequestBuilder.php';
require_once $prodRoot . DIRECTORY_SEPARATOR . 'HostBOutboundHeaderBuilder.php';
require_once $prodRoot . DIRECTORY_SEPARATOR . 'HostBHttpClient.php';
require_once $prodRoot . DIRECTORY_SEPARATOR . 'http' . DIRECTORY_SEPARATOR . 'HostBResponseNormalizer.php';

/**
 * Host A tour search orchestration: tenant context → Host B /api/tour/search → normalized result.
 */
final class TourSearchService
{
    private const SEARCH_URL_BASE = 'https://bonusmee.com/view/cloud/cloud_store_tourdate.php';

    private TenantContextResolver $tenantContextResolver;

    public function __construct(?TenantContextResolver $tenantContextResolver = null)
    {
        $this->tenantContextResolver = $tenantContextResolver ?? new TenantContextResolver();
    }

    /**
     * @return array{
     *   ok: bool,
     *   httpStatus: int,
     *   errorCode: string|null,
     *   message: string|null,
     *   traceId: string,
     *   pagination: array{page: int, pageSize: int, total: int}|null,
     *   items: list<array<string, mixed>>,
     *   search_url: string|null
     * }
     */
    public static function search(
        string $sno,
        string $keyword,
        int $page = 1,
        int $pageSize = 20,
        string $traceId = ''
    ): array {
        return (new self())->searchInstance($sno, $keyword, $page, $pageSize, $traceId);
    }

    /**
     * @return array<string, mixed>
     */
    public function searchInstance(
        string $sno,
        string $keyword,
        int $page = 1,
        int $pageSize = 20,
        string $traceId = ''
    ): array {
        $trace = self::resolveTraceId($traceId);
        $normalizedSno = trim($sno);
        $normalizedKeyword = trim($keyword);

        if ($normalizedKeyword === '') {
            return self::failure(
                'KEYWORD_REQUIRED',
                'Search keyword is required.',
                $trace,
                0,
                $normalizedSno !== '' ? self::buildSearchUrl($normalizedSno, '') : null
            );
        }

        $tenantResult = $this->tenantContextResolver->resolve($normalizedSno);
        if (!($tenantResult['ok'] ?? false)) {
            return self::failure(
                (string) ($tenantResult['errorCode'] ?? 'TENANT_CONTEXT_ERROR'),
                (string) ($tenantResult['message'] ?? 'Tenant context resolution failed.'),
                $trace,
                0,
                null
            );
        }

        /** @var array<string, mixed> $tenantContext */
        $tenantContext = $tenantResult['tenantContext'];
        $authoritativeSno = (string) ($tenantContext['sno'] ?? $normalizedSno);
        $searchUrl = self::buildSearchUrl($authoritativeSno, $normalizedKeyword);

        $hostB = app_config_get('gateway.host_b', []);
        if (!is_array($hostB)) {
            $hostB = [];
        }

        if (!self::isTruthy(app_config_get('gateway.host_b.http_enabled', false))) {
            return self::failure(
                'HOSTB_HTTP_DISABLED',
                'Host B HTTP is disabled for this environment.',
                $trace,
                0,
                $searchUrl
            );
        }

        $baseUrl = trim((string) ($hostB['base_url'] ?? ''));
        if ($baseUrl === '') {
            return self::failure(
                'HOSTB_CONFIG_MISSING',
                'Host B base URL is not configured.',
                $trace,
                0,
                $searchUrl
            );
        }

        $apiKey = $hostB['api_key'] ?? null;
        if ($apiKey === null || (is_string($apiKey) && trim($apiKey) === '')) {
            return self::failure(
                'HOSTB_CONFIG_MISSING',
                'Host B API key is not configured.',
                $trace,
                0,
                $searchUrl
            );
        }

        try {
            $clientParams = [
                'sno' => $authoritativeSno,
                'keyword' => $normalizedKeyword,
                'page' => max(1, $page),
                'pageSize' => max(1, $pageSize),
            ];

            $built = TourSearchRequestBuilder::build($clientParams, $tenantContext);
            $headers = HostBOutboundHeaderBuilder::build(['api_key' => $apiKey], $trace);

            $httpResult = MvpStagingHostBHttpClient::sendGet(
                $baseUrl,
                (string) $built['path'],
                $built['query'],
                $headers,
                (int) $built['timeout']
            );

            $httpStatus = (int) ($httpResult['httpStatus'] ?? 0);
            $transportError = (string) ($httpResult['error'] ?? '');

            if ($transportError !== '') {
                if ($transportError === 'MVP_STAGING_HTTP_DRY_RUN') {
                    return self::failure(
                        'HOSTB_HTTP_DRY_RUN',
                        'Host B HTTP dry-run mode is active; no request was sent.',
                        $trace,
                        $httpStatus,
                        $searchUrl
                    );
                }

                return self::failure(
                    'HOSTB_TIMEOUT',
                    'Host B request failed or timed out.',
                    $trace,
                    $httpStatus,
                    $searchUrl
                );
            }

            if ($httpStatus === 401) {
                return self::failure('HOSTB_401', 'Host B rejected the API key.', $trace, $httpStatus, $searchUrl);
            }
            if ($httpStatus === 403) {
                return self::failure('HOSTB_403', 'Host B forbade this request.', $trace, $httpStatus, $searchUrl);
            }
            if ($httpStatus >= 500) {
                return self::failure('HOSTB_500', 'Host B returned a server error.', $trace, $httpStatus, $searchUrl);
            }
            if ($httpStatus < 200 || $httpStatus >= 300) {
                return self::failure(
                    'HOSTB_HTTP_ERROR',
                    'Host B returned an unexpected HTTP status.',
                    $trace,
                    $httpStatus,
                    $searchUrl
                );
            }

            $rawBody = is_string($httpResult['body'] ?? null) ? (string) $httpResult['body'] : '';
            $decoded = HostBResponseNormalizer::decodeJsonBody($rawBody, $trace);

            if (isset($decoded['errorCode']) && ($decoded['status'] ?? null) === null) {
                return self::failure(
                    (string) $decoded['errorCode'],
                    (string) ($decoded['message'] ?? 'Host B response could not be parsed.'),
                    $trace,
                    $httpStatus,
                    $searchUrl
                );
            }

            $upstreamStatus = strtolower(trim((string) ($decoded['status'] ?? '')));
            if ($upstreamStatus !== '' && $upstreamStatus !== 'success') {
                $msg = trim((string) ($decoded['message'] ?? ''));
                return self::failure(
                    'HOSTB_UPSTREAM_ERROR',
                    $msg !== '' ? $msg : 'Host B reported a non-success status.',
                    $trace,
                    $httpStatus,
                    $searchUrl
                );
            }

            $pagination = self::mapPagination($decoded, $page, $pageSize);
            $items = self::mapItems($decoded);

            return [
                'ok' => true,
                'httpStatus' => $httpStatus,
                'errorCode' => null,
                'message' => null,
                'traceId' => $trace,
                'pagination' => $pagination,
                'items' => $items,
                'search_url' => $searchUrl,
            ];
        } catch (\InvalidArgumentException $e) {
            return self::failure(
                'TOUR_SEARCH_INVALID_REQUEST',
                $e->getMessage(),
                $trace,
                0,
                $searchUrl
            );
        } catch (\Throwable $e) {
            return self::failure(
                'TOUR_SEARCH_INTERNAL_ERROR',
                'Tour search failed unexpectedly.',
                $trace,
                0,
                $searchUrl
            );
        }
    }

    public static function buildSearchUrl(string $sno, string $keyword): string
    {
        $query = http_build_query(
            [
                'openExternalBrowser' => '1',
                'UnCarousel' => '1',
                'fromDMDetailFlag' => '1',
                'clearParam' => 'Y',
                'mode' => '1',
                'sno' => trim($sno),
                'keyword' => $keyword,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $longUrl = self::SEARCH_URL_BASE . '?' . $query;

        return (new ShortUrlService())->toPublicShortUrl($longUrl);
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array{page: int, pageSize: int, total: int}
     */
    private static function mapPagination(array $decoded, int $fallbackPage, int $fallbackPageSize): array
    {
        $pg = $decoded['pagination'] ?? [];
        if (!is_array($pg)) {
            $pg = [];
        }

        return [
            'page' => isset($pg['page']) ? (int) $pg['page'] : max(1, $fallbackPage),
            'pageSize' => isset($pg['pageSize']) ? (int) $pg['pageSize'] : max(1, $fallbackPageSize),
            'total' => isset($pg['total']) ? (int) $pg['total'] : 0,
        ];
    }

    /**
     * @param array<string, mixed> $decoded
     * @return list<array<string, mixed>>
     */
    private static function mapItems(array $decoded): array
    {
        $data = $decoded['data'] ?? [];
        if (!is_array($data)) {
            return [];
        }

        $items = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = self::mapItem($row);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapItem(array $row): array
    {
        $item = [
            'title' => (string) ($row['couponName'] ?? ''),
            'price' => isset($row['price']) ? (int) $row['price'] : 0,
            'tourDate' => (string) ($row['tourDate'] ?? ''),
            'couponNo' => isset($row['couponNo']) ? (int) $row['couponNo'] : 0,
            'tourSeqNo' => isset($row['tourSeqNo']) ? (int) $row['tourSeqNo'] : 0,
            'areaNames' => (string) ($row['areaNames'] ?? ''),
            'departureStr' => (string) ($row['departureStr'] ?? ''),
            'storeName' => (string) ($row['storeName'] ?? ''),
        ];

        if (isset($row['schLink']) && is_scalar($row['schLink'])) {
            $schLink = trim((string) $row['schLink']);
            if ($schLink !== '') {
                $item['schLink'] = $schLink;
            }
        }

        if (isset($row['schLinkName']) && is_scalar($row['schLinkName'])) {
            $schLinkName = trim((string) $row['schLinkName']);
            if ($schLinkName !== '') {
                $item['schLinkName'] = $schLinkName;
            }
        }

        $schLinks = self::normalizeSchLinksFromRow($row);
        if ($schLinks !== []) {
            $item['schLinks'] = $schLinks;
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array{schLinkName: string, schLink: string}>
     */
    private static function normalizeSchLinksFromRow(array $row): array
    {
        if (!isset($row['schLinks']) || !is_array($row['schLinks'])) {
            return [];
        }

        $out = [];
        foreach ($row['schLinks'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $url = isset($entry['schLink']) && is_scalar($entry['schLink']) ? trim((string) $entry['schLink']) : '';
            if ($url === '') {
                continue;
            }
            $name = isset($entry['schLinkName']) && is_scalar($entry['schLinkName'])
                ? trim((string) $entry['schLinkName'])
                : '';
            $out[] = ['schLinkName' => $name, 'schLink' => $url];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function failure(
        string $errorCode,
        string $message,
        string $traceId,
        int $httpStatus,
        ?string $searchUrl
    ): array {
        return [
            'ok' => false,
            'httpStatus' => $httpStatus,
            'errorCode' => $errorCode,
            'message' => $message,
            'traceId' => $traceId,
            'pagination' => null,
            'items' => [],
            'search_url' => $searchUrl,
        ];
    }

    private static function resolveTraceId(string $traceId): string
    {
        $trimmed = trim($traceId);
        if ($trimmed !== '') {
            return $trimmed;
        }

        return date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    }

    /**
     * @param mixed $value
     */
    private static function isTruthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $v = strtolower(trim($value));
            return $v === '1' || $v === 'true' || $v === 'yes' || $v === 'on';
        }

        return (bool) $value;
    }
}
