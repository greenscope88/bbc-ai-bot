<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuPromptRequest.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuGeminiUnderstandingClientInterface.php';

/**
 * Test-only Gemini client stub — Output Contract shape (entities, no semantic_notes).
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
     * @return array{
     *   intent: string,
     *   entities: array<string, mixed>,
     *   confidence: float,
     *   clarification: array{required: bool, reason: string}
     * }
     */
    public static function defaultResolver(AiuPromptRequest $request): array
    {
        $text = trim($request->getCustomerUtterance());
        $compact = preg_replace('/\s+/u', '', $text) ?? $text;

        if (self::containsAny($text, ['真人客服', '找真人', '轉人工', '人工客服', '找客服'])) {
            return self::semantic('human_service', [], 0.95, false, '');
        }

        if (self::containsAny($text, ['客服電話', '公司地址', '護照', '有哪些服務', '取消規定', '可以刷卡'])) {
            return self::semantic('knowledge', [], 0.9, false, '');
        }

        if (self::containsAny($text, ['想出去玩', '幫我看看', '有推薦嗎', '我想旅遊', '我要去', '怎麼安排'])) {
            return self::semantic('ambiguous', [], 0.4, true, 'intent_ambiguous');
        }

        if (self::containsAny($text, ['東京', '北海道', '火星', '大阪', '京都', '首爾', '曼谷', '新加坡', '歐洲', '日本', '自由行', '旅遊', '行程', '團'])) {
            $destination = self::extractDestination($text);
            if ($destination === '' && mb_strpos($text, '自由行', 0, 'UTF-8') !== false) {
                $destination = '東京';
            }
            $hasDate = self::containsMonthOrDate($text);
            $duration = self::extractDuration($compact);
            $entities = [
                'destination' => $destination !== '' ? [$destination] : [],
            ];
            if ($duration !== null) {
                $entities['duration'] = $duration;
                $days = (int) preg_replace('/\D+/', '', $duration);
                if ($days > 0) {
                    $entities['duration_days'] = $days;
                }
            }
            if ($hasDate) {
                $range = self::extractMonthRange($text);
                $entities['date_range'] = ['from' => $range['from'], 'to' => $range['to']];
                $entities['date_expression'] = $range['expression'];
                $entities['date_from'] = $range['from'];
                $entities['date_to'] = $range['to'];
            }

            $needsClarification = !$hasDate;

            return self::semantic(
                'product_search',
                $entities,
                $needsClarification ? 0.5 : 0.92,
                $needsClarification,
                $needsClarification ? 'missing_travel_dates' : ''
            );
        }

        return self::semantic('knowledge', [], 0.7, false, '');
    }

    /**
     * @param array<string, mixed> $entities
     * @return array{
     *   intent: string,
     *   entities: array<string, mixed>,
     *   confidence: float,
     *   clarification: array{required: bool, reason: string}
     * }
     */
    private static function semantic(
        string $intent,
        array $entities,
        float $confidence,
        bool $clarificationRequired,
        string $clarificationReason
    ): array {
        return [
            'intent' => $intent,
            'entities' => $entities,
            'confidence' => $confidence,
            'clarification' => [
                'required' => $clarificationRequired,
                'reason' => $clarificationReason,
            ],
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

    /**
     * Stub-only Gemini simulation of month expressions (not Normalize re-inference).
     *
     * @return array{from: string, to: string, expression: string}
     */
    private static function extractMonthRange(string $text): array
    {
        $year = 2026;
        if (preg_match('/(20\d{2})\s*年/u', $text, $ym) === 1) {
            $year = (int) $ym[1];
        }

        $month = 8;
        if (preg_match('/(\d{1,2})月/u', $text, $m) === 1) {
            $month = max(1, min(12, (int) $m[1]));
        } elseif (mb_strpos($text, '八月', 0, 'UTF-8') !== false) {
            $month = 8;
        } elseif (mb_strpos($text, '三月', 0, 'UTF-8') !== false) {
            $month = 3;
        }

        $lastDay = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
            ->modify('last day of this month')
            ->format('d');

        $fromDay = 1;
        $toDay = $lastDay;
        $expression = $month . '月';
        if (mb_strpos($text, '月初', 0, 'UTF-8') !== false) {
            $toDay = 10;
            $expression = $month . '月初';
        } elseif (mb_strpos($text, '月中', 0, 'UTF-8') !== false) {
            $fromDay = 11;
            $toDay = 20;
            $expression = $month . '月中';
        } elseif (mb_strpos($text, '月底', 0, 'UTF-8') !== false) {
            $fromDay = 21;
            $expression = $month . '月底';
        }

        return [
            'from' => sprintf('%04d-%02d-%02d', $year, $month, $fromDay),
            'to' => sprintf('%04d-%02d-%02d', $year, $month, $toDay),
            'expression' => $expression,
        ];
    }

    private static function extractMonthIso(string $text): string
    {
        return self::extractMonthRange($text)['from'];
    }
}
