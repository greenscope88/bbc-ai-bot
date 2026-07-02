<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationContextView.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ContinuitySegments.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'PersonaRenderHints.php';

/**
 * Phase 2-E Step 2-E-2e — echoes conversation_context strings only (presentation).
 */
final class ConversationContinuityPresenter
{
    public function buildSegments(
        GroundedInput $input,
        ConversationContextView $context,
        PersonaRenderHints $hints
    ): ContinuitySegments {
        if ($context->isEmpty() && $input->getResumeContext() === null) {
            return new ContinuitySegments();
        }

        $prefixParts = [];

        $resumeContext = $input->getResumeContext();
        if (is_array($resumeContext) && $resumeContext !== []) {
            $prefixParts[] = '您好，我已看過剛才的對話，';
        }

        if ($context->hasCurrentRequirement()) {
            $prefixParts[] = '延續您剛才提到的' . $context->getCurrentRequirement() . '，';
        }

        $issues = $context->getOutstandingIssues();
        if ($issues !== []) {
            $lines = ['先前待確認：'];
            foreach ($issues as $issue) {
                $lines[] = '- ' . $issue;
            }
            $prefixParts[] = implode("\n", $lines);
        }

        $prefix = $prefixParts === [] ? null : implode("\n\n", $prefixParts);

        return new ContinuitySegments($prefix);
    }
}
