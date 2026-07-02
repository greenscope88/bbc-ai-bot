<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundingAssemblyException.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'RuntimeType.php';

/**
 * Phase 2-F Step 2-F-1 — Grounding Runtime sole input contract.
 *
 * SSOT: docs/BATS_AI_GROUNDING_LAYER.md §12.
 */
final class GroundingAssemblyContext
{
    private string $traceId;

    private string $conversationId;

    private string $tenantSno;

    private string $customerQuery;

    private string $runtimeType;

    private string $sourceType;

    /** @var array<string, mixed> */
    private array $runtimeResult;

    /** @var array<string, mixed> */
    private array $dispatchResult;

    /** @var array<string, mixed> */
    private array $tenant;

    /** @var array<string, mixed> */
    private array $tone;

    /** @var array<string, mixed> */
    private array $memorySnapshot;

    /** @var array<string, mixed> */
    private array $stateSnapshot;

    /** @var array<string, mixed> */
    private array $aiuProjection;

    /** @var array<string, mixed>|null */
    private ?array $batsSearchIntent;

    /**
     * @param array<string, mixed> $runtimeResult
     * @param array<string, mixed> $dispatchResult
     * @param array<string, mixed> $tenant
     * @param array<string, mixed> $tone
     * @param array<string, mixed> $memorySnapshot
     * @param array<string, mixed> $stateSnapshot
     * @param array<string, mixed> $aiuProjection
     * @param array<string, mixed>|null $batsSearchIntent
     */
    public function __construct(
        string $traceId,
        string $conversationId,
        string $tenantSno,
        string $customerQuery,
        string $runtimeType,
        string $sourceType,
        array $runtimeResult,
        array $dispatchResult,
        array $tenant = [],
        array $tone = [],
        array $memorySnapshot = [],
        array $stateSnapshot = [],
        array $aiuProjection = [],
        ?array $batsSearchIntent = null
    ) {
        $this->traceId = trim($traceId);
        $this->conversationId = trim($conversationId);
        $this->tenantSno = trim($tenantSno);
        $this->customerQuery = trim($customerQuery);
        $this->runtimeType = trim($runtimeType);
        $this->sourceType = trim($sourceType);
        $this->runtimeResult = $runtimeResult;
        $this->dispatchResult = $dispatchResult;
        $this->tenant = $tenant;
        $this->tone = $tone;
        $this->memorySnapshot = $memorySnapshot;
        $this->stateSnapshot = $stateSnapshot;
        $this->aiuProjection = $aiuProjection;
        $this->batsSearchIntent = $batsSearchIntent;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['trace_id'] ?? ''),
            (string) ($data['conversation_id'] ?? ''),
            (string) ($data['tenant_sno'] ?? ''),
            (string) ($data['customer_query'] ?? ''),
            (string) ($data['runtime_type'] ?? RuntimeType::KNOWLEDGE_PRIVATE),
            (string) ($data['source_type'] ?? ''),
            is_array($data['runtime_result'] ?? null) ? $data['runtime_result'] : [],
            is_array($data['dispatch_result'] ?? null) ? $data['dispatch_result'] : [],
            is_array($data['tenant'] ?? null) ? $data['tenant'] : [],
            is_array($data['tone'] ?? null) ? $data['tone'] : [],
            is_array($data['memory_snapshot'] ?? null) ? $data['memory_snapshot'] : [],
            is_array($data['state_snapshot'] ?? null) ? $data['state_snapshot'] : [],
            is_array($data['aiu_projection'] ?? null) ? $data['aiu_projection'] : [],
            isset($data['bats_search_intent']) && is_array($data['bats_search_intent'])
                ? $data['bats_search_intent']
                : null
        );
    }

    public function validateRequired(): void
    {
        if ($this->traceId === '') {
            throw new GroundingAssemblyException('missing trace_id');
        }
        if ($this->conversationId === '') {
            throw new GroundingAssemblyException('missing conversation_id');
        }
        if ($this->tenantSno === '') {
            throw new GroundingAssemblyException('missing tenant_sno');
        }
        if ($this->customerQuery === '') {
            throw new GroundingAssemblyException('missing customer_query');
        }
        if ($this->runtimeType === '' || !RuntimeType::isValid($this->runtimeType)) {
            throw new GroundingAssemblyException('invalid or missing runtime_type');
        }
        if ($this->sourceType === '') {
            throw new GroundingAssemblyException('missing source_type');
        }
        if ($this->runtimeResult === []) {
            throw new GroundingAssemblyException('missing runtime_result');
        }
    }

    public function getTraceId(): string
    {
        return $this->traceId;
    }

    public function getConversationId(): string
    {
        return $this->conversationId;
    }

    public function getTenantSno(): string
    {
        return $this->tenantSno;
    }

    public function getCustomerQuery(): string
    {
        return $this->customerQuery;
    }

    public function getRuntimeType(): string
    {
        return $this->runtimeType;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRuntimeResult(): array
    {
        return $this->runtimeResult;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDispatchResult(): array
    {
        return $this->dispatchResult;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTenant(): array
    {
        return $this->tenant;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTone(): array
    {
        return $this->tone;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMemorySnapshot(): array
    {
        return $this->memorySnapshot;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStateSnapshot(): array
    {
        return $this->stateSnapshot;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAiuProjection(): array
    {
        return $this->aiuProjection;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBatsSearchIntent(): ?array
    {
        return $this->batsSearchIntent;
    }
}
