<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'RuntimeType.php';

/**
 * Phase 2-E Step 2-E-1 — Grounded Response Composer input contract (presentation layer).
 *
 * SSOT: docs/BATS_AI_GROUNDED_RESPONSE_COMPOSER.md §8.5（v1.0 Freeze）.
 *
 * Carries grounded data already resolved by a Runtime. This DTO never resolves
 * facts itself, never searches, never calls Gemini, and never makes routing
 * decisions. `rawRuntimeResult` is transitional for legacy pass-through only
 * and is excluded from formal `toArray()` contract serialization.
 */
final class GroundedInput
{
    public const SCHEMA_VERSION = 1;

    /** @deprecated Legacy source label — prefer RuntimeType. */
    public const SOURCE_TENANT_PRIVATE = 'tenant_private';
    /** @deprecated Legacy source label */
    public const SOURCE_INDUSTRY_SHARED = 'industry_shared';
    /** @deprecated Legacy source label */
    public const SOURCE_HUMAN_SERVICE = 'human_service';
    /** @deprecated Legacy source label */
    public const SOURCE_PRODUCT_SEARCH = 'product_search';

    public const PURPOSE_KNOWLEDGE_REPLY = 'knowledge_reply';
    public const PURPOSE_PRODUCT_REPLY = 'product_reply';

    public const CONVERSATION_OWNER_AI = 'AI';
    public const CONVERSATION_OWNER_HUMAN = 'HUMAN';

    public const CONVERSATION_STATUS_ACTIVE = 'ACTIVE';

    private string $runtimeType;

    private string $sourceType;

    private ?string $knowledgeType;

    /** @var list<array<string, mixed>> */
    private array $groundedFacts;

    /** @var array<string, mixed> */
    private array $rawRuntimeResult;

    /** @var array<string, mixed> */
    private array $tenant;

    /** @var array{persona: string, allow_emoji: bool} */
    private array $tone;

    private string $replyPurpose;

    /** @var list<array<string, mixed>> */
    private array $productList;

    /** @var array<string, mixed> */
    private array $recommendationSummary;

    /** @var array<string, mixed> */
    private array $replyPolicy;

    /** @var list<array<string, mixed>> */
    private array $externalLinks;

    /** @var array<string, mixed> */
    private array $metadata;

    /** @var array<string, mixed> */
    private array $conversationContext;

    private string $conversationOwner;

    private string $conversationStatus;

    /** @var array<string, mixed>|null */
    private ?array $resumeContext;

