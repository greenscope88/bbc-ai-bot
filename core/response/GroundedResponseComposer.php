<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundedOutput.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ComposerRuntime.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ResponseBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'LayoutStrategySelector.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundedOutputValidator.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'experience' . DIRECTORY_SEPARATOR . 'PersonaAdapter.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'experience' . DIRECTORY_SEPARATOR . 'ConversationExperienceLayer.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'RuntimeType.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'clarification' . DIRECTORY_SEPARATOR . 'ClarificationContract.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR
    . 'renderer' . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'LineMessagePayload.php';

/**
 * Phase 9-C-2C-1 — Grounded Response Composer (presentation layer, thin wrapper).
 *
 * SSOT: docs/PHASE_9C2C_GROUNDED_RESPONSE_COMPOSER.md
 *
 * Phase 2-E-2a: when `grounded_composer_generative_enabled` is ON, delegates to
 * ComposerRuntime (Human Takeover guard + legacy pass-through). When OFF, legacy
 * pass-through only — behavior unchanged from Phase 2-E-1 for Product/Knowledge.
 *
 * Phase 2-E-2b: flag ON + knowledge runtime_type routes through KnowledgeLayoutStrategy.
 *
 * B0-LINE-02C-2: clarification GroundedInput always uses ClarificationLayoutStrategy
 * (sole normal clarification wording owner), independent of the generative feature flag.
 */
final class GroundedResponseComposer
{
    public const FEATURE_GROUNDED_COMPOSER_GENERATIVE_ENABLED = 'grounded_composer_generative_enabled';

    /** @var array<string, mixed> */
    private array $featureConfig;

    private ComposerRuntime $composerRuntime;

    private LayoutStrategySelector $layoutStrategySelector;

    private ResponseBuilder $responseBuilder;

    private GroundedOutputValidator $groundedOutputValidator;

    private PersonaAdapter $personaAdapter;

    private ConversationExperienceLayer $conversationExperienceLayer;

    /**
     * @param array<string, mixed>|null $featureConfig Override for tests; loads config/bats_feature.php when null.
     */
    public function __construct(
        ?array $featureConfig = null,
        ?ComposerRuntime $composerRuntime = null,
        ?LayoutStrategySelector $layoutStrategySelector = null,
        ?ResponseBuilder $responseBuilder = null,
        ?GroundedOutputValidator $groundedOutputValidator = null,
        ?PersonaAdapter $personaAdapter = null,
        ?ConversationExperienceLayer $conversationExperienceLayer = null
    ) {
        $this->featureConfig = $featureConfig ?? self::loadFeatureConfig();
        $this->composerRuntime = $composerRuntime ?? new ComposerRuntime();
        $this->layoutStrategySelector = $layoutStrategySelector ?? LayoutStrategySelector::createDefault();
        $this->responseBuilder = $responseBuilder ?? new ResponseBuilder();
        $this->groundedOutputValidator = $groundedOutputValidator ?? new GroundedOutputValidator();
        $this->personaAdapter = $personaAdapter ?? new PersonaAdapter();
        $this->conversationExperienceLayer = $conversationExperienceLayer ?? new ConversationExperienceLayer();
    }

