<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeIdentity.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeState.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeStateFactory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeStateStore.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeLoadResult.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeMutationResult.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'DestinationFeasibilityContracts.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'DestinationRelationCapabilityRegistry.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logger.php';

/**
 * Single owner for capability-unavailable WAITING resume persist / terminate.
 * Gate and Composer must not write state.
 */
final class StructuredSearchCapabilityResumeService
{
    public const FAILURE_TRIGGER_INCOMPLETE = 'trigger_incomplete';
    public const FAILURE_FACTORY = 'factory_rejected';
    public const FAILURE_MUTATION = 'mutation_failed';
    public const FAILURE_IDENTITY = 'identity_unavailable';

    private StructuredSearchResumeStateStore $store;

    public function __construct(?StructuredSearchResumeStateStore $store = null)
    {
        $this->store = $store ?? new StructuredSearchResumeStateStore();
    }

    public function getStore(): StructuredSearchResumeStateStore
    {
        return $this->store;
    }

    /**
     * Persist WAITING_SINGLE_DESTINATION when exact trigger holds.
     *
     * @param array<string, mixed> $gateObservability
     * @param array<string, mixed> $aiuEntities
     * @return array{
     *   ok: bool,
     *   operation: string|null,
     *   mutation_status: string|null,
     *   state: StructuredSearchResumeState|null,
     *   failure_code: string|null,
     *   observability: array<string, mixed>
     * }
     */
    public function persistWaitingSingleDestination(
        ?StructuredSearchResumeIdentity $identity,
        BatsSearchIntent $intent,
        array $gateObservability,
        string $eventId,
        string $traceId,
        \DateTimeImmutable $referenceDateTime,
        array $aiuEntities = []
    ): array {
        $obs = $this->baseObservability($gateObservability, $intent);

        if ($identity === null) {
            return $this->fail(self::FAILURE_IDENTITY, $obs);
        }

        if (!$this->triggerHolds($gateObservability, $intent)) {
            return $this->fail(self::FAILURE_TRIGGER_INCOMPLETE, $obs);
        }

        $eventId = trim($eventId);
        if ($eventId === '') {
            return $this->fail(self::FAILURE_TRIGGER_INCOMPLETE, $obs + [
                'resume_state_failure_code' => 'missing_webhook_event_id',
            ]);
        }

        $load = $this->store->load($identity, $referenceDateTime);
        $prior = $load->isFound() ? $load->getState() : null;

        try {
            if ($prior !== null) {
                $next = StructuredSearchResumeStateFactory::fromRelationCapabilityUnavailable(
                    $identity,
                    $intent,
                    $eventId,
                    StructuredSearchResumeState::OP_REPLACE,
                    $referenceDateTime,
                    $traceId,
                    $prior->getStateVersion() + 1,
                    DestinationRelationCapabilityRegistry::VERSION,
                    $prior->getCreatedAt(),
                    $aiuEntities
                );
                $mutation = $this->store->replace(
                    $identity,
                    $next,
                    $eventId,
                    $prior->getStateVersion()
                );
                $operation = 'replace';
            } else {
                $next = StructuredSearchResumeStateFactory::fromRelationCapabilityUnavailable(
                    $identity,
                    $intent,
                    $eventId,
                    StructuredSearchResumeState::OP_CREATE,
                    $referenceDateTime,
                    $traceId,
                    1,
                    DestinationRelationCapabilityRegistry::VERSION,
                    null,
                    $aiuEntities
                );
                $mutation = $this->store->create($identity, $next, $eventId);
                $operation = 'create';
            }
        } catch (\InvalidArgumentException $e) {
            return $this->fail(self::FAILURE_FACTORY, $obs + [
                'resume_state_failure_code' => $e->getMessage(),
            ]);
        }

        $obs = array_merge($obs, [
            'resume_schema_version' => StructuredSearchResumeState::SCHEMA_VERSION,
            'resume_trigger_source' => StructuredSearchResumeState::TRIGGER_DESTINATION_EXECUTION_GATE,
            'resume_reason' => StructuredSearchResumeState::RESUME_REASON_RELATION_CAPABILITY_UNAVAILABLE,
            'asked_entity' => 'destination',
            'resume_state_id' => substr($identity->getStorageKey(), 0, 12),
            'resume_state_version' => $mutation->getVersion(),
            'resume_state_status' => StructuredSearchResumeState::STATUS_WAITING_SINGLE_DESTINATION,
            'resume_state_operation' => $operation,
            'resume_state_failure_code' => $mutation->isSuccess() ? null : $mutation->getStatus(),
            'relation_capability_version' => DestinationRelationCapabilityRegistry::VERSION,
            'response_route' => DestinationFeasibilityContracts::RESPONSE_ROUTE_CAPABILITY_UNAVAILABLE,
            'prior_destination_relation' => $intent->getDestinationRelation(),
            'prior_date_from' => $intent->getDateFrom(),
            'prior_date_to' => $intent->getDateTo(),
        ]);

        if (!$mutation->isSuccess()) {
            Logger::log('saas_router.log', 'structured_search_capability_resume_persist_failed', $obs);

            return [
                'ok' => false,
                'operation' => $operation,
                'mutation_status' => $mutation->getStatus(),
                'state' => null,
                'failure_code' => self::FAILURE_MUTATION,
                'observability' => $obs,
            ];
        }

        $state = $mutation->getState() ?? $next;
        Logger::log('saas_router.log', 'structured_search_capability_resume_persisted', $obs);

        return [
            'ok' => true,
            'operation' => $operation,
            'mutation_status' => $mutation->getStatus(),
            'state' => $state,
            'failure_code' => null,
            'observability' => $obs,
        ];
    }

