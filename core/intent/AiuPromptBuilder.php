<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuPromptRequest.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuProductSetContext.php';

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
            $this->blockProductSetContext($request),
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

    /**
     * B0-LINE-01D-3J-11O1: single resolution point for the request's authoritative
     * Product-Set Context — shared by rendering ([B-03a]) and safe fingerprinting
     * (productSetContextFingerprint()) so both read the exact same Context instance.
     * Does not read registry/config/SearchConditionContract and never logs.
     */
    private function resolveProductSetContext(AiuPromptRequest $request): AiuProductSetContext
    {
        return $request->getProductSetContext();
    }

    /**
     * B0-LINE-01D-3J-11O1: safe canonical resolved-context fingerprint for the
     * Product-Set Context actually carried by this request — computed by the Context
     * itself (single fingerprint authority), never re-derived here. No prompt/context/
     * registry/config/SCC read; no logging.
     */
    public function productSetContextFingerprint(AiuPromptRequest $request): string
    {
        return $this->resolveProductSetContext($request)->getResolvedContextFingerprint();
    }

    /**
     * B0-LINE-01D-3J-11: authoritative tenant-scoped Product-Set Context facts.
     *
     * Fail-closed: throws before any Gemini call when the request carries no
     * resolved context (AiuPromptRequest::getProductSetContext()).
     */
    private function blockProductSetContext(AiuPromptRequest $request): string
    {
        $facts = $this->resolveProductSetContext($request)->toPromptFactsArray();
        $body = json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \RuntimeException(
                'AiuPromptBuilder: failed to serialize the authoritative Product-Set Context facts'
            );
        }

        return <<<TXT
