<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundedOutput.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'LayoutProfile.php';

/**
 * Phase 2-E Step 2-E-2a — Human Takeover defense-in-depth guard.
 *
 * SSOT: docs/BATS_AI_GROUNDED_RESPONSE_COMPOSER.md §15.
 *
 * When `conversation_owner=HUMAN`, returns a suppressed GroundedOutput without
 * throwing. Primary Guard remains the Orchestrator; this path is fallback only.
 */
final class HumanTakeoverDefenseGuard
{
    /**
     * @return GroundedOutput|null Null when compose may continue; suppressed output otherwise.
     */
    public static function evaluate(GroundedInput $input): ?GroundedOutput
    {
        if ($input->getConversationOwner() !== GroundedInput::CONVERSATION_OWNER_HUMAN) {
            return null;
        }

        $tone = $input->getTone();
        $persona = trim((string) ($tone['persona'] ?? ''));

        return new GroundedOutput(
            '',
            false,
            0,
            $input->getSourceType(),
            false,
            ['human_takeover_suppressed'],
            ReplyType::SUPPRESSED_HUMAN_TAKEOVER,
            LayoutProfile::MINIMAL,
            true,
            true,
            $persona
        );
    }
}
