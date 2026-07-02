<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-2e — next best action presentation result (internal VO).
 */
final class NextBestActionResult
{
    private ?string $presentedKey;

    private ?string $closingLine;

    private ?string $skippedReason;

    public function __construct(
        ?string $presentedKey = null,
        ?string $closingLine = null,
        ?string $skippedReason = null
    ) {
        $this->presentedKey = $presentedKey;
        $this->closingLine = $closingLine;
        $this->skippedReason = $skippedReason;
    }

    public function getPresentedKey(): ?string
    {
        return $this->presentedKey;
    }

    public function getClosingLine(): ?string
    {
        return $this->closingLine;
    }

    public function getSkippedReason(): ?string
    {
        return $this->skippedReason;
    }

    public function isPresented(): bool
    {
        return $this->presentedKey !== null && $this->presentedKey !== '';
    }
}
