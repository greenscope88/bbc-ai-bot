<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntimeInterface.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentContextLoader.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuPromptRequest.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuGeminiUnderstandingClientInterface.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuPromptBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuGeminiUnderstandingClient.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuGeminiUnderstandingClientStub.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuSemanticJsonNormalizer.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingResult.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'conversation' . DIRECTORY_SEPARATOR . 'ConversationOwner.php';

/**
 * AI Intent Understanding Runtime — Gemini Understanding + Normalize only.
 *
 * B0-3: Does NOT plan dispatch_plan / execution_hint (BBC AI Runtime / B1).
 */
final class AiIntentUnderstandingRuntime implements AiIntentUnderstandingRuntimeInterface
{
    public const PHASE = '2-D-4';

    private AiuGeminiUnderstandingClientInterface $geminiClient;
    private AiuPromptBuilder $promptBuilder;
    private AiuSemanticJsonNormalizer $semanticNormalizer;
    private AiIntentContextLoader $contextLoader;

    public function __construct(
        ?AiuGeminiUnderstandingClientInterface $geminiClient = null,
        ?AiuPromptBuilder $promptBuilder = null,
        ?AiuSemanticJsonNormalizer $semanticNormalizer = null,
        ?AiIntentContextLoader $contextLoader = null
    ) {
        $this->geminiClient = $geminiClient ?? new AiuGeminiUnderstandingClient();
        $this->promptBuilder = $promptBuilder ?? new AiuPromptBuilder();
        $this->semanticNormalizer = $semanticNormalizer ?? new AiuSemanticJsonNormalizer();
        $this->contextLoader = $contextLoader ?? new AiIntentContextLoader();
    }

    public static function createForTesting(
        ?AiuGeminiUnderstandingClientInterface $geminiClient = null,
        ?AiIntentContextLoader $contextLoader = null
    ): self {
        return new self(
            $geminiClient ?? new AiuGeminiUnderstandingClientStub(),
            null,
            null,
            $contextLoader ?? AiIntentContextLoader::createForTesting()
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $context
     */
    public function understand(string $customerMessage, array $context = []): AiIntentUnderstandingResult
    {
        $message = trim($customerMessage);
        $now = ($context['now'] ?? null) instanceof \DateTimeImmutable ? $context['now'] : null;
        $referenceDate = ($context['reference_date'] ?? null) instanceof \DateTimeImmutable
            ? $context['reference_date']
            : $now;

        $conversationId = isset($context['conversation_id']) ? (string) $context['conversation_id'] : '';
        $tenantSno = isset($context['tenant_sno']) ? trim((string) $context['tenant_sno']) : '';
        $channel = isset($context['channel']) ? trim((string) $context['channel']) : 'line';

        $snapshot = $this->contextLoader->load($conversationId, $now);

        $promptRequest = new AiuPromptRequest(
            $tenantSno,
            $channel,
            $message,
            $snapshot['context_snapshot'],
            $snapshot['owner_snapshot'],
            $snapshot['conversation_stage'],
            $snapshot['resume_context'],
            isset($context['request_id']) ? (string) $context['request_id'] : null
        );

        $semanticRaw = $this->geminiClient->understand($promptRequest);
        $rawDatePresence = self::observeRawDateFieldPresence($semanticRaw);
        $normalized = $this->semanticNormalizer->normalize($semanticRaw, $message, $referenceDate);

        $detected = $normalized['intent'];
        $entities = $normalized['entities'];
        $clarificationRequired = (bool) $normalized['clarification_required'];
        $clarificationReason = (string) $normalized['clarification_reason'];
        $confidence = (float) $normalized['confidence'];

        $result = new AiIntentUnderstandingResult($detected);
        $result
            ->setEntities($entities)
            ->setContextSnapshot($snapshot['context_snapshot'])
            ->setOwnerSnapshot($snapshot['owner_snapshot'])
            ->setConversationStage($snapshot['conversation_stage'])
            ->setResumeContext($snapshot['resume_context'])
            ->setClarification($clarificationRequired, $clarificationReason)
            ->setConfidence($confidence)
            ->attachDatePipelineRawPresence($rawDatePresence);

        return $result;
    }

    /**
     * Observability-only: boolean presence of Gemini raw date fields.
     * Does not parse utterance, invent dates, or alter $semanticRaw.
     *
     * @param array<string, mixed> $semanticRaw
     * @return array{
     *   raw_has_date_range: bool,
     *   raw_has_date_from: bool,
     *   raw_has_date_to: bool,
     *   raw_has_date_expression: bool
     * }
     */
    public static function observeRawDateFieldPresence(array $semanticRaw): array
    {
        $entities = isset($semanticRaw['entities']) && is_array($semanticRaw['entities'])
            ? $semanticRaw['entities']
            : [];

        $dateRange = $entities['date_range'] ?? null;
        $hasDateRange = is_array($dateRange);

        $rawFrom = $entities['date_from'] ?? null;
        if (!self::rawScalarPresent($rawFrom) && $hasDateRange) {
            $rawFrom = $dateRange['from'] ?? null;
        }

        $rawTo = $entities['date_to'] ?? null;
        if (!self::rawScalarPresent($rawTo) && $hasDateRange) {
            $rawTo = $dateRange['to'] ?? null;
        }

        return [
            'raw_has_date_range' => $hasDateRange,
            'raw_has_date_from' => self::rawScalarPresent($rawFrom),
            'raw_has_date_to' => self::rawScalarPresent($rawTo),
            'raw_has_date_expression' => self::rawScalarPresent($entities['date_expression'] ?? null),
        ];
    }

    /**
     * @param mixed $value
     */
    private static function rawScalarPresent($value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_int($value) || is_float($value)) {
            return true;
        }

        return false;
    }
}
