<?php
declare(strict_types=1);

/**
 * Maps upstream HTTP status to gateway-facing error codes (no body parsing).
 */
final class UpstreamErrorMapper
{
    /**
     * @return array{httpStatus: int, errorCode: string, message: string}
     */
    public static function map(int $upstreamHttpStatus): array
    {
        if ($upstreamHttpStatus >= 200 && $upstreamHttpStatus < 300) {
            return [
                'httpStatus' => $upstreamHttpStatus,
                'errorCode' => 'OK',
                'message' => 'Success',
            ];
        }

        if ($upstreamHttpStatus === 401) {
            return ['httpStatus' => 502, 'errorCode' => 'UPSTREAM_UNAUTHORIZED', 'message' => 'Upstream rejected credentials.'];
        }
        if ($upstreamHttpStatus === 403) {
            return ['httpStatus' => 502, 'errorCode' => 'UPSTREAM_FORBIDDEN', 'message' => 'Upstream denied access.'];
        }
        if ($upstreamHttpStatus === 404) {
            return ['httpStatus' => 502, 'errorCode' => 'UPSTREAM_NOT_FOUND', 'message' => 'Upstream resource not found.'];
        }
        if ($upstreamHttpStatus === 429) {
            return ['httpStatus' => 503, 'errorCode' => 'UPSTREAM_RATE_LIMITED', 'message' => 'Upstream rate limited.'];
        }
        if ($upstreamHttpStatus >= 500 && $upstreamHttpStatus < 600) {
            return ['httpStatus' => 502, 'errorCode' => 'UPSTREAM_SERVER_ERROR', 'message' => 'Upstream server error.'];
        }

        return ['httpStatus' => 502, 'errorCode' => 'UPSTREAM_ERROR', 'message' => 'Upstream request failed.'];
    }
}
