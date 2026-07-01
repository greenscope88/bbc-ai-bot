<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundedOutput.php';

/**
 * Phase 9-C-2C-1 — Grounded Response Composer (presentation layer, thin wrapper).
 *
 * SSOT: docs/PHASE_9C2C_GROUNDED_RESPONSE_COMPOSER.md
 *
 * Single, multi-tenant composer that presents Runtime-resolved grounded data
 * under one contract (GroundedInput -> GroundedOutput).
 *
 * Scope (9-C-2C-1 Knowledge Path, 9-C-2C-2 Product Path): the Runtime already
 * composes the final reply text (Knowledge: KnowledgeResponseComposer /
 * HumanServiceResponseComposer; Product: TravelConsultantPersonaRuntime via
 * GeminiClient). This composer passes that text through unchanged and only
 * normalizes the surrounding grounded metadata (reply_type / layout_profile /
 * used_facts_count) so downstream phases rely on a stable contract. Behavior is
 * unchanged.
 *
 * Hard rules (BATS_AI_PERSONA.md §5 / SSOT §7):
 *  - Never adds prices, dates, URLs, phone numbers, policies, or products.
 *  - Never invents facts the Runtime did not provide.
 *  - Never regenerates recommendation copy, never calls Gemini, never searches,
 *    never makes routing decisions.
 */
final class GroundedResponseComposer
{
    /**
     * Compose a GroundedOutput from an already-resolved GroundedInput.
     *
     * The reply text is taken verbatim from the Runtime result; this method
     * only derives grounded metadata and records non-blocking safety notes.
     */
    public function compose(GroundedInput $input): GroundedOutput
    {
        $text = $input->getRuntimeReplyText();
        $grounded = $input->isRuntimeGrounded();
        $usedFactsCount = $input->getFactCount();
        $sourceType = $input->getSourceType();
        $humanServiceRequired = $sourceType === GroundedInput::SOURCE_HUMAN_SERVICE;

        $safetyNotes = [];

        // Contract invariant (SSOT §4): grounded reply must reference >=1 fact.
        if ($grounded && $usedFactsCount === 0) {
            $safetyNotes[] = 'grounded_without_facts';
        }

        // Contract invariant: ungrounded reply must not claim facts.
        if (!$grounded && $usedFactsCount > 0) {
            $usedFactsCount = 0;
            $safetyNotes[] = 'ungrounded_fact_count_reset';
        }

        // Human-service fallback is, by definition, not grounded in tenant data.
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
     * Reply text must already be produced by the Product Runtime; this method
     * never regenerates recommendation copy.
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
