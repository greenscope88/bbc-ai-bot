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
            $this->blockStructuredSearchResumeState($request),
            $this->blockCustomerUtterance($request),
            $this->blockOutputSchema($request),
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
[B-03b Reference Datetime — read-only — CURRENT REQUEST ANCHOR]
reference_calendar_date: {$date}
reference_timezone: {$tz}
Use THIS current-request reference only as the date-resolution anchor for relative or incomplete date expressions.
Do not use prior provenance datetime (if present in structured search resume) as the date-resolution anchor.
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

General natural-language dates (Gemini native reasoning):
- Travel dates are NOT limited to explicit Gregorian year/month/day in the customer utterance.
- Use your native language, calendar, and date reasoning together with reference_calendar_date, reference_timezone, Conversation Context, and the full Customer Utterance.
- Understand relative dates, holidays, vacation periods, seasons, year boundaries, lunar-calendar festival names, and other context-resolvable expressions when you can form a reliable inclusive date_range.
- When you can reliably resolve a range, you MUST output date_range.from and date_range.to (YYYY-MM-DD) AND keep date_expression as the customer's original phrasing.
- Do NOT set clarification.required=true with missing_travel_dates solely because the customer did not state explicit Gregorian dates, if you can still derive a reliable range from context.
- Illustrative examples only (non-exhaustive; NOT an allowlist; NOT a closed-set; NOT a mapping table; generalize beyond these labels):
  農曆過年, 農曆春節, 過年, 跨年 — resolve using lunar/Gregorian reasoning and reference time; never output memorized fixed date ranges tied only to these example words.
- Only when, after reference time, timezone, utterance, and context, you still cannot form a reliable date_range: preserve date_expression, set date_range to null, and follow [B-04] for missing_travel_dates.

Event-Centered Travel Departure Window Policy (calendar-event travel timing without a precise departure day):
- Precedence (strict): Customer explicit date instruction > Conversation Context > this default event-centered departure window.
- You determine event meaning, regional/locale context, event year, and the event_anchor_date (the local calendar date of the main celebration the customer intends to participate in) using reference_calendar_date, reference_timezone, the Customer Utterance, and Conversation Context.
- For transition events that span a year/day boundary, event_anchor_date is the local calendar day when the primary celebration begins — not the next calendar day after the boundary.
- Default departure search window applies ONLY when the customer did not specify a more precise departure day and context does not override:
  date_range.from = event_anchor_date minus exactly three calendar days;
  date_range.to = event_anchor_date;
  both endpoints inclusive → exactly four calendar dates.
- Do NOT treat the event_anchor_date as one of the three days before the event; that mistake yields only three dates and is forbidden.
- Before output, perform an arithmetic self-check: date_range.from + exactly 3 calendar days must equal date_range.to. If the check fails, recalculate until it holds.
- Output complete date_range.from and date_range.to (YYYY-MM-DD) and preserve date_expression as the customer's original phrasing.
- Do NOT apply this default when the customer names a specific departure day, the event day only, a day relative to the event, post-event wording, or a broader semantic range around the event — honor that instruction instead.
- Only when event date or range remains unreliable after reference time, timezone, utterance, and context: set date_range to null and follow [B-04] for missing_travel_dates.

Illustrative behaviors only (non-exhaustive; NOT an allowlist; NOT a closed-set; NOT a mapping table; generalize beyond these utterances):
  Event-themed trip without precise departure → event_anchor_date minus exactly three calendar days through event_anchor_date (exactly four calendar dates).
  Explicit departure on a stated calendar day for an event-themed trip → that explicit day only, not the default window.
  Departing on the event day → event date per customer instruction, not the three-days-before default.
  Post-event travel wording → post-event semantics; do not force the three-days-before default.
  A single calendar day relative to an event (e.g. the day before) → that relative day only.
  Flexible wording around an event (e.g. before and after acceptable) → inclusive range matching full utterance semantics across the event.
  Cannot reliably determine event date or year → clarification per [B-04].
