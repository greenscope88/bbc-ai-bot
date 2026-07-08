<?php

declare(strict_types=1);



require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntimeInterface.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentContextLoader.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'DispatchPlan.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ExecutionHint.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuPromptRequest.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuGeminiUnderstandingClientInterface.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuPromptBuilder.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuGeminiUnderstandingClient.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuGeminiUnderstandingClientStub.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuSemanticJsonNormalizer.php';

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'conversation' . DIRECTORY_SEPARATOR . 'ConversationOwner.php';



/**

 * Phase 2-D Step 2-D-4 — AI Intent Understanding Runtime（Gemini Understanding Core）.

 *

 * SSOT: docs/BATS_AI_INTENT_UNDERSTANDING_V2.md §15 / §16 / F-9 / F-10

 *

 * Gemini 為唯一 Semantic Understanding Core；AIU Runtime 僅編排 Prompt、Normalize、Dispatch Planning。

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

        $normalized = $this->semanticNormalizer->normalize($semanticRaw, $message);



        $detected = $normalized['intent'];

        $entity = $normalized['entity'];

        $clarificationRequired = (bool) $normalized['clarification_required'];

        $clarificationReason = (string) $normalized['clarification_reason'];



        if ($detected === AiIntentCategory::PRODUCT_SEARCH && $this->isHumanServiceEntity($entity)) {

            $detected = AiIntentCategory::KNOWLEDGE;

        }



        $owner = $snapshot['owner_snapshot'];

        [$dispatchPlan, $executionHint] = $this->planDispatch($detected, $owner, $clarificationRequired, $entity);



        $result = new AiIntentUnderstandingResult($detected, $dispatchPlan);

        $result

            ->setEntity($entity)

            ->setContextSnapshot($snapshot['context_snapshot'])

            ->setOwnerSnapshot($owner)

            ->setConversationStage($snapshot['conversation_stage'])

            ->setResumeContext($snapshot['resume_context'])

            ->setClarification($clarificationRequired, $clarificationReason)

            ->setExecutionHint($executionHint);



        return $result;

    }



    /**

     * @param array<string, mixed> $entity

     * @return array{0: string, 1: string|null}

     */

    private function planDispatch(string $category, string $owner, bool $clarificationRequired, array $entity): array

    {

        if ($owner === ConversationOwner::HUMAN) {

            return [DispatchPlan::HUMAN, ExecutionHint::HUMAN_BLOCKED];

        }



        if ($this->isHumanServiceEntity($entity)) {

            return [DispatchPlan::KNOWLEDGE, ExecutionHint::KNOWLEDGE_RESOLVE];

        }



        if ($category === AiIntentCategory::AMBIGUOUS) {

            return [DispatchPlan::CLARIFICATION, null];

        }



        if ($category === AiIntentCategory::PRODUCT_SEARCH) {

            if ($clarificationRequired) {

                return [DispatchPlan::CLARIFICATION, ExecutionHint::PRODUCT_CLARIFICATION];

            }



            return [DispatchPlan::PRODUCT, ExecutionHint::PRODUCT_SEARCH];

        }



        return [DispatchPlan::KNOWLEDGE, ExecutionHint::KNOWLEDGE_RESOLVE];

    }



    /**

     * @param array<string, mixed> $entity

     */

    private function isHumanServiceEntity(array $entity): bool

    {

        return ($entity['human_service_request'] ?? false) === true;

    }

}


