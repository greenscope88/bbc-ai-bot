<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'date_clarification_line_formatter.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'tour_fallback_formatter.php';

/**
 * Resolve LINE reply text for tour_query: fixed system list when context exists (scheme C).
 */
final class TourLineReplyComposer
{
    /**
     * @param callable(): array{ok: bool, text?: string|null} $geminiCaller
     * @return array{
     *   reply_text: string,
     *   ai_ok: bool,
     *   used_fixed_tour_list: bool,
     *   used_tour_fallback: bool
     * }
     */
    public static function resolve(
        string $prompt,
        string $tourContext,
        callable $geminiCaller,
        bool $allowFixedFormatter = true
    ): array {
        $ctx = trim($tourContext);
        if ($allowFixedFormatter && $ctx !== '' && DateClarificationLineFormatter::isClarificationContext($ctx)) {
            $clarificationReply = DateClarificationLineFormatter::formatFromTourContext($ctx);
            if ($clarificationReply !== '') {
                return [
                    'reply_text' => $clarificationReply,
                    'ai_ok' => true,
                    'used_fixed_tour_list' => false,
                    'used_tour_fallback' => false,
                ];
            }
        }

        if ($allowFixedFormatter && $ctx !== '') {
            $fixed = TourFallbackFormatter::formatFromTourContext($ctx);
            if ($fixed !== '') {
                return [
                    'reply_text' => $fixed,
                    'ai_ok' => true,
                    'used_fixed_tour_list' => true,
                    'used_tour_fallback' => false,
                ];
            }
        }

        $geminiResult = $geminiCaller();
        if (($geminiResult['ok'] ?? false) === true) {
            return [
                'reply_text' => (string) ($geminiResult['text'] ?? ''),
                'ai_ok' => true,
                'used_fixed_tour_list' => false,
                'used_tour_fallback' => false,
            ];
        }

        if ($allowFixedFormatter && $ctx !== '') {
            $fallback = TourFallbackFormatter::formatFromTourContext($ctx);
            if ($fallback !== '') {
                return [
                    'reply_text' => $fallback,
                    'ai_ok' => true,
                    'used_fixed_tour_list' => false,
                    'used_tour_fallback' => true,
                ];
            }
        }

        return [
            'reply_text' => '您好，目前系統較忙碌，請稍後再試，或聯繫客服為您服務。',
            'ai_ok' => false,
            'used_fixed_tour_list' => false,
            'used_tour_fallback' => false,
        ];
    }
}
