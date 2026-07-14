<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuGeminiUnderstandingClientInterface.php';

/**
 * Gemini Understanding Client ABI.
 *
 * Output shape: AIU v2 Gemini Output Contract (intent / entities / confidence / clarification).
 */
interface AiuGeminiUnderstandingClientInterface
{
    /**
     * @return array{
     *   intent: string,
     *   entities: array<string, mixed>,
     *   confidence: float,
     *   clarification: array{required: bool, reason: string}
     * }
     */
    public function understand(AiuPromptRequest $request): array;
}
