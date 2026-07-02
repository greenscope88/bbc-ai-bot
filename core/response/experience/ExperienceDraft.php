<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-2e — experience-enhanced draft for ResponseBuilder (internal VO).
 */
final class ExperienceDraft
{
    private string $replyText;

    private bool $grounded;

    private int $usedFactsCount;

    private string $replyType;

    private string $layoutProfile;

    /** @var list<string> */
    private array $referencedFactIds;

    private string $voiceProfileUsed;

    private ?string $nextBestActionPresented;

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
        string $voiceProfileUsed,
        ?string $nextBestActionPresented = null
    ) {
        $this->replyText = $replyText;
        $this->grounded = $grounded;
        $this->usedFactsCount = max(0, $usedFactsCount);
        $this->replyType = $replyType;
        $this->layoutProfile = $layoutProfile;
        $this->referencedFactIds = $referencedFactIds;
        $this->voiceProfileUsed = $voiceProfileUsed;
        $this->nextBestActionPresented = $nextBestActionPresented;
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

    public function getVoiceProfileUsed(): string
    {
        return $this->voiceProfileUsed;
    }

    public function getNextBestActionPresented(): ?string
    {
        return $this->nextBestActionPresented;
    }
}
