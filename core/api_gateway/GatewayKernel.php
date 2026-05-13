<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'TraceIdMiddleware.php';

/**
 * API Gateway kernel skeleton (Phase 1). Not wired to the production entrypoint yet.
 *
 * TODO: When emitting HTTP responses, add response header X-Trace-Id mirroring $requestContext['traceId'].
 */
final class GatewayKernel
{
    /**
     * @param array<string, mixed> $requestContext
     * @return array<string, mixed>
     */
    public function execute(array $requestContext): array
    {
        TraceIdMiddleware::apply($requestContext);

        return $requestContext;
    }
}
