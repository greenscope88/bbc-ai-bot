<?php
declare(strict_types=1);

/**
 * Stage 1-B-12 HTTP client for formal tour search API (no LINE / Gemini wiring).
 */
final class TourSearchApiClient
{
    private const DEFAULT_BASE_URL = 'https://bonusmee.com/api/gateway/tour/search.php';

    private string $baseUrl;

    private int $timeoutSeconds;

    /** @var callable(string, array<string, string>, int): array{ok: bool, http_status: int, body: string, transport_error: string|null}|null */
    private $transport;

    /**
     * @param callable(string, array<string, string>, int): array{ok: bool, http_status: int, body: string, transport_error: string|null}|null $transport
     */
    public function __construct(?string $baseUrl = null, int $timeoutSeconds = 5, ?callable $transport = null)
    {
        $this->baseUrl = $baseUrl ?? self::DEFAULT_BASE_URL;
        $this->timeoutSeconds = max(1, $timeoutSeconds);
        $this->transport = $transport;
    }

    /**
     * @return array{
     *   success: bool,
     *   http_status: int,
     *   traceId: string|null,
     *   sno: string,
     *   keyword: string,
     *   pagination: array<string, mixed>|null,
     *   items: list<array<string, mixed>>,
     *   search_url: string|null,
     *   error: array{code: string, message: string}|null
     * }
     */
    public function search(
        string $sno,
        string $keyword,
        int $page = 1,
        int $pageSize = 5,
        ?string $traceId = null
    ): array {
        try {
            $normalizedSno = trim($sno);
            $normalizedKeyword = trim($keyword);

            if ($normalizedSno === '') {
                return $this->failureResult(
                    0,
                    $normalizedSno,
                    $normalizedKeyword,
                    null,
                    'CLIENT_MISSING_SNO',
                    'Parameter sno is required.'
                );
            }

            if ($normalizedKeyword === '') {
                return $this->failureResult(
                    0,
                    $normalizedSno,
                    $normalizedKeyword,
                    $traceId !== null ? trim($traceId) : null,
                    'CLIENT_MISSING_KEYWORD',
                    'Parameter keyword is required.'
                );
            }

            $page = max(1, $page);
            $pageSize = max(1, min(100, $pageSize));
            $tid = $traceId !== null ? trim($traceId) : '';

            $url = $this->buildRequestUrl($normalizedSno, $normalizedKeyword, $page, $pageSize, $tid !== '' ? $tid : null);
            $headers = [];
            if ($tid !== '') {
                $headers['X-Trace-Id'] = $tid;
            }

            $transport = $this->transport ?? [$this, 'defaultTransport'];
            $raw = $transport($url, $headers, $this->timeoutSeconds);

            if (!is_array($raw)) {
                return $this->failureResult(
                    0,
                    $normalizedSno,
                    $normalizedKeyword,
                    $tid !== '' ? $tid : null,
                    'CLIENT_TRANSPORT_INVALID',
                    'HTTP transport returned invalid response.'
                );
            }

            $transportError = isset($raw['transport_error']) ? (string) $raw['transport_error'] : '';
            if ($transportError !== '') {
                return $this->failureResult(
                    0,
                    $normalizedSno,
                    $normalizedKeyword,
                    $tid !== '' ? $tid : null,
                    'CLIENT_HTTP_TIMEOUT',
                    'HTTP request failed or timed out.'
                );
            }

            $httpStatus = (int) ($raw['http_status'] ?? 0);
            $body = isset($raw['body']) && is_string($raw['body']) ? $raw['body'] : '';

            if ($body === '') {
                return $this->failureResult(
                    $httpStatus,
                    $normalizedSno,
                    $normalizedKeyword,
                    $tid !== '' ? $tid : null,
                    'CLIENT_EMPTY_RESPONSE',
                    'Empty HTTP response body.'
                );
            }

            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                return $this->failureResult(
                    $httpStatus,
                    $normalizedSno,
                    $normalizedKeyword,
                    $tid !== '' ? $tid : null,
                    'CLIENT_JSON_PARSE_FAILED',
                    'Response is not valid JSON.'
                );
            }

            return $this->mapApiPayload($decoded, $httpStatus, $normalizedSno, $normalizedKeyword, $tid !== '' ? $tid : null);
        } catch (\Throwable $e) {
            return $this->failureResult(
                0,
                trim($sno),
                trim($keyword),
                $traceId !== null ? trim($traceId) : null,
                'CLIENT_INTERNAL_ERROR',
                'Tour search API client failed.'
            );
        }
    }

    public function buildRequestUrl(
        string $sno,
        string $keyword,
        int $page = 1,
        int $pageSize = 5,
        ?string $traceId = null
    ): string {
        $query = [
            'sno' => trim($sno),
            'keyword' => trim($keyword),
            'page' => (string) max(1, $page),
            'pageSize' => (string) max(1, min(100, $pageSize)),
        ];

        $tid = $traceId !== null ? trim($traceId) : '';
        if ($tid !== '') {
            $query['traceId'] = $tid;
        }

        $separator = strpos($this->baseUrl, '?') === false ? '?' : '&';

        return $this->baseUrl . $separator . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, string> $headers
     * @return array{ok: bool, http_status: int, body: string, transport_error: string|null}
     */
    private function defaultTransport(string $url, array $headers, int $timeoutSeconds): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'ok' => false,
                'http_status' => 0,
                'body' => '',
                'transport_error' => 'curl_init_failed',
            ];
        }

        $headerLines = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => $headerLines,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return [
                'ok' => false,
                'http_status' => $httpStatus,
                'body' => is_string($body) ? $body : '',
                'transport_error' => $err !== '' ? $err : 'curl_error_' . $errno,
            ];
        }

        return [
            'ok' => true,
            'http_status' => $httpStatus,
            'body' => is_string($body) ? $body : '',
            'transport_error' => null,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function mapApiPayload(
        array $payload,
        int $httpStatus,
        string $sno,
        string $keyword,
        ?string $fallbackTraceId
    ): array {
        $apiSuccess = ($payload['success'] ?? false) === true;
        $traceId = isset($payload['traceId']) && is_string($payload['traceId']) && $payload['traceId'] !== ''
            ? $payload['traceId']
            : $fallbackTraceId;

        $pagination = $payload['pagination'] ?? null;
        if (!is_array($pagination)) {
            $pagination = null;
        }

        $items = $payload['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        $normalizedItems = [];
        foreach ($items as $row) {
            if (is_array($row)) {
                $normalizedItems[] = $row;
            }
        }

        $searchUrl = isset($payload['search_url']) && is_string($payload['search_url'])
            ? $payload['search_url']
            : null;

        if (!$apiSuccess) {
            $err = $payload['error'] ?? null;
            $code = 'API_ERROR';
            $message = 'Tour search API returned failure.';
            if (is_array($err)) {
                if (isset($err['code']) && is_string($err['code']) && $err['code'] !== '') {
                    $code = $err['code'];
                }
                if (isset($err['message']) && is_string($err['message']) && $err['message'] !== '') {
                    $message = $err['message'];
                }
            }

            return $this->failureResult($httpStatus, $sno, $keyword, $traceId, $code, $message, $pagination, $normalizedItems, $searchUrl);
        }

        return [
            'success' => true,
            'http_status' => $httpStatus,
            'traceId' => $traceId,
            'sno' => isset($payload['sno']) && is_string($payload['sno']) ? $payload['sno'] : $sno,
            'keyword' => isset($payload['keyword']) && is_string($payload['keyword']) ? $payload['keyword'] : $keyword,
            'pagination' => $pagination,
            'items' => $normalizedItems,
            'search_url' => $searchUrl,
            'error' => null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>|null $pagination
     * @return array<string, mixed>
     */
    private function failureResult(
        int $httpStatus,
        string $sno,
        string $keyword,
        ?string $traceId,
        string $errorCode,
        string $errorMessage,
        ?array $pagination = null,
        array $items = [],
        ?string $searchUrl = null
    ): array {
        return [
            'success' => false,
            'http_status' => $httpStatus,
            'traceId' => $traceId,
            'sno' => $sno,
            'keyword' => $keyword,
            'pagination' => $pagination,
            'items' => $items,
            'search_url' => $searchUrl,
            'error' => [
                'code' => $errorCode,
                'message' => $errorMessage,
            ],
        ];
    }
}
