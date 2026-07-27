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
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuOutputContractValidator.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuSemanticJsonNormalizer.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingResult.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeIdentity.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeState.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeStateFactory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeStateStore.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeLoadResult.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeMutationResult.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeDispositionContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuClarificationReasonContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuSearchKeywordTokenProjector.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuSearchKeywordProjectionResult.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuSearchKeywordTokenException.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'conversation' . DIRECTORY_SEPARATOR . 'ConversationOwner.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logger.php';

/**
 * AI Intent Understanding Runtime — Gemini Understanding + Normalize + Resume State lifecycle.
 *
 * B0-3: Does NOT plan dispatch_plan / execution_hint (BBC AI Runtime / B1).
 * B0-LINE-04C: Generic Structured Search Resume State (no Runtime entity merge).
 */
final class AiIntentUnderstandingRuntime implements AiIntentUnderstandingRuntimeInterface
{
    public const PHASE = '2-D-4';

    private AiuGeminiUnderstandingClientInterface $geminiClient;
    private AiuPromptBuilder $promptBuilder;
    private AiuOutputContractValidator $outputContractValidator;
    private AiuSemanticJsonNormalizer $semanticNormalizer;
    private AiIntentContextLoader $contextLoader;
    private StructuredSearchResumeStateStore $resumeStore;

    public function __construct(
        ?AiuGeminiUnderstandingClientInterface $geminiClient = null,
        ?AiuPromptBuilder $promptBuilder = null,
        ?AiuSemanticJsonNormalizer $semanticNormalizer = null,
        ?AiIntentContextLoader $contextLoader = null,
        ?StructuredSearchResumeStateStore $resumeStore = null,
        ?AiuOutputContractValidator $outputContractValidator = null
    ) {
        $this->geminiClient = $geminiClient ?? new AiuGeminiUnderstandingClient();
        $this->promptBuilder = $promptBuilder ?? new AiuPromptBuilder();
        $this->outputContractValidator = $outputContractValidator ?? new AiuOutputContractValidator();
        $this->semanticNormalizer = $semanticNormalizer ?? new AiuSemanticJsonNormalizer();
        $this->contextLoader = $contextLoader ?? new AiIntentContextLoader();
        $this->resumeStore = $resumeStore ?? new StructuredSearchResumeStateStore();
    }

    public static function createForTesting(
        ?AiuGeminiUnderstandingClientInterface $geminiClient = null,
        ?AiIntentContextLoader $contextLoader = null,
        ?StructuredSearchResumeStateStore $resumeStore = null
    ): self {
        return new self(
            $geminiClient ?? new AiuGeminiUnderstandingClientStub(),
            null,
            null,
            $contextLoader ?? AiIntentContextLoader::createForTesting(),
            $resumeStore
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
        $promptReference = $referenceDate instanceof \DateTimeImmutable
            ? $referenceDate
            : new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));

        $conversationId = isset($context['conversation_id']) ? (string) $context['conversation_id'] : '';
        $tenantSno = isset($context['tenant_sno']) ? trim((string) $context['tenant_sno']) : '';
        $channel = isset($context['channel']) ? trim((string) $context['channel']) : 'line';
        $channelId = isset($context['channel_id']) ? trim((string) $context['channel_id']) : '';
        $lineUserId = isset($context['line_user_id']) ? trim((string) $context['line_user_id']) : '';
        $webhookEventId = isset($context['webhook_event_id']) ? trim((string) $context['webhook_event_id']) : '';
        $traceId = isset($context['trace_id']) ? trim((string) $context['trace_id']) : '';

        $snapshot = $this->contextLoader->load($conversationId, $now);

        $identity = null;
        $priorState = null;
        $stateInjected = false;
        $loadStatus = StructuredSearchResumeLoadResult::NOT_FOUND;
        $storageKeyPrefix = '';

