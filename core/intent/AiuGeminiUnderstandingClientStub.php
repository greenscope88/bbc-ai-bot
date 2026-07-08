<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuPromptRequest.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuGeminiUnderstandingClientInterface.php';

/**
 * Test-only Gemini client stub — simulates Semantic JSON for unit tests (not production path).
 */
final class AiuGeminiUnderstandingClientStub implements AiuGeminiUnderstandingClientInterface
{
    /** @var callable */
    private $resolver;

    /**
     * @param callable(AiuPromptRequest): array $resolver
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? static function (AiuPromptRequest $request): array {
            return AiuGeminiUnderstandingClientStub::defaultResolver($request);
        };
    }

    public function understand(AiuPromptRequest $request): array
    {
        return ($this->resolver)($request);
    }

    /**
     * Default deterministic semantic responses for existing AIU unit tests.
     *
     * @return array{
     *   intent: string,
     *   entity: array<string, mixed>,
     *   confidence: float,
     *   clarification: array{required: bool, reason: string},
     *   semantic_notes?: string
     * }
     */
    public static function defaultResolver(AiuPromptRequest $request): array
    {
        $text = trim($request->getCustomerUtterance());
        $compact = preg_replace('/\s+/u', '', $text) ?? $text;

        if (self::containsAny($text, ['真人客服', '找真人', '轉人工', '人工客服', '找客服'])) {
            return self::semantic(
                AiIntentCategory::KNOWLEDGE,
                ['human_service_request' => true, 'free_text' => $text],
                0.95,
                false,
                ''
            );
        }

        if (self::containsAny($text, ['客服電話', '公司地址', '護照', '有哪些服務', '取消規定', '可以刷卡'])) {
            return self::semantic(AiIntentCategory::KNOWLEDGE, ['free_text' => $text], 0.9, false, '');
        }

        if (self::containsAny($text, ['想出去玩', '幫我看看', '有推薦嗎', '我想旅遊', '我要去', '怎麼安排'])) {
            return self::semantic(AiIntentCategory::AMBIGUOUS, [], 0.4, true, 'intent_ambiguous');
        }

        if (self::containsAny($text, ['東京', '北海道', '火星', '大阪', '京都', '首爾', '曼谷', '新加坡', '歐洲', '日本', '自由行', '旅遊', '行程', '團'])) {
            $destination = self::extractDestination($text);
            if ($destination === '' && mb_strpos($text, '自由行', 0, 'UTF-8') !== false) {
                $destination = '東京';
            }
            $hasDate = self::containsMonthOrDate($text);
            $duration = self::extractDuration($compact);
            $entity = [
                'destination' => $destination,
                'free_text' => $text,
            ];
            if ($duration !== null) {
                $entity['duration'] = $duration;
            }
            if ($hasDate) {
                $entity['date_from'] = self::extractMonthIso($text);
            }

            $needsClarification = !$hasDate;

            return self::semantic(
                AiIntentCategory::PRODUCT_SEARCH,
                $entity,
                $needsClarification ? 0.5 : 0.92,
                $needsClarification,
                $needsClarification ? 'missing_travel_dates' : ''
            );
        }

        return self::semantic(AiIntentCategory::KNOWLEDGE, ['free_text' => $text], 0.7, false, '');
    }

    /**
     * @param array<string, mixed> $entity
     * @return array{
     *   intent: string,
     *   entity: array<string, mixed>,
     *   confidence: float,
     *   clarification: array{required: bool, reason: string},
     *   semantic_notes?: string
     * }
     */
    private static function semantic(
        string $intent,
        array $entity,
        float $confidence,
        bool $clarificationRequired,
        string $clarificationReason
    ): array {
        return [
            'intent' => $intent,
            'entity' => $entity,
            'confidence' => $confidence,
            'clarification' => [
                'required' => $clarificationRequired,
                'reason' => $clarificationReason,
            ],
            'semantic_notes' => '',
        ];
    }

    private static function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (mb_strpos($text, $needle, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    private static function containsMonthOrDate(string $text): bool
    {
        return preg_match('/\d{1,2}月|一月|二月|三月|四月|五月|六月|七月|八月|九月|十月|十一月|十二月/u', $text) === 1;
    }

    private static function extractDestination(string $text): string
    {
        foreach (['北海道', '東京', '火星', '大阪', '京都', '首爾', '曼谷', '新加坡', '歐洲', '日本'] as $dest) {
            if (mb_strpos($text, $dest, 0, 'UTF-8') !== false) {
                return $dest;
            }
        }

        return '';
    }

    private static function extractDuration(string $compact): ?string
    {
        if (preg_match('/(\d+)日/u', $compact, $m) === 1) {
            return $m[1] . '日';
        }
        if (preg_match('/([一二三四五六七八九十]+)日/u', $compact, $m) === 1) {
            return $m[1] . '日';
        }

        return null;
    }

    private static function extractMonthIso(string $text): string
    {
        if (preg_match('/(\d{1,2})月/u', $text, $m) === 1) {
            $month = (int) $m[1];

            return sprintf('2026-%02d-01', max(1, min(12, $month)));
        }
        if (mb_strpos($text, '八月', 0, 'UTF-8') !== false) {
            return '2026-08-01';
        }
        if (mb_strpos($text, '3月', 0, 'UTF-8') !== false || mb_strpos($text, '三月', 0, 'UTF-8') !== false) {
            return '2026-03-01';
        }

        return '2026-08-01';
    }
}
