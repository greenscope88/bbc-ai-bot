<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'TourSearchRequestBuilder.php';

/**
 * MVP dry-run adapter: simulates Host B proxy assembly for tour.search without HTTP, SQL, or audit persistence.
 */
final class TourSearchDryRunProxy
{
    /**
     * @param array<string, mixed> $clientParams
     * @param array<string, mixed> $tenantContext
     * @return array{
     *     service: string,
     *     method: string,
     *     path: string,
     *     timeout: int,
     *     query: array<string, string|int>,
     *     traceId: string,
     *     dryRun: bool,
     *     httpSent: bool
     * }
     */
    public static function dryRun(array $clientParams, array $tenantContext, string $traceId): array
    {
        $tid = trim($traceId);
        if ($tid === '') {
            throw new InvalidArgumentException('traceId must be a non-empty string.');
        }

        $built = TourSearchRequestBuilder::build($clientParams, $tenantContext);

        return array_merge($built, [
            'traceId' => $tid,
            'dryRun' => true,
            'httpSent' => false,
        ]);
    }
}
