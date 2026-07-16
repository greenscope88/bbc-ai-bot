<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundingOrchestratorContextFactory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundingRuntime.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundingAssemblyException.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'GroundedResponseComposer.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'GroundedOutput.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'RuntimeType.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'LayoutProfile.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'clarification'
    . DIRECTORY_SEPARATOR . 'ClarificationContract.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'clarification'
    . DIRECTORY_SEPARATOR . 'ClarificationContractFactory.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';

/**
 * Phase 2-F Step 2-F-2b — authoritative Grounding pipeline with legacy fallback.
 *
 * SSOT: docs/BATS_AI_GROUNDING_LAYER.md §11 / §25.
 *
 * B0-LINE-02C-2: composeClarificationReply is the sole Pipeline entry for
 * generative clarification (no Search/Host B; never parses utterance).
 */
final class GroundingPipelineRuntime
{
    public const FLAG_ENABLED = 'grounding_layer_authoritative_enabled';

    public const FLAG_TENANTS = 'grounding_layer_authoritative_tenant_snos';

    public const PIPELINE_LEGACY = 'legacy';

    public const PIPELINE_AUTHORITATIVE = 'grounding_authoritative';

    public const PIPELINE_LEGACY_FALLBACK = 'legacy_fallback';

    public const PIPELINE_CLARIFICATION = 'clarification';

    public const ROUTE_GENERATIVE_CLARIFICATION = 'phase_9c1_generative_clarification';

    public const ROUTE_CLARIFICATION_FAIL_CLOSED = 'phase_9c1_clarification_fail_closed';

