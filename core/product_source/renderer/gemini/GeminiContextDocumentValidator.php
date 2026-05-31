<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GeminiContextDocument.php';

/**
 * Validates Gemini context documents (Phase 9-B-23).
 */
final class GeminiContextDocumentValidator
{
    /** @var list<string> */
    private const FORBIDDEN_TOP_LEVEL_KEYS = [
        'prompt',
        'system_prompt',
        'user_prompt',
        'model',
        'api_key',
        'apiKey',
        'endpoint',
        'headers',
        'replyToken',
        'reply_token',
        'accessToken',
        'access_token',
        'http',
        'curl',
    ];

    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    public function collectViolations(array $document): array
    {
        $violations = [];
        $normalized = GeminiContextDocument::normalizeDocument($document);

        foreach (self::FORBIDDEN_TOP_LEVEL_KEYS as $key) {
            if (array_key_exists($key, $document)) {
                $violations[] = 'forbidden top-level key: ' . $key;
            }
        }

        if ($normalized['customer_query'] === '') {
            $violations[] = 'customer_query is required';
        }

        if ($normalized['tenant_name'] === '') {
            $violations[] = 'tenant_name is required';
        }

        if (!isset($document['search_results']) || !is_array($document['search_results'])) {
            $violations[] = 'search_results must be an array';
        }

        if ($normalized['voice_profile'] === []) {
            $violations[] = 'voice_profile is required';
        } else {
            $violations = array_merge($violations, $this->collectVoiceProfileViolations($normalized['voice_profile']));
        }

        if ($normalized['tenant_service_scope'] === []) {
            $violations[] = 'tenant_service_scope is required';
        }

        if ($normalized['guard_policy'] === []) {
            $violations[] = 'guard_policy is required';
        } else {
            $violations = array_merge($violations, $this->collectGuardPolicyViolations($normalized['guard_policy']));
        }

        if ($normalized['fallback_policy'] === []) {
            $violations[] = 'fallback_policy is required';
        } else {
            $violations = array_merge($violations, $this->collectFallbackPolicyViolations($normalized['fallback_policy']));
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $document
     * @throws \InvalidArgumentException
     */
    public function validate(array $document): GeminiContextDocument
    {
        $violations = $this->collectViolations($document);
        if ($violations !== []) {
            throw new \InvalidArgumentException(
                'Gemini context document invalid (' . count($violations) . ' issue(s)): '
                . implode('; ', $violations)
            );
        }

        return GeminiContextDocument::fromArray($document);
    }

    /**
     * @param array<string, mixed> $voiceProfile
     * @return list<string>
     */
    private function collectVoiceProfileViolations(array $voiceProfile): array
    {
        $violations = [];

        $persona = isset($voiceProfile['persona']) ? trim((string) $voiceProfile['persona']) : '';
        if ($persona === '') {
            $violations[] = 'voice_profile.persona is required';
        }

        if (!isset($voiceProfile['tone']) || !is_array($voiceProfile['tone']) || $voiceProfile['tone'] === []) {
            $violations[] = 'voice_profile.tone is required';
        }

        if (!isset($voiceProfile['constraints']) || !is_array($voiceProfile['constraints']) || $voiceProfile['constraints'] === []) {
            $violations[] = 'voice_profile.constraints is required';
        }

        if (!isset($voiceProfile['emoji_policy']) || !is_array($voiceProfile['emoji_policy'])) {
            $violations[] = 'voice_profile.emoji_policy is required';
        } elseif (!isset($voiceProfile['emoji_policy']['allowed_emojis'])
            || !is_array($voiceProfile['emoji_policy']['allowed_emojis'])
            || $voiceProfile['emoji_policy']['allowed_emojis'] === []
        ) {
            $violations[] = 'voice_profile.emoji_policy.allowed_emojis is required';
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $guardPolicy
     * @return list<string>
     */
    private function collectGuardPolicyViolations(array $guardPolicy): array
    {
        $violations = [];

        foreach (['grounding_required', 'allow_hallucination', 'strict_data_mode'] as $key) {
            if (!array_key_exists($key, $guardPolicy)) {
                $violations[] = 'guard_policy.' . $key . ' is required';
            }
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $fallbackPolicy
     * @return list<string>
     */
    private function collectFallbackPolicyViolations(array $fallbackPolicy): array
    {
        $violations = [];

        $mode = isset($fallbackPolicy['mode']) ? trim((string) $fallbackPolicy['mode']) : '';
        if ($mode === '') {
            $violations[] = 'fallback_policy.mode is required';
        }

        $message = isset($fallbackPolicy['fallback_message']) ? trim((string) $fallbackPolicy['fallback_message']) : '';
        if ($message === '') {
            $violations[] = 'fallback_policy.fallback_message is required';
        }

        return $violations;
    }
}
