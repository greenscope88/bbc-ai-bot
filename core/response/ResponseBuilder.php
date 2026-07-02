<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundedOutput.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'LayoutDraft.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'experience' . DIRECTORY_SEPARATOR . 'ExperienceDraft.php';

/**
 * Phase 2-E Step 2-E-2b — assembles LayoutDraft into frozen GroundedOutput.
 */
final class ResponseBuilder
{
    public function buildFromExperience(GroundedInput $input, ExperienceDraft $draft): GroundedOutput
    {
        $sourceType = $input->getSourceType();
        $humanServiceRequired = $sourceType === GroundedInput::SOURCE_HUMAN_SERVICE;
        $grounded = $draft->isGrounded();
        $usedFactsCount = $draft->getUsedFactsCount();
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
            $draft->getReplyText(),
            $grounded,
            $usedFactsCount,
            $sourceType,
            $humanServiceRequired,
            $safetyNotes,
            $draft->getReplyType(),
            $draft->getLayoutProfile(),
            false,
            true,
            $draft->getVoiceProfileUsed(),
            [],
            $draft->getReferencedFactIds(),
            $draft->getNextBestActionPresented()
        );
    }

    public function build(GroundedInput $input, LayoutDraft $draft): GroundedOutput
    {
        $sourceType = $input->getSourceType();
        $humanServiceRequired = $sourceType === GroundedInput::SOURCE_HUMAN_SERVICE;
        $grounded = $draft->isGrounded();
        $usedFactsCount = $draft->getUsedFactsCount();
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

        $tone = $input->getTone();
        $persona = trim((string) ($tone['persona'] ?? ''));

        return new GroundedOutput(
            $draft->getReplyText(),
            $grounded,
            $usedFactsCount,
            $sourceType,
            $humanServiceRequired,
            $safetyNotes,
            $draft->getReplyType(),
            $draft->getLayoutProfile(),
            false,
            true,
            $persona,
            [],
            $draft->getReferencedFactIds()
        );
    }
}
