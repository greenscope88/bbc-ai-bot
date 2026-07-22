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

        if (self::containsAny($text, ['真人客服', '找真人', '轉人工', '人工客服', '找客服'])) {
            return self::semantic('human_service', [], 0.95, false, '');
        }

        if (self::containsAny($text, ['客服電話', '公司地址', '護照', '有哪些服務', '取消規定', '可以刷卡'])) {
            return self::semantic('knowledge', [], 0.9, false, '');
        }

        if (self::containsAny($text, ['想出去玩', '幫我看看', '有推薦嗎', '我想旅遊', '我要去', '怎麼安排'])) {
            return self::semantic('ambiguous', [], 0.4, true, 'intent_ambiguous');
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
}
