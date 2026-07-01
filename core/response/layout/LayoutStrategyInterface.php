<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'LayoutDraft.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'GroundedInput.php';

/**
 * Phase 2-E Step 2-E-2b — layout strategy contract (internal).
 */
interface LayoutStrategyInterface
{
    public function supports(GroundedInput $input): bool;

    public function compose(GroundedInput $input): LayoutDraft;
}
