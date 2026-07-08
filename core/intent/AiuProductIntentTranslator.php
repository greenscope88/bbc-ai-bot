<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingResult.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ClarificationPolicy.php';

/**
 * Phase 2-D Step 2-D-5 — AIU v2 Last Mile：Product Contract Translation.
 *
 * SSOT: docs/BATS_AI_INTENT_UNDERSTANDING_V2.md §5 / §15 / §16.
 *
 * 唯一職責：將 AIU v2 已理解之 `AiIntentUnderstandingResult`（Gemini Semantic Result
 * 經 Normalize 後之權威輸出）**Contract Translation** 為 Execution Layer 之
 * `BatsSearchIntent`。
 *
 * 嚴格邊界（AIU v2 Last Mile Scope）：
 *   - **僅 Contract Translation**：逐欄複製已理解之 `entity` / `clarification`；
 *     **不**重新理解 Customer Utterance、**不**做 Keyword / Rule / Lexicon 判斷、
 *     **不**呼叫 `BatsSearchIntentBuilder` / `ClarificationPolicy` / `TravelIntentLexicon`。
 *   - **Clarification 權威來源**：一律取自 `AiIntentUnderstandingResult` 之
 *     top-level `clarification`（§15.6 Clarification Detection），非 entity 內部欄位。
 *   - Reason 僅做 **enum vocab 對應**（semantic reason → execution reason enum），
 *     此為 Contract Translation，非語意判斷。
 */
final class AiuProductIntentTranslator
{
    /**
     * Semantic clarification reason（Gemini/§16 詞彙）→ Execution reason enum。
     *
     * 僅為兩套封閉詞彙之對應表；不含任何 Customer Utterance 關鍵字比對。
     *
     * @var array<string, string>
     */
    private const REASON_VOCAB_MAP = [
        'missing_travel_dates' => ClarificationPolicy::REASON_DATE_REQUIRED,
        'date_required' => ClarificationPolicy::REASON_DATE_REQUIRED,
        'missing_destination' => ClarificationPolicy::REASON_DESTINATION_UNKNOWN,
        'destination_unknown' => ClarificationPolicy::REASON_DESTINATION_UNKNOWN,
    ];

    /**
     * 將 AIU v2 Product Semantic Result 轉為 Execution 用 BatsSearchIntent。
     *
     * @return BatsSearchIntent
     */
    public function translate(AiIntentUnderstandingResult $result): BatsSearchIntent
    {
        $entity = $result->getEntity();

        $freeText = trim((string) ($entity['free_text'] ?? ''));
        $clarificationRequired = $result->isClarificationRequired();
        $clarificationReason = $this->translateReason(
            $result->getClarificationReason(),
            $this->nullableString($entity['destination'] ?? null),
            $clarificationRequired
        );

        return new BatsSearchIntent(
            $freeText,
            BatsSearchIntent::INTENT_TOUR_SEARCH,
            $this->nullableString($entity['destination'] ?? null),
            $this->stringList($entity['destination_alias'] ?? []),
            $this->stringList($entity['multi_destination'] ?? []),
            $this->nullableString($entity['departure_city'] ?? null),
            $this->nullableString($entity['date_from'] ?? null),
            $this->nullableString($entity['date_to'] ?? null),
            $this->stringList($entity['travel_type'] ?? []),
            $this->nullableInt($entity['budget_min'] ?? null),
            $this->nullableInt($entity['budget_max'] ?? null),
            $this->nullableInt($entity['people_count'] ?? null),
            $this->nullableString($entity['people_label'] ?? null),
            $this->nullableString($entity['duration'] ?? null),
            $this->nullableString($entity['product_type'] ?? null),
            $this->nullableString($entity['landmark'] ?? null),
            $this->stringList($entity['must_have'] ?? []),
            $this->stringList($entity['avoid'] ?? []),
            $clarificationRequired,
            $clarificationReason,
            isset($entity['confidence']) ? (float) $entity['confidence'] : 0.0
        );
    }

    /**
     * Contract Translation of clarification reason vocab（非語意判斷）。
     */
    private function translateReason(string $semanticReason, ?string $destination, bool $clarificationRequired): ?string
    {
        if (!$clarificationRequired) {
            return null;
        }

        $reason = trim($semanticReason);
        if ($reason !== '' && isset(self::REASON_VOCAB_MAP[$reason])) {
            return self::REASON_VOCAB_MAP[$reason];
        }

        // 未在對應表且無明確目的地 → 以目的地未知處理（Execution enum 對齊）。
        if ($destination === null) {
            return ClarificationPolicy::REASON_DESTINATION_UNKNOWN;
        }

        // 有目的地但語意標記需追問（多為日期缺失）→ 日期澄清 enum。
        return ClarificationPolicy::REASON_DATE_REQUIRED;
    }

    /**
     * @param mixed $value
     */
    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }

    /**
     * @param mixed $value
     */
    private function nullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function stringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $s = trim((string) $item);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }
}
