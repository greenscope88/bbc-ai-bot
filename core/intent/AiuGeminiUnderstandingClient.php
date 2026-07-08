<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuPromptRequest.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuGeminiUnderstandingClientInterface.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuPromptBuilder.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'gemini_service.php';

/**
 * Phase 2-D Step 2-D-4 — Production Gemini Understanding Core client.
 *
 * SSOT: docs/BATS_AI_INTENT_UNDERSTANDING_V2.md §15 / §16
 */
final class AiuGeminiUnderstandingClient implements AiuGeminiUnderstandingClientInterface
{
    private AiuPromptBuilder $promptBuilder;

    /** @var callable|null */
    private $httpCaller;

    /**
     * @param callable|null $httpCaller Optional test hook: (string $prompt): array{ok:bool,text:?string,error:?string}
     */
    public function __construct(?AiuPromptBuilder $promptBuilder = null, ?callable $httpCaller = null)
    {
        $this->promptBuilder = $promptBuilder ?? new AiuPromptBuilder();
        $this->httpCaller = $httpCaller;
    }

    public function understand(AiuPromptRequest $request): array
    {
        $prompt = $this->promptBuilder->build($request);
        $result = $this->httpCaller !== null
            ? ($this->httpCaller)($prompt)
            : $this->callGeminiJson($prompt);

        if (($result['ok'] ?? false) !== true) {
            throw new \RuntimeException('Gemini understanding failed: ' . (string) ($result['error'] ?? 'unknown'));
        }

        $decoded = $this->decodeSemanticJson((string) ($result['text'] ?? ''));

        return $this->normalizeSemanticShape($decoded, $request->getCustomerUtterance());
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
    private function decodeSemanticJson(string $text): array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            throw new \RuntimeException('Gemini semantic JSON empty');
        }

        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/u', $trimmed, $m) === 1) {
            $trimmed = trim($m[1]);
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Gemini semantic JSON invalid');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array{
     *   intent: string,
     *   entity: array<string, mixed>,
     *   confidence: float,
     *   clarification: array{required: bool, reason: string},
     *   semantic_notes?: string
     * }
     */
    private function normalizeSemanticShape(array $decoded, string $utterance): array
    {
        $intent = trim((string) ($decoded['intent'] ?? ''));
        $entity = isset($decoded['entity']) && is_array($decoded['entity']) ? $decoded['entity'] : [];
        $confidence = isset($decoded['confidence']) ? (float) $decoded['confidence'] : 0.0;
        $clarification = isset($decoded['clarification']) && is_array($decoded['clarification'])
            ? $decoded['clarification']
            : [];

        if (!isset($entity['free_text']) || trim((string) $entity['free_text']) === '') {
            $entity['free_text'] = $utterance;
        }

        return [
            'intent' => $intent,
            'entity' => $entity,
            'confidence' => max(0.0, min(1.0, $confidence)),
            'clarification' => [
                'required' => (bool) ($clarification['required'] ?? false),
                'reason' => trim((string) ($clarification['reason'] ?? '')),
            ],
            'semantic_notes' => trim((string) ($decoded['semantic_notes'] ?? '')),
        ];
    }
}
