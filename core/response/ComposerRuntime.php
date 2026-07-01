<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundedOutput.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'HumanTakeoverDefenseGuard.php';

/**
 * Phase 2-E Step 2-E-2a — Composer Runtime pipeline skeleton.
 *
 * SSOT: docs/BATS_AI_GROUNDED_RESPONSE_COMPOSER.md §3 / §15.
 *
 * Phase 2-E-2a scope: Human Takeover guard + legacy pass-through fallback only.
 * Generative NLG, Prioritization, LayoutStrategy, and Validator are future steps.
 */
final class ComposerRuntime
{
    /**
     * @param callable(GroundedInput): GroundedOutput $legacyPassThrough
     */
    public function run(GroundedInput $input, callable $legacyPassThrough): GroundedOutput
    {
        $suppressed = HumanTakeoverDefenseGuard::evaluate($input);
        if ($suppressed !== null) {
            return $suppressed;
        }

        return $legacyPassThrough($input);
    }
}
