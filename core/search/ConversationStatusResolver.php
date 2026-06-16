<?php
declare(strict_types=1);

/**
 * Phase 9-C-1d-β2 conversation status resolver (AD-005 / AD-005A / AD-005B / AD-006).
 *
 * MVP store: in-memory (tests) or JSON files under runtime/conversation_status/.
 */
final class ConversationStatusResolver
{
    public const STATUS_AI_ACTIVE = 'AI_ACTIVE';
    public const STATUS_HUMAN_ACTIVE = 'HUMAN_ACTIVE';
    public const DEFAULT_TIMEOUT_MINUTES = 5;

    private ?string $storeDirectory;

    private int $timeoutMinutes;

    /** @var array<string, array<string, mixed>>|null */
    private $memoryStore;

    /**
     * @param array<string, array<string, mixed>>|null $memoryStore When set, uses in-memory store (tests).
     */
    public function __construct(
        ?string $storeDirectory = null,
        int $timeoutMinutes = self::DEFAULT_TIMEOUT_MINUTES,
        ?array &$memoryStore = null
    ) {
        $this->storeDirectory = $storeDirectory;
        $this->timeoutMinutes = $timeoutMinutes > 0 ? $timeoutMinutes : self::DEFAULT_TIMEOUT_MINUTES;
        $this->memoryStore = &$memoryStore;
    }

    /**
     * @param array<string, array<string, mixed>>|null $memoryStore
     */
    public static function createForTesting(?array &$memoryStore = null): self
    {
        if ($memoryStore === null) {
            $memoryStore = [];
        }

        return new self(null, self::DEFAULT_TIMEOUT_MINUTES, $memoryStore);
    }

    public static function defaultStoreDirectory(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'conversation_status';
    }

    public function getTimeoutMinutes(): int
    {
        return $this->timeoutMinutes;
    }

    public function resolveStatus(string $conversationId, ?\DateTimeImmutable $now = null): string
    {
        $state = $this->readState($conversationId);
        $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));
        if (!$this->isHumanTakeoverActive($state, $now)) {
            return self::STATUS_AI_ACTIVE;
        }

        return self::STATUS_HUMAN_ACTIVE;
    }

    public function isHumanActive(string $conversationId, ?\DateTimeImmutable $now = null): bool
    {
        return $this->resolveStatus($conversationId, $now) === self::STATUS_HUMAN_ACTIVE;
    }

    public function markHumanActive(string $conversationId, ?\DateTimeImmutable $timestamp = null): void
    {
        $timestamp = $timestamp ?? new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));
        $state = $this->readState($conversationId);
        $state['human_takeover_at'] = $timestamp->format(\DateTimeInterface::ATOM);
        $state['conversation_status'] = self::STATUS_HUMAN_ACTIVE;
        $this->writeState($conversationId, $state);
    }

    public function recordCustomerMessage(
        string $conversationId,
        string $message,
        ?\DateTimeImmutable $timestamp = null
    ): void {
        $this->appendMessage($conversationId, 'customer', $message, $timestamp);
    }

    public function recordHumanAgentMessage(
        string $conversationId,
        string $message,
        ?\DateTimeImmutable $timestamp = null
    ): void {
        $timestamp = $timestamp ?? new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));
        $this->appendMessage($conversationId, 'human_agent', $message, $timestamp);
        $this->markHumanActive($conversationId, $timestamp);
    }

    /**
     * @return list<array{role: string, message: string, recorded_at: string}>
     */
    public function getRecentMessages(string $conversationId, int $limit = 50): array
    {
        $state = $this->readState($conversationId);
        $messages = $state['messages'] ?? [];
        if (!is_array($messages)) {
            return [];
        }

        $normalized = [];
        foreach ($messages as $row) {
            if (!is_array($row)) {
                continue;
            }
            $role = isset($row['role']) ? trim((string) $row['role']) : '';
            $message = isset($row['message']) ? trim((string) $row['message']) : '';
            $recordedAt = isset($row['recorded_at']) ? trim((string) $row['recorded_at']) : '';
            if ($role === '' || $message === '') {
                continue;
            }
            $normalized[] = [
                'role' => $role,
                'message' => $message,
                'recorded_at' => $recordedAt,
            ];
        }

        if ($limit > 0 && count($normalized) > $limit) {
            return array_slice($normalized, -$limit);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function isHumanTakeoverActive(array $state, \DateTimeImmutable $now): bool
    {
        $raw = isset($state['human_takeover_at']) ? trim((string) $state['human_takeover_at']) : '';
        if ($raw === '') {
            return false;
        }

        try {
            $takeoverAt = new \DateTimeImmutable($raw);
        } catch (\Exception $e) {
            return false;
        }

        $expiresAt = $takeoverAt->modify('+' . $this->timeoutMinutes . ' minutes');

        return $now <= $expiresAt;
    }

    private function appendMessage(
        string $conversationId,
        string $role,
        string $message,
        ?\DateTimeImmutable $timestamp = null
    ): void {
        $message = trim($message);
        if ($message === '') {
            return;
        }

        $timestamp = $timestamp ?? new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));
        $state = $this->readState($conversationId);
        $messages = $state['messages'] ?? [];
        if (!is_array($messages)) {
            $messages = [];
        }
        $messages[] = [
            'role' => $role,
            'message' => $message,
            'recorded_at' => $timestamp->format(\DateTimeInterface::ATOM),
        ];
        $state['messages'] = $messages;
        $this->writeState($conversationId, $state);
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(string $conversationId): array
    {
        if ($this->memoryStore !== null) {
            return $this->memoryStore[$conversationId] ?? [];
        }

        $path = $this->stateFilePath($conversationId);
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeState(string $conversationId, array $state): void
    {
        if ($this->memoryStore !== null) {
            $this->memoryStore[$conversationId] = $state;

            return;
        }

        $dir = $this->storeDirectory ?? self::defaultStoreDirectory();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $this->stateFilePath($conversationId);
        file_put_contents(
            $path,
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    private function stateFilePath(string $conversationId): string
    {
        $dir = $this->storeDirectory ?? self::defaultStoreDirectory();
        $safeId = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $conversationId) ?? 'unknown';

        return $dir . DIRECTORY_SEPARATOR . $safeId . '.json';
    }
}