    /**
     * Compose a GroundedOutput from an already-resolved GroundedInput.
     */
    public function compose(GroundedInput $input): GroundedOutput
    {
        // Clarification always owns generative ClarificationLayoutStrategy wording.
        if ($input->getRuntimeType() === RuntimeType::CLARIFICATION) {
            return $this->composerRuntime->run(
                $input,
                function (GroundedInput $groundedInput): GroundedOutput {
                    return $this->composeGenerativePath($groundedInput);
                }
            );
        }

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

    /**
     * Sole normal clarification wording owner entry.
     */
    public function composeClarificationReply(ClarificationContract $contract): GroundedOutput
    {
        $output = $this->compose(GroundedInput::fromClarificationContract($contract));

        return $this->attachClarificationMetadata($output, $contract);
    }

    public function isGenerativeRuntimeEnabled(): bool
    {
        return (bool) ($this->featureConfig[self::FEATURE_GROUNDED_COMPOSER_GENERATIVE_ENABLED] ?? false);
    }

    /**
     * Generative path (2-E-2b): strategy when supported; otherwise legacy.
     */
    public function composeGenerativePath(GroundedInput $input): GroundedOutput
    {
        $strategy = $this->layoutStrategySelector->resolve($input);
        if ($strategy !== null) {
            $layoutDraft = $strategy->compose($input);
            $personaDraft = $this->personaAdapter->adapt($input, $layoutDraft);
            $experienceDraft = $this->conversationExperienceLayer->enhance($input, $personaDraft);
            $candidate = $this->responseBuilder->buildFromExperience($input, $experienceDraft);
            // Clarification: skip phone/URL/price text-scan strict mode (ISO dates
            // false-positive as phones). ClarificationOutputValidator already ran.
            $strictTraceability = $input->getRuntimeType() !== RuntimeType::CLARIFICATION;

            $finalized = $this->groundedOutputValidator->validateAndFinalize(
                $input,
                $candidate,
                $strictTraceability
            );

            if ($input->getRuntimeType() === RuntimeType::CLARIFICATION) {
                $raw = $input->getRawRuntimeResult();
                $contractArr = isset($raw['clarification_contract']) && is_array($raw['clarification_contract'])
                    ? $raw['clarification_contract']
                    : [];
                if ($contractArr !== []) {
                    // Rebuild a lightweight contract view for metadata only.
                    $notes = $finalized->getValidationNotes();
                    $missing = (string) ($contractArr['missing_entity'] ?? '');
                    $reason = (string) ($contractArr['clarification_reason'] ?? '');
                    if ($missing !== '') {
                        $notes[] = 'asked_entity:' . $missing;
                    }
                    if ($reason !== '') {
                        $notes[] = 'clarification_reason:' . $reason;
                    }
                    if ($finalized->getReplyText() === ClarificationContract::TECHNICAL_FAIL_CLOSED_TEXT) {
                        $notes[] = 'failure_reason:clarification_foundation_fail_closed';
                    }
                    $known = isset($contractArr['known_entities']) && is_array($contractArr['known_entities'])
                        ? $contractArr['known_entities']
                        : [];
                    $notes[] = 'known_entities_count:' . count($known);

                    return new GroundedOutput(
                        $finalized->getReplyText(),
                        $finalized->isGrounded(),
                        $finalized->getUsedFactsCount(),
                        $finalized->getSourceType(),
                        $finalized->isHumanServiceRequired(),
                        $finalized->getSafetyNotes(),
                        $finalized->getReplyType() !== ''
                            ? $finalized->getReplyType()
                            : ReplyType::CLARIFICATION,
                        $finalized->getLayoutProfile(),
                        $finalized->isReplySuppressed(),
                        $finalized->isValidationPassed(),
                        $finalized->getVoiceProfileUsed(),
                        $notes,
                        $finalized->getReferencedFactIds(),
                        $finalized->getNextBestActionPresented(),
                        $finalized->getChannelMessages()
                    );
                }
            }

            return $finalized;
        }

        $candidate = $this->composeLegacyPassThrough($input);

        return $this->groundedOutputValidator->validateAndFinalize($input, $candidate, false);
    }

    /**
     * @param list<string> $referencedFactIds
     */
    public function composeBbcshopsFlexMultiSourceReply(
        GroundedInput $input,
        LineMessagePayload $channelMessages,
        array $referencedFactIds
    ): GroundedOutput {
        $uniqueRefs = array_values(array_unique(array_map('strval', $referencedFactIds)));
        $usedFactsCount = count($uniqueRefs);
        $replyType = $this->resolveReplyType($input, false, $usedFactsCount);
        $firstText = '';
        foreach ($channelMessages->getMessages() as $message) {
            if (is_array($message) && ($message['type'] ?? '') === 'text') {
                $firstText = trim((string) ($message['text'] ?? ''));
                break;
            }
        }

        $candidate = new GroundedOutput(
            $firstText,
            $usedFactsCount > 0,
            $usedFactsCount,
            $input->getSourceType(),
            false,
            [],
            $replyType,
            LayoutProfile::PRODUCT_RICH,
            false,
            true,
            $this->resolveVoiceProfileUsed($input),
            [],
            $uniqueRefs,
            null,
            $channelMessages
        );

        return $this->groundedOutputValidator->validateAndFinalize($input, $candidate, true);
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

    private function attachClarificationMetadata(
        GroundedOutput $output,
        ClarificationContract $contract
    ): GroundedOutput {
        $notes = $output->getValidationNotes();
        $notes[] = 'asked_entity:' . $contract->getMissingEntity();
        $notes[] = 'clarification_reason:' . $contract->getClarificationReason();
        $notes[] = 'known_entities_count:' . count($contract->getKnownEntities());
        if ($output->getReplyText() === ClarificationContract::TECHNICAL_FAIL_CLOSED_TEXT) {
            $notes[] = 'failure_reason:clarification_foundation_fail_closed';
        }

        return new GroundedOutput(
            $output->getReplyText(),
            $output->isGrounded(),
            $output->getUsedFactsCount(),
            $output->getSourceType(),
            $output->isHumanServiceRequired(),
            $output->getSafetyNotes(),
            $output->getReplyType() !== '' ? $output->getReplyType() : ReplyType::CLARIFICATION,
            $output->getLayoutProfile(),
            $output->isReplySuppressed(),
            $output->isValidationPassed(),
            $output->getVoiceProfileUsed(),
            array_values(array_unique($notes)),
            $output->getReferencedFactIds(),
            $output->getNextBestActionPresented(),
            $output->getChannelMessages()
        );
    }

    private function resolveReplyType(
        GroundedInput $input,
        bool $humanServiceRequired,
        int $usedFactsCount
    ): string {
        if ($humanServiceRequired) {
            return GroundedOutput::REPLY_TYPE_HUMAN_FALLBACK;
        }

        if ($input->getRuntimeType() === RuntimeType::CLARIFICATION) {
            return ReplyType::CLARIFICATION;
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