        if ($tenantSno !== '' && $channelId !== '' && $lineUserId !== '') {
            $identity = StructuredSearchResumeIdentity::fromParts($tenantSno, $channelId, $lineUserId, $channel);
            $storageKeyPrefix = substr($identity->getStorageKey(), 0, 12);
            $load = $this->resumeStore->load($identity, $promptReference);
            $loadStatus = $load->getStatus();

            if ($loadStatus === StructuredSearchResumeLoadResult::CORRUPT) {
                $this->resumeStore->quarantineCorrupt($identity);
                $this->logResumeEvent('structured_search_resume_load', [
                    'resume_load_status' => $loadStatus,
                    'state_found' => false,
                    'state_identity_hash_prefix' => $storageKeyPrefix,
                    'failure_reason' => $load->getFailureReason(),
                    'trace_id' => $traceId,
                ]);
                throw new \RuntimeException('structured_search_resume_corrupt');
            }
            if ($loadStatus === StructuredSearchResumeLoadResult::IDENTITY_MISMATCH) {
                $this->logResumeEvent('structured_search_resume_load', [
                    'resume_load_status' => $loadStatus,
                    'state_found' => false,
                    'state_identity_hash_prefix' => $storageKeyPrefix,
                    'failure_reason' => $load->getFailureReason(),
                    'trace_id' => $traceId,
                ]);
                throw new \RuntimeException('structured_search_resume_identity_mismatch');
            }
            if ($loadStatus === StructuredSearchResumeLoadResult::IO_ERROR) {
                $this->logResumeEvent('structured_search_resume_load', [
                    'resume_load_status' => $loadStatus,
                    'state_found' => false,
                    'state_identity_hash_prefix' => $storageKeyPrefix,
                    'failure_reason' => $load->getFailureReason(),
                    'trace_id' => $traceId,
                ]);
                throw new \RuntimeException('structured_search_resume_io_error');
            }
            if ($loadStatus === StructuredSearchResumeLoadResult::EXPIRED) {
                $expired = $load->getState();
                $observedVersion = $expired !== null ? $expired->getStateVersion() : 0;
                if ($observedVersion >= 1) {
                    $this->resumeStore->expireIfStillExpired($identity, $observedVersion, $promptReference);
                }
                $priorState = null;
                $stateInjected = false;
            } elseif ($loadStatus === StructuredSearchResumeLoadResult::FOUND) {
                $priorState = $load->getState();
                $stateInjected = $priorState !== null;
            }
        }

        $this->logResumeEvent('structured_search_resume_load', [
            'resume_load_status' => $loadStatus,
            'state_found' => $stateInjected,
            'prior_state_version' => $priorState !== null ? $priorState->getStateVersion() : null,
            'prior_asked_entity' => $priorState !== null ? $priorState->getAskedEntity() : null,
            'prior_resume_reason' => $priorState !== null ? $priorState->getResumeReason() : null,
            'prior_reason' => $priorState !== null ? $priorState->getAiuClarificationReason() : null,
            'resume_schema_version' => $priorState !== null ? $priorState->getSchemaVersion() : null,
            'state_valid' => $stateInjected,
            'state_identity_hash_prefix' => $storageKeyPrefix,
            'state_injected_into_prompt' => $stateInjected,
            'trace_id' => $traceId,
        ]);

        $promptRequest = new AiuPromptRequest(
            $tenantSno,
            $channel,
            $message,
            $snapshot['context_snapshot'],
            $snapshot['owner_snapshot'],
            $snapshot['conversation_stage'],
            $snapshot['resume_context'],
            $promptReference,
            isset($context['request_id']) ? (string) $context['request_id'] : null,
            $stateInjected && $priorState !== null ? $priorState->toPromptInjectionArray() : null
        );

        $semanticRaw = $this->geminiClient->understand($promptRequest);
        $rawDatePresence = self::observeRawDateFieldPresence($semanticRaw);
        $validatedSemantic = $this->outputContractValidator->validate($semanticRaw);
        $normalized = $this->semanticNormalizer->normalize($validatedSemantic, $message, $referenceDate);

        $resumeDisposition = (string) ($normalized['resume_disposition'] ?? '');
        StructuredSearchResumeDispositionContract::assertValidForPriorState(
            $resumeDisposition,
            $stateInjected
        );

