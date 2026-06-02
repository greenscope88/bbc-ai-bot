<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'LineMessagePayload.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'LineMessagePayloadValidator.php';

/**
 * LINE transport result (Phase 9-B-26B-3).
 *
 * Dry-run outcome before LineService HTTP. Must not contain API responses or credentials.
 */
final class TransportResult
{
    public const SCHEMA_VERSION = 1;

    /** @var list<string> */
    public const TRANSPORT_MODES = ['reply', 'push'];

    private bool $success;

    private int $messageCount;

    private int $payloadSize;

    private string $transportMode;

    private string $traceId;

    private int $schemaVersion;

    private function __construct(
        bool $success,
        int $messageCount,
        int $payloadSize,
        string $transportMode,
        string $traceId,
        int $schemaVersion
    ) {
        $this->success = $success;
        $this->messageCount = $messageCount;
        $this->payloadSize = $payloadSize;
        $this->transportMode = $transportMode;
        $this->traceId = $traceId;
        $this->schemaVersion = $schemaVersion;
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function fromArray(array $document): self
    {
        $normalized = self::normalizeDocument($document);

        return new self(
            (bool) $normalized['success'],
            (int) $normalized['message_count'],
            (int) $normalized['payload_size'],
            (string) $normalized['transport_mode'],
            (string) $normalized['trace_id'],
            (int) $normalized['schema_version']
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'success' => $this->success,
            'message_count' => $this->messageCount,
            'payload_size' => $this->payloadSize,
            'transport_mode' => $this->transportMode,
            'trace_id' => $this->traceId,
        ];
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessageCount(): int
    {
        return $this->messageCount;
    }

    public function getPayloadSize(): int
    {
        return $this->payloadSize;
    }

    public function getTransportMode(): string
    {
        return $this->transportMode;
    }

    public function getTraceId(): string
    {
        return $this->traceId;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function normalizeDocument(array $document): array
    {
        $out = [];

        $out['schema_version'] = isset($document['schema_version'])
            ? (int) $document['schema_version']
            : self::SCHEMA_VERSION;
        $out['success'] = isset($document['success']) && (bool) $document['success'];
        $out['message_count'] = isset($document['message_count'])
            ? (int) $document['message_count']
            : 0;
        $out['payload_size'] = isset($document['payload_size'])
            ? (int) $document['payload_size']
            : 0;
        $out['transport_mode'] = isset($document['transport_mode'])
            ? strtolower(trim((string) $document['transport_mode']))
            : '';
        $out['trace_id'] = isset($document['trace_id'])
            ? trim((string) $document['trace_id'])
            : '';

        return $out;
    }
}

/**
 * LINE transport adapter skeleton (Phase 9-B-26B-3).
 *
 * LineMessagePayload → TransportResult. No HTTP, curl, or LINE API calls.
 */
final class LineTransport
{
    /** @var list<string> */
    private const FORBIDDEN_RESULT_KEYS = [
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
    ];

    private LineMessagePayloadValidator $payloadValidator;

    public function __construct(?LineMessagePayloadValidator $payloadValidator = null)
    {
        $this->payloadValidator = $payloadValidator ?? new LineMessagePayloadValidator();
    }

    /**
     * @param array<string, mixed> $context trace_id
     */
    public function prepareReply(
        LineMessagePayload $payload,
        string $replyToken,
        array $context = []
    ): TransportResult {
        if (trim($replyToken) === '') {
            throw new \InvalidArgumentException('replyToken is required for reply transport');
        }

        return $this->prepare($payload, TransportResult::TRANSPORT_MODES[0], $context);
    }

    /**
     * @param array<string, mixed> $context trace_id
     */
    public function preparePush(LineMessagePayload $payload, array $context = []): TransportResult
    {
        return $this->prepare($payload, 'push', $context);
    }

    /**
     * @param array<string, mixed> $context transport_mode, trace_id, replyToken (reply only)
     */
    public function simulateSend(LineMessagePayload $payload, array $context = []): TransportResult
    {
        $mode = isset($context['transport_mode'])
            ? strtolower(trim((string) $context['transport_mode']))
            : 'reply';

        if ($mode === 'reply') {
            $replyToken = isset($context['reply_token'])
                ? trim((string) $context['reply_token'])
                : (isset($context['replyToken']) ? trim((string) $context['replyToken']) : '');

            if ($replyToken === '') {
                throw new \InvalidArgumentException('replyToken is required for reply simulateSend');
            }

            return $this->prepareReply($payload, $replyToken, $context);
        }

        return $this->preparePush($payload, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function prepare(
        LineMessagePayload $payload,
        string $transportMode,
        array $context
    ): TransportResult {
        if (!in_array($transportMode, TransportResult::TRANSPORT_MODES, true)) {
            throw new \InvalidArgumentException('Unsupported transport_mode: ' . $transportMode);
        }

        $payloadDocument = $payload->toArray();
        $this->payloadValidator->validate($payloadDocument);

        $traceId = isset($context['trace_id'])
            ? trim((string) $context['trace_id'])
            : $this->generateTraceId();

        if ($traceId === '') {
            throw new \InvalidArgumentException('trace_id must not be empty');
        }

        $encoded = json_encode($payloadDocument, JSON_UNESCAPED_UNICODE);
        $payloadSize = is_string($encoded) ? strlen($encoded) : 0;
        $messageCount = count($payload->getMessages());

        $resultDocument = [
            'success' => true,
            'message_count' => $messageCount,
            'payload_size' => $payloadSize,
            'transport_mode' => $transportMode,
            'trace_id' => $traceId,
        ];

        $violations = $this->collectResultViolations($resultDocument);
        if ($violations !== []) {
            throw new \InvalidArgumentException(
                'Transport result invalid (' . count($violations) . ' issue(s)): '
                . implode('; ', $violations)
            );
        }

        return TransportResult::fromArray($resultDocument);
    }

    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    public function collectResultViolations(array $document): array
    {
        $violations = [];

        foreach (self::FORBIDDEN_RESULT_KEYS as $key) {
            if (array_key_exists($key, $document)) {
                $violations[] = 'forbidden key: ' . $key;
            }
        }

        $normalized = TransportResult::normalizeDocument($document);

        if (!array_key_exists('success', $document)) {
            $violations[] = 'success is required';
        }

        if ($normalized['transport_mode'] === '') {
            $violations[] = 'transport_mode is required';
        } elseif (!in_array($normalized['transport_mode'], TransportResult::TRANSPORT_MODES, true)) {
            $violations[] = 'invalid transport_mode';
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

    private function generateTraceId(): string
    {
        return 'line-transport-' . bin2hex(random_bytes(8));
    }
}
