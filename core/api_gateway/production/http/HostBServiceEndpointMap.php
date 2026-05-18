<?php
declare(strict_types=1);

/**
 * Gateway service id → relative path (no host). Paths are placeholders until Host B Swagger is frozen.
 */
final class HostBServiceEndpointMap
{
    /** @var array<string, string> */
    private static $paths = [
        'tour.search' => '/api/tour/search',
        'order.query' => '/api/order/query',
    ];

    public static function relativePath(string $gatewayService): string
    {
        if (!isset(self::$paths[$gatewayService])) {
            throw new InvalidArgumentException('Unknown gateway service for Host B mapping.');
        }

        return self::$paths[$gatewayService];
    }
}