        $detected = $normalized['intent'];
        $entities = $normalized['entities'];
        $clarificationRequired = (bool) $normalized['clarification_required'];
        $clarificationReason = (string) $normalized['clarification_reason'];
        $confidence = (float) $normalized['confidence'];

        $projectionGate = $this->applySearchKeywordProjectionGate(
            $detected,
            $clarificationRequired,
            $entities
        );
        $entities = $projectionGate['entities'];
        $searchKeywordProjection = $projectionGate['projection'];
        $projectionObservability = $projectionGate['observability'];

        $result = new AiIntentUnderstandingResult($detected);
        $result
            ->setEntities($entities)
            ->setContextSnapshot($snapshot['context_snapshot'])
            ->setOwnerSnapshot($snapshot['owner_snapshot'])
            ->setConversationStage($snapshot['conversation_stage'])
            ->setResumeContext($snapshot['resume_context'])
            ->setClarification($clarificationRequired, $clarificationReason)
            ->setConfidence($confidence)
            ->setResumeDisposition($resumeDisposition)
            ->attachDatePipelineRawPresence($rawDatePresence);
        if ($searchKeywordProjection instanceof AiuSearchKeywordProjectionResult) {
            $result->attachSearchKeywordProjection($searchKeywordProjection);
        }

        $mutationOp = null;
        $mutationStatus = null;
        $resultingVersion = null;
        $clearBeforeSearch = null;

        if ($identity !== null) {
            $lifecycle = $this->applyResumeLifecycle(
                $identity,
                $result,
                $priorState,
                $stateInjected,
                $webhookEventId,
                $traceId,
                $promptReference,
                $resumeDisposition
            );
            $mutationOp = $lifecycle['operation'];
            $mutationStatus = $lifecycle['status'];
            $resultingVersion = $lifecycle['version'];
            $clearBeforeSearch = $lifecycle['clear_before_search'];
        }

        $obs = [
            'resume_load_status' => $loadStatus,
            'state_found' => $stateInjected,
            'state_valid' => $stateInjected,
            'prior_state_version' => $priorState !== null ? $priorState->getStateVersion() : null,
            'prior_asked_entity' => $priorState !== null ? $priorState->getAskedEntity() : null,
            'prior_resume_reason' => $priorState !== null ? $priorState->getResumeReason() : null,
            'prior_reason' => $priorState !== null ? $priorState->getAiuClarificationReason() : null,
            'prior_destination_relation' => $priorState !== null
                ? ($priorState->getKnownEntities()['destination_relation'] ?? null)
                : null,
            'prior_date_from' => $priorState !== null
                ? ($priorState->getKnownEntities()['date_from'] ?? null)
                : null,
            'prior_date_to' => $priorState !== null
                ? ($priorState->getKnownEntities()['date_to'] ?? null)
                : null,
            'resume_schema_version' => $priorState !== null
                ? $priorState->getSchemaVersion()
                : StructuredSearchResumeState::SCHEMA_VERSION,
            'resume_disposition' => $resumeDisposition,
            'mutation_operation' => $mutationOp,
            'mutation_result' => $mutationStatus,
            'resulting_version' => $resultingVersion,
            'state_identity_hash_prefix' => $storageKeyPrefix,
            'state_injected_into_prompt' => $stateInjected,
            'clear_before_search_result' => $clearBeforeSearch,
            'trace_id' => $traceId,
        ];
        $obs = array_merge($obs, $projectionObservability);
        $result->attachStructuredSearchResumeObservability($obs);
        $this->logResumeEvent('structured_search_resume_lifecycle', $obs);

