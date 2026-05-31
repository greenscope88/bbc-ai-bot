<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'LineSenderResult.php';

/**
 * Validates LINE sender result documents (Phase 9-B-25).
 */
final class LineSenderResultValidator
{
    /** @var list<string> */
    private const FORBIDDEN_TOP_LEVEL_KEYS = [
        'http',
        'curl',
        'endpoint',
        'headers',
        'status_code',
        'response_body',
        'replyToken',
        'reply_token',
        'accessToken',
        'access_token',
        'api_key',
        'apiKey',
    ];

    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    public function collectViolations(array $document): array
    {
        $violations = [];
        $normalized = LineSenderResult::normalizeDocument($document);

        foreach (self::FORBIDDEN_TOP_LEVEL_KEYS as $key) {
            if (array_key_exists($key, $document)) {
                $violations[] = 'forbidden top-level key: ' . $key;
            }
        }

        if (!array_key_exists('success', $document)) {
            $violations[] = 'success is required';
        }

        if ($normalized['sender_type'] === '') {
            $violations[] = 'sender_type is required';
        } elseif (!in_array($normalized['sender_type'], LineSenderResult::SENDER_TYPES, true)) {
            $violations[] = 'invalid sender_type';
        }

        if (!array_key_exists('message_count', $document)) {
            $violations[] = 'message_count is required';
        } elseif ($normalized['message_count'] < 0) {
            $violations[] = 'message_count must be zero or positive';
        }

        if (!array_key_exists('payload_size', $document)) {
            $violations[] = 'payload_size is required';
        } elseif ($normalized['payload_size'] < 0) {
            $violations[] = 'payload_size must be zero or positive';
        }

        if ($normalized['trace_id'] === '') {
            $violations[] = 'trace_id is required';
        }

        if ($normalized['success'] && $normalized['message_count'] === 0) {
            $violations[] = 'success=true requires message_count > 0';
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $document
     * @throws \InvalidArgumentException
     */
    public function validate(array $document): LineSenderResult
    {
        $violations = $this->collectViolations($document);
        if ($violations !== []) {
            throw new \InvalidArgumentException(
                'Line sender result invalid (' . count($violations) . ' issue(s)): '
                . implode('; ', $violations)
            );
        }

        return LineSenderResult::fromArray($document);
    }
}