[B-03a Product-Set Context — read-only — CURRENT REQUEST AUTHORITATIVE SEARCHABLE PRODUCT SET]
{$body}
These are the authoritative searchable product-set facts for THIS request — read-only, Runtime-supplied.
searchable_product_categories are the parent product set's own inherent categories for this request.
executable_search_dimensions are the only search conditions the current product search contract can execute for this request.
TXT;
    }

    private function blockEntitySchema(): string
    {
        return <<<'TXT'
[B-03 Entity Schema Reference]
Extract into entities object (fixed keys). Use null for missing scalars; [] for missing arrays.
Keys:
destination (array of strings — country/city/subnational/landmark; do NOT pick a primary),
destination_relation (string — REQUIRED for product_search: single | and | or | sequential | uncertain; never omit; never null),
destination_semantics (array — REQUIRED for product_search; nested candidate objects; use [] when no candidates),
travel_area (string|null — continent/large region e.g. 歐洲),
search_keyword_components (array<object> — see [B-03d]),
search_keyword_tokens (array<string> — see [B-03d]),
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

[B-03c Destination Feasibility & Relation — Product Search]
For EVERY product_search result you MUST output all three keys (never omit; never null):
- destination (array)
- destination_relation: single | and | or | sequential | uncertain
- destination_semantics (array)

destination_semantics items — one per candidate the customer mentioned or implied — each MUST be:
  label (string),
  semantic_role: travel_destination | named_place_or_poi | preference_or_descriptor | uncertain,
  travel_feasibility: { status: executable | non_executable | uncertain, feasibility_reason: string }
Only this nested travel_feasibility shape is valid. Do NOT output a flat string feasibility.
feasibility_reason is MANDATORY for EVERY candidate:
- MUST be present as a non-empty string (never omit the key; never null; never "" or whitespace-only).
- Required for status=executable, status=non_executable, AND status=uncertain — no status may omit reason.
- Write a short Gemini-authored structural reason for the feasibility judgment (open vocabulary; not a closed PHP enum, place list, or keyword mapping table).
- Do NOT invent Runtime defaults, aliases, or leave reason blank hoping Runtime will fill it.

General destination-intent policy (role vs feasibility):
- If the customer clearly treats an entity, concept, or place as a travel destination they want to go to, preserve that destination intent.
- Even when that destination is unrealistic, unreachable, unbookable, or not served by ordinary travel products, still assign a destination projection role (travel_destination or named_place_or_poi) and set travel_feasibility.status = non_executable with a short non-empty general feasibility_reason.
- Whether something is a destination is decided by the customer's semantic role; whether it can be searched is decided only by feasibility.
- Do NOT use missing_destination merely because a stated travel destination looks fictional, celestial, mythical, abstract, or commercially unavailable.
- Use natural language and world knowledge; do NOT rely on fixed place lists, closed destination vocabularies, or destination-to-result mapping tables.

Use preference_or_descriptor for themes, events, seasons, preferences, or descriptors — never mark those as travel_destination.
entities.destination MUST contain exactly the labels whose semantic_role is travel_destination or named_place_or_poi, in first-seen candidate order, regardless of travel_feasibility status.
Do not filter destination by executable vs non_executable; feasibility is for runtime gates only.
When destination candidates exist, destination_relation MUST be an explicit allowed value — never omit and never invent a silent default.
When no destination candidate exists for product_search:
  destination = []
  destination_semantics = []
  destination_relation = uncertain
  (and follow [B-04] missing_destination clarification rules)

[B-03d Search Keyword Tokens — Product Search]
search_keyword_tokens (array<string> — open vocabulary; Gemini-authored semantic search order):
- Only after completing the mandatory Step 1 through Step 5 decision below, output the complete current-turn ordered list of qualifying searchable concepts.
- Mandatory ordered decision: before emitting any token, you MUST perform Step 1 through Step 5 below, in order, for every candidate semantic component of the current turn (current turn or preserved resume context). Do not emit a token from a partial, out-of-order, or skipped pass through these steps.

Step 1 — Establish the active product set:
- Product-set baseline: judge discriminability within BBC's CURRENT SEARCHABLE TRAVEL PRODUCT SET only — i.e. whether a component further narrows which products in that set match — never as a travel-product-vs-non-travel-information distinction. This current searchable product collection for the customer's search intent is the parent set every candidate component is judged against.
- The active product set is given by the [B-03a] Product-Set Context searchable_product_categories for this request. You MUST use those authoritative facts instead of assuming or guessing the product set. A component that only restates one of those parent-set categories (or the parent set as a whole) is a Catalog/object restatement.

Step 2 — Classify each candidate's semantic role before emission:
- Each token MUST be a minimal complete semantic component of the customer's wording (not a fragment of one) that is both: (1) explicitly expressed by the customer (current turn or preserved resume context), and (2) independently able to narrow the customer's product-search intent if used alone as a search condition.
- Semantic role of each component (assign exactly one): Product constraint — further narrows, changes, or reorders eligible products through an executable destination/area/theme/activity/promotion/festival/POI/preference, or other current searchable condition within the product set; or Catalog/object restatement — only restates the catalog's own inherent product class, or the customer's general desire to find/buy travel products, without narrowing which products match. Only a component classified as Product constraint may proceed toward emission. Emit ONLY Product constraint components as search_keyword_tokens, and never a component classified as Catalog/object restatement.

Step 3 — Counterfactual executability check:
- Before emitting the array, self-check every candidate token semantically: if removing it would broaden or change the customer's product-search intent, keep it; if removing it would leave that intent unchanged, do not emit it.
- Counterfactual-plus-executability check: retain a candidate only when BOTH (1) removing it would broaden or change which products match, AND (2) the current product search contract can actually execute that distinction; omit it when demand is unchanged without it, or when current product data cannot yet distinguish on it. Never retain wording merely to reserve a future or not-yet-established product category.
- Executability MUST be judged against [B-03a] executable_search_dimensions. A distinction not expressible by those dimensions is not executable.
- Apply this same discriminability self-check to every candidate class — geographic surface, theme, activity, promotion, festival/event, attraction/POI/landmark, and preference — without closed vocabularies or special-case word lists.

Step 4 — Decompose fused surface phrases before emission:
- When a single customer phrase fuses a Product constraint together with the catalog's inherent class or a general search/purchase desire, semantically separate the two roles and keep only the Product constraint role as a token; this is meaning-based role decomposition, not mechanical string splitting, and it never applies to an indivisible proper name or compound place/POI name.
- Perform this decomposition before token emission, not after: never emit the whole fused surface phrase merely because one part of it is a valid Product constraint; emit only the decomposed Product constraint component and omit the fused Catalog/object-restatement remainder.
- Never drop a nearby theme, activity, promotion, festival/event, or POI token that independently passes the Product constraint self-check merely because it sits next to excluded Catalog/object restatement wording.

Step 5 — Preserve valid original surface wording:
- Treat an indivisible proper name or compound place/POI name as one minimal complete semantic component: apply the self-check to the whole name as a single unit, not to its internal characters or parts, and never split it into fragments or drop only part of it.
- Preserve each emitted token's original customer surface wording and relative order; do not translate, normalize, synonym-replace, or invent wording for keyword tokens.

Component authorship — required output shape (entities.search_keyword_components):
- For every candidate you evaluated through Step 1 through Step 5, emit one entities.search_keyword_components entry: {"surface": "<original customer wording>", "semantic_role": "product_constraint" | "catalog_object_restatement", "decision": "keep" | "omit", "decision_reason": "<short reason>"}.
- semantic_role is exactly "product_constraint" when Step 2 classified the component as narrowing the product set, or exactly "catalog_object_restatement" when Step 2 classified it as only restating the catalog's inherent class or a general search/purchase desire.
- decision is exactly "keep" only for a product_constraint component that survives Step 3 and Step 4; every catalog_object_restatement component MUST be decision "omit" — a catalog_object_restatement component is never "keep".
- decision_reason is a short, non-empty, Gemini-authored explanation of that decision (open vocabulary; not a fixed template or enum).
- entities.search_keyword_tokens MUST exactly equal, in the same order and count, the surface of every component whose decision is "keep"; tokens are a derived restatement of kept components, never an independently authored list.

- Include when present and discriminable: travel_area, destination, searchable geographic subdivision, festival/event, theme, searchable attraction/POI/landmark, preserved search terms.
- Conditionally include product_type when the customer uses it as a search qualifier (not merely a product category label) AND it passes the discriminability self-check.
- Conditionally include searchable must-have terms when they are positive search qualifiers AND they pass the discriminability self-check.
- EXCLUDE from this array: dates, departure, budget, people count, avoid/exclusion terms, and non-searchable metadata.
- Do NOT use closed theme, event, destination, or attraction vocabularies; use natural language understanding only.
- search_keyword_components and search_keyword_tokens are REQUIRED on every intent — never omit either key; use [] for both when no candidate component qualifies, otherwise output the full current authoritative lists.
- On every turn with prior resume state, output the FULL new authoritative array (not a delta); Runtime will not merge or append tokens.

Geographic surface vs structured destination (Gemini semantic roles only):
- Structured destination fields (destination labels and destination_semantics labels) MAY use a canonical geographic form when that best represents the travel destination for structured search.
- search_keyword_tokens MUST retain the customer's original geographic surface term(s) when those terms are searchable and would narrow the product result set — even if structured destination uses a different canonical form for the same place.
- Keep independent theme / preference / event tokens as separate entries in search_keyword_tokens when present AND each passes the discriminability self-check.
- Do NOT emit as search_keyword_tokens any wording that fails the discriminability self-check, including generic travel-product wording that only expresses a desire to search or buy travel products without narrowing the product set, or wording that is a Catalog/object restatement of the catalog's inherent product class.
- Assign these roles by natural-language semantic understanding only; do not rely on fixed synonym tables, alias lists, or closed vocabularies.
- Runtime validates structured shape and passes through Gemini results; it does not re-parse the customer utterance to invent, rewrite, translate, or delete keyword tokens.
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
  ALSO set: destination=[], destination_semantics=[], destination_relation=uncertain
  (all three keys required; never omit destination_relation; never use single as a silent stand-in for missing destination)
- Destination present AND usable travel dates missing or incomplete (date_range / date_from+date_to not both usable):
  clarification.reason = missing_travel_dates
- Do NOT use missing_travel_dates only because the customer omitted explicit Gregorian dates when [B-03c] still allows you to resolve a reliable date_range from natural-language date_expression.
- Do NOT use missing_destination when the customer named a travel destination intent that is merely non_executable; keep the destination candidate and set feasibility accordingly.

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

status / resume_reason / asked_entity describe why Runtime is waiting.
When resume_reason is "relation_capability_unavailable" and asked_entity is "destination":
the latest customer message answers the single-destination request.
Retain known date_from/date_to and other known_entities unless the customer clearly revises them.
Re-evaluate destination candidates, destination_relation, destination_semantics, and travel_feasibility.
Output a FULL authoritative AIU result (not a delta). Do NOT blindly accept a place name without semantic judgment.

You are the sole merge authority. Output a FULL authoritative AIU result (not a delta).
Runtime will NOT merge old and new entities.
Runtime will NOT rewrite destination_relation or invent dates.

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
  "entities": {
    "destination": [],
    "destination_relation": "single | and | or | sequential | uncertain",
    "destination_semantics": [
      {
        "label": "string",
        "semantic_role": "travel_destination | named_place_or_poi | preference_or_descriptor | uncertain",
        "travel_feasibility": {
          "status": "executable | non_executable | uncertain",
          "feasibility_reason": "string"
        }
      }
    ],
    "search_keyword_components": [
      {
        "surface": "string",
        "semantic_role": "product_constraint | catalog_object_restatement",
        "decision": "keep | omit",
        "decision_reason": "string"
      }
    ],
    "search_keyword_tokens": []
  },
  "confidence": 0.0,
  "clarification": { "required": false, "reason": "" },
  "resume_disposition": "continue_pending | modify_pending | new_request"
}
resume_disposition is REQUIRED because Structured Search Resume State was injected.
For product_search, destination, destination_relation, destination_semantics, and search_keyword_tokens policy per [B-03d] are REQUIRED keys (use [] / uncertain when empty; never omit destination keys; never null destination_relation).
When destination_semantics is non-empty, every candidate MUST include nested travel_feasibility.status and a non-empty travel_feasibility.feasibility_reason (never omit; never "").
entities.search_keyword_components and entities.search_keyword_tokens are REQUIRED keys on every intent (never omit; use [] for both when no candidate component applies). Each search_keyword_components entry MUST include a non-empty surface, semantic_role exactly "product_constraint" or "catalog_object_restatement", decision exactly "keep" or "omit", and a non-empty decision_reason; a catalog_object_restatement entry MUST NOT be decision "keep". search_keyword_tokens MUST exactly equal, in the same order and count, the surface of every decision=keep component.
entities must include all fixed keys. Do NOT output semantic_notes.
Do NOT output dispatch_plan, execution_hint, runtime_action, or search_action.
TXT;
        }

        return <<<'TXT'
