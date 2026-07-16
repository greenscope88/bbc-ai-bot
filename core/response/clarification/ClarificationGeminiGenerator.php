<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ClarificationContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ClarificationGeminiPromptBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'gemini_service.php';

/**
 * One application-level Gemini generation call for clarification JSON.
 *
 * Reuses callGeminiUrl transport retry only. No clarification-specific retry loop.
 */
final class ClarificationGeminiGenerator
{
    private ClarificationGeminiPromptBuilder $promptBuilder;

    /** @var callable|null */
    private $geminiCaller;

    private int $generationCallCount = 0;

    /**
     * @param callable|null $geminiCaller Optional stub: (string $prompt): array{ok:bool,text:?string,error:?string}
     */
    public function __construct(
        ?ClarificationGeminiPromptBuilder $promptBuilder = null,
        ?callable $geminiCaller = null
    ) {
        $this->promptBuilder = $promptBuilder ?? new ClarificationGeminiPromptBuilder();
        $this->geminiCaller = $geminiCaller;
    }

    /**
     * @return array{
     *   ok: bool,
     *   output: ?array<string, mixed>,
     *   error: ?string,
     *   failure_reason: ?string,
     *   generation_calls: int,
     *   prompt: string
     * }
     */
    public function generate(ClarificationContract $contract): array
    {
        $prompt = $this->promptBuilder->build($contract);
        $this->generationCallCount = 0;

        $result = $this->invokeOnce($prompt);
        $this->generationCallCount = 1;

        if (($result['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'output' => null,
                'error' => isset($result['error']) ? (string) $result['error'] : 'gemini_failed',
                'failure_reason' => 'clarification_gemini_failed',
                'generation_calls' => $this->generationCallCount,
                'prompt' => $prompt,
            ];
        }

        try {
            $decoded = $this->decodeJson((string) ($result['text'] ?? ''));
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'output' => null,
                'error' => $e->getMessage(),
                'failure_reason' => 'clarification_output_malformed',
                'generation_calls' => $this->generationCallCount,
                'prompt' => $prompt,
            ];
        }

        return [
            'ok' => true,
            'output' => $decoded,
            'error' => null,
            'failure_reason' => null,
            'generation_calls' => $this->generationCallCount,
            'prompt' => $prompt,
        ];
    }

    public function getGenerationCallCount(): int
    {
        return $this->generationCallCount;
    }

    /**
     * @return array{ok: bool, text: ?string, error: ?string}
     */
    private function invokeOnce(string $prompt): array
    {
        if ($this->geminiCaller !== null) {
            $out = ($this->geminiCaller)($prompt);
            return [
                'ok' => (bool) ($out['ok'] ?? false),
                'text' => isset($out['text']) ? (string) $out['text'] : null,
                'error' => isset($out['error']) ? (string) $out['error'] : null,
            ];
        }

        return $this->callGeminiJson($prompt);
    }

    /**
     * @return array{ok: bool, text: ?string, error: ?string}
     */
    private function callGeminiJson(string $prompt): array
    {
        $apiKey = readGeminiEnvValue('GEMINI_API_KEY');
        if ($apiKey === '') {
            return ['ok' => false, 'text' => null, 'error' => 'GEMINI_API_KEY 未設定'];
        }

        $requestPayload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'thinkingConfig' => [
                    'thinkingBudget' => 0,
                ],
            ],
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key='
            . rawurlencode($apiKey);

        // Single application-level call; callGeminiUrl owns transport retries.
        $http = callGeminiUrl($url, $requestPayload);

        return [
            'ok' => (bool) ($http['ok'] ?? false),
            'text' => isset($http['text']) ? (string) $http['text'] : null,
            'error' => isset($http['error']) ? (string) $http['error'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $text): array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            throw new \RuntimeException('clarification gemini JSON empty');
        }

        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/u', $trimmed, $m) === 1) {
            $trimmed = trim($m[1]);
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('clarification gemini JSON invalid');
        }

        return $decoded;
    }
}