    /**
     * @param list<array<string, mixed>> $groundedFacts
     * @param array<string, mixed>      $rawRuntimeResult
     * @param array<string, mixed>      $tenant
     * @param array<string, mixed>      $tone
     * @param list<array<string, mixed>> $productList
     * @param array<string, mixed>      $recommendationSummary
     * @param array<string, mixed>      $replyPolicy
     * @param list<array<string, mixed>> $externalLinks
     * @param array<string, mixed>      $metadata
     * @param array<string, mixed>      $conversationContext
     * @param array<string, mixed>|null $resumeContext
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
        array $replyPolicy = [],
        ?string $runtimeType = null,
        array $tone = [],
        array $externalLinks = [],
        array $metadata = [],
        array $conversationContext = [],
        string $conversationOwner = self::CONVERSATION_OWNER_AI,
        string $conversationStatus = self::CONVERSATION_STATUS_ACTIVE,
        ?array $resumeContext = null
    ) {
        $this->sourceType = $sourceType;
        $this->runtimeType = $runtimeType ?? self::mapSourceTypeToRuntimeType($sourceType);
        RuntimeType::assertValid($this->runtimeType);

        $this->knowledgeType = $knowledgeType;
        $this->groundedFacts = self::normalizeGroundedFactsList($groundedFacts);
        $this->rawRuntimeResult = $rawRuntimeResult;
        $this->tenant = $tenant;
        $this->tone = self::normalizeTone($tone, $tenant);
        $this->replyPurpose = $replyPurpose;
        $this->productList = $productList;
        $this->recommendationSummary = $recommendationSummary;
        $this->replyPolicy = self::normalizeReplyPolicy($replyPolicy);
        $this->externalLinks = $externalLinks;
        $this->metadata = $metadata;
        $this->conversationContext = $conversationContext;
        $this->conversationOwner = $conversationOwner;
        $this->conversationStatus = $conversationStatus;
        $this->resumeContext = $resumeContext;
    }

    /**
     * Build a GroundedInput from a Knowledge Runtime result array.
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

        $replyText = trim((string) ($result['reply_text'] ?? ''));
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
                $facts[] = [
                    'fact_id' => (string) $result[$key],
                    'fact_type' => $factType,
                    'value' => $replyText,
                    'source_ref' => $key,
                ];
            }
        }

        if ($facts === [] && (bool) ($result['grounded'] ?? false) === true) {
            $facts[] = [
                'fact_id' => null,
                'fact_type' => $knowledgeType ?? 'profile_field',
                'value' => $replyText,
            ];
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
     * @param array<string, mixed> $result
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

        $facts = [];
        foreach ($productList as $row) {
            $title = isset($row['title']) ? trim((string) $row['title']) : '';
            if ($title === '') {
                continue;
            }
            $facts[] = [
                'fact_id' => $title,
                'fact_type' => 'product_row',
                'value' => $title,
            ];
        }

        $replyPolicyMode = $resultCount > 0 ? 'recommend' : 'no_results';
        $replyPolicy = [
            'mode' => $replyPolicyMode,
            'grounded_only' => true,
            'max_products' => 3,
        ];

        $externalLinks = [];
        foreach ($productList as $row) {
            $url = isset($row['primary_url']) ? trim((string) $row['primary_url']) : '';
            if ($url !== '') {
                $externalLinks[] = ['url' => $url, 'title' => (string) ($row['title'] ?? '')];
            }
        }

        return new self(
            self::SOURCE_PRODUCT_SEARCH,
            null,
            $facts,
            $result,
            $tenant,
            $replyPurpose,
            $productList,
            $recommendationSummary,
            $replyPolicy,
            RuntimeType::PRODUCT_SEARCH,
            [],
            $externalLinks
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $runtimeType = (string) ($data['runtime_type'] ?? RuntimeType::KNOWLEDGE_PRIVATE);
        $sourceType = isset($data['source_type'])
            ? (string) $data['source_type']
            : self::mapRuntimeTypeToSourceType($runtimeType);

        $productListRaw = $data['product_list'] ?? [];
        if (is_array($productListRaw) && isset($productListRaw['items']) && is_array($productListRaw['items'])) {
            $productList = array_values(array_filter($productListRaw['items'], 'is_array'));
        } elseif (is_array($productListRaw)) {
            $productList = array_values(array_filter($productListRaw, 'is_array'));
        } else {
            $productList = [];
        }

        $rawRuntime = is_array($data['raw_runtime_result'] ?? null) ? $data['raw_runtime_result'] : [];

        return new self(
            $sourceType,
            isset($data['knowledge_type']) ? (string) $data['knowledge_type'] : null,
            is_array($data['grounded_facts'] ?? null) ? $data['grounded_facts'] : [],
            $rawRuntime,
            is_array($data['tenant'] ?? null) ? $data['tenant'] : [],
            (string) ($data['reply_purpose'] ?? self::PURPOSE_KNOWLEDGE_REPLY),
            $productList,
            is_array($data['recommendation_summary'] ?? null) ? $data['recommendation_summary'] : [],
            is_array($data['reply_policy'] ?? null) ? $data['reply_policy'] : [],
            $runtimeType,
            is_array($data['tone'] ?? null) ? $data['tone'] : [],
            is_array($data['external_links'] ?? null) ? array_values(array_filter($data['external_links'], 'is_array')) : [],
            is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            is_array($data['conversation_context'] ?? null) ? $data['conversation_context'] : [],
            (string) ($data['conversation_owner'] ?? self::CONVERSATION_OWNER_AI),
            (string) ($data['conversation_status'] ?? self::CONVERSATION_STATUS_ACTIVE),
            isset($data['resume_context']) && is_array($data['resume_context']) ? $data['resume_context'] : null
        );
    }

    public static function mapSourceTypeToRuntimeType(string $sourceType): string
    {
        switch ($sourceType) {
            case self::SOURCE_PRODUCT_SEARCH:
                return RuntimeType::PRODUCT_SEARCH;
            case self::SOURCE_INDUSTRY_SHARED:
                return RuntimeType::KNOWLEDGE_SHARED;
            case self::SOURCE_HUMAN_SERVICE:
                return RuntimeType::HUMAN_SERVICE;
            default:
                return RuntimeType::KNOWLEDGE_PRIVATE;
        }
    }

    public static function mapRuntimeTypeToSourceType(string $runtimeType): string
    {
        switch ($runtimeType) {
            case RuntimeType::PRODUCT_SEARCH:
                return self::SOURCE_PRODUCT_SEARCH;
            case RuntimeType::KNOWLEDGE_SHARED:
                return self::SOURCE_INDUSTRY_SHARED;
            case RuntimeType::HUMAN_SERVICE:
                return self::SOURCE_HUMAN_SERVICE;
            case RuntimeType::CLARIFICATION:
            case RuntimeType::WAITING_ACK:
                return self::SOURCE_TENANT_PRIVATE;
            default:
                return self::SOURCE_TENANT_PRIVATE;
        }
    }

    public function getRuntimeType(): string
    {
        return $this->runtimeType;
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
     * @return list<array<string, mixed>>
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
     * Transitional legacy accessor — not part of formal contract serialization.
     *
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

    /**
     * @return array{persona: string, allow_emoji: bool}
     */
    public function getTone(): array
    {
        return $this->tone;
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

    /**
     * @return list<array<string, mixed>>
     */
    public function getExternalLinks(): array
    {
        return $this->externalLinks;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConversationContext(): array
    {
        return $this->conversationContext;
    }

    public function getConversationOwner(): string
    {
        return $this->conversationOwner;
    }

    public function getConversationStatus(): string
    {
        return $this->conversationStatus;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResumeContext(): ?array
    {
        return $this->resumeContext;
    }

    public function getSchemaVersion(): int
    {
        return self::SCHEMA_VERSION;
    }

    public function isRuntimeGrounded(): bool
    {
        return (bool) ($this->rawRuntimeResult['grounded'] ?? false);
    }

    public function getRuntimeReplyText(): string
    {
        return trim((string) ($this->rawRuntimeResult['reply_text'] ?? ''));
    }

    /**
     * Formal frozen contract serialization (§8.5). Excludes `rawRuntimeResult`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'schema_version' => self::SCHEMA_VERSION,
            'runtime_type' => $this->runtimeType,
            'source_type' => $this->sourceType,
            'tenant' => $this->tenant,
            'tone' => $this->tone,
            'grounded_facts' => $this->groundedFacts,
            'product_list' => ['items' => $this->productList],
            'external_links' => $this->externalLinks,
            'reply_policy' => $this->replyPolicy,
            'metadata' => $this->metadata,
            'conversation_context' => $this->conversationContext,
            'conversation_owner' => $this->conversationOwner,
            'conversation_status' => $this->conversationStatus,
        ];

        if ($this->knowledgeType !== null && $this->knowledgeType !== '') {
            $out['knowledge_type'] = $this->knowledgeType;
        }
        if ($this->replyPurpose !== '') {
            $out['reply_purpose'] = $this->replyPurpose;
        }
        if ($this->recommendationSummary !== []) {
            $out['recommendation_summary'] = $this->recommendationSummary;
        }
        if ($this->resumeContext !== null) {
            $out['resume_context'] = $this->resumeContext;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $facts
     *
     * @return list<array<string, mixed>>
     */
    private static function normalizeGroundedFactsList(array $facts): array
    {
        $normalized = [];
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $normalized[] = self::normalizeGroundedFact($fact);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $fact
     *
     * @return array<string, mixed>
     */
    private static function normalizeGroundedFact(array $fact): array
    {
        $factId = $fact['fact_id'] ?? $fact['id'] ?? null;
        $factType = (string) ($fact['fact_type'] ?? $fact['type'] ?? '');
        $value = (string) ($fact['value'] ?? '');

        $out = [
            'fact_type' => $factType,
            'value' => $value,
        ];
        if ($factId !== null && $factId !== '') {
            $out['fact_id'] = (string) $factId;
        } elseif (array_key_exists('fact_id', $fact) || array_key_exists('id', $fact)) {
            $out['fact_id'] = $factId;
        }
        if (isset($fact['source_ref']) && (string) $fact['source_ref'] !== '') {
            $out['source_ref'] = (string) $fact['source_ref'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $tone
     * @param array<string, mixed> $tenant
     *
     * @return array{persona: string, allow_emoji: bool}
     */
    private static function normalizeTone(array $tone, array $tenant): array
    {
        $persona = trim((string) ($tone['persona'] ?? $tenant['persona'] ?? ''));
        $allowEmoji = array_key_exists('allow_emoji', $tone)
            ? (bool) $tone['allow_emoji']
            : true;

        return [
            'persona' => $persona,
            'allow_emoji' => $allowEmoji,
        ];
    }

    /**
     * @param array<string, mixed> $replyPolicy
     *
     * @return array<string, mixed>
     */
    private static function normalizeReplyPolicy(array $replyPolicy): array
    {
        if ($replyPolicy === []) {
            return [
                'mode' => 'recommend',
                'grounded_only' => true,
            ];
        }

        if (!isset($replyPolicy['grounded_only'])) {
            $replyPolicy['grounded_only'] = true;
        }

        return $replyPolicy;
    }
}
