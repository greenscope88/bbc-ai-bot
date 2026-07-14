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
            ->setConfidence($confidence);

        return $result;
    }
}