        return $result;
    }

    /**
     * @return array{
     *   operation: string|null,
     *   status: string|null,
     *   version: int|null,
     *   clear_before_search: string|null
     * }
     */
    private function applyResumeLifecycle(
        StructuredSearchResumeIdentity $identity,
        AiIntentUnderstandingResult $result,
        ?StructuredSearchResumeState $priorState,
        bool $stateInjected,
        string $webhookEventId,
        string $traceId,
        \DateTimeImmutable $referenceDateTime,
        string $resumeDisposition
    ): array {
        $isProduct = $result->getIntent() === AiIntentCategory::PRODUCT_SEARCH;
        $out = [
            'operation' => null,
            'status' => null,
            'version' => null,
            'clear_before_search' => null,
        ];
        unset($stateInjected);

        if ($isProduct && $result->isClarificationRequired()) {
            if ($webhookEventId === '') {
                throw new \RuntimeException('structured_search_resume_invalid_state:missing_webhook_event_id');
            }

            // Prior capability WAITING already retained absolute dates — asking dates again is retention failure.
            if ($priorState !== null
                && $priorState->isCapabilityWaiting()
                && trim($result->getClarificationReason()) === AiuClarificationReasonContract::AIU_MISSING_TRAVEL_DATES
            ) {
                $known = $priorState->getKnownEntities();
                $priorFrom = isset($known['date_from']) ? trim((string) $known['date_from']) : '';
                $priorTo = isset($known['date_to']) ? trim((string) $known['date_to']) : '';
                if ($priorFrom !== '' && $priorTo !== '') {
                    $clear = $this->resumeStore->clear($identity, $priorState->getStateVersion());
                    $out['operation'] = 'clear';
                    $out['status'] = $clear->getStatus();
                    $out['version'] = $clear->getVersion();
                    throw new \RuntimeException('structured_search_resume_context_retention_failure');
                }
            }

            // new_request while clarifying: clear prior then create fresh from authoritative Gemini output.
            if ($priorState !== null
                && $resumeDisposition === StructuredSearchResumeDispositionContract::NEW_REQUEST) {
                $clear = $this->resumeStore->clear($identity, $priorState->getStateVersion());
                if (!$clear->isSuccess()) {
                    throw new \RuntimeException(
                        'structured_search_resume_mutation_failed:' . $clear->getStatus()
                    );
                }
                $priorState = null;
            }

            if ($priorState !== null) {
                $next = StructuredSearchResumeStateFactory::fromValidatedClarification(
                    $identity,
                    $result,
                    $webhookEventId,
                    StructuredSearchResumeState::OP_REPLACE,
                    $referenceDateTime,
                    $traceId,
                    $priorState->getStateVersion() + 1,
                    $priorState->getCreatedAt()
                );
                $mutation = $this->resumeStore->replace(
                    $identity,
                    $next,
                    $webhookEventId,
                    $priorState->getStateVersion()
                );
                $out['operation'] = 'replace';
            } else {
                $next = StructuredSearchResumeStateFactory::fromValidatedClarification(
                    $identity,
                    $result,
                    $webhookEventId,
                    StructuredSearchResumeState::OP_CREATE,
                    $referenceDateTime,
                    $traceId,
                    1,
                    null
                );
                $mutation = $this->resumeStore->create($identity, $next, $webhookEventId);
                $out['operation'] = 'create';
            }

            $out['status'] = $mutation->getStatus();
            $out['version'] = $mutation->getVersion();
            if (!$mutation->isSuccess()) {
                throw new \RuntimeException(
                    'structured_search_resume_mutation_failed:' . $mutation->getStatus()
                );
            }

            return $out;
        }

        // Resolved product search → clear-before-search when prior pending exists.
        // Capability WAITING must NOT clear here; consume only after SearchCondition success.
        if ($isProduct && !$result->isClarificationRequired()) {
            if ($priorState !== null && $priorState->isCapabilityWaiting()) {
                $out['clear_before_search'] = 'deferred_capability_waiting';
                $out['operation'] = null;
                $out['status'] = null;
                $out['version'] = $priorState->getStateVersion();

                return $out;
            }

            if ($priorState !== null) {
                $mutation = $this->resumeStore->clear($identity, $priorState->getStateVersion());
                $out['operation'] = 'clear';
                $out['status'] = $mutation->getStatus();
                $out['version'] = $mutation->getVersion();
                $out['clear_before_search'] = $mutation->getStatus();
                if (!$mutation->isSuccess()) {
                    throw new \RuntimeException(
                        'structured_search_resume_clear_before_search_failed:' . $mutation->getStatus()
                    );
                }
            } else {
                $out['clear_before_search'] = StructuredSearchResumeMutationResult::ALREADY_ABSENT;
            }

            return $out;
        }

        // Non-product: clear prior Product pending if present.
        if ($priorState !== null) {
            $mutation = $this->resumeStore->clear($identity, $priorState->getStateVersion());
            $out['operation'] = 'clear';
            $out['status'] = $mutation->getStatus();
            $out['version'] = $mutation->getVersion();
            if (!$mutation->isSuccess()
                && $mutation->getStatus() !== StructuredSearchResumeMutationResult::ALREADY_ABSENT) {
                throw new \RuntimeException(
                    'structured_search_resume_clear_non_product_failed:' . $mutation->getStatus()
                );
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $entities
     * @return array{
     *   entities: array<string, mixed>,
     *   projection: AiuSearchKeywordProjectionResult|null,
     *   observability: array<string, mixed>
     * }
     */
    private function applySearchKeywordProjectionGate(
        string $intent,
        bool $clarificationRequired,
        array $entities
    ): array {
        $observability = [
            'aiu_search_token_projection_status' => 'skipped',
            'aiu_search_token_projection_reason' => '',
            'aiu_search_token_count' => 0,
            'aiu_search_token_duplicate_removed_count' => 0,
            'aiu_search_keyword_projection_length' => 0,
            'aiu_search_keyword_hash8' => '',
            'capability_resume_tokens_present' => false,
            'capability_resume_token_count' => 0,
        ];

        if ($intent !== AiIntentCategory::PRODUCT_SEARCH) {
            return [
                'entities' => $entities,
                'projection' => null,
                'observability' => $observability,
            ];
        }

        $hasKey = array_key_exists('search_keyword_tokens', $entities);
        $projector = new AiuSearchKeywordTokenProjector();

        if ($clarificationRequired) {
            if (!$hasKey) {
                return [
                    'entities' => $entities,
                    'projection' => null,
                    'observability' => $observability,
                ];
            }

            $projection = $projector->project(
                $entities['search_keyword_tokens'],
                AiuSearchKeywordTokenProjector::MODE_OPTIONAL_PRESENT
            );
            $entities['search_keyword_tokens'] = $projection->getTokens();

            return [
                'entities' => $entities,
                'projection' => $projection,
                'observability' => $this->projectionObservability($projection, true),
            ];
        }

        $projection = $projector->project(
            $hasKey ? $entities['search_keyword_tokens'] : null,
            AiuSearchKeywordTokenProjector::MODE_REQUIRED
        );
        $entities['search_keyword_tokens'] = $projection->getTokens();

        return [
            'entities' => $entities,
            'projection' => $projection,
            'observability' => $this->projectionObservability($projection, true),
        ];
    }

  /**
     * @return array<string, mixed>
     */
    private function projectionObservability(AiuSearchKeywordProjectionResult $projection, bool $present): array
    {
        $keyword = $projection->getKeyword();

        return [
            'aiu_search_token_projection_status' => 'ok',
            'aiu_search_token_projection_reason' => $projection->getDuplicateRemovedCount() > 0
                ? AiuSearchKeywordTokenException::TOKEN_DUPLICATE_REMOVED
                : '',
            'aiu_search_token_count' => count($projection->getTokens()),
            'aiu_search_token_duplicate_removed_count' => $projection->getDuplicateRemovedCount(),
            'aiu_search_keyword_projection_length' => $projection->getProjectionLength(),
            'aiu_search_keyword_hash8' => substr(hash('crc32b', $keyword), 0, 8),
            'capability_resume_tokens_present' => $present,
            'capability_resume_token_count' => count($projection->getTokens()),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function logResumeEvent(string $step, array $payload): void
    {
        Logger::log('saas_router.log', $step, $payload);
    }

    /**
     * Observability-only: boolean presence of Gemini raw date fields.
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
