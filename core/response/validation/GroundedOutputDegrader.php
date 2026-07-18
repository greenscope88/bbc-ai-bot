<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'GroundedOutput.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'GroundedOutputValidationResult.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'clarification' . DIRECTORY_SEPARATOR . 'ClarificationContract.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'knowledge' . DIRECTORY_SEPARATOR . 'KnowledgeResponseComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR
    . 'renderer' . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'LineMessagePayload.php';
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
    public const PROFILE_BBCSHOPS_FLEX_MULTI_SOURCE = 'bbcshops_flex_multi_source';

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
        if ($this->isBbcshopsFlexMultiSource($input)) {
            return $this->technicalFailClosed($candidate, $result);
        }

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

    private function isBbcshopsFlexMultiSource(GroundedInput $input): bool
    {
        $policy = $input->getReplyPolicy();

        return isset($policy['safety_degrader_profile'])
            && trim((string) $policy['safety_degrader_profile']) === self::PROFILE_BBCSHOPS_FLEX_MULTI_SOURCE;
    }

    private function technicalFailClosed(
        GroundedOutput $candidate,
        GroundedOutputValidationResult $result
    ): GroundedOutput {
        $safetyNotes = $candidate->getSafetyNotes();
        $safetyNotes[] = 'validation_failed';

        $validationNotes = $result->getNotes();
        if ($result->getFailedRuleIds() !== []) {
            $validationNotes[] = 'failed_rules:' . implode(',', $result->getFailedRuleIds());
        }

        $message = ClarificationContract::TECHNICAL_FAIL_CLOSED_TEXT;

        return new GroundedOutput(
            $message,
            false,
            0,
            $candidate->getSourceType(),
            false,
            $safetyNotes,
            ReplyType::NO_RESULTS,
            $candidate->getLayoutProfile(),
            false,
            false,
            $candidate->getVoiceProfileUsed(),
            $validationNotes,
            [],
            null,
            LineMessagePayload::fromMessages([
                ['type' => 'text', 'text' => $message],
            ])
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
