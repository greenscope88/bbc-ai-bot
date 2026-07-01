<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-2b — internal NLG draft (not a frozen contract field).
 */
final class LayoutDraft
{
    private string $replyText;

    private bool $grounded;

    private int $usedFactsCount;

    private string $replyType;

    private string $layoutProfile;

    /** @var list<string> */
    private array $referencedFactIds;

    /**
     * @param list<string> $referencedFactIds
     */
    public function __construct(
        string $replyText,
        bool $grounded,
        int $usedFactsCount,
        string $replyType,
        string $layoutProfile,
        array $referencedFactIds = []
    ) {
        $this->replyText = $replyText;
        $this->grounded = $grounded;
        $this->usedFactsCount = max(0, $usedFactsCount);
        $this->replyType = $replyType;
        $this->layoutProfile = $layoutProfile;
        $this->referencedFactIds = $referencedFactIds;
    }

    public function getReplyText(): string
    {
        return $this->replyText;
    }

    public function isGrounded(): bool
    {
        return $this->grounded;
    }

    public function getUsedFactsCount(): int
    {
        return $this->usedFactsCount;
    }

    public function getReplyType(): string
    {
        return $this->replyType;
    }

    public function getLayoutProfile(): string
    {
        return $this->layoutProfile;
    }

    /**
     * @return list<string>
     */
    public function getReferencedFactIds(): array
    {
        return $this->referencedFactIds;
    }
}
