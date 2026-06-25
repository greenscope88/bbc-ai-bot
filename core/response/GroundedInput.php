<?php
declare(strict_types=1);

/**
 * Phase 9-C-2C-1 — Grounded Response Composer input contract (presentation layer).
 *
 * SSOT: docs/PHASE_9C2C_GROUNDED_RESPONSE_COMPOSER.md
 *
 * Carries grounded data already resolved by a Runtime. This DTO never resolves
 * facts itself, never searches, never calls Gemini, and never makes routing
 * decisions. It only describes what the Runtime already produced so that the
 * GroundedResponseComposer can present it under a single contract.
 */
final class GroundedInput
{
    public const SOURCE_TENANT_PRIVATE = 'tenant_private';
    public const SOURCE_INDUSTRY_SHARED = 'industry_shared';
    public const SOURCE_HUMAN_SERVICE = 'human_service';

    public const PURPOSE_KNOWLEDGE_REPLY = 'knowledge_reply';

    private string $sourceType;

    private ?string $knowledgeType;

    /** @var list<array{id: string|null, type: string}> */
    private array $groundedFacts;

    /** @var array<string, mixed> */
    private array $rawRuntimeResult;

    /** @var array<string, mixed> */
    private array $tenant;

    private string $replyPurpose;

    /**
     * @param list<array{id: string|null, type: string}> $groundedFacts
     * @param array<string, mixed> $rawRuntimeResult
     * @param array<string, mixed> $tenant
     */
    public function __construct(
        string $sourceType,
        ?string $knowledgeType,
        array $groundedFacts,
        array $rawRuntimeResult,
        array $tenant = [],
        string $replyPurpose = self::PURPOSE_KNOWLEDGE_REPLY
    ) {
        $this->sourceType = $sourceType;
        $this->knowledgeType = $knowledgeType;
        $this->groundedFacts = $groundedFacts;
        $this->rawRuntimeResult = $rawRuntimeResult;
        $this->tenant = $tenant;
        $this->replyPurpose = $replyPurpose;
    }

    /**
     * Build a GroundedInput from a Knowledge Runtime result array.
     *
     * Accepts the array returned by TenantPrivateKnowledgeRuntime::handle()
     * (which already includes industry-shared / human-service fallback results).
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $tenant
     */
    public static function fromKnowledgeRuntimeResult(
        array $result,
        array $tenant = [],
        string $replyPurpose = self::PURPOSE_KNOWLEDGE_REPLY
    ): self {
        $fallbackLayer = isset($result['fallback_layer']) ? trim((string) $result['fallback_layer']) : '';
        if ($fallbackLayer === 'human_service') {
            $sourceType = self::SOURCE_HUMAN_SERVICE;
        } elseif ($fallbackLayer === 'industry_shared') {
            $sourceType = self::SOURCE_INDUSTRY_SHARED;
        } else {
            $sourceType = self::SOURCE_TENANT_PRIVATE;
        }

        $knowledgeType = isset($result['query_type']) && trim((string) $result['query_type']) !== ''
            ? trim((string) $result['query_type'])
            : null;

        $facts = [];
        foreach (
            [
                'qa_id' => 'qa',
                'price_id' => 'price',
                'service_id' => 'service_item',
                'link_id' => 'link',
                'shared_item_id' => 'faq_shared',
            ] as $key => $factType
        ) {
            if (!empty($result[$key])) {
                $facts[] = ['id' => (string) $result[$key], 'type' => $factType];
            }
        }

        // Grounded reply with no explicit id (e.g. company_profile) still counts
        // as one grounded fact, but only when the Runtime marked it grounded.
        if ($facts === [] && (bool) ($result['grounded'] ?? false) === true) {
            $facts[] = ['id' => null, 'type' => $knowledgeType ?? 'profile_field'];
        }

        return new self(
            $sourceType,
            $knowledgeType,
            $facts,
            $result,
            $tenant,
            $replyPurpose
        );
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function getKnowledgeType(): ?string
    {
        return $this->knowledgeType;
    }

    /**
     * @return list<array{id: string|null, type: string}>
     */
    public function getGroundedFacts(): array
    {
        return $this->groundedFacts;
    }

    public function getFactCount(): int
    {
        return count($this->groundedFacts);
    }

    /**
     * @return array<string, mixed>
     */
    public function getRawRuntimeResult(): array
    {
        return $this->rawRuntimeResult;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTenant(): array
    {
        return $this->tenant;
    }

    public function getReplyPurpose(): string
    {
        return $this->replyPurpose;
    }

    public function isRuntimeGrounded(): bool
    {
        return (bool) ($this->rawRuntimeResult['grounded'] ?? false);
    }

    public function getRuntimeReplyText(): string
    {
        return trim((string) ($this->rawRuntimeResult['reply_text'] ?? ''));
    }
}
