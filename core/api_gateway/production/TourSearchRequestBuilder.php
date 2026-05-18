<?php

declare(strict_types=1);



require_once __DIR__ . DIRECTORY_SEPARATOR . 'ServiceRegistry.php';



/**

 * MVP request builder: maps allowlisted client params to Host B `tour.search` query shape.

 * Internal tenantContext (depID, storeNo, store_uid, provider_id_no) is validated for Host A only —

 * it MUST NOT be merged into the Host B query (Host B uses `sno` + TenantResolver per contract).

 * Does not perform HTTP, SQL, or API key / header handling.

 */

final class TourSearchRequestBuilder

{

    private const SERVICE_NAME = 'tour.search';



    /** @var list<string> Query keys forwarded toward Host B `/api/tour/search` (allowlist). */

    private const HOST_B_QUERY_ALLOWLIST = [

        'sno',

        'keyword',

        'destination',

        'country',

        'city',

        'dateFrom',

        'dateTo',

        'page',

        'pageSize',

    ];



    /** @var list<string> Required on tenantContext for Host A internal validation only (not sent upstream). */

    private const TENANT_INTERNAL_REQUIRED_KEYS = [

        'depID',

        'storeNo',

        'store_uid',

        'provider_id_no',

    ];



    /** @var list<string> Client param keys that must never appear on Host B query (case-insensitive match on key). */

    private const CLIENT_FORBIDDEN_KEYS_LOWER = [

        'depid',

        'storeno',

        'store_uid',

        'provider_id_no',

        'sql',

        'table',

        'column',

        'where',

        'orderby',

    ];



    private const DEFAULT_PAGE = 1;

    private const DEFAULT_PAGE_SIZE = 20;

    private const MAX_PAGE_SIZE = 50;



    /**

     * @param array<string, mixed> $clientParams

     * @param array<string, mixed> $tenantContext Internal tenant context (validated, never merged as depID/storeNo/… into query)

     * @return array{service: string, method: string, path: string, timeout: int, query: array<string, string|int>}

     */

    public static function build(array $clientParams, array $tenantContext): array

    {

        $spec = ServiceRegistry::get(self::SERVICE_NAME);

        if ($spec === null) {

            throw new InvalidArgumentException('tour.search is not available in ServiceRegistry (missing or disabled).');

        }



        self::assertTenantContextForInternalValidation($tenantContext);



        $method = isset($spec['method']) ? (string) $spec['method'] : '';

        $path = isset($spec['path']) ? (string) $spec['path'] : '';

        if ($method === '' || $path === '') {

            throw new RuntimeException('tour.search registry entry is missing method or path.');

        }



        $timeout = isset($spec['timeout']) ? (int) $spec['timeout'] : 0;

        if ($timeout <= 0) {

            throw new RuntimeException('tour.search registry entry has invalid timeout.');

        }



        $query = [];



        foreach (self::HOST_B_QUERY_ALLOWLIST as $key) {

            if ($key === 'page' || $key === 'pageSize') {

                continue;

            }

            if (!array_key_exists($key, $clientParams)) {

                continue;

            }

            if (self::isForbiddenClientKey($key)) {

                continue;

            }

            $value = $clientParams[$key];

            if ($value === null) {

                continue;

            }

            if (!is_string($value) && !is_int($value) && !is_float($value)) {

                continue;

            }

            $s = trim((string) $value);

            if ($s === '') {

                continue;

            }

            $query[$key] = $s;

        }



        $query['page'] = self::normalizePage($clientParams);

        $query['pageSize'] = self::normalizePageSize($clientParams);



        self::injectAuthoritativeSno($query, $clientParams, $tenantContext);



        return [

            'service' => self::SERVICE_NAME,

            'method' => $method,

            'path' => $path,

            'timeout' => $timeout,

            'query' => $query,

        ];

    }



    /**

     * @param array<string, string|int> $query

     * @param array<string, mixed> $clientParams

     * @param array<string, mixed> $tenantContext

     */

    private static function injectAuthoritativeSno(array &$query, array $clientParams, array $tenantContext): void

    {

        if (isset($query['sno'])) {

            $v = trim((string) $query['sno']);

            if ($v !== '') {

                $query['sno'] = $v;



                return;

            }

            unset($query['sno']);

        }



        if (isset($tenantContext['sno'])) {

            $ts = $tenantContext['sno'];

            if (is_string($ts) || is_int($ts) || is_float($ts)) {

                $s = trim((string) $ts);

                if ($s !== '') {

                    $query['sno'] = $s;



                    return;

                }

            }

        }



        if (isset($clientParams['sno'])) {

            $cs = $clientParams['sno'];

            if (is_string($cs) || is_int($cs) || is_float($cs)) {

                $s = trim((string) $cs);

                if ($s !== '') {

                    $query['sno'] = $s;



                    return;

                }

            }

        }



        throw new InvalidArgumentException('Missing sno for Host B query (provide in clientParams or tenantContext).');

    }



    /**

     * @param array<string, mixed> $tenantContext

     */

    private static function assertTenantContextForInternalValidation(array $tenantContext): void

    {

        foreach (self::TENANT_INTERNAL_REQUIRED_KEYS as $key) {

            if (!array_key_exists($key, $tenantContext)) {

                throw new InvalidArgumentException('Missing tenant context key: ' . $key);

            }

            $v = $tenantContext[$key];

            if ($v === null || (is_string($v) && trim($v) === '')) {

                throw new InvalidArgumentException('Empty tenant context value for: ' . $key);

            }

            if (!is_string($v) && !is_int($v) && !is_float($v)) {

                throw new InvalidArgumentException('Invalid tenant context type for: ' . $key);

            }

        }

    }



    private static function isForbiddenClientKey(string $key): bool

    {

        $lower = strtolower($key);

        foreach (self::CLIENT_FORBIDDEN_KEYS_LOWER as $f) {

            if ($lower === $f) {

                return true;

            }

        }



        return false;

    }



    /**

     * @param array<string, mixed> $clientParams

     */

    private static function normalizePage(array $clientParams): int

    {

        if (!isset($clientParams['page'])) {

            return self::DEFAULT_PAGE;

        }

        $p = $clientParams['page'];

        if (!is_numeric($p)) {

            return self::DEFAULT_PAGE;

        }

        $n = (int) $p;

        if ($n < 1) {

            return self::DEFAULT_PAGE;

        }



        return $n;

    }



    /**

     * @param array<string, mixed> $clientParams

     */

    private static function normalizePageSize(array $clientParams): int

    {

        if (!isset($clientParams['pageSize'])) {

            return self::DEFAULT_PAGE_SIZE;

        }

        $p = $clientParams['pageSize'];

        if (!is_numeric($p)) {

            return self::DEFAULT_PAGE_SIZE;

        }

        $n = (int) $p;

        if ($n < 1) {

            return self::DEFAULT_PAGE_SIZE;

        }

        if ($n > self::MAX_PAGE_SIZE) {

            return self::MAX_PAGE_SIZE;

        }



        return $n;

    }

}

