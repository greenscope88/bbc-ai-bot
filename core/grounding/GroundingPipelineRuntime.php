<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundingOrchestratorContextFactory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundingRuntime.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundingAssemblyException.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'GroundedResponseComposer.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'GroundedOutput.php';

/**
 * Phase 2-F Step 2-F-2b — authoritative Grounding pipeline with legacy fallback.
 *
 * SSOT: docs/BATS_AI_GROUNDING_LAYER.md §11 / §25.
 */
final class GroundingPipelineRuntime
{
    public const FLAG_ENABLED = 'grounding_layer_authoritative_enabled';

    public const FLAG_TENANTS = 'grounding_layer_authoritative_tenant_snos';

    public const PIPELINE_LEGACY = 'legacy';

    public const PIPELINE_AUTHORITATIVE = 'grounding_authoritative';

    public const PIPELINE_LEGACY_FALLBACK = 'legacy_fallback';

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
