<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GeminiResponseContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GeminiContextDocument.php';

/**
 * Validates Gemini response contracts and context business rules (Phase 9-B-24).
 */
final class GeminiResponseContractValidator
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

    /** @var list<string> */
    private const HALLUCINATION_MARKERS = [
        '保證成團',
        '限時優惠',
        '保證有位',
        '機位充足',
    ];

    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    public function collectViolations(array $document): array
    {
        $violations = [];
        $normalized = GeminiResponseContract::normalizeDocument($document);

        foreach (self::FORBIDDEN_TOP_LEVEL_KEYS as $key) {
            if (array_key_exists($key, $document)) {
                $violations[] = 'forbidden top-level key: ' . $key;
            }
        }

        if ($normalized['reply_text'] === '') {
            $violations[] = 'reply_text is required';
        }

        if ($normalized['reply_type'] === '') {
            $violations[] = 'reply_type is required';
        } elseif (!in_array($normalized['reply_type'], GeminiResponseContract::REPLY_TYPES, true)) {
            $violations[] = 'invalid reply_type';
        }

        if ($normalized['voice_profile_used'] === '') {
            $violations[] = 'voice_profile_used is required';
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $responseDocument
     * @param array<string, mixed> $contextDocument
     * @return list<string>
     */
    public function collectContextViolations(array $responseDocument, array $contextDocument): array
    {
        $violations = $this->collectViolations($responseDocument);
        if ($violations !== []) {
            return $violations;
        }

        $response = GeminiResponseContract::normalizeDocument($responseDocument);
        $context = GeminiContextDocument::normalizeDocument($contextDocument);
        $replyType = $response['reply_type'];

        if ($replyType === 'normal_reply') {
            if ($response['used_fallback']) {
                $violations[] = 'normal_reply must not set used_fallback=true';
            }
            $recSummary = isset($context['recommendation_summary']) && is_array($context['recommendation_summary'])
                ? $context['recommendation_summary']
                : null;
            $hasSearchResults = $context['search_results'] !== [];
            $hasRecommendations = $recSummary !== null && (int) ($recSummary['result_count'] ?? 0) > 0;
            $hasProductNoResults = $recSummary !== null
                && (int) ($recSummary['result_count'] ?? 0) === 0
                && $this->isProductSearchContext($context);
            if (!$hasSearchResults && !$hasRecommendations && !$hasProductNoResults) {
                $violations[] = 'normal_reply requires search_results or recommendation_summary in context';
            }
            $violations = array_merge($violations, $this->collectEmojiViolations(
                $response['reply_text'],
                $context['voice_profile']
            ));
            $violations = array_merge($violations, $this->collectHallucinationViolations(
                $response['reply_text'],
                $context
            ));
        }

        if ($replyType === 'human_agent_fallback') {
            if (!$response['used_fallback']) {
                $violations[] = 'human_agent_fallback requires used_fallback=true';
            }
            $fallbackMessage = isset($context['fallback_policy']['fallback_message'])
                ? trim((string) $context['fallback_policy']['fallback_message'])
                : '';
            if ($fallbackMessage !== '' && $response['reply_text'] !== $fallbackMessage) {
                $violations[] = 'human_agent_fallback reply_text must match fallback_policy.fallback_message';
            }
            if ($response['used_service_scope'] === null) {
                $violations[] = 'human_agent_fallback requires used_service_scope';
            }
        }

        if ($replyType === 'out_of_scope') {
            if ($response['used_fallback']) {
                $violations[] = 'out_of_scope must not set used_fallback=true';
            }
            if ($response['used_service_scope'] !== null) {
                $violations[] = 'out_of_scope must not set used_service_scope';
            }
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $document
     * @throws \InvalidArgumentException
     */
    public function validate(array $document): GeminiResponseContract
    {
        $violations = $this->collectViolations($document);
        if ($violations !== []) {
            throw new \InvalidArgumentException(
                'Gemini response contract invalid (' . count($violations) . ' issue(s)): '
                . implode('; ', $violations)
            );
        }

        return GeminiResponseContract::fromArray($document);
    }

    /**
     * @param array<string, mixed> $responseDocument
     * @param array<string, mixed> $contextDocument
     * @throws \InvalidArgumentException
     */
    public function validateWithContext(array $responseDocument, array $contextDocument): GeminiResponseContract
    {
        $violations = $this->collectContextViolations($responseDocument, $contextDocument);
        if ($violations !== []) {
            throw new \InvalidArgumentException(
                'Gemini response context rule invalid (' . count($violations) . ' issue(s)): '
                . implode('; ', $violations)
            );
        }

        return GeminiResponseContract::fromArray($responseDocument);
    }

    /**
     * @param array<string, mixed> $voiceProfile
     * @return list<string>
     */
    private function collectEmojiViolations(string $replyText, array $voiceProfile): array
    {
        $violations = [];
        $allowed = isset($voiceProfile['emoji_policy']['allowed_emojis'])
            && is_array($voiceProfile['emoji_policy']['allowed_emojis'])
            ? $voiceProfile['emoji_policy']['allowed_emojis']
            : [];

        if ($allowed === []) {
            return $violations;
        }

        $emojis = $this->extractEmojis($replyText);
        if (count($emojis) > 5) {
            $violations[] = 'reply_text emoji count exceeds policy limit';
        }

        foreach ($emojis as $emoji) {
            if (!in_array($emoji, $allowed, true)) {
                $violations[] = 'reply_text contains disallowed emoji: ' . $emoji;
            }
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $context
     * @return list<string>
     */
    private function collectHallucinationViolations(string $replyText, array $context): array
    {
        $violations = [];
        $guardPolicy = $context['guard_policy'];

        if (($guardPolicy['allow_hallucination'] ?? false) === true) {
            return $violations;
        }

        $groundedCorpus = $this->buildGroundedCorpus($context);

        foreach (self::HALLUCINATION_MARKERS as $marker) {
            if (mb_strpos($replyText, $marker) !== false && mb_strpos($groundedCorpus, $marker) === false) {
                $violations[] = 'reply_text contains ungrounded marker: ' . $marker;
            }
        }

        if (preg_match('/NT\$?\s?\d{1,3}(?:,\d{3})+|\d{4,6}\s?元/u', $replyText, $matches) === 1) {
            $priceToken = $matches[0];
            if (mb_strpos($groundedCorpus, $priceToken) === false
                && !$this->priceExistsInMetadata($priceToken, $context['search_results'])
            ) {
                $violations[] = 'reply_text contains ungrounded price';
            }
        }

        if (preg_match('/\d{4}[\/\-年]\d{1,2}[\/\-月]\d{1,2}日?/u', $replyText, $matches) === 1) {
            $dateToken = $matches[0];
            if (mb_strpos($groundedCorpus, $dateToken) === false) {
                $violations[] = 'reply_text contains ungrounded date';
            }
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildGroundedCorpus(array $context): string
    {
        $parts = [];

        foreach ($context['search_results'] as $item) {
            $parts[] = (string) ($item['title'] ?? '');
            if (isset($item['summary']) && is_string($item['summary'])) {
                $parts[] = $item['summary'];
            }
            $parts[] = (string) ($item['primary_url'] ?? '');
            if (isset($item['metadata']) && is_array($item['metadata'])) {
                $parts[] = json_encode($item['metadata'], JSON_UNESCAPED_UNICODE) ?: '';
            }
        }

        if (isset($context['recommendation_summary']) && is_array($context['recommendation_summary'])) {
            $summary = $context['recommendation_summary'];
            $parts[] = (string) ($summary['recommendation_reason'] ?? '');
            $parts[] = (string) ($summary['primary_url'] ?? '');
            if (isset($summary['top_products']) && is_array($summary['top_products'])) {
                foreach ($summary['top_products'] as $product) {
                    if (!is_array($product)) {
                        continue;
                    }
                    $parts[] = (string) ($product['title'] ?? '');
                    $parts[] = (string) ($product['summary'] ?? '');
                    $parts[] = (string) ($product['primary_url'] ?? '');
                }
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function isProductSearchContext(array $context): bool
    {
        if (!isset($context['bats_search_intent']) || !is_array($context['bats_search_intent'])) {
            return false;
        }

        $intent = $context['bats_search_intent'];
        if (($intent['clarification_required'] ?? false) === true) {
            return false;
        }

        if (isset($intent['destination']) && is_array($intent['destination']) && $intent['destination'] !== []) {
            return true;
        }

        $freeText = isset($intent['free_text']) ? trim((string) $intent['free_text']) : '';

        return preg_match('/\d{1,2}\s*月/u', $freeText) === 1;
    }

    /**
     * @param list<array<string, mixed>> $searchResults
     */
    private function priceExistsInMetadata(string $priceToken, array $searchResults): bool
    {
        $digits = preg_replace('/\D/u', '', $priceToken);
        if (!is_string($digits) || $digits === '') {
            return false;
        }

        foreach ($searchResults as $item) {
            if (!isset($item['metadata']) || !is_array($item['metadata'])) {
                continue;
            }
            foreach ($item['metadata'] as $value) {
                if (is_numeric($value) && (string) (int) $value === $digits) {
                    return true;
                }
                if (is_string($value) && mb_strpos($value, $digits) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function extractEmojis(string $text): array
    {
        if (preg_match_all(
            '/(?:[\x{1F1E6}-\x{1F1FF}]{2})|[\x{1F300}-\x{1FAFF}][\x{FE0E}\x{FE0F}]?|[\x{2600}-\x{27BF}][\x{FE0E}\x{FE0F}]?/u',
            $text,
            $matches
        ) !== 1) {
            return [];
        }

        return $matches[0];
    }
}