    /**
     * @param array<string, mixed> $config
     */
    public static function isAuthoritativeEnabled(array $config, string $tenantSno): bool
    {
        $enabled = isset($config[self::FLAG_ENABLED]) && (bool) $config[self::FLAG_ENABLED];
        if (!$enabled) {
            return false;
        }

        $tenantSno = trim($tenantSno);
        if ($tenantSno === '') {
            return false;
        }

        $allow = isset($config[self::FLAG_TENANTS]) && is_array($config[self::FLAG_TENANTS])
            ? array_values(array_filter(array_map('strval', $config[self::FLAG_TENANTS])))
            : [];

        return in_array($tenantSno, $allow, true);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{output: GroundedOutput, pipeline: string, fallback_reason?: string}
     */
    public static function composeKnowledgeReply(array $params): array
    {
        $config = isset($params['config']) && is_array($params['config'])
            ? $params['config']
            : self::loadDefaultConfig();
        $tenantSno = trim((string) ($params['tenant_sno'] ?? ''));
        $composer = $params['composer'] ?? new GroundedResponseComposer($config);
        $legacyTenant = is_array($params['legacy_tenant'] ?? null) ? $params['legacy_tenant'] : [];
        $runtimeResult = is_array($params['runtime_result'] ?? null) ? $params['runtime_result'] : [];

        if (!self::isAuthoritativeEnabled($config, $tenantSno)) {
            return [
                'output' => $composer->composeFromKnowledgeResult($runtimeResult, $legacyTenant),
                'pipeline' => self::PIPELINE_LEGACY,
            ];
        }

        try {
            $context = GroundingOrchestratorContextFactory::build($params);
            $groundingRuntime = $params['grounding_runtime'] ?? new GroundingRuntime();
            $groundedInput = $groundingRuntime->assemble($context);
            $output = $composer->compose($groundedInput);

            self::emitSuccess($params, 'knowledge');

            return [
                'output' => $output,
                'pipeline' => self::PIPELINE_AUTHORITATIVE,
            ];
        } catch (\Throwable $e) {
            self::emitFallback($params, 'knowledge', $e);

            return [
                'output' => $composer->composeFromKnowledgeResult($runtimeResult, $legacyTenant),
                'pipeline' => self::PIPELINE_LEGACY_FALLBACK,
                'fallback_reason' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{output: GroundedOutput, pipeline: string, fallback_reason?: string}
     */
    public static function composeProductReply(array $params): array
    {
        $config = isset($params['config']) && is_array($params['config'])
            ? $params['config']
            : self::loadDefaultConfig();
        $tenantSno = trim((string) ($params['tenant_sno'] ?? ''));
        $composer = $params['composer'] ?? new GroundedResponseComposer($config);
        $legacyTenant = is_array($params['legacy_tenant'] ?? null) ? $params['legacy_tenant'] : [];
        $runtimeResult = is_array($params['runtime_result'] ?? null) ? $params['runtime_result'] : [];

        if (!self::isAuthoritativeEnabled($config, $tenantSno)) {
            return [
                'output' => $composer->composeProductReply($runtimeResult, $legacyTenant),
                'pipeline' => self::PIPELINE_LEGACY,
            ];
        }

        try {
            $context = GroundingOrchestratorContextFactory::build($params);
            $groundingRuntime = $params['grounding_runtime'] ?? new GroundingRuntime();
            $groundedInput = $groundingRuntime->assemble($context);
            $output = $composer->compose($groundedInput);

            self::emitSuccess($params, 'product');

            return [
                'output' => $output,
                'pipeline' => self::PIPELINE_AUTHORITATIVE,
            ];
        } catch (\Throwable $e) {
            self::emitFallback($params, 'product', $e);

            return [
                'output' => $composer->composeProductReply($runtimeResult, $legacyTenant),
                'pipeline' => self::PIPELINE_LEGACY_FALLBACK,
                'fallback_reason' => $e->getMessage(),
            ];
        }
    }

    /**
     * Clarification entry — never parses utterance, never calls Search/Host B.
     *
     * @param array<string, mixed> $params
     *
     * @return array{
     *   output: GroundedOutput,
     *   pipeline: string,
     *   route: string,
     *   clarification_reason: string,
     *   missing_entity: string,
     *   asked_entity: string,
     *   host_b_executed: bool,
     *   final_owner: string,
     *   failure_reason: ?string,
     *   validation_passed: bool,
     *   used_facts_count: int
     * }
     */
    public static function composeClarificationReply(array $params): array
    {
        $config = isset($params['config']) && is_array($params['config'])
            ? $params['config']
            : self::loadDefaultConfig();
        /** @var GroundedResponseComposer $composer */
        $composer = $params['composer'] ?? new GroundedResponseComposer($config);
        /** @var ClarificationContractFactory $factory */
        $factory = $params['contract_factory'] ?? new ClarificationContractFactory();

        $reason = trim((string) ($params['clarification_reason'] ?? ''));
        $knownEntities = self::resolveKnownEntities($params);
        $tenant = is_array($params['tenant'] ?? null) ? $params['tenant'] : [];
        if ($tenant === [] && is_array($params['legacy_tenant'] ?? null)) {
            $tenant = $params['legacy_tenant'];
        }
        $tone = is_array($params['tone'] ?? null) ? $params['tone'] : [
            'persona' => 'travel_consultant',
            'allow_emoji' => true,
        ];

        $factoryInput = [
            'clarification_required' => true,
            'clarification_reason' => $reason,
            'known_entities' => $knownEntities,
            'tenant' => $tenant,
            'tone' => $tone,
            'trace_id' => (string) ($params['trace_id'] ?? ''),
            'tenant_sno' => (string) ($params['tenant_sno'] ?? ($tenant['tenant_sno'] ?? $tenant['sno'] ?? '')),
            'conversation_id' => (string) ($params['conversation_id'] ?? ''),
        ];

        try {
            $contract = $factory->create($factoryInput);
        } catch (\Throwable $e) {
            $failureReason = trim($e->getMessage()) !== ''
                ? trim($e->getMessage())
                : 'clarification_input_invalid';
            $output = self::buildTechnicalFailClosedOutput($tone, $failureReason);
            self::emitClarification($params, $output, self::ROUTE_CLARIFICATION_FAIL_CLOSED, $failureReason, '');

            return self::clarificationEnvelope(
                $output,
                self::ROUTE_CLARIFICATION_FAIL_CLOSED,
                $reason,
                '',
                $failureReason
            );
        }

        $output = $composer->composeClarificationReply($contract);
        $isFailClosed = $output->getReplyText() === ClarificationContract::TECHNICAL_FAIL_CLOSED_TEXT
            || $output->isValidationPassed() === false;
        $route = $isFailClosed
            ? self::ROUTE_CLARIFICATION_FAIL_CLOSED
            : self::ROUTE_GENERATIVE_CLARIFICATION;
        $failureReason = $isFailClosed
            ? self::extractFailureReason($output, 'clarification_foundation_fail_closed')
            : null;

        self::emitClarification(
            $params,
            $output,
            $route,
            $failureReason,
            $contract->getMissingEntity()
        );

        return self::clarificationEnvelope(
            $output,
            $route,
            $contract->getClarificationReason(),
            $contract->getMissingEntity(),
            $failureReason
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function resolveKnownEntities(array $params): array
    {
        if (isset($params['known_entities']) && is_array($params['known_entities'])) {
            return $params['known_entities'];
        }

        $intent = $params['bats_search_intent'] ?? null;
        if ($intent instanceof BatsSearchIntent) {
            return self::knownEntitiesFromBatsIntent($intent);
        }

        if (is_array($intent)) {
            return self::knownEntitiesFromIntentArray($intent);
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function knownEntitiesFromBatsIntent(BatsSearchIntent $intent): array
    {
        $known = [];
        $dateFrom = $intent->getDateFrom();
        $dateTo = $intent->getDateTo();
        $departure = $intent->getDepartureCity();
        $destination = $intent->getDestination();

        if ($dateFrom !== null && $dateFrom !== '') {
            $known['date_from'] = $dateFrom;
        }
        if ($dateTo !== null && $dateTo !== '') {
            $known['date_to'] = $dateTo;
        }
        if ($departure !== null && $departure !== '') {
            $known['departure'] = $departure;
        }
        if ($destination !== []) {
            $known['destination'] = $destination;
        }

        return $known;
    }

    /**
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    public static function knownEntitiesFromIntentArray(array $intent): array
    {
        $known = [];
        foreach (['date_from', 'date_to', 'departure', 'destination'] as $key) {
            $srcKey = $key === 'departure' && !array_key_exists('departure', $intent)
                ? 'departure_city'
                : $key;
            if (!array_key_exists($srcKey, $intent)) {
                continue;
            }
            $value = $intent[$srcKey];
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $known[$key] = $value;
        }

        return $known;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function emitSuccess(array $params, string $path): void
    {
        self::emit('grounding_authoritative_compose_success', [
            'trace_id' => (string) ($params['trace_id'] ?? ''),
            'tenant_sno' => (string) ($params['tenant_sno'] ?? ''),
            'conversation_id' => (string) ($params['conversation_id'] ?? ''),
            'path' => $path,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function emitFallback(array $params, string $path, \Throwable $e): void
    {
        self::emit('grounding_authoritative_fallback', [
            'trace_id' => (string) ($params['trace_id'] ?? ''),
            'tenant_sno' => (string) ($params['tenant_sno'] ?? ''),
            'conversation_id' => (string) ($params['conversation_id'] ?? ''),
            'path' => $path,
            'exception_class' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function emitClarification(
        array $params,
        GroundedOutput $output,
        string $route,
        ?string $failureReason,
        string $askedEntity
    ): void {
        self::emit('phase_9c1_clarification_compose', [
            'trace_id' => (string) ($params['trace_id'] ?? ''),
            'tenant_sno' => (string) ($params['tenant_sno'] ?? ''),
            'conversation_id' => (string) ($params['conversation_id'] ?? ''),
            'clarification_reason' => (string) ($params['clarification_reason'] ?? ''),
            'asked_entity' => $askedEntity,
            'reply_type' => $output->getReplyType(),
            'used_facts_count' => $output->getUsedFactsCount(),
            'validation_passed' => $output->isValidationPassed(),
            'failure_reason' => $failureReason,
            'final_owner' => 'grounded_response_composer',
            'host_b_executed' => false,
            'final_route' => $route,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function emit(string $step, array $context): void
    {
        try {
            if (class_exists('Logger')) {
                \Logger::log('saas_router.log', $step, $context);
            }
        } catch (\Throwable $e) {
            // Never throw from logging.
        }
    }

    /**
     * @param array{persona?: string, allow_emoji?: bool} $tone
     */
    private static function buildTechnicalFailClosedOutput(array $tone, string $failureReason): GroundedOutput
    {
        $persona = trim((string) ($tone['persona'] ?? 'travel_consultant'));

        return new GroundedOutput(
            ClarificationContract::TECHNICAL_FAIL_CLOSED_TEXT,
            false,
            0,
            GroundedInput::SOURCE_TENANT_PRIVATE,
            false,
            [],
            ReplyType::CLARIFICATION,
            LayoutProfile::MINIMAL,
            false,
            false,
            $persona !== '' ? $persona : 'travel_consultant',
            ['failure_reason:' . $failureReason]
        );
    }

    /**
     * @return array{
     *   output: GroundedOutput,
     *   pipeline: string,
     *   route: string,
     *   clarification_reason: string,
     *   missing_entity: string,
     *   asked_entity: string,
     *   host_b_executed: bool,
     *   final_owner: string,
     *   failure_reason: ?string,
     *   validation_passed: bool,
     *   used_facts_count: int
     * }
     */
    private static function clarificationEnvelope(
        GroundedOutput $output,
        string $route,
        string $reason,
        string $missingEntity,
        ?string $failureReason
    ): array {
        return [
            'output' => $output,
            'pipeline' => self::PIPELINE_CLARIFICATION,
            'route' => $route,
            'clarification_reason' => $reason,
            'missing_entity' => $missingEntity,
            'asked_entity' => $missingEntity,
            'host_b_executed' => false,
            'final_owner' => 'grounded_response_composer',
            'failure_reason' => $failureReason,
            'validation_passed' => $output->isValidationPassed(),
            'used_facts_count' => $output->getUsedFactsCount(),
        ];
    }

    private static function extractFailureReason(GroundedOutput $output, string $default): string
    {
        foreach ($output->getValidationNotes() as $note) {
            if (strpos((string) $note, 'failure_reason:') === 0) {
                return substr((string) $note, strlen('failure_reason:'));
            }
        }

        return $default;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadDefaultConfig(): array
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bats_feature.php';
        if (!is_file($path)) {
            return [];
        }

        $loaded = require $path;

        return is_array($loaded) ? $loaded : [];
    }
}
