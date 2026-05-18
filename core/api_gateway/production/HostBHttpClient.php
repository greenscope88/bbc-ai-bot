<?php

declare(strict_types=1);



/**

 * MVP staging-only GET client for Host B (Step 10).

 *

 * NOTE: This file intentionally does NOT define global class `HostBHttpClient` because

 * `core/api_gateway/production/http/HostBHttpClient.php` already provides the Phase 6 skeleton

 * implementing `HostBHttpClientInterface`. This class is a separate, staging-oriented entry point.

 *

 * `get()` remains a mock-only envelope for legacy callers.

 * `sendGet()` performs real GET when not in dry-run mode (see MVP_STAGING_HOSTB_HTTP_DRY_RUN env).

 */

final class MvpStagingHostBHttpClient

{

    private const MIN_TIMEOUT = 1;

    private const MAX_TIMEOUT = 30;



    /** When truthy (e.g. "1"), `sendGet` builds the public request URL only and skips socket I/O. */

    private const DRY_RUN_ENV = 'MVP_STAGING_HOSTB_HTTP_DRY_RUN';



    /**

     * Staging-oriented GET request shape (mock-only in Step 10 — no socket I/O).

     *

     * @param array<string, string|int|float> $query Query parameters (caller must enforce allowlists).

     * @return array{httpStatus: int, body: string, error: string, durationMs: int}

     */

    public static function get(string $baseUrl, string $path, array $query, int $timeout): array

    {

        $started = microtime(true);

        self::assertBaseUrl($baseUrl);

        self::assertPath($path);

        self::assertTimeout($timeout);

        self::assertQueryScalar($query);



        $durationMs = (int) max(0, round((microtime(true) - $started) * 1000));



        return [

            'httpStatus' => 0,

            'body' => '',

            'error' => 'MVP Step 10: real HTTP not executed (mock-only staging client).',

            'durationMs' => $durationMs,

        ];

    }



    /**

     * Staging outbound GET toward Host B. Caller supplies URL parts and headers (e.g. from

     * TourSearchRequestBuilder + HostBOutboundHeaderBuilder). Does not execute SQL.

     *

     * When {@see self::DRY_RUN_ENV} is truthy, no network I/O is performed (tests / local wiring).

     *

     * @param array<string, string|int|float> $query

     * @param array<string, string> $headers Header name => value (ASCII); forbidden names are stripped.

     * @return array{httpStatus: int, body: string|null, error: string|null, durationMs: int, requestUrl: string}

     */

    public static function sendGet(string $baseUrl, string $path, array $query, array $headers, int $timeout): array

    {

        $started = microtime(true);

        self::assertBaseUrl($baseUrl);

        self::assertPath($path);

        self::assertTimeout($timeout);

        self::assertQueryScalar($query);

        $safeHeaders = self::sanitizeOutboundHeaders($headers);



        $publicQuery = self::stripSensitiveQueryKeysForUrl($query);

        $requestUrl = self::buildPublicRequestUrl($baseUrl, $path, $publicQuery);

        self::assertRequestUrlDoesNotContainApiKeyMaterial($requestUrl, $safeHeaders);



        if (self::isHttpDryRun()) {

            $durationMs = (int) max(0, round((microtime(true) - $started) * 1000));



            return [

                'httpStatus' => 0,

                'body' => null,

                'error' => 'MVP_STAGING_HTTP_DRY_RUN',

                'durationMs' => $durationMs,

                'requestUrl' => $requestUrl,

            ];

        }



        $headerBlock = self::flattenHeadersForTransport($safeHeaders);

        $ctx = stream_context_create([

            'http' => [

                'method' => 'GET',

                'header' => $headerBlock,

                'timeout' => $timeout,

                'ignore_errors' => true,

            ],

        ]);



        $prior = error_get_last();

        $body = @file_get_contents($requestUrl, false, $ctx);

        $durationMs = (int) max(0, round((microtime(true) - $started) * 1000));



        $httpStatus = 0;

        if (isset($http_response_header) && is_array($http_response_header) && $http_response_header !== []) {

            $httpStatus = self::parseStatusFromHeaders($http_response_header);

        }



        if ($body === false) {

            $err = error_get_last();

            $msg = 'http_transport_failed';

            if ($err !== $prior && isset($err['message']) && is_string($err['message'])) {

                $msg = self::redactTransportErrorMessage($err['message']);

            }



            return [

                'httpStatus' => $httpStatus > 0 ? $httpStatus : 0,

                'body' => null,

                'error' => $msg,

                'durationMs' => $durationMs,

                'requestUrl' => $requestUrl,

            ];

        }



        return [

            'httpStatus' => $httpStatus > 0 ? $httpStatus : 200,

            'body' => $body,

            'error' => null,

            'durationMs' => $durationMs,

            'requestUrl' => $requestUrl,

        ];

    }



    private static function isHttpDryRun(): bool

    {

        $v = getenv(self::DRY_RUN_ENV);

        if ($v === false || $v === '') {

            return false;

        }



        return filter_var($v, FILTER_VALIDATE_BOOLEAN);

    }



