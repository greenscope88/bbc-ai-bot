<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundingOrchestratorContextFactory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundingRuntime.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'GroundedResponseComposer.php';

/**
 * Phase 2-F Step 2-F-2a — Grounding Layer shadow probe (observe-only).
 *
 * SSOT: docs/BATS_AI_GROUNDING_LAYER.md §11 / Phase 2-F-2 Shadow Strategy.
 *
 * Parallel assemble + optional shadow compose for parity logging.
 * Never changes production outbound reply.
 */
final class GroundingShadowProbe
{
    public const FLAG_ENABLED = 'grounding_layer_shadow_enabled';

    public const FLAG_TENANTS = 'grounding_layer_shadow_tenant_snos';

    public const FLAG_AUTHORITATIVE = 'grounding_layer_authoritative_enabled';

    /**
     * @param array<string, mixed> $config
     */
    public static function isEnabled(array $config, string $tenantSno): bool
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
     * @param GroundingRuntime|null $runtime
     * @param callable|null $logger fn(string $step, array $context): void
     *
     * @return array<string, mixed>
     */
    public static function run(
        array $params,
        ?GroundingRuntime $runtime = null,
        ?callable $logger = null
    ): array {
        try {
            $config = isset($params['config']) && is_array($params['config'])
                ? $params['config']
                : self::loadDefaultConfig();

            if (isset($config[self::FLAG_AUTHORITATIVE]) && (bool) $config[self::FLAG_AUTHORITATIVE] === true) {
                return ['executed' => false, 'reason' => 'authoritative_flag_must_remain_off_in_f2a'];
            }

            $tenantSno = trim((string) ($params['tenant_sno'] ?? ''));
            if (!self::isEnabled($config, $tenantSno)) {
                return ['executed' => false, 'reason' => 'shadow_disabled_or_tenant_unmatched'];
            }

            $path = trim((string) ($params['path'] ?? ''));
            $legacyReplyText = (string) ($params['legacy_reply_text'] ?? '');
            $traceId = (string) ($params['trace_id'] ?? '');

            $context = GroundingOrchestratorContextFactory::build($params);
            $runtime = $runtime ?? new GroundingRuntime();
            $groundedInput = $runtime->assemble($context);

            $conversationContext = $groundedInput->getConversationContext();
            $shadowComposer = new GroundedResponseComposer($config);
            $shadowOutput = $shadowComposer->compose($groundedInput);
            $shadowReplyText = $shadowOutput->getReplyText();

            $structureParity = self::evaluateStructureParity($conversationContext);
            $replyParity = self::normalizeText($legacyReplyText) === self::normalizeText($shadowReplyText);

            $logContext = [
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
                'conversation_id' => (string) ($params['conversation_id'] ?? ''),
                'path' => $path,
                'runtime_type' => $groundedInput->getRuntimeType(),
                'customer_query_hash' => self::messageHash((string) ($params['customer_query'] ?? '')),
                'legacy_reply_text_length' => mb_strlen($legacyReplyText),
                'shadow_reply_text_length' => mb_strlen($shadowReplyText),
                'reply_text_parity' => $replyParity,
                'structure_parity' => $structureParity,
                'conversation_context_keys' => array_keys($conversationContext),
                'destination' => $conversationContext['destination'] ?? null,
                'travel_dates' => $conversationContext['travel_dates'] ?? null,
                'duration' => $conversationContext['duration'] ?? null,
                'current_requirement' => $conversationContext['current_requirement'] ?? null,
                'next_best_action_hint' => $groundedInput->getReplyPolicy()['next_best_action_hint'] ?? null,
                'shadow_mode' => true,
            ];

            self::emit('grounding_layer_shadow_probe', $logContext, $logger);

            return array_merge([
                'executed' => true,
                'reason' => 'evaluated',
                'reply_text_parity' => $replyParity,
                'structure_parity' => $structureParity,
            ], $logContext);
        } catch (\Throwable $e) {
            self::emit('grounding_layer_shadow_probe_error', [
                'trace_id' => (string) ($params['trace_id'] ?? ''),
                'path' => (string) ($params['path'] ?? ''),
                'message' => $e->getMessage(),
            ], $logger);

            return ['executed' => false, 'reason' => 'exception'];
        }
    }

    /**
     * @param array<string, mixed> $conversationContext
     */
    private static function evaluateStructureParity(array $conversationContext): bool
    {
        return isset($conversationContext['customer_query']) === false
            && (
                isset($conversationContext['destination'])
                || isset($conversationContext['travel_dates'])
                || isset($conversationContext['duration'])
                || isset($conversationContext['current_requirement'])
            );
    }

    private static function normalizeText(string $text): string
    {
        $normalized = preg_replace('/\s+/u', '', trim($text));

        return is_string($normalized) ? $normalized : trim($text);
    }

    private static function messageHash(string $message): string
    {
        if ($message === '') {
            return '';
        }

        return substr(hash('sha256', $message), 0, 16);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function emit(string $step, array $context, ?callable $logger): void
    {
        try {
            if ($logger !== null) {
                $logger($step, $context);

                return;
            }

            if (class_exists('Logger')) {
                \Logger::log('saas_router.log', $step, $context);
            }
        } catch (\Throwable $e) {
            // Swallow: shadow logging must never affect main flow.
        }
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
