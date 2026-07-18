<?php
declare(strict_types=1);

/**
 * AIU Entity Validation — product_type Closed Set v1 (values domain only).
 *
 * SSOT contract: docs/BATS_AI_NORMALIZE.md §6.5 (value list authority).
 * Runtime owner / order: docs/BATS_AI_RUNTIME.md §2.4 (Validation → Normalize).
 *
 * Soft-null only for entities.product_type; does not rewrite other entities,
 * does not move values to keyword, and does not trigger Clarification / Legacy.
 */
final class AiuOutputContractValidator
{
    /**
     * Production Closed Set v1 — single runtime authority for membership checks.
     * Contract list lives in docs/BATS_AI_NORMALIZE.md §6.5; do not duplicate elsewhere.
     *
     * @var list<string>
     */
    private const PRODUCT_TYPE_CLOSED_SET_V1 = [
        '自由行',
        '半自助',
        '跟團',
        '團體',
        '迷你團',
        '包車',
        '郵輪',
    ];

    /**
     * Validate a Gemini Semantic Result array; return a validated copy.
     *
     * Structural shape failures remain the responsibility of the Gemini client
     * output-contract gate. This method never rejects a whole result solely for
     * an illegal product_type (soft-null).
     *
     * @param array<string, mixed> $semantic
     * @return array<string, mixed>
     */
    public function validate(array $semantic): array
    {
        $out = $semantic;

        if (!array_key_exists('entities', $out) || !is_array($out['entities'])) {
            return $out;
        }

        // Preserve list-shaped entities for the existing structural failure route
        // (Gemini client assert); do not invent a second structural authority here.
        $entities = $out['entities'];
        if ($entities !== [] && array_keys($entities) === range(0, count($entities) - 1)) {
            return $out;
        }

        if (!array_key_exists('product_type', $entities)) {
            return $out;
        }

        $entities['product_type'] = $this->validateProductType($entities['product_type']);
        $out['entities'] = $entities;

        return $out;
    }

    /**
     * @param mixed $value
     */
    private function validateProductType($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (!in_array($trimmed, self::PRODUCT_TYPE_CLOSED_SET_V1, true)) {
            return null;
        }

        return $trimmed;
    }
}
