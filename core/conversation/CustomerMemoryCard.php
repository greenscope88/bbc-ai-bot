<?php
declare(strict_types=1);

/**
 * Phase 2-B Step 1 — Customer Memory Card（客戶記憶卡）.
 *
 * SSOT: docs/BATS_AI_CONVERSATION_MEMORY.md（Customer Memory Card — Core Concept）.
 *
 * Customer Memory Card 是 AI 對「目前客服服務狀態」的標準化理解模型
 * （Standardized Service State Model）。它不是 CRM、不是會員資料、不是永久
 * Profile，而是維持客服連續性所需的服務狀態。
 *
 * 七個最低必要核心欄位（SSOT §「Minimum Required Fields」）：
 *   1. Current Requirement             目前需求
 *   2. Conversation Stage              對話階段
 *   3. Completed Items                 已完成事項
 *   4. Outstanding Issues              待辦事項
 *   5. Human Handoff Status            真人接手狀態
 *   6. AI Summary                      AI 摘要
 *   7. Recently Recommended Products   最近推薦商品
 *
 * 邊界（SSOT §10 Memory Boundaries）：本卡片只「記得被明確告知的事實」，
 * 不自行推測、不自行補充。推測與內容生成不屬於本元件職責。
 */
final class CustomerMemoryCard
{
    /**
     * Conversation Stage 取值對齊 Conversation Lifecycle
     * （BATS_AI_CONVERSATION_ARCHITECTURE.md §12.1）。
     */
    public const STAGE_NEW = 'New';
    public const STAGE_ACTIVE = 'Active';
    public const STAGE_RESOLVED = 'Resolved';
    public const STAGE_CLOSING = 'Closing';
    public const STAGE_COMPLETED = 'Completed';
    public const STAGE_CLOSED = 'Closed';

    /**
     * Human Handoff Status 為 Conversation Owner（AI | HUMAN）之唯讀鏡像。
     * Memory Runtime 不擁有狀態管理權（CA-010），僅同步 State Runtime 提供值。
     */
    public const HANDOFF_AI = 'AI';
    public const HANDOFF_HUMAN = 'HUMAN';

    /** @var list<string> */
    private const VALID_STAGES = [
        self::STAGE_NEW,
        self::STAGE_ACTIVE,
        self::STAGE_RESOLVED,
        self::STAGE_CLOSING,
        self::STAGE_COMPLETED,
        self::STAGE_CLOSED,
    ];

    /** @var list<string> */
    private const VALID_HANDOFF = [
        self::HANDOFF_AI,
        self::HANDOFF_HUMAN,
    ];

    private const RECENT_PRODUCTS_LIMIT = 20;

    private string $conversationId;

    /** Field 1 — Current Requirement. */
    private ?string $currentRequirement = null;

    /** Field 2 — Conversation Stage. */
    private string $conversationStage = self::STAGE_NEW;

    /**
     * Field 3 — Completed Items.
     *
     * @var list<string>
     */
    private array $completedItems = [];

    /**
     * Field 4 — Outstanding Issues.
     *
     * @var list<string>
     */
    private array $outstandingIssues = [];

    /** Field 5 — Human Handoff Status（Conversation Owner 鏡像）. */
    private string $humanHandoffStatus = self::HANDOFF_AI;

    /** Field 6 — AI Summary. */
    private ?string $aiSummary = null;

    /**
     * Field 7 — Recently Recommended Products（商品識別字串：id 或 title）.
     *
     * @var list<string>
     */
    private array $recentlyRecommendedProducts = [];

    private ?string $updatedAt = null;

    public function __construct(string $conversationId)
    {
        $conversationId = trim($conversationId);
        if ($conversationId === '') {
            throw new \InvalidArgumentException('conversationId must not be empty');
        }
        $this->conversationId = $conversationId;
    }

    public static function create(string $conversationId): self
    {
        return new self($conversationId);
    }

    public function getConversationId(): string
    {
        return $this->conversationId;
    }

    public function getCurrentRequirement(): ?string
    {
        return $this->currentRequirement;
    }

    public function setCurrentRequirement(?string $requirement): self
    {
        $requirement = $requirement !== null ? trim($requirement) : null;
        $this->currentRequirement = ($requirement === '' ? null : $requirement);

        return $this;
    }

    public function getConversationStage(): string
    {
        return $this->conversationStage;
    }

