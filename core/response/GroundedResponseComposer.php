<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundedOutput.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ComposerRuntime.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ResponseBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'LayoutStrategySelector.php';

/**
 * Phase 9-C-2C-1 — Grounded Response Composer (presentation layer, thin wrapper).
 *
 * SSOT: docs/PHASE_9C2C_GROUNDED_RESPONSE_COMPOSER.md
 *
 * Phase 2-E-2a: when `grounded_composer_generative_enabled` is ON, delegates to
 * ComposerRuntime (Human Takeover guard + legacy pass-through). When OFF, legacy
 * pass-through only — behavior unchanged from Phase 2-E-1.
 *
 * Phase 2-E-2b: flag ON + knowledge runtime_type routes through KnowledgeLayoutStrategy.
 */
final class GroundedResponseComposer
{
    public const FEATURE_GROUNDED_COMPOSER_GENERATIVE_ENABLED = 'grounded_composer_generative_enabled';

    /** @var array<string, mixed> */
    private array $featureConfig;

    private ComposerRuntime $composerRuntime;

    private LayoutStrategySelector $layoutStrategySelector;

    private ResponseBuilder $responseBuilder;

    /**
     * @param array<string, mixed>|null $featureConfig Override for tests; loads config/bats_feature.php when null.
     */
    public function __construct(
        ?array $featureConfig = null,
        ?ComposerRuntime $composerRuntime = null,
        ?LayoutStrategySelector $layoutStrategySelector = null,
        ?ResponseBuilder $responseBuilder = null
    ) {
        $this->featureConfig = $featureConfig ?? self::loadFeatureConfig();
        $this->composerRuntime = $composerRuntime ?? new ComposerRuntime();
        $this->layoutStrategySelector = $layoutStrategySelector ?? LayoutStrategySelector::createDefault();
        $this->responseBuilder = $responseBuilder ?? new ResponseBuilder();
    }

    /**
     * Compose a GroundedOutput from an already-resolved GroundedInput.
     */
    public function compose(GroundedInput $input): GroundedOutput
    {
        if (!$this->isGenerativeRuntimeEnabled()) {
            return $this->composeLegacyPassThrough($input);
        }

        return $this->composerRuntime->run(
            $input,
            function (GroundedInput $groundedInput): GroundedOutput {
                return $this->composeGenerativePath($groundedInput);
            }
        );
    }

    /**
     * Convenience entry for the Knowledge Path exit in the orchestrator.
     *
     * @param array<string, mixed> $knowledgeResult Result from TenantPrivateKnowledgeRuntime::handle()
     * @param array<string, mixed> $tenant          Tenant context (registry-driven)
     */
    public function composeFromKnowledgeResult(array $knowledgeResult, array $tenant = []): GroundedOutput
    {
        return $this->compose(
            GroundedInput::fromKnowledgeRuntimeResult($knowledgeResult, $tenant)
        );
    }

    /**
     * Convenience entry for the Product Path push reply exit in the orchestrator.
     *
     * @param array<string, mixed> $productResult Expected: reply_text, grounded,
     *   recommendation_summary, product_list
     * @param array<string, mixed> $tenant
     */
    public function composeProductReply(array $productResult, array $tenant = []): GroundedOutput
    {
        return $this->compose(
            GroundedInput::fromProductRuntimeResult($productResult, $tenant)
        );
    }

    public function isGenerativeRuntimeEnabled(): bool
    {
        return (bool) ($this->featureConfig[self::FEATURE_GROUNDED_COMPOSER_GENERATIVE_ENABLED] ?? false);
    }

  /**
     * Generative path (2-E-2b): knowledge strategy when supported; otherwise legacy.
     */
    public function composeGenerativePath(GroundedInput $input): GroundedOutput
    {
        $strategy = $this->layoutStrategySelector->resolve($input);
        if ($strategy !== null) {
            return $this->responseBuilder->build($input, $strategy->compose($input));
        }

        return $this->composeLegacyPassThrough($input);
    }

    /**
     * Legacy pass-through path (Phase 2-E-1). Reply text from Runtime; metadata normalized only.
     */
    public function composeLegacyPassThrough(GroundedInput $input): GroundedOutput
    {
        $text = $input->getRuntimeReplyText();
        $grounded = $input->isRuntimeGrounded();
        $usedFactsCount = $input->getFactCount();
        $sourceType = $input->getSourceType();
        $humanServiceRequired = $sourceType === GroundedInput::SOURCE_HUMAN_SERVICE;

        $safetyNotes = [];

        if ($grounded && $usedFactsCount === 0) {
            $safetyNotes[] = 'grounded_without_facts';
        }

        if (!$grounded && $usedFactsCount > 0) {
            $usedFactsCount = 0;
            $safetyNotes[] = 'ungrounded_fact_count_reset';
        }

        if ($humanServiceRequired) {
            $grounded = false;
            $usedFactsCount = 0;
        }

        return new GroundedOutput(
            $text,
            $grounded,
            $usedFactsCount,
            $sourceType,
            $humanServiceRequired,
            $safetyNotes,
            $this->resolveReplyType($input, $humanServiceRequired, $usedFactsCount),
            $this->resolveLayoutProfile($sourceType),
            false,
            true,
            $this->resolveVoiceProfileUsed($input)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function loadFeatureConfig(): array
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bats_feature.php';
        if (!is_readable($path)) {
            return [];
        }

        $loaded = require $path;

        return is_array($loaded) ? $loaded : [];
    }

    private function resolveReplyType(
        GroundedInput $input,
        bool $humanServiceRequired,
        int $usedFactsCount
    ): string {
        if ($humanServiceRequired) {
            return GroundedOutput::REPLY_TYPE_HUMAN_FALLBACK;
        }

        if ($input->getSourceType() === GroundedInput::SOURCE_PRODUCT_SEARCH) {
            return $input->getReplyPolicyMode() === 'no_results' || $usedFactsCount === 0
                ? GroundedOutput::REPLY_TYPE_NO_RESULTS
                : GroundedOutput::REPLY_TYPE_NORMAL;
        }

        return GroundedOutput::REPLY_TYPE_NORMAL;
    }

    private function resolveLayoutProfile(string $sourceType): string
    {
        if ($sourceType === GroundedInput::SOURCE_PRODUCT_SEARCH) {
            return GroundedOutput::LAYOUT_PRODUCT_RICH;
        }

        if ($sourceType === GroundedInput::SOURCE_HUMAN_SERVICE) {
            return GroundedOutput::LAYOUT_MINIMAL;
        }

        return GroundedOutput::LAYOUT_KNOWLEDGE_STANDARD;
    }

    private function resolveVoiceProfileUsed(GroundedInput $input): string
    {
        $tone = $input->getTone();
        $persona = trim((string) ($tone['persona'] ?? ''));

        return $persona;
    }
}