    /**
     * Version-safe clear of capability WAITING (mixed / non_executable / uncertain terminate).
     *
     * @return array{ok: bool, mutation_status: string, observability: array<string, mixed>}
     */
    public function clearCapabilityWaitingIfPresent(
        ?StructuredSearchResumeIdentity $identity,
        \DateTimeImmutable $referenceDateTime,
        string $traceId
    ): array {
        $obs = [
            'trace_id' => $traceId,
            'resume_state_operation' => 'clear',
        ];
        if ($identity === null) {
            return ['ok' => true, 'mutation_status' => StructuredSearchResumeMutationResult::ALREADY_ABSENT, 'observability' => $obs];
        }

        $load = $this->store->load($identity, $referenceDateTime);
        if (!$load->isFound()) {
            $obs['resume_state_operation'] = 'already_absent';

            return ['ok' => true, 'mutation_status' => StructuredSearchResumeMutationResult::ALREADY_ABSENT, 'observability' => $obs];
        }

        $state = $load->getState();
        if ($state === null || !$state->isCapabilityWaiting()) {
            return ['ok' => true, 'mutation_status' => 'not_capability_waiting', 'observability' => $obs];
        }

        $mutation = $this->store->clear($identity, $state->getStateVersion());
        $obs['resume_state_id'] = substr($identity->getStorageKey(), 0, 12);
        $obs['resume_state_version'] = $state->getStateVersion();
        $obs['resume_state_status'] = $state->getStatus();
        $obs['resume_state_failure_code'] = $mutation->isSuccess() ? null : $mutation->getStatus();

        Logger::log('saas_router.log', 'structured_search_capability_resume_cleared', $obs);

        return [
            'ok' => $mutation->isSuccess()
                || $mutation->getStatus() === StructuredSearchResumeMutationResult::ALREADY_ABSENT,
            'mutation_status' => $mutation->getStatus(),
            'observability' => $obs,
        ];
    }

