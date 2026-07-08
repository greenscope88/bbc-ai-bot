<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuPromptRequest.php';

/**
 * Phase 2-D Step 2-D-4 — Gemini Understanding Core client ABI.
 *
 * SSOT: docs/BATS_AI_INTENT_UNDERSTANDING_V2.md §15 / §16
 */
interface AiuGeminiUnderstandingClientInterface
{
    /**
     * @return array{
     *   intent: string,
     *   entity: array<string, mixed>,
     *   confidence: float,
     *   clarification: array{required: bool, reason: string},
     *   semantic_notes?: string
     * }
     */
    public function understand(AiuPromptRequest $request): array;
}
