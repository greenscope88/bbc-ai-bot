<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-2e — persona consume projection (internal VO).
 *
 * SSOT: docs/BATS_AI_PERSONA.md (reference only; rules not redefined here).
 */
final class PersonaRenderHints
{
    public const OPENING_STANDARD = 'standard';
    public const OPENING_RESUME_ACK = 'resume_ack';
    public const OPENING_MINIMAL = 'minimal';

    public const COMMERCE_SOFT_GUIDE = 'soft_guide';
    public const COMMERCE_NONE = 'none';

    public const SSOT_REF = 'BATS_AI_PERSONA.md';

    private string $voiceProfileUsed;

    private bool $allowEmoji;

    private string $openingStyle;

    private string $commerceClosingStyle;

    public function __construct(
        string $voiceProfileUsed,
        bool $allowEmoji,
        string $openingStyle = self::OPENING_STANDARD,
        string $commerceClosingStyle = self::COMMERCE_SOFT_GUIDE
    ) {
        $this->voiceProfileUsed = $voiceProfileUsed;
        $this->allowEmoji = $allowEmoji;
        $this->openingStyle = $openingStyle;
        $this->commerceClosingStyle = $commerceClosingStyle;
    }

    public function getVoiceProfileUsed(): string
    {
        return $this->voiceProfileUsed;
    }

    public function isAllowEmoji(): bool
    {
        return $this->allowEmoji;
    }

    public function getOpeningStyle(): string
    {
        return $this->openingStyle;
    }

    public function getCommerceClosingStyle(): string
    {
        return $this->commerceClosingStyle;
    }

    public function getPersonaSsotRef(): string
    {
        return self::SSOT_REF;
    }
}