    /**

     * @param array<string, string> $headers

     * @return array<string, string>

     */

    private static function sanitizeOutboundHeaders(array $headers): array

    {

        $out = [];

        foreach ($headers as $name => $value) {

            if (!is_string($name) || trim($name) === '') {

                throw new InvalidArgumentException('header names must be non-empty strings.');

            }

            if (!is_string($value)) {

                throw new InvalidArgumentException('header values must be strings.');

            }

            $ln = strtolower($name);

            if ($ln === 'x-bbc-api-key' || $ln === 'authorization') {

                continue;

            }

            $out[$name] = $value;

        }



        return $out;

    }



    /**

     * @param array<string, string> $headers

     */

    private static function flattenHeadersForTransport(array $headers): string

    {

        $lines = [];

        foreach ($headers as $name => $value) {

            $lines[] = trim($name) . ': ' . $value;

        }



        return $lines === [] ? '' : implode("\r\n", $lines) . "\r\n";

    }



    /**

     * @param array<string, string|int|float> $query

     * @return array<string, string|int|float>

     */

    private static function stripSensitiveQueryKeysForUrl(array $query): array

    {

        $out = [];

        foreach ($query as $k => $v) {

            if (!is_string($k)) {

                continue;

            }

            $lk = strtolower($k);

            if ($lk === 'api_key' || $lk === 'x-api-key' || $lk === 'authorization') {

                continue;

            }

            $out[$k] = $v;

        }



        return $out;

    }



    /**

     * @param array<string, string|int|float> $query

     */

    private static function buildPublicRequestUrl(string $baseUrl, string $path, array $query): string

    {

        $base = rtrim(trim($baseUrl), '/');

        $rel = $path === '' ? '/' : $path;

        $url = $base . $rel;

        if ($query !== []) {

            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        }



        return $url;

    }



    /**

     * @param array<string, string> $headers

     */

    private static function assertRequestUrlDoesNotContainApiKeyMaterial(string $requestUrl, array $headers): void

    {

        foreach ($headers as $name => $value) {

            if (!is_string($name) || !is_string($value) || $value === '') {

                continue;

            }

            if (strtolower($name) !== 'x-api-key') {

                continue;

            }

            if (strlen($value) >= 4 && strpos($requestUrl, $value) !== false) {

                throw new RuntimeException('Internal error: X-API-Key material must not appear in requestUrl.');

            }

        }

    }



    /**

     * @param array<int, string> $headers

     */

    private static function parseStatusFromHeaders(array $headers): int

    {

        $first = $headers[0] ?? '';

        if (!is_string($first) || !preg_match('#^HTTP/\S+\s+(\d{3})#', $first, $m)) {

            return 0;

        }



        return (int) $m[1];

    }



    private static function redactTransportErrorMessage(string $message): string

    {

        $lower = strtolower($message);

        if (strpos($lower, 'x-api-key') !== false || strpos($lower, 'authorization') !== false) {

            return 'http_transport_failed';

        }



        return 'http_transport_failed';

    }



    private static function assertBaseUrl(string $baseUrl): void

    {

        $trim = trim($baseUrl);

        if ($trim === '') {

            throw new InvalidArgumentException('baseUrl must not be empty.');

        }

        if (filter_var($trim, FILTER_VALIDATE_URL) === false) {

            throw new InvalidArgumentException('baseUrl must be a valid http(s) URL.');

        }

        $scheme = parse_url($trim, PHP_URL_SCHEME);

        if (!is_string($scheme)) {

            throw new InvalidArgumentException('baseUrl must include a valid scheme.');

        }

        $ls = strtolower($scheme);

        if ($ls !== 'http' && $ls !== 'https') {

            throw new InvalidArgumentException('baseUrl must use http or https scheme.');

        }

        if (strpos($trim, "\n") !== false || strpos($trim, "\r") !== false) {

            throw new InvalidArgumentException('baseUrl must not contain newline characters.');

        }

    }



    private static function assertPath(string $path): void

    {

        if ($path === '') {

            throw new InvalidArgumentException('path must not be empty.');

        }

        if ($path[0] !== '/') {

            throw new InvalidArgumentException('path must start with "/".');

        }

        if (strpos($path, '://') !== false) {

            throw new InvalidArgumentException('path must not be a full URL.');

        }

    }



    private static function assertTimeout(int $timeout): void

    {

        if ($timeout < self::MIN_TIMEOUT || $timeout > self::MAX_TIMEOUT) {

            throw new InvalidArgumentException(

                'timeout must be between ' . self::MIN_TIMEOUT . ' and ' . self::MAX_TIMEOUT . ' seconds.'

            );

        }

    }



    /**

     * @param array<string, string|int|float> $query

     */

    private static function assertQueryScalar(array $query): void

    {

        foreach ($query as $k => $v) {

            if (!is_string($k) || $k === '') {

                throw new InvalidArgumentException('query keys must be non-empty strings.');

            }

            if (!is_string($v) && !is_int($v) && !is_float($v)) {

                throw new InvalidArgumentException('query values must be scalar string or number.');

            }

        }

    }

}

