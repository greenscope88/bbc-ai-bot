<?php
declare(strict_types=1);

/**
 * Phase 9-C-2B-3 — Human Service fallback replies (centralized persona).
 *
 * Runtime must not hardcode human-service copy; delegate here.
 * Future: per-tenant tone via tenant_key registry.
 */
final class HumanServiceResponseComposer
{
    /** @var list<string> */
    private const DEFAULT_OPENING_POOL = [
        '您好 😊',
        '哈囉 👋',
    ];

    /** @var list<string> */
    private const DEFAULT_BODY_POOL = [
        "這個問題我目前無法從資料庫找到確切答案，\n已為您轉接人工客服協助。",
        "這題目前沒有找到可引用的正式資料，\n我們會由人工客服為您進一步協助。",
    ];

    /** @var list<string> */
    private const DEFAULT_CLOSING_POOL = [
        "請稍候，\n客服人員會盡快回覆您 ✈️",
        "感謝您的耐心等候，\n我們的客服會盡快協助您 😊",
    ];

    /** @var callable(string, int): int|null */
    private $indexPicker;

    /**
     * @param callable(string, int): int|null $indexPicker
     */
    public function __construct(?callable $indexPicker = null)
    {
        $this->indexPicker = $indexPicker;
    }

    public function compose(?string $tenantKey = null, ?string $companyName = null): string
    {
        unset($tenantKey);

        $companyName = trim((string) $companyName);
        $opening = $this->pickFromPool('human_opening', self::DEFAULT_OPENING_POOL);
        $body = $this->pickFromPool('human_body', self::DEFAULT_BODY_POOL);
        $closing = $this->pickFromPool('human_closing', self::DEFAULT_CLOSING_POOL);

        if ($companyName !== '') {
            $body = str_replace('人工客服', $companyName . '人工客服', $body);
        }

        return $opening . "\n\n" . $body . "\n\n" . $closing;
    }

    /**
     * @param list<string> $pool
     */
    private function pickFromPool(string $poolKey, array $pool): string
    {
        if ($pool === []) {
            return '';
        }

        $index = 0;
        if ($this->indexPicker !== null) {
            $index = (int) ($this->indexPicker)($poolKey, count($pool));
        }

        $index = max(0, min(count($pool) - 1, $index));

        return $pool[$index];
    }
}
