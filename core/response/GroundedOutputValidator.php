<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundedOutput.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundedOutputValidationResult.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'validation' . DIRECTORY_SEPARATOR . 'GroundedFactAllowlistBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'validation' . DIRECTORY_SEPARATOR . 'GroundedOutputDegrader.php';

/**
 * Phase 2-E Step 2-E-2d — Composer internal output verification gate.
 *
 * SSOT: docs/BATS_AI_GROUNDED_RESPONSE_COMPOSER.md §10 / OUT-001..OUT-006.
 */
final class GroundedOutputValidator
{
    private GroundedOutputDegrader $degrader;

    public function __construct(?GroundedOutputDegrader $degrader = null)
    {
        $this->degrader = $degrader ?? new GroundedOutputDegrader();
    }

    public function validateAndFinalize(
        GroundedInput $input,
        GroundedOutput $candidate,
        bool $strictTraceability = true
    ): GroundedOutput {
        if (
            $candidate->isReplySuppressed()
            && $candidate->getReplyType() === ReplyType::SUPPRESSED_HUMAN_TAKEOVER
        ) {
            return $candidate;
        }

        $result = $this->validate($input, $candidate, $strictTraceability);
        if ($result->isPassed()) {
            return $candidate;
        }

        return $this->degrader->degrade($input, $candidate, $result);
    }

    public function validate(
        GroundedInput $input,
        GroundedOutput $output,
        bool $strictTraceability = true
    ): GroundedOutputValidationResult {
        $failedRuleIds = [];
        $notes = [];

        if ($input->getConversationOwner() === GroundedInput::CONVERSATION_OWNER_HUMAN) {
            if (
                !$output->isReplySuppressed()
                || $output->getReplyText() !== ''
                || $output->getReplyType() !== ReplyType::SUPPRESSED_HUMAN_TAKEOVER
            ) {
                $failedRuleIds[] = 'VR-007';
                $notes[] = 'human_guard_inconsistent';
            }
        }

        if (
            $output->isGrounded()
            && $output->getUsedFactsCount() === 0
            && $output->getReplyType() === ReplyType::NORMAL
        ) {
            $failedRuleIds[] = 'VR-001';
            $notes[] = 'grounded_without_facts_normal_reply';
        }

        if ($output->getUsedFactsCount() > $input->getFactCount()) {
            $failedRuleIds[] = 'VR-002';
            $notes[] = 'used_facts_count_exceeds_input';
        }

        $allowlist = GroundedFactAllowlistBuilder::build($input);
        $referencedFactIds = $output->getReferencedFactIds();
        if ($referencedFactIds !== []) {
            $allowedFactIds = $allowlist['fact_ids'];
            foreach ($referencedFactIds as $referencedId) {
                if (!in_array($referencedId, $allowedFactIds, true)) {
                    $failedRuleIds[] = 'VR-006';
                    $notes[] = 'referenced_fact_id_not_in_input:' . $referencedId;
                    break;
                }
            }
        }

        if ($strictTraceability) {
            $traceabilityFailures = $this->validateTraceability($output->getReplyText(), $allowlist);
            foreach ($traceabilityFailures as $failure) {
                if (!in_array($failure['rule'], $failedRuleIds, true)) {
                    $failedRuleIds[] = $failure['rule'];
                }
                $notes[] = $failure['note'];
            }
        }

        if ($failedRuleIds !== []) {
            return GroundedOutputValidationResult::fail(array_values(array_unique($failedRuleIds)), $notes);
        }

        return GroundedOutputValidationResult::pass();
    }

    /**
     * @param array{
     *   fact_ids: list<string>,
     *   urls: list<string>,
     *   phones: list<string>,
     *   titles: list<string>,
     *   prices: list<string>,
     *   text_fragments: list<string>
     * } $allowlist
     *
     * @return list<array{rule: string, note: string}>
     */
    private function validateTraceability(string $replyText, array $allowlist): array
    {
        $failures = [];

        foreach (GroundedFactAllowlistBuilder::extractUrls($replyText) as $url) {
            if (!GroundedFactAllowlistBuilder::isUrlAllowed($url, $allowlist['urls'])) {
                $failures[] = [
                    'rule' => 'VR-004',
                    'note' => 'invented_url:' . $url,
                ];
            }
        }

        foreach (GroundedFactAllowlistBuilder::extractPhoneDigits($replyText) as $phone) {
            if (!GroundedFactAllowlistBuilder::isPhoneAllowed($phone, $allowlist['phones'])) {
                $failures[] = [
                    'rule' => 'VR-004',
                    'note' => 'invented_phone:' . $phone,
                ];
            }
        }

        foreach (GroundedFactAllowlistBuilder::extractPrices($replyText) as $price) {
            if (!GroundedFactAllowlistBuilder::isPriceAllowed($price, $allowlist['prices'])) {
                $failures[] = [
                    'rule' => 'VR-005',
                    'note' => 'invented_price:' . $price,
                ];
            }
        }

        return $failures;
    }
}
