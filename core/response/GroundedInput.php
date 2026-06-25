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
    public const SOURCE_PRODUCT_SEARCH = 'product_search';

    public const PURPOSE_KNOWLEDGE_REPLY = 'knowledge_reply';
    public const PURPOSE_PRODUCT_REPLY = 'product_reply';

    private string $sourceType;

    private ?string $knowledgeType;

    /** @var list<array{id: string|null, type: string}> */
    private array $groundedFacts;

    /** @var array<string, mixed> */
    private array $rawRuntimeResult;

    /** @var array<string, mixed> */
    private array $tenant;

    private string $replyPurpose;

    /** @var list<array<string, mixed>> */
    private array $productList;

    /** @var array<string, mixed> */
    private array $recommendationSummary;

    /** @var array<string, mixed> */
    private array $replyPolicy;

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
        string $replyPurpose = self::PURPOSE_KNOWLEDGE_REPLY,
        array $productList = [],
        array $recommendationSummary = [],
        array $replyPolicy = []
    ) {
        $this->sourceType = $sourceType;
        $this->knowledgeType = $knowledgeType;
        $this->groundedFacts = $groundedFacts;
        $this->rawRuntimeResult = $rawRuntimeResult;
        $this->tenant = $tenant;
        $this->replyPurpose = $replyPurpose;
        $this->productList = $productList;
        $this->recommendationSummary = $recommendationSummary;
        $this->replyPolicy = $replyPolicy;
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

    /**
     * Build a GroundedInput from a Product Runtime result.
     *
     * The reply text is the persona/recommendation copy already produced by the
     * Product Runtime (TravelConsultantPersonaRuntime via GeminiClient). This
     * factory only structures the surrounding grounded data; it never produces
     * recommendation copy itself.
     *
     * @param array<string, mixed> $result Expected keys:
     *   reply_text: string,
     *   grounded: bool,
     *   recommendation_summary: array<string, mixed>,
     *   product_list: list<array<string, mixed>>  (publish plan items)
     * @param array<string, mixed> $tenant
     */
    public static function fromProductRuntimeResult(
        array $result,
        array $tenant = [],
        string $replyPurpose = self::PURPOSE_PRODUCT_REPLY
    ): self {
        $recommendationSummary = isset($result['recommendation_summary']) && is_array($result['recommendation_summary'])
            ? $result['recommendation_summary']
            : [];

        $productList = isset($result['product_list']) && is_array($result['product_list'])
            ? array_values(array_filter($result['product_list'], 'is_array'))
            : [];

        $resultCount = isset($recommendationSummary['result_count'])
            ? max(0, (int) $recommendationSummary['result_count'])
            : count($productList);

        // One grounded fact per product row already resolved by the Runtime.
        $facts = [];
        foreach ($productList as $row) {
            $title = isset($row['title']) ? trim((string) $row['title']) : '';
            if ($title === '') {
                continue;
            }
            $facts[] = ['id' => $title, 'type' => 'product_row'];
        }

        $replyPolicyMode = $resultCount > 0 ? 'recommend' : 'no_results';
        $replyPolicy = [
            'mode' => $replyPolicyMode,
            'grounded_only' => true,
            'max_products' => 3,
        ];

        return new self(
            self::SOURCE_PRODUCT_SEARCH,
            null,
            $facts,
            $result,
            $tenant,
            $replyPurpose,
            $productList,
            $recommendationSummary,
            $replyPolicy
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

    /**
     * @return list<array<string, mixed>>
     */
    public function getProductList(): array
    {
        return $this->productList;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRecommendationSummary(): array
    {
        return $this->recommendationSummary;
    }

    /**
     * @return array<string, mixed>
     */
    public function getReplyPolicy(): array
    {
        return $this->replyPolicy;
    }

    public function getReplyPolicyMode(): string
    {
        return isset($this->replyPolicy['mode']) ? (string) $this->replyPolicy['mode'] : '';
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
