<?php
declare(strict_types=1);

/**
 * Phase 2-B Step 3 — Conversation Policy（Tenant Policy 設定）.
 *
 * SSOT: docs/BATS_AI_CONVERSATION_ARCHITECTURE.md §12.5（CA-012）、§12.6、§15.
 *
 * 表示「租戶是否啟用各推薦類型」之 Tenant Policy 設定，為 Recommendation
 * Eligibility（CA-012）的第三個維度。Tenant-independent：同一套結構適用所有
 * 租戶，差異僅來自設定值（§15）。
 *
 * 本檔為純值物件 / 設定容器：不做判定（判定由 RecommendationEligibilityEvaluator），
 * 不管理 State、不組句。
 */
final class ConversationPolicy
{
    /** §12.5 主要推薦類型。 */
    public const RECOMMENDATION_PRODUCT = 'product';        // 商品
    public const RECOMMENDATION_CAMPAIGN = 'campaign';      // 活動
    public const RECOMMENDATION_COUPON = 'coupon';          // 優惠
    public const RECOMMENDATION_REVIEW_INVITE = 'review_invite'; // 評論邀請

    /** @var list<string> */
    private const VALID_TYPES = [
        self::RECOMMENDATION_PRODUCT,
        self::RECOMMENDATION_CAMPAIGN,
        self::RECOMMENDATION_COUPON,
        self::RECOMMENDATION_REVIEW_INVITE,
    ];

    /**
     * 各推薦類型是否啟用。
     *
     * 預設值（保守、Tenant-independent）：僅 product 啟用；活動 / 優惠 / 評論邀請
     * 預設關閉，需由租戶設定明確開啟（對齊 §12.6 Future Hooks 之保守原則）。
     *
     * @var array<string, bool>
     */
    private array $recommendationEnabled = [
        self::RECOMMENDATION_PRODUCT => true,
        self::RECOMMENDATION_CAMPAIGN => false,
        self::RECOMMENDATION_COUPON => false,
        self::RECOMMENDATION_REVIEW_INVITE => false,
    ];

    public static function isValidType(string $type): bool
    {
        return in_array($type, self::VALID_TYPES, true);
    }

    public static function assertValidType(string $type): void
    {
        if (!self::isValidType($type)) {
            throw new \InvalidArgumentException('invalid recommendation type: ' . $type);
        }
    }

    /**
     * @return list<string>
     */
    public static function allTypes(): array
    {
        return self::VALID_TYPES;
    }

    public static function create(): self
    {
        return new self();
    }

    public function isRecommendationEnabled(string $type): bool
    {
        self::assertValidType($type);

        return $this->recommendationEnabled[$type] ?? false;
    }

    public function enableRecommendation(string $type): self
    {
        self::assertValidType($type);
        $this->recommendationEnabled[$type] = true;

        return $this;
    }

    public function disableRecommendation(string $type): self
    {
        self::assertValidType($type);
        $this->recommendationEnabled[$type] = false;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'recommendation_enabled' => $this->recommendationEnabled,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $policy = new self();

        $enabled = $data['recommendation_enabled'] ?? [];
        if (is_array($enabled)) {
            foreach (self::VALID_TYPES as $type) {
                if (array_key_exists($type, $enabled)) {
                    $policy->recommendationEnabled[$type] = (bool) $enabled[$type];
                }
            }
        }

        return $policy;
    }
}
