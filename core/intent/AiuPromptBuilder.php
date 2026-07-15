<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuPromptRequest.php';

/**
 * AIU Prompt Scheme Builder（B-01～B-11）.
 *
 * SSOT: AIU v2 Gemini Output Contract v1.0 Rev.1 (Frozen) + Gap Matrix B0-6.
 */
final class AiuPromptBuilder
{
    public function build(AiuPromptRequest $request): string
    {
        $blocks = [
            $this->blockMandate(),
            $this->blockIntentTaxonomy(),
            $this->blockEntitySchema(),
            $this->blockReferenceDatetime($request),
            $this->blockDateResolutionRules(),
            $this->blockClarificationDetection(),
            $this->blockContextSnapshot($request),
            $this->blockOwnerSnapshot($request),
            $this->blockConversationStage($request),
            $this->blockResumeContext($request),
            $this->blockCustomerUtterance($request),
            $this->blockOutputSchema(),
            $this->blockGuardrails(),
        ];

        return implode("\n\n", $blocks);
    }

    private function blockMandate(): string
    {
        return <<<'TXT'
[B-01 Understanding Mandate]
You are the Semantic Understanding Core for BBC AI travel customer service.
Understand the customer's true intent from natural language. Output JSON only.
Do not generate customer-facing reply text. Do not decide routing or execution.
TXT;
    }

    private function blockIntentTaxonomy(): string
    {
        return <<<'TXT'
[B-02 Intent Taxonomy]
Classify intent as exactly one of:
- "product_search" — customer wants tour/product search, quotes, dates, destinations, itineraries
- "knowledge" — FAQ, policies, contact info, services, passport/visa, company info
- "human_service" — explicit request for human agent / 真人客服 / 轉人工 / 找客服
- "ambiguous" — intent too vague to route (e.g. "想出去玩" without destination or topic)

Legacy labels "Product Search" / "Knowledge" / "Ambiguous" are also accepted by Normalize.
TXT;
    }

    private function blockEntitySchema(): string
    {
        return <<<'TXT'
[B-03 Entity Schema Reference]
Extract into entities object (fixed keys). Use null for missing scalars; [] for missing arrays.
Keys:
destination (array of strings — country/city/subnational/landmark; do NOT pick a primary),
travel_area (string|null — continent/large region e.g. 歐洲),
date_range (object|null — {"from":"YYYY-MM-DD","to":"YYYY-MM-DD"} when resolvable; null only when genuinely unresolvable),
date_expression (string|null — original date phrasing; always preserve when the customer mentioned a date),
duration_days (number|null),
date_flexibility (string|null),
product_type (string|null),
theme (array),
occasion (string|null),
travel_style (string|null),
departure (string|null),
route (array),
people_count / adult_count / child_count / senior_count (number|null — never invent counts),
group_type (string|null),
budget_amount (number|null), budget_unit (string|null), currency (string|null),
price_sensitivity (string|null),
hotel_preference / transportation_preference / airline_preference /
meal_preference / room_preference (arrays),
constraint / exclusion / special_need (arrays),
preserved_keywords (array — only terms that cannot fit a formal entity),
unclassified_terms (array).

Do not put destination/theme/meal_preference values into preserved_keywords.
"希望直飛" → transportation_preference; "不要轉機" → exclusion.
TXT;
    }

    private function blockReferenceDatetime(AiuPromptRequest $request): string
    {
        $date = $request->getReferenceCalendarDate();
        $tz = $request->getReferenceTimezone();

        return <<<TXT
[B-03b Reference Datetime — read-only]
reference_calendar_date: {$date}
reference_timezone: {$tz}
Use this reference only for resolving relative or incomplete date expressions into date_range.
Do not rewrite the customer utterance.
TXT;
    }

