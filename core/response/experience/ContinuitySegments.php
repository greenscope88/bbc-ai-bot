<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-2e — continuity presentation segments (internal VO).
 */
final class ContinuitySegments
{
    private ?string $prefix;

    private ?string $suffix;

    public function __construct(?string $prefix = null, ?string $suffix = null)
    {
        $this->prefix = $prefix;
        $this->suffix = $suffix;
    }

    public function getPrefix(): ?string
    {
        return $this->prefix;
    }

    public function getSuffix(): ?string
    {
        return $this->suffix;
    }

    public function isEmpty(): bool
    {
        return ($this->prefix === null || $this->prefix === '')
            && ($this->suffix === null || $this->suffix === '');
    }
}
