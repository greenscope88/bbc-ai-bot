<?php
declare(strict_types=1);

/**
 * Phase 9-C-2C-1 — Grounded Response Composer output contract (presentation layer).
 *
 * SSOT: docs/PHASE_9C2C_GROUNDED_RESPONSE_COMPOSER.md
 *
 * The final, ready-to-send reply text plus grounded metadata. The text is
 * produced strictly from the GroundedInput; the composer never adds facts that
 * the Runtime did not provide.
 */
final class GroundedOutput
{
    public const REPLY_TYPE_NORMAL = 'normal_reply';
    public const REPLY_TYPE_NO_RESULTS = 'no_results';
    public const REPLY_TYPE_CLARIFICATION = 'clarification';
    public const REPLY_TYPE_HUMAN_FALLBACK = 'human_agent_fallback';

    public const LAYOUT_PRODUCT_RICH = 'product_rich_v1';
    public const LAYOUT_KNOWLEDGE_STANDARD = 'knowledge_standard_v1';
    public const LAYOUT_MINIMAL = 'minimal_v1';

    private string $text;

    private bool $grounded;

    private int $usedFactsCount;

    private string $sourceType;

    private bool $humanServiceRequired;

    /** @var list<string> */
    private array $safetyNotes;

    private string $replyType;

    private string $layoutProfile;

    /**
     * @param list<string> $safetyNotes
     */
    public function __construct(
        string $text,
        bool $grounded,
        int $usedFactsCount,
        string $sourceType,
        bool $humanServiceRequired,
        array $safetyNotes = [],
        string $replyType = self::REPLY_TYPE_NORMAL,
        string $layoutProfile = self::LAYOUT_KNOWLEDGE_STANDARD
    ) {
        $this->text = $text;
        $this->grounded = $grounded;
        $this->usedFactsCount = max(0, $usedFactsCount);
        $this->sourceType = $sourceType;
        $this->humanServiceRequired = $humanServiceRequired;
        $this->safetyNotes = $safetyNotes;
        $this->replyType = $replyType;
        $this->layoutProfile = $layoutProfile;
    }

    public function getText(): string
    {
        return $this->text;
    }

    /**
     * SSOT contract alias for getText(); reply_text is the canonical field name.
     */
    public function getReplyText(): string
    {
        return $this->text;
    }

    public function getReplyType(): string
    {
        return $this->replyType;
    }

    public function getLayoutProfile(): string
    {
        return $this->layoutProfile;
    }

    public function isGrounded(): bool
    {
        return $this->grounded;
    }

    public function getUsedFactsCount(): int
    {
        return $this->usedFactsCount;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function isHumanServiceRequired(): bool
    {
        return $this->humanServiceRequired;
    }

    /**
     * @return list<string>
     */
    public function getSafetyNotes(): array
    {
        return $this->safetyNotes;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'reply_text' => $this->text,
            'reply_type' => $this->replyType,
            'layout_profile' => $this->layoutProfile,
            'grounded' => $this->grounded,
            'used_facts_count' => $this->usedFactsCount,
            'source_type' => $this->sourceType,
            'human_service_required' => $this->humanServiceRequired,
            'safety_notes' => $this->safetyNotes,
        ];
    }
}
