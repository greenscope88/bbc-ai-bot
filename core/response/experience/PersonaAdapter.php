<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'LayoutDraft.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'PersonaRenderHints.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'PersonaAdjustedDraft.php';

/**
 * Phase 2-E Step 2-E-2e — consumes tone / persona / emoji policy from GroundedInput.
 *
 * SSOT: docs/BATS_AI_PERSONA.md (reference only).
 */
final class PersonaAdapter
{
    private const DEFAULT_PERSONA = 'travel_consultant';

    public function adapt(GroundedInput $input, LayoutDraft $draft): PersonaAdjustedDraft
    {
        $tone = $input->getTone();
        $persona = trim((string) ($tone['persona'] ?? ''));
        if ($persona === '') {
            $persona = self::DEFAULT_PERSONA;
        }

        $allowEmoji = (bool) ($tone['allow_emoji'] ?? false);
        $openingStyle = $this->resolveOpeningStyle($input);
        $commerceClosingStyle = PersonaRenderHints::COMMERCE_SOFT_GUIDE;

        $hints = new PersonaRenderHints(
            $persona,
            $allowEmoji,
            $openingStyle,
            $commerceClosingStyle
        );

        $replyText = $draft->getReplyText();
        if (!$allowEmoji) {
            $replyText = $this->stripEmoji($replyText);
        }

        return PersonaAdjustedDraft::fromLayoutDraft($draft, $hints, $replyText);
    }

    private function resolveOpeningStyle(GroundedInput $input): string
    {
        $resumeContext = $input->getResumeContext();
        if (is_array($resumeContext) && $resumeContext !== []) {
            return PersonaRenderHints::OPENING_RESUME_ACK;
        }

        return PersonaRenderHints::OPENING_STANDARD;
    }

    private function stripEmoji(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $stripped = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $text);
        if (!is_string($stripped)) {
            return $text;
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", $stripped));
    }
}
