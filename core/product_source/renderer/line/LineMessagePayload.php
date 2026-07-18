<?php
declare(strict_types=1);

/**
 * LINE Messaging API messages[] payload contract (Phase 9-B-22 text MVP).
 *
 * Must not contain replyToken, access tokens, endpoints, or HTTP headers.
 */
final class LineMessagePayload
{
    /** @var list<array<string, mixed>> */
    private array $messages;

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function __construct(array $messages)
    {
        $this->messages = $messages;
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function fromArray(array $document): self
    {
        $messages = [];
        if (isset($document['messages']) && is_array($document['messages'])) {
            foreach ($document['messages'] as $message) {
                if (is_array($message)) {
                    $messages[] = $message;
                }
            }
        }

        return new self($messages);
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public static function fromMessages(array $messages): self
    {
        $normalized = [];
        foreach ($messages as $message) {
            if (is_array($message)) {
                $normalized[] = $message;
            }
        }

        return new self($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'messages' => $this->messages,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
}
