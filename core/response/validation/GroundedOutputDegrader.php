<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'GroundedOutput.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'GroundedOutputValidationResult.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'knowledge' . DIRECTORY_SEPARATOR . 'KnowledgeResponseComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR
    . 'recommendation' . DIRECTORY_SEPARATOR . 'TravelConsultantPersonaRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR
    . 'recommendation' . DIRECTORY_SEPARATOR . 'TravelConsultantPersonaFormatter.php';

/**
 * Phase 2-E Step 2-E-2d — fail-closed downgrade for validation failures.
 *
 * SSOT: docs/BATS_AI_GROUNDED_RESPONSE_COMPOSER.md §10.4 / OUT-006.
 *
 * Does not fix hallucinated reply text, does not call Runtime / Gemini / Search.
 */
final class GroundedOutputDegrader
{
    private KnowledgeResponseComposer $knowledgeComposer;

    private TravelConsultantPersonaRuntime $personaRuntime;

    public function __construct(
        ?KnowledgeResponseComposer $knowledgeComposer = null,
        ?TravelConsultantPersonaRuntime $personaRuntime = null
    ) {
        $this->knowledgeComposer = $knowledgeComposer ?? new KnowledgeResponseComposer(static function (string $poolKey, int $count): int {
            return 0;
        });
        $this->personaRuntime = $personaRuntime ?? new TravelConsultantPersonaRuntime(
            TravelConsultantPersonaFormatter::createWithFixedIndex(0)
        );
    }

    public function degrade(
        GroundedInput $input,
        GroundedOutput $candidate,
        GroundedOutputValidationResult $result
    ): GroundedOutput {
        $replyType = $input->getSourceType() === GroundedInput::SOURCE_HUMAN_SERVICE
            ? ReplyType::HUMAN_FALLBACK
            : ReplyType::NO_RESULTS;

        $safetyNotes = $candidate->getSafetyNotes();
        $safetyNotes[] = 'validation_failed';

        $validationNotes = $result->getNotes();
        if ($result->getFailedRuleIds() !== []) {
            $validationNotes[] = 'failed_rules:' . implode(',', $result->getFailedRuleIds());
        }

        return new GroundedOutput(
            $this->resolveSafeReplyText($input),
            false,
            0,
            $candidate->getSourceType(),
            $candidate->isHumanServiceRequired(),
            $safetyNotes,
            $replyType,
            $candidate->getLayoutProfile(),
            false,
            false,
            $candidate->getVoiceProfileUsed(),
            $validationNotes,
            [],
            null
        );
    }

    private function resolveSafeReplyText(GroundedInput $input): string
    {
        if ($input->getSourceType() === GroundedInput::SOURCE_PRODUCT_SEARCH) {
            return $this->personaRuntime->composeNoResultsMessage();
        }

        return $this->knowledgeComposer->composeUnresolvableReply();
    }
}