    /**
     * CAS consume after SearchCondition successfully created.
     *
     * @return array{ok: bool, mutation_status: string, observability: array<string, mixed>}
     */
    public function consumeAfterSearchConditionCreated(
        ?StructuredSearchResumeIdentity $identity,
        \DateTimeImmutable $referenceDateTime,
        string $traceId
    ): array {
        $obs = [
            'trace_id' => $traceId,
            'resume_state_operation' => 'consume_after_search_condition',
            'search_condition_created' => true,
        ];
        if ($identity === null) {
            return ['ok' => true, 'mutation_status' => StructuredSearchResumeMutationResult::ALREADY_ABSENT, 'observability' => $obs];
        }

        $load = $this->store->load($identity, $referenceDateTime);
        if (!$load->isFound()) {
            $obs['resume_state_operation'] = 'already_absent';

            return ['ok' => true, 'mutation_status' => StructuredSearchResumeMutationResult::ALREADY_ABSENT, 'observability' => $obs];
        }

        $state = $load->getState();
        if ($state === null) {
            return ['ok' => true, 'mutation_status' => StructuredSearchResumeMutationResult::ALREADY_ABSENT, 'observability' => $obs];
        }

        // Only capability WAITING is deferred past AIU clear-before-search.
        if (!$state->isCapabilityWaiting()) {
            return ['ok' => true, 'mutation_status' => 'not_capability_waiting', 'observability' => $obs];
        }

        $mutation = $this->store->clear($identity, $state->getStateVersion());
        $obs['resume_state_id'] = substr($identity->getStorageKey(), 0, 12);
        $obs['resume_state_version'] = $state->getStateVersion();
        $obs['resume_reason'] = $state->getResumeReason();
        $obs['resume_state_failure_code'] = $mutation->isSuccess() ? null : $mutation->getStatus();

        Logger::log('saas_router.log', 'structured_search_capability_resume_consumed', $obs);

        return [
            'ok' => $mutation->isSuccess()
                || $mutation->getStatus() === StructuredSearchResumeMutationResult::ALREADY_ABSENT,
            'mutation_status' => $mutation->getStatus(),
            'observability' => $obs,
        ];
    }

    /**
     * @param array<string, mixed> $gateObservability
     */
    public function triggerHolds(array $gateObservability, BatsSearchIntent $intent): bool
    {
        $decision = trim((string) ($gateObservability['execution_gate_decision'] ?? ''));
        $route = trim((string) ($gateObservability['response_route'] ?? ''));
        if ($decision !== DestinationFeasibilityContracts::EXECUTION_DENY_RELATION_CAPABILITY_UNAVAILABLE) {
            return false;
        }
        if ($route !== DestinationFeasibilityContracts::RESPONSE_ROUTE_CAPABILITY_UNAVAILABLE) {
            return false;
        }

        $relation = trim($intent->getDestinationRelation());
        if (!in_array($relation, [
            DestinationFeasibilityContracts::RELATION_AND,
            DestinationFeasibilityContracts::RELATION_OR,
            DestinationFeasibilityContracts::RELATION_SEQUENTIAL,
        ], true)) {
            return false;
        }

        $dateFrom = trim((string) ($intent->getDateFrom() ?? ''));
        $dateTo = trim((string) ($intent->getDateTo() ?? ''));
        if ($dateFrom === '' || $dateTo === '') {
            return false;
        }

        try {
            StructuredSearchResumeStateFactory::assertCapabilityKnownEntitiesComplete(
                StructuredSearchResumeStateFactory::knownEntitiesFromBatsSearchIntent($intent)
            );
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        foreach ([
            'search_condition_created',
            'product_source_executed',
            'host_b_executed',
            'multi_source_links_built',
        ] as $flag) {
            if (($gateObservability[$flag] ?? null) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $gateObservability
     * @return array<string, mixed>
     */
    private function baseObservability(array $gateObservability, BatsSearchIntent $intent): array
    {
        return [
            'resume_schema_version' => StructuredSearchResumeState::SCHEMA_VERSION,
            'execution_gate_decision' => $gateObservability['execution_gate_decision'] ?? null,
            'response_route' => $gateObservability['response_route'] ?? null,
            'search_condition_created' => $gateObservability['search_condition_created'] ?? null,
            'host_b_executed' => $gateObservability['host_b_executed'] ?? false,
            'prior_destination_relation' => $intent->getDestinationRelation(),
            'prior_date_from' => $intent->getDateFrom(),
            'prior_date_to' => $intent->getDateTo(),
            'relation_capability_version' => DestinationRelationCapabilityRegistry::VERSION,
        ];
    }

    /**
     * @param array<string, mixed> $obs
     * @return array{
     *   ok: bool,
     *   operation: string|null,
     *   mutation_status: string|null,
     *   state: StructuredSearchResumeState|null,
     *   failure_code: string|null,
     *   observability: array<string, mixed>
     * }
     */
    private function fail(string $code, array $obs): array
    {
        $obs['resume_state_failure_code'] = $obs['resume_state_failure_code'] ?? $code;
        $obs['resume_state_operation'] = 'persist_rejected';
        Logger::log('saas_router.log', 'structured_search_capability_resume_persist_rejected', $obs);

        return [
            'ok' => false,
            'operation' => null,
            'mutation_status' => null,
            'state' => null,
            'failure_code' => $code,
            'observability' => $obs,
        ];
    }
}