    public function setConversationStage(string $stage): self
    {
        $stage = trim($stage);
        if (!in_array($stage, self::VALID_STAGES, true)) {
            throw new \InvalidArgumentException('invalid conversation stage: ' . $stage);
        }
        $this->conversationStage = $stage;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getCompletedItems(): array
    {
        return $this->completedItems;
    }

    public function addCompletedItem(string $item): self
    {
        $item = trim($item);
        if ($item === '') {
            return $this;
        }
        if (!in_array($item, $this->completedItems, true)) {
            $this->completedItems[] = $item;
        }

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getOutstandingIssues(): array
    {
        return $this->outstandingIssues;
    }

    public function addOutstandingIssue(string $issue): self
    {
        $issue = trim($issue);
        if ($issue === '') {
            return $this;
        }
        if (!in_array($issue, $this->outstandingIssues, true)) {
            $this->outstandingIssues[] = $issue;
        }

        return $this;
    }

    /**
     * 將某項待辦標記為完成：自 Outstanding Issues 移除，並加入 Completed Items。
     */
    public function resolveOutstandingIssue(string $issue): self
    {
        $issue = trim($issue);
        if ($issue === '') {
            return $this;
        }
        $this->outstandingIssues = array_values(array_filter(
            $this->outstandingIssues,
            static function (string $existing) use ($issue): bool {
                return $existing !== $issue;
            }
        ));

        return $this->addCompletedItem($issue);
    }

    public function getHumanHandoffStatus(): string
    {
        return $this->humanHandoffStatus;
    }

    public function setHumanHandoffStatus(string $status): self
    {
        $status = trim($status);
        if (!in_array($status, self::VALID_HANDOFF, true)) {
            throw new \InvalidArgumentException('invalid human handoff status: ' . $status);
        }
        $this->humanHandoffStatus = $status;

        return $this;
    }

    public function getAiSummary(): ?string
    {
        return $this->aiSummary;
    }

    public function setAiSummary(?string $summary): self
    {
        $summary = $summary !== null ? trim($summary) : null;
        $this->aiSummary = ($summary === '' ? null : $summary);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getRecentlyRecommendedProducts(): array
    {
        return $this->recentlyRecommendedProducts;
    }

    public function addRecommendedProduct(string $productRef): self
    {
        $productRef = trim($productRef);
        if ($productRef === '') {
            return $this;
        }
        // Move-to-end semantics so "recently" reflects latest interaction.
        $this->recentlyRecommendedProducts = array_values(array_filter(
            $this->recentlyRecommendedProducts,
            static function (string $existing) use ($productRef): bool {
                return $existing !== $productRef;
            }
        ));
        $this->recentlyRecommendedProducts[] = $productRef;

        if (count($this->recentlyRecommendedProducts) > self::RECENT_PRODUCTS_LIMIT) {
            $this->recentlyRecommendedProducts = array_slice(
                $this->recentlyRecommendedProducts,
                -self::RECENT_PRODUCTS_LIMIT
            );
        }

        return $this;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function touch(?string $updatedAt): self
    {
        $updatedAt = $updatedAt !== null ? trim($updatedAt) : null;
        $this->updatedAt = ($updatedAt === '' ? null : $updatedAt);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'current_requirement' => $this->currentRequirement,
            'conversation_stage' => $this->conversationStage,
            'completed_items' => $this->completedItems,
            'outstanding_issues' => $this->outstandingIssues,
            'human_handoff_status' => $this->humanHandoffStatus,
            'ai_summary' => $this->aiSummary,
            'recently_recommended_products' => $this->recentlyRecommendedProducts,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $conversationId = isset($data['conversation_id']) ? (string) $data['conversation_id'] : '';
        $card = new self($conversationId);

        if (array_key_exists('current_requirement', $data)) {
            $card->setCurrentRequirement(
                $data['current_requirement'] !== null ? (string) $data['current_requirement'] : null
            );
        }
        if (isset($data['conversation_stage']) && trim((string) $data['conversation_stage']) !== '') {
            $card->setConversationStage((string) $data['conversation_stage']);
        }
        foreach (self::toStringList($data['completed_items'] ?? []) as $item) {
            $card->addCompletedItem($item);
        }
        foreach (self::toStringList($data['outstanding_issues'] ?? []) as $issue) {
            $card->addOutstandingIssue($issue);
        }
        if (isset($data['human_handoff_status']) && trim((string) $data['human_handoff_status']) !== '') {
            $card->setHumanHandoffStatus((string) $data['human_handoff_status']);
        }
        if (array_key_exists('ai_summary', $data)) {
            $card->setAiSummary($data['ai_summary'] !== null ? (string) $data['ai_summary'] : null);
        }
        foreach (self::toStringList($data['recently_recommended_products'] ?? []) as $product) {
            $card->addRecommendedProduct($product);
        }
        if (array_key_exists('updated_at', $data)) {
            $card->touch($data['updated_at'] !== null ? (string) $data['updated_at'] : null);
        }

        return $card;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function toStringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                continue;
            }
            $out[] = (string) $item;
        }

        return $out;
    }
}
