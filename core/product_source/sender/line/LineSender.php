<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'LineMessagePayload.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'LineMessagePayloadValidator.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'LineSenderResult.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'LineSenderResultValidator.php';

/**
 * LINE sender contract skeleton (Phase 9-B-25).
 *
 * LineMessagePayload → LineSenderResult. No HTTP, curl, or LINE API calls.
 */
final class LineSender
{
    private LineMessagePayloadValidator $payloadValidator;

    private LineSenderResultValidator $resultValidator;

    public function __construct(
        ?LineMessagePayloadValidator $payloadValidator = null,
        ?LineSenderResultValidator $resultValidator = null
    ) {
        $this->payloadValidator = $payloadValidator ?? new LineMessagePayloadValidator();
        $this->resultValidator = $resultValidator ?? new LineSenderResultValidator();
    }

    /**
     * Prepares a sender result from payload without network I/O.
     *
     * @param array<string, mixed> $sendContext sender_type, trace_id
     */
    public function prepare(LineMessagePayload $payload, array $sendContext = []): LineSenderResult
    {
        $payloadDocument = $payload->toArray();
        $this->payloadValidator->validate($payloadDocument);

        $senderType = isset($sendContext['sender_type'])
            ? strtolower(trim((string) $sendContext['sender_type']))
            : 'reply';

        if (!in_array($senderType, LineSenderResult::SENDER_TYPES, true)) {
            throw new \InvalidArgumentException(
                'Unsupported sender_type: ' . $senderType
            );
        }

        $traceId = isset($sendContext['trace_id'])
            ? trim((string) $sendContext['trace_id'])
            : $this->generateTraceId();

        if ($traceId === '') {
            throw new \InvalidArgumentException('trace_id must not be empty');
        }

        $encoded = json_encode($payloadDocument, JSON_UNESCAPED_UNICODE);
        $payloadSize = is_string($encoded) ? strlen($encoded) : 0;
        $messageCount = count($payload->getMessages());

        return $this->resultValidator->validate([
            'success' => true,
            'sender_type' => $senderType,
            'message_count' => $messageCount,
            'payload_size' => $payloadSize,
            'trace_id' => $traceId,
        ]);
    }

    /**
     * @param array<string, mixed> $sendContext
     */
    public function prepareFromArray(array $payloadDocument, array $sendContext = []): LineSenderResult
    {
        $payload = $this->payloadValidator->validate($payloadDocument);

        return $this->prepare($payload, $sendContext);
    }

    private function generateTraceId(): string
    {
        return 'line-' . bin2hex(random_bytes(8));
    }
}
