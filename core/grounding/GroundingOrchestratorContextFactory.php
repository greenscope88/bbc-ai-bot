<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundingAssemblyContext.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'conversation' . DIRECTORY_SEPARATOR . 'ConversationMemoryRuntime.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'conversation' . DIRECTORY_SEPARATOR . 'ConversationStateRuntime.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'RuntimeType.php';

/**
 * Phase 2-F Step 2-F-2a — builds GroundingAssemblyContext from orchestrator slices.
 *
 * SSOT: docs/BATS_AI_GROUNDING_LAYER.md §12.
 *
 * Read-only: may call Memory / State Runtime get() when snapshots are not injected.
 */
final class GroundingOrchestratorContextFactory
{
    /**
     * @param array<string, mixed> $params
     */
    public static function build(array $params): GroundingAssemblyContext
    {
        $runtimeType = trim((string) ($params['runtime_type'] ?? RuntimeType::KNOWLEDGE_PRIVATE));
        $sourceType = trim((string) ($params['source_type'] ?? ''));
        if ($sourceType === '') {
            $sourceType = GroundedInput::mapRuntimeTypeToSourceType($runtimeType);
        }

        $conversationId = trim((string) ($params['conversation_id'] ?? ''));
        $memorySnapshot = self::resolveMemorySnapshot($params, $conversationId);
        $stateSnapshot = self::resolveStateSnapshot($params, $conversationId);
        $aiuProjection = self::resolveAiuProjection($params);

        return GroundingAssemblyContext::fromArray([
            'trace_id' => (string) ($params['trace_id'] ?? ''),
            'conversation_id' => $conversationId,
            'tenant_sno' => (string) ($params['tenant_sno'] ?? ''),
            'customer_query' => (string) ($params['customer_query'] ?? ''),
            'runtime_type' => $runtimeType,
            'source_type' => $sourceType,
            'runtime_result' => is_array($params['runtime_result'] ?? null) ? $params['runtime_result'] : [],
            'dispatch_result' => is_array($params['dispatch_result'] ?? null) ? $params['dispatch_result'] : [],
            'tenant' => is_array($params['tenant'] ?? null) ? $params['tenant'] : [],
            'tone' => is_array($params['tone'] ?? null) ? $params['tone'] : [],
            'memory_snapshot' => $memorySnapshot,
            'state_snapshot' => $stateSnapshot,
            'aiu_projection' => $aiuProjection,
            'bats_search_intent' => isset($params['bats_search_intent']) && is_array($params['bats_search_intent'])
                ? $params['bats_search_intent']
                : null,
        ]);
    }

    /**
     * @param array<string, mixed> $knowledgeResult
     */
    public static function resolveKnowledgeRuntimeType(array $knowledgeResult): string
    {
        $fallbackLayer = isset($knowledgeResult['fallback_layer'])
            ? trim((string) $knowledgeResult['fallback_layer'])
            : '';

        if ($fallbackLayer === 'human_service') {
            return RuntimeType::HUMAN_SERVICE;
        }
        if ($fallbackLayer === 'industry_shared') {
            return RuntimeType::KNOWLEDGE_SHARED;
        }

        return RuntimeType::KNOWLEDGE_PRIVATE;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function resolveMemorySnapshot(array $params, string $conversationId): array
    {
        if (isset($params['memory_snapshot']) && is_array($params['memory_snapshot'])) {
            return $params['memory_snapshot'];
        }

        if ($conversationId === '') {
            return [];
        }

        try {
            $card = (new ConversationMemoryRuntime())->get($conversationId);
            if ($card === null) {
                return [];
            }

            return $card->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function resolveStateSnapshot(array $params, string $conversationId): array
    {
        if (isset($params['state_snapshot']) && is_array($params['state_snapshot'])) {
            return $params['state_snapshot'];
        }

        if ($conversationId === '') {
            return [];
        }

        try {
            $state = (new ConversationStateRuntime())->get($conversationId);
            if ($state === null) {
                return [];
            }

            $raw = $state->toArray();

            return [
                'conversation_owner' => (string) ($raw['owner'] ?? GroundedInput::CONVERSATION_OWNER_AI),
                'conversation_status' => (string) ($raw['status'] ?? GroundedInput::CONVERSATION_STATUS_ACTIVE),
                'resume_context' => isset($params['resume_context']) && is_array($params['resume_context'])
                    ? $params['resume_context']
                    : null,
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function resolveAiuProjection(array $params): array
    {
        if (isset($params['aiu_projection']) && is_array($params['aiu_projection'])) {
            return $params['aiu_projection'];
        }

        $searchIntent = isset($params['bats_search_intent']) && is_array($params['bats_search_intent'])
            ? $params['bats_search_intent']
            : [];

        $entity = self::projectEntityFromBatsSearchIntent($searchIntent);
        if ($entity === []) {
            return [];
        }

        return ['entities' => $entity];
    }

    /**
     * @param array<string, mixed> $searchIntent
     *
     * @return array<string, mixed>
     */
    private static function projectEntityFromBatsSearchIntent(array $searchIntent): array
    {
        $entities = [];

        if (isset($searchIntent['destination']) && is_array($searchIntent['destination'])) {
            $list = [];
            foreach ($searchIntent['destination'] as $token) {
                $part = trim((string) $token);
                if ($part !== '') {
                    $list[] = $part;
                }
            }
            if ($list !== []) {
                $entities['destination'] = array_values(array_unique($list));
            }
        }

        $dateFrom = isset($searchIntent['date_from']) ? trim((string) $searchIntent['date_from']) : '';
        if ($dateFrom !== '') {
            $entities['travel_dates'] = $dateFrom;
        }

        if (isset($searchIntent['people_count']) && $searchIntent['people_count'] !== null && $searchIntent['people_count'] !== '') {
            $entities['party_size'] = (string) (int) $searchIntent['people_count'];
        }

        $duration = isset($searchIntent['duration']) ? trim((string) $searchIntent['duration']) : '';
        if ($duration === '' && isset($searchIntent['duration_days'])) {
            $duration = trim((string) $searchIntent['duration_days']);
        }
        if ($duration !== '') {
            $entities['duration'] = $duration;
        }

        return $entities;
    }
}