[B-10 Output Schema — AIU v2 Gemini Output Contract]
Respond with JSON only (no markdown):
{
  "intent": "product_search | knowledge | human_service | ambiguous",
  "entities": {
    "destination": [],
    "destination_relation": "single | and | or | sequential | uncertain",
    "destination_semantics": [
      {
        "label": "string",
        "semantic_role": "travel_destination | named_place_or_poi | preference_or_descriptor | uncertain",
        "travel_feasibility": {
          "status": "executable | non_executable | uncertain",
          "feasibility_reason": "string"
        }
      }
    ],
    "search_keyword_components": [
      {
        "surface": "string",
        "semantic_role": "product_constraint | catalog_object_restatement",
        "decision": "keep | omit",
        "decision_reason": "string"
      }
    ],
    "search_keyword_tokens": []
  },
  "confidence": 0.0,
  "clarification": { "required": false, "reason": "" },
  "resume_disposition": "new_request"
}
resume_disposition may be omitted or "new_request" when no pending Structured Search Resume State was injected.
For product_search, destination, destination_relation, destination_semantics, and search_keyword_tokens policy per [B-03d] are REQUIRED keys (use [] / uncertain when empty; never omit destination keys; never null destination_relation).
When destination_semantics is non-empty, every candidate MUST include nested travel_feasibility.status and a non-empty travel_feasibility.feasibility_reason (never omit; never "").
entities.search_keyword_components and entities.search_keyword_tokens are REQUIRED keys on every intent (never omit; use [] for both when no candidate component applies). Each search_keyword_components entry MUST include a non-empty surface, semantic_role exactly "product_constraint" or "catalog_object_restatement", decision exactly "keep" or "omit", and a non-empty decision_reason; a catalog_object_restatement entry MUST NOT be decision "keep". search_keyword_tokens MUST exactly equal, in the same order and count, the surface of every decision=keep component.
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
