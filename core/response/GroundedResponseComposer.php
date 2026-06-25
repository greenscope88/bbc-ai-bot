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
 * 9-C-2C-1 scope: Knowledge Path only. The Knowledge Runtime already composes
 * the final grounded reply text (via KnowledgeResponseComposer /
 * HumanServiceResponseComposer). This composer therefore passes that text
 * through unchanged and only normalizes the surrounding grounded metadata so
 * downstream phases can rely on a stable contract. Behavior is unchanged.
 *
 * Hard rules (BATS_AI_PERSONA.md §5 / SSOT §7):
 *  - Never adds prices, dates, URLs, phone numbers, policies, or products.
 *  - Never invents facts the Runtime did not provide.
 *  - Never calls Gemini, never searches, never makes routing decisions.
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
            $safetyNotes
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
}