- Compute event dates by reasoning; do not memorize fixed event-to-date rows or static reference-date illustrations in output.

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
- product_search and a required slot is missing under the rules below, OR
- confidence < 0.55

When clarification.required = false: set clarification.reason to empty string ("").

When intent is ambiguous: clarification.reason = intent_ambiguous (Ambiguous only; outside Product Search closed enum).

When intent is product_search and clarification.required = true, clarification.reason MUST be exactly one of:
- missing_destination
- missing_travel_dates

Product Search Destination First + entity conditions (structured entities only):
- Destination missing (entities.destination empty), regardless of dates:
  clarification.reason = missing_destination
- Destination present AND usable travel dates missing or incomplete (date_range / date_from+date_to not both usable):
  clarification.reason = missing_travel_dates
- Do NOT use missing_travel_dates only because the customer omitted explicit Gregorian dates when [B-03c] still allows you to resolve a reliable date_range from natural-language date_expression.

Forbidden Product Search clarification.reason values (do NOT use):
- critical_slots_missing
- date_required
- destination_unknown
- null/empty when clarification.required = true
- any freeform or invented machine reason

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

    private function blockStructuredSearchResumeState(AiuPromptRequest $request): string
    {
        $pending = $request->getStructuredSearchResumeState();
        if ($pending === null) {
            return <<<'TXT'
[B-08b Structured Search Resume State — read-only]
null
No prior pending Product Search clarification state is injected.
resume_disposition may be omitted or "new_request".
Do NOT invent prior known_entities.
TXT;
        }

        $json = json_encode($pending, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body = $json !== false ? $json : '{}';

        return <<<TXT
[B-08b Structured Search Resume State — read-only pending facts]
{$body}

Prior provenance_reference_* fields are audit-only. They are NOT the date-resolution anchor.
Current-request reference_calendar_date / reference_timezone remain the sole date-resolution anchor.
Prior absolute date fields inside known_entities are known facts you may retain or revise.

You are the sole merge authority. Output a FULL authoritative AIU result (not a delta).
Runtime will NOT merge old and new entities.

When this pending block is present, you MUST set resume_disposition to exactly one of:
- "continue_pending" — customer continues filling the prior missing condition using prior facts
- "modify_pending" — customer revises prior facts while staying on Product Search clarification/search
- "new_request" — customer starts an unrelated request; do NOT inherit unrelated prior facts
TXT;
    }

    private function blockCustomerUtterance(AiuPromptRequest $request): string
    {
        return '[B-09 Customer Utterance — do not rewrite]' . "\n" . $request->getCustomerUtterance();
    }

    private function blockOutputSchema(AiuPromptRequest $request): string
    {
        if ($request->hasStructuredSearchResumeState()) {
            return <<<'TXT'
[B-10 Output Schema — AIU v2 Gemini Output Contract]
Respond with JSON only (no markdown):
{
  "intent": "product_search | knowledge | human_service | ambiguous",
  "entities": {},
  "confidence": 0.0,
  "clarification": { "required": false, "reason": "" },
  "resume_disposition": "continue_pending | modify_pending | new_request"
}
resume_disposition is REQUIRED because Structured Search Resume State was injected.
entities must include all fixed keys. Do NOT output semantic_notes.
Do NOT output dispatch_plan, execution_hint, runtime_action, or search_action.
TXT;
        }

        return <<<'TXT'
[B-10 Output Schema — AIU v2 Gemini Output Contract]
Respond with JSON only (no markdown):
{
  "intent": "product_search | knowledge | human_service | ambiguous",
  "entities": {},
  "confidence": 0.0,
  "clarification": { "required": false, "reason": "" },
  "resume_disposition": "new_request"
}
resume_disposition may be omitted or "new_request" when no pending Structured Search Resume State was injected.
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
