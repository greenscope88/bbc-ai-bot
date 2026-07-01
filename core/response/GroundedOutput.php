<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'LayoutProfile.php';

/**
 * Phase 2-E Step 2-E-1 — Grounded Response Composer output contract (presentation layer).
 *
 * SSOT: docs/BATS_AI_GROUNDED_RESPONSE_COMPOSER.md §9.5（v1.0 Freeze）.
 *
 * Carries the final reply text plus grounded metadata. Phase 2-E-1 establishes the
 * frozen output contract; `validation_passed = true` in this phase means legacy
 * pass-through contract-compatible only — Grounded Output Validator is not yet
 * implemented (Phase 2-E-2+).
 */
final class GroundedOutput
{
    /** @deprecated Use ReplyType::* — retained for backward compatibility. */
    public const REPLY_TYPE_NORMAL = ReplyType::NORMAL;
    /** @deprecated Use ReplyType::* */
    public const REPLY_TYPE_NO_RESULTS = ReplyType::NO_RESULTS;
    /** @deprecated Use ReplyType::* */
    public const REPLY_TYPE_CLARIFICATION = ReplyType::CLARIFICATION;
    /** @deprecated Use ReplyType::* */
    public const REPLY_TYPE_HUMAN_FALLBACK = ReplyType::HUMAN_FALLBACK;

    /** @deprecated Use LayoutProfile::* */
    public const LAYOUT_PRODUCT_RICH = LayoutProfile::PRODUCT_RICH;
    /** @deprecated Use LayoutProfile::* */
    public const LAYOUT_KNOWLEDGE_STANDARD = LayoutProfile::KNOWLEDGE_STANDARD;
    /** @deprecated Use LayoutProfile::* */
    public const LAYOUT_MINIMAL = LayoutProfile::MINIMAL;

    public const SCHEMA_VERSION = 1;

    private string $text;

    private bool $grounded;

    private int $usedFactsCount;

    private string $sourceType;

    private bool $humanServiceRequired;

    /** @var list<string> */
    private array $safetyNotes;

    private string $replyType;

    private string $layoutProfile;

    private bool $replySuppressed;

    private bool $validationPassed;

    private string $voiceProfileUsed;

    /** @var list<string> */
    private array $validationNotes;

    /** @var list<string> */
    private array $referencedFactIds;

    private ?string $nextBestActionPresented;

    /**
     * @param list<string> $safetyNotes
     * @param list<string> $validationNotes
     * @param list<string> $referencedFactIds
     */
    public function __construct(
        string $text,
        bool $grounded,
        int $usedFactsCount,
        string $sourceType,
        bool $humanServiceRequired,
        array $safetyNotes = [],
        string $replyType = ReplyType::NORMAL,
        string $layoutProfile = LayoutProfile::KNOWLEDGE_STANDARD,
        bool $replySuppressed = false,
        bool $validationPassed = true,
        string $voiceProfileUsed = '',
        array $validationNotes = [],
        array $referencedFactIds = [],
        ?string $nextBestActionPresented = null
    ) {
        ReplyType::assertValid($replyType);
        LayoutProfile::assertValid($layoutProfile);

        $this->text = $text;
        $this->grounded = $grounded;
        $this->usedFactsCount = max(0, $usedFactsCount);
        $this->sourceType = $sourceType;
        $this->humanServiceRequired = $humanServiceRequired;
        $this->safetyNotes = $safetyNotes;
        $this->replyType = $replyType;
        $this->layoutProfile = $layoutProfile;
        $this->replySuppressed = $replySuppressed;
        $this->validationPassed = $validationPassed;
        $this->voiceProfileUsed = $voiceProfileUsed;
        $this->validationNotes = $validationNotes;
        $this->referencedFactIds = $referencedFactIds;
        $this->nextBestActionPresented = $nextBestActionPresented;
    }

    public function getText(): string
    {
        return $this->text;
    }

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

    public function isReplySuppressed(): bool
    {
        return $this->replySuppressed;
    }

    public function isValidationPassed(): bool
    {
        return $this->validationPassed;
    }

    public function getVoiceProfileUsed(): string
    {
        return $this->voiceProfileUsed;
    }

    /**
     * @return list<string>
     */
    public function getValidationNotes(): array
    {
        return $this->validationNotes;
    }

    /**
     * @return list<string>
     */
    public function getReferencedFactIds(): array
    {
        return $this->referencedFactIds;
    }

    public function getNextBestActionPresented(): ?string
    {
        return $this->nextBestActionPresented;
    }

    public function getSchemaVersion(): int
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Formal frozen contract serialization (§9.5). Excludes transitional fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'schema_version' => self::SCHEMA_VERSION,
            'reply_text' => $this->text,
            'text' => $this->text,
            'reply_type' => $this->replyType,
            'grounded' => $this->grounded,
            'used_facts_count' => $this->usedFactsCount,
            'layout_profile' => $this->layoutProfile,
            'voice_profile_used' => $this->voiceProfileUsed,
            'reply_suppressed' => $this->replySuppressed,
            'validation_passed' => $this->validationPassed,
            'source_type' => $this->sourceType,
            'human_service_required' => $this->humanServiceRequired,
            'safety_notes' => $this->safetyNotes,
        ];

        if ($this->validationNotes !== []) {
            $out['validation_notes'] = $this->validationNotes;
        }
        if ($this->referencedFactIds !== []) {
            $out['referenced_fact_ids'] = $this->referencedFactIds;
        }
        if ($this->nextBestActionPresented !== null && $this->nextBestActionPresented !== '') {
            $out['next_best_action_presented'] = $this->nextBestActionPresented;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $text = trim((string) ($data['reply_text'] ?? $data['text'] ?? ''));
        $replyType = (string) ($data['reply_type'] ?? ReplyType::NORMAL);
        $layoutProfile = (string) ($data['layout_profile'] ?? LayoutProfile::KNOWLEDGE_STANDARD);

        return new self(
            $text,
            (bool) ($data['grounded'] ?? false),
            max(0, (int) ($data['used_facts_count'] ?? 0)),
            (string) ($data['source_type'] ?? ''),
            (bool) ($data['human_service_required'] ?? false),
            is_array($data['safety_notes'] ?? null) ? array_values(array_map('strval', $data['safety_notes'])) : [],
            $replyType,
            $layoutProfile,
            (bool) ($data['reply_suppressed'] ?? false),
            (bool) ($data['validation_passed'] ?? true),
            (string) ($data['voice_profile_used'] ?? ''),
            is_array($data['validation_notes'] ?? null) ? array_values(array_map('strval', $data['validation_notes'])) : [],
            is_array($data['referenced_fact_ids'] ?? null) ? array_values(array_map('strval', $data['referenced_fact_ids'])) : [],
            isset($data['next_best_action_presented']) ? (string) $data['next_best_action_presented'] : null
        );
    }
}
