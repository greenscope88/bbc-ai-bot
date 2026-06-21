<?php
declare(strict_types=1);

/**
 * Company profile knowledge replies (Phase 9-C-2B-2A, AD-007 MVP pools).
 *
 * Facts from company_profile only; wording may vary; persona warm / professional.
 */
final class KnowledgeResponseComposer
{
    /** @var list<string> */
    private const OPENING_POOL = [
        '您好 😊',
        '哈囉 👋',
        '您好呀 🌸',
    ];

    /** @var array<string, list<string>> */
    private const CLOSING_POOL = [
        'phone' => [
            "若有旅遊規劃需求，\n歡迎隨時與我們聯繫 ✈️",
            "如需旅遊諮詢，\n歡迎再與我們聯繫 😊",
        ],
        'address' => [
            "期待有機會為您服務 🌸",
            "歡迎來店洽詢旅遊方案 😊",
        ],
        'business_hours' => [
            "如需旅遊諮詢，\n歡迎於營業時間與我們聯繫 ✈️",
            "若有行程規劃需求，\n歡迎於上述時段與我們聯繫 😊",
        ],
        'website' => [
            "歡迎至官網查看更多旅遊資訊 ✈️",
            "若有旅遊需求，\n也歡迎透過官網與我們聯繫 😊",
        ],
        'email' => [
            "若有旅遊相關問題，\n歡迎來信與我們聯繫 ✈️",
            "期待為您的旅遊規劃提供協助 😊",
        ],
        'line_official' => [
            "歡迎加入官方 LINE，\n隨時為您提供旅遊諮詢 ✈️",
            "若有旅遊需求，\n歡迎透過 LINE 與我們聯繫 😊",
        ],
        'summary' => [
            "若有旅遊相關需求，\n歡迎隨時詢問 ✈️",
            "如需行程建議，\n歡迎再告訴我們您的想法 😊",
        ],
        'company_name' => [
            "若有旅遊規劃需求，\n歡迎隨時與我們聯繫 ✈️",
        ],
        'default' => [
            "若有旅遊相關需求，\n歡迎隨時詢問 ✈️",
        ],
        'service_qa' => [
            "若還有其他旅遊相關問題，\n歡迎隨時詢問 ✈️",
            "如需更多協助，\n歡迎再告訴我們 😊",
        ],
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

    public function composeCompanyProfileReply(string $field, string $companyName, string $value): string
    {
        $companyName = trim($companyName) !== '' ? trim($companyName) : '我們';
        $label = $this->fieldLabel($field, $companyName);
        $opening = $this->pickFromPool('opening', self::OPENING_POOL);
        $closing = $this->pickFromPool($field, self::CLOSING_POOL[$field] ?? self::CLOSING_POOL['default']);

        return $opening . "\n\n"
            . $label . "\n\n"
            . $value . "\n\n"
            . $closing;
    }

    public function composeEmptyFieldReply(): string
    {
        return $this->pickFromPool('opening', self::OPENING_POOL) . "\n\n"
            . "目前尚未提供相關資訊，\n"
            . '請與客服人員聯繫。';
    }

    public function composeMissingProfileReply(): string
    {
        return $this->pickFromPool('opening', self::OPENING_POOL) . "\n\n"
            . "目前尚未提供相關資訊，\n"
            . '請與客服人員聯繫。';
    }

    public function composeUnresolvableReply(): string
    {
        return $this->composeMissingProfileReply();
    }

    public function composeServiceQaReply(string $groundedFact, ?string $category = null): string
    {
        $groundedFact = trim($groundedFact);
        if ($groundedFact === '') {
            return $this->composeEmptyFieldReply();
        }

        $opening = $this->pickFromPool('opening', self::OPENING_POOL);
        $label = $this->serviceQaLabel($category);
        $closing = $this->pickFromPool('service_qa', self::CLOSING_POOL['service_qa']);

        return $opening . "\n\n"
            . $label . "\n\n"
            . $groundedFact . "\n\n"
            . $closing;
    }

    private function serviceQaLabel(?string $category): string
    {
        $category = trim((string) $category);
        if ($category !== '') {
            return '關於' . $category . '，為您整理如下：';
        }

        return '為您整理以下資訊：';
    }

    private function fieldLabel(string $field, string $companyName): string
    {
        if ($field === KnowledgeQueryFieldResolver::FIELD_PHONE) {
            return $companyName . '客服電話為：';
        }
        if ($field === KnowledgeQueryFieldResolver::FIELD_ADDRESS) {
            return $companyName . '地址如下：';
        }
        if ($field === KnowledgeQueryFieldResolver::FIELD_BUSINESS_HOURS) {
            return '目前營業時間為：';
        }
        if ($field === KnowledgeQueryFieldResolver::FIELD_WEBSITE) {
            return $companyName . '官方網站為：';
        }
        if ($field === KnowledgeQueryFieldResolver::FIELD_EMAIL) {
            return $companyName . '客服信箱為：';
        }
        if ($field === KnowledgeQueryFieldResolver::FIELD_LINE_OFFICIAL) {
            return $companyName . '官方 LINE 為：';
        }
        if ($field === KnowledgeQueryFieldResolver::FIELD_SUMMARY) {
            return $companyName . '主要提供：';
        }
        if ($field === KnowledgeQueryFieldResolver::FIELD_COMPANY_NAME) {
            return '公司名稱為：';
        }

        return $companyName . '相關資訊如下：';
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