    private function blockDateResolutionRules(): string
    {
        return <<<'TXT'
[B-03c Date Resolution Rules — Gemini authority]
Resolve date expressions into date_range using reference_calendar_date and reference_timezone.
Both date_range endpoints are inclusive. Format every endpoint as YYYY-MM-DD.
Always preserve the original phrasing in date_expression when a date was mentioned.

Month-only rules (no explicit year):
- Future month (month number > reference month in the same year):
  date_range.from = first day of that month in the reference year;
  date_range.to = last day of that month.
  Example: reference_calendar_date 2026-07-14 + "8月" → from 2026-08-01 to 2026-08-31.
- Current month (month number == reference month):
  date_range.from = reference_calendar_date;
  date_range.to = last day of that month in the reference year.
  Example: reference_calendar_date 2026-07-14 + "7月" → from 2026-07-14 to 2026-07-31.
- Earlier month (month number < reference month):
  date_range.from = first day of that month in (reference year + 1);
  date_range.to = last day of that month in (reference year + 1).
  Example: reference_calendar_date 2026-12-20 + "1月" → from 2027-01-01 to 2027-01-31.

Explicit year/month (e.g. "2027年3月"):
- Use the explicit year and month; full calendar month first day through last day.
  Example: "2027年3月" → from 2027-03-01 to 2027-03-31.

「近期」 (exact token 近期 only; do not invent other synonyms):
- date_range.from = reference_calendar_date;
- date_range.to = reference_calendar_date + 60 calendar days.
  Example: reference_calendar_date 2026-07-14 + "近期" → from 2026-07-14 to 2026-09-12.

Unresolvable dates (e.g. purely vague with no usable calendar meaning):
- Preserve date_expression when present;
- Set date_range to null;
- Do not invent dates.
TXT;
    }

    private function blockClarificationDetection(): string
    {
        return <<<'TXT'
[B-04 Clarification Detection Policy]
Set clarification.required = true when:
- intent is ambiguous, OR
- product_search but critical slots missing (e.g. destination without usable dates), OR
- confidence < 0.55
Set clarification.reason to a short machine reason (e.g. missing_travel_dates, intent_ambiguous).
Detection only — do NOT write clarification questions for the customer.
TXT;
    }

    private function blockContextSnapshot(AiuPromptRequest $request): string
    {
        $json = json_encode($request->getContextSnapshot(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '[B-05 Context Snapshot — read-only]' . "\n" . ($json !== false ? $json : '{}');
    }

    private function blockOwnerSnapshot(AiuPromptRequest $request): string
    {
        return '[B-06 Owner Snapshot — read-only]' . "\n" . $request->getOwnerSnapshot();
    }

    private function blockConversationStage(AiuPromptRequest $request): string
    {
        return '[B-07 Conversation Stage — read-only]' . "\n" . $request->getConversationStage();
    }

    private function blockResumeContext(AiuPromptRequest $request): string
    {
        $resume = $request->getResumeContext();
        $json = json_encode($resume, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '[B-08 Resume Context — read-only]' . "\n" . ($json !== false ? $json : 'null');
    }

    private function blockCustomerUtterance(AiuPromptRequest $request): string
    {
        return '[B-09 Customer Utterance — do not rewrite]' . "\n" . $request->getCustomerUtterance();
    }

    private function blockOutputSchema(): string
    {
        return <<<'TXT'
[B-10 Output Schema — AIU v2 Gemini Output Contract]
Respond with JSON only (no markdown):
{
  "intent": "product_search | knowledge | human_service | ambiguous",
  "entities": {},
  "confidence": 0.0,
  "clarification": { "required": false, "reason": "" }
}
entities must include all fixed keys. Do NOT output semantic_notes.
Do NOT output dispatch_plan, execution_hint, runtime_action, or search_action.
TXT;
    }

    private function blockGuardrails(): string
    {
        return <<<'TXT'
[B-11 Guardrails]
- Do NOT output dispatch_plan, execution_hint, runtime_action, search_action, or semantic_notes
- Do NOT generate reply text or clarification questions for the customer
- Do NOT change owner or conversation state
- Use natural language understanding, not keyword tables
- Keep destination as an array; never invent child_count without explicit number
TXT;
    }
}
