<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'LayoutDraft.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'PersonaRenderHints.php';

/**
 * Phase 2-E Step 2-E-2e — layout draft after persona consume (internal VO).
 */
final class PersonaAdjustedDraft
{
    private string $replyText;

    private bool $grounded;

    private int $usedFactsCount;

    private string $replyType;

    private string $layoutProfile;

    /** @var list<string> */
    private array $referencedFactIds;

    private PersonaRenderHints $hints;

    /**
     * @param list<string> $referencedFactIds
     */
    public function __construct(
        string $replyText,
        bool $grounded,
        int $usedFactsCount,
        string $replyType,
        string $layoutProfile,
        array $referencedFactIds,
        PersonaRenderHints $hints
    ) {
        $this->replyText = $replyText;
        $this->grounded = $grounded;
        $this->usedFactsCount = max(0, $usedFactsCount);
        $this->replyType = $replyType;
        $this->layoutProfile = $layoutProfile;
        $this->referencedFactIds = $referencedFactIds;
        $this->hints = $hints;
    }

    public static function fromLayoutDraft(LayoutDraft $draft, PersonaRenderHints $hints, string $replyText): self
    {
        return new self(
            $replyText,
            $draft->isGrounded(),
            $draft->getUsedFactsCount(),
            $draft->getReplyType(),
            $draft->getLayoutProfile(),
            $draft->getReferencedFactIds(),
            $hints
        );
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

    public function getHints(): PersonaRenderHints
    {
        return $this->hints;
    }
}
