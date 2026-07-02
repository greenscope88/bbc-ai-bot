<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'PersonaAdjustedDraft.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ExperienceDraft.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationContextReader.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationContinuityPresenter.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ContinuitySegments.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'NextBestActionPresenter.php';

/**
 * Phase 2-E Step 2-E-2e — conversation experience enhancement (presentation only).
 *
 * SSOT: docs/BATS_AI_GROUNDED_RESPONSE_COMPOSER.md §4.1 / §13 / §14.
 */
final class ConversationExperienceLayer
{
    private ConversationContinuityPresenter $continuityPresenter;

    private NextBestActionPresenter $nextBestActionPresenter;

    public function __construct(
        ?ConversationContinuityPresenter $continuityPresenter = null,
        ?NextBestActionPresenter $nextBestActionPresenter = null
    ) {
        $this->continuityPresenter = $continuityPresenter ?? new ConversationContinuityPresenter();
        $this->nextBestActionPresenter = $nextBestActionPresenter ?? new NextBestActionPresenter();
    }

    public function enhance(GroundedInput $input, PersonaAdjustedDraft $draft): ExperienceDraft
    {
        $context = ConversationContextReader::fromInput($input);
        $hints = $draft->getHints();

        if ($context->isEmpty() && $input->getResumeContext() === null && !$this->hasNextBestActionHint($input)) {
            return $this->toExperienceDraft($draft, $draft->getReplyText(), null);
        }

        $segments = $this->continuityPresenter->buildSegments($input, $context, $hints);
        $replyText = $this->applySegments($draft->getReplyText(), $segments);

        $nbaResult = $this->nextBestActionPresenter->present($input, $context, $hints);
        if ($nbaResult->isPresented() && $nbaResult->getClosingLine() !== null && $nbaResult->getClosingLine() !== '') {
            $replyText = $this->appendClosingLine($replyText, $nbaResult->getClosingLine());
        }

        return $this->toExperienceDraft($draft, $replyText, $nbaResult->getPresentedKey());
    }

    private function hasNextBestActionHint(GroundedInput $input): bool
    {
        $replyPolicy = $input->getReplyPolicy();
        if (isset($replyPolicy['next_best_action_hint']) && trim((string) $replyPolicy['next_best_action_hint']) !== '') {
            return true;
        }

        $metadata = $input->getMetadata();
        return isset($metadata['next_best_action_hint']) && trim((string) $metadata['next_best_action_hint']) !== '';
    }

    private function applySegments(string $replyText, ContinuitySegments $segments): string
    {
        if ($segments->isEmpty()) {
            return $replyText;
        }

        $parts = [];
        $prefix = $segments->getPrefix();
        if ($prefix !== null && $prefix !== '') {
            $parts[] = $prefix;
        }

        if ($replyText !== '') {
            $parts[] = $replyText;
        }

        $suffix = $segments->getSuffix();
        if ($suffix !== null && $suffix !== '') {
            $parts[] = $suffix;
        }

        return implode("\n\n", $parts);
    }

    private function appendClosingLine(string $replyText, string $closingLine): string
    {
        if ($replyText === '') {
            return $closingLine;
        }

        return $replyText . "\n\n" . $closingLine;
    }

    private function toExperienceDraft(
        PersonaAdjustedDraft $draft,
        string $replyText,
        ?string $nextBestActionPresented
    ): ExperienceDraft {
        return new ExperienceDraft(
            $replyText,
            $draft->isGrounded(),
            $draft->getUsedFactsCount(),
            $draft->getReplyType(),
            $draft->getLayoutProfile(),
            $draft->getReferencedFactIds(),
            $draft->getHints()->getVoiceProfileUsed(),
            $nextBestActionPresented
        );
    }
}
