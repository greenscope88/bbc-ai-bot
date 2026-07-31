<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuPromptRequest.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuGeminiUnderstandingClientInterface.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuPromptBuilder.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'gemini_service.php';

/**
 * Production Gemini Understanding Core client.
 *
 * SSOT: AIU v2 Gemini Output Contract v1.0 Rev.1 (Frozen) — Gap B0-1 / B0-2 / B0-6.
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

        return $this->normalizeSemanticShape($decoded);
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
     *   entities: array<string, mixed>,
     *   confidence: float,
     *   clarification: array{required: bool, reason: string},
     *   resume_disposition: string|null
     * }
     */
    private function normalizeSemanticShape(array $decoded): array
    {
        $this->assertOutputContractEntities($decoded);

        $intent = trim((string) ($decoded['intent'] ?? ''));
        $entities = $decoded['entities'];

        $confidence = isset($decoded['confidence']) ? (float) $decoded['confidence'] : 0.0;
        $clarification = isset($decoded['clarification']) && is_array($decoded['clarification'])
            ? $decoded['clarification']
            : [];

        $resumeDisposition = null;
        if (array_key_exists('resume_disposition', $decoded)) {
            $resumeDisposition = trim((string) $decoded['resume_disposition']);
        }

        // B0-2: never emit semantic_notes.
        return [
            'intent' => $intent,
            'entities' => $entities,
            'confidence' => max(0.0, min(1.0, $confidence)),
            'clarification' => [
                'required' => (bool) ($clarification['required'] ?? false),
                'reason' => trim((string) ($clarification['reason'] ?? '')),
            ],
            'resume_disposition' => $resumeDisposition,
        ];
    }

    /**
     * FR-1: Output Contract entities gate — invalid shape stops AIU flow (RuntimeException
     * propagates to Selector legacy fallback per Integration Spec §4).
     *
     * @param array<string, mixed> $decoded
     */
    private function assertOutputContractEntities(array $decoded): void
    {
        if (array_key_exists('entity', $decoded)) {
            throw new \RuntimeException('Gemini output contract invalid: legacy entity key is forbidden');
        }

        if (!array_key_exists('entities', $decoded) || !is_array($decoded['entities'])) {
            throw new \RuntimeException('Gemini output contract invalid: entities must be present as array');
        }

        $entities = $decoded['entities'];
        if ($entities !== [] && array_keys($entities) === range(0, count($entities) - 1)) {
            throw new \RuntimeException('Gemini output contract invalid: entities must be an object');
        }

        $this->assertSearchKeywordRoleContract($entities);
    }

    /**
     * B0-LINE-01D-3J-9: structured keyword role contract gate.
     *
     * Validates only structure and cross-field equality between
     * entities.search_keyword_components and entities.search_keyword_tokens.
     * No semantic parsing, literal checks, or classification is performed here;
     * component metadata is non-execution metadata for downstream execution.
     *
     * @param array<string, mixed> $entities
     */
    private function assertSearchKeywordRoleContract(array $entities): void
    {
        if (!array_key_exists('search_keyword_components', $entities) || !is_array($entities['search_keyword_components'])) {
            throw new \RuntimeException(
                'Gemini output contract invalid: search_keyword_components must be present as an array'
            );
        }

        if (!array_key_exists('search_keyword_tokens', $entities) || !is_array($entities['search_keyword_tokens'])) {
            throw new \RuntimeException(
                'Gemini output contract invalid: search_keyword_tokens must be present as an array'
            );
        }

        $components = $entities['search_keyword_components'];
        if ($components !== [] && array_keys($components) !== range(0, count($components) - 1)) {
            throw new \RuntimeException(
                'Gemini output contract invalid: search_keyword_components must be a list'
            );
        }

        $tokens = $entities['search_keyword_tokens'];
        if ($tokens !== [] && array_keys($tokens) !== range(0, count($tokens) - 1)) {
            throw new \RuntimeException(
                'Gemini output contract invalid: search_keyword_tokens must be a list'
            );
        }

        $keepSurfaces = [];
        $seenKeepSurfaces = [];
        foreach ($components as $component) {
            if (!is_array($component)) {
                throw new \RuntimeException(
                    'Gemini output contract invalid: search_keyword_components element must be an object'
                );
            }

            $surface = $component['surface'] ?? null;
            if (!is_string($surface) || trim($surface) === '') {
                throw new \RuntimeException(
                    'Gemini output contract invalid: search_keyword_components.surface must be a non-empty string'
                );
            }

            $semanticRole = $component['semantic_role'] ?? null;
            if ($semanticRole !== 'product_constraint' && $semanticRole !== 'catalog_object_restatement') {
                throw new \RuntimeException(
                    'Gemini output contract invalid: search_keyword_components.semantic_role must be '
                    . 'exactly product_constraint or catalog_object_restatement'
                );
            }

            $decision = $component['decision'] ?? null;
            if ($decision !== 'keep' && $decision !== 'omit') {
                throw new \RuntimeException(
                    'Gemini output contract invalid: search_keyword_components.decision must be exactly keep or omit'
                );
            }

            $decisionReason = $component['decision_reason'] ?? null;
            if (!is_string($decisionReason) || trim($decisionReason) === '') {
                throw new \RuntimeException(
                    'Gemini output contract invalid: search_keyword_components.decision_reason must be a non-empty string'
                );
            }

            if ($semanticRole === 'catalog_object_restatement' && $decision === 'keep') {
                throw new \RuntimeException(
                    'Gemini output contract invalid: a catalog_object_restatement component must not be decision=keep'
                );
            }

            if ($decision === 'keep') {
                if (isset($seenKeepSurfaces[$surface])) {
                    throw new \RuntimeException(
                        'Gemini output contract invalid: decision=keep component surfaces must be unique'
                    );
                }
                $seenKeepSurfaces[$surface] = true;
                $keepSurfaces[] = $surface;
            }
        }

        foreach ($tokens as $token) {
            if (!is_string($token)) {
                throw new \RuntimeException(
                    'Gemini output contract invalid: search_keyword_tokens element must be a string'
                );
            }
        }

        if ($tokens !== $keepSurfaces) {
            throw new \RuntimeException(
                'Gemini output contract invalid: search_keyword_tokens must exactly equal, in order and count, '
                . 'the surface of every decision=keep search_keyword_components entry'
            );
        }
    }
}
