<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'LineMessagePayload.php';

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
        'flex',
        'template',
        'bubble',
        'hero',
        'body',
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
