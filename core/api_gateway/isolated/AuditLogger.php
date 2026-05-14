<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AuditLogRecordBuilder.php';

/**
 * Phase 4 Stage 4 — append-only audit logger with injectable sink (isolated).
 * Default sink is in-memory only: no DB, no file, no network.
 */
interface AuditLogSinkInterface
{
    /**
     * @param array<string, mixed> $record
     */
    public function append(array $record): void;
}

final class InMemoryAuditLogSink implements AuditLogSinkInterface
{
    /** @var list<array<string, mixed>> */
    private array $records = [];

    /**
     * @param array<string, mixed> $record
     */
    public function append(array $record): void
    {
        $this->records[] = $record;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    public function clear(): void
    {
        $this->records = [];
    }

    public function count(): int
    {
        return count($this->records);
    }
}

final class AuditLogger
{
    private AuditLogSinkInterface $sink;

    private AuditLogRecordBuilder $builder;

    public function __construct(?AuditLogSinkInterface $sink = null, ?AuditLogRecordBuilder $builder = null)
    {
        $this->sink = $sink ?? new InMemoryAuditLogSink();
        $this->builder = $builder ?? new AuditLogRecordBuilder();
    }

    public function getSink(): AuditLogSinkInterface
    {
        return $this->sink;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, record: array<string, mixed>|null, error: string|null}
     */
    public function log(array $input): array
    {
        $built = $this->builder->build($input);
        if (!($built['ok'] ?? false) || !is_array($built['record'] ?? null)) {
            return $built;
        }

        $this->sink->append($built['record']);

        return $built;
    }
}
