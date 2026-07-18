<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'LineMessagePayload.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'ReplyType.php';

/**
 * Validates LINE text-only message payloads (Phase 9-B-22).
 */
final class LineMessagePayloadValidator
{
    /** @var list<string> */
    private const FORBIDDEN_TOP_LEVEL_KEYS = [
        'replyToken',
        'reply_token',
        'accessToken',
        'access_token',
        'endpoint',
        'headers',
    ];

    /** @var list<string> */
    private const FORBIDDEN_NESTED_KEYS = [
        'template',
        'hero',
        'footer',
    ];

    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    public function collectViolations(array $document): array
    {
        $violations = [];

        foreach (self::FORBIDDEN_TOP_LEVEL_KEYS as $key) {
            if (array_key_exists($key, $document)) {
                $violations[] = 'forbidden top-level key: ' . $key;
            }
        }

        $violations = array_merge($violations, $this->collectForbiddenNestedKeys($document));

        if (!isset($document['messages'])) {
            $violations[] = 'messages is required';
        } elseif (!is_array($document['messages'])) {
            $violations[] = 'messages must be an array';
        } else {
            if ($document['messages'] === []) {
                $violations[] = 'messages must not be empty';
            }

            foreach ($document['messages'] as $index => $message) {
                if (!is_array($message)) {
                    $violations[] = 'messages[' . $index . '] must be an object';
                    continue;
                }

                $type = isset($message['type']) ? strtolower(trim((string) $message['type'])) : '';
                if ($type === '') {
                    $violations[] = 'messages[' . $index . '].type is required';
                } elseif ($type !== 'text') {
                    $violations[] = 'messages[' . $index . '].type must be text';
                }

                $text = isset($message['text']) ? trim((string) $message['text']) : '';
                if ($text === '') {
                    $violations[] = 'messages[' . $index . '].text is required';
                }
            }
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $document
     * @throws \InvalidArgumentException
     */
    public function validate(array $document): LineMessagePayload
    {
        $violations = $this->collectViolations($document);
        if ($violations !== []) {
            throw new \InvalidArgumentException(
                'Line message payload invalid (' . count($violations) . ' issue(s)): '
                . implode('; ', $violations)
            );
        }

        return LineMessagePayload::fromArray($document);
    }

    /**
     * @param array<string, mixed> $document
     */
    public function validateForReplyType(array $document, string $replyType): LineMessagePayload
    {
        $violations = $this->collectTransportViolations($document, $replyType);
        if ($violations !== []) {
            throw new \InvalidArgumentException(
                'Line message payload invalid for reply_type ' . $replyType . ' ('
                . count($violations) . ' issue(s)): ' . implode('; ', $violations)
            );
        }

        return LineMessagePayload::fromArray($document);
    }

    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    private function collectTransportViolations(array $document, string $replyType): array
    {
        $messages = isset($document['messages']) && is_array($document['messages']) ? $document['messages'] : [];
        if ($messages === []) {
            return ['messages must not be empty'];
        }

        if ($replyType === ReplyType::NORMAL) {
            $count = count($messages);
            if (!in_array($count, [2, 3], true)) {
                return ['normal reply requires two or three messages'];
            }
            if (!is_array($messages[0]) || (($messages[0]['type'] ?? '') !== 'text')) {
                return ['normal reply first message must be text'];
            }
            if ($count === 3 && (!is_array($messages[2]) || (($messages[2]['type'] ?? '') !== 'text'))) {
                return ['normal reply third message must be text'];
            }
            $hasFlex = false;
            foreach ($messages as $message) {
                if (is_array($message) && ($message['type'] ?? '') === 'flex') {
                    $hasFlex = true;
                }
            }
            if (!$hasFlex && $count !== 2) {
                return ['other-source-only normal reply requires exactly two text messages'];
            }

            return [];
        }

        if ($replyType === ReplyType::NO_RESULTS || $replyType === ReplyType::CLARIFICATION) {
            if (count($messages) !== 1 || !is_array($messages[0]) || (($messages[0]['type'] ?? '') !== 'text')) {
                return ['safe reply requires exactly one text message'];
            }

            return [];
        }

        return [];
    }

    /**
     * @param mixed $value
     * @param list<string> $path
     * @return list<string>
     */
    private function collectForbiddenNestedKeys($value, array $path = []): array
    {
        $violations = [];

        if (!is_array($value)) {
            return $violations;
        }

        foreach ($value as $key => $nested) {
            $keyLabel = (string) $key;

            if (is_string($key)) {
                $lowerKey = strtolower($key);
                if (in_array($lowerKey, self::FORBIDDEN_NESTED_KEYS, true)) {
                    $violations[] = 'forbidden key: ' . implode('.', array_merge($path, [$keyLabel]));
                }

                if ($lowerKey === 'type' && is_string($nested)) {
                    $lowerValue = strtolower(trim($nested));
                    if (in_array($lowerValue, ['flex', 'template', 'bubble'], true)) {
                        $violations[] = 'forbidden message type: ' . implode('.', array_merge($path, [$keyLabel]));
                    }
                }
            }

            if (is_array($nested)) {
                $violations = array_merge(
                    $violations,
                    $this->collectForbiddenNestedKeys($nested, array_merge($path, [$keyLabel]))
                );
            }
        }

        return $violations;
    }
}
