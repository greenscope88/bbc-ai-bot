<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuPromptRequest.php';

/**
 * Phase 2-D Step 2-D-4 — AIU Prompt Scheme v1.0 Builder（B-01～B-11）.
 *
 * SSOT: docs/BATS_AI_INTENT_UNDERSTANDING_V2.md §16.4
 */
final class AiuPromptBuilder
{
    public function build(AiuPromptRequest $request): string
    {
        $blocks = [
            $this->blockMandate(),
            $this->blockIntentTaxonomy(),
            $this->blockEntitySchema(),
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
[B-02 Intent Taxonomy — CA-009]
Classify intent as exactly one of:
- "Product Search" — customer wants tour/product search, quotes, dates, destinations, itineraries
- "Knowledge" — FAQ, policies, contact info, services, passport/visa, company info
- "Ambiguous" — intent too vague to route (e.g. "想出去玩" without destination or topic)

Special: if customer explicitly requests human agent / 真人客服 / 轉人工 / 找客服,
set intent "Knowledge" and entity.human_service_request = true.
TXT;
    }

    private function blockEntitySchema(): string
    {
        return <<<'TXT'
[B-03 Entity Schema Reference]
For Product Search, extract semantic entities into entity object:
destination, destination_alias (array), multi_destination (array), departure_city,
date_from, date_to (YYYY-MM-DD when possible), travel_type (array), duration (string),
budget_min, budget_max, people_count, landmark, must_have (array), avoid (array),
free_text (original semantic summary), human_service_request (bool, default false).

Normalize spacing in semantic meaning (e.g. "火星五日遊8月" and "火星五日遊 8月" should yield same destination/dates).
For Knowledge/Ambiguous, entity may be {} or minimal; human_service_request when applicable.
TXT;
    }

    private function blockClarificationDetection(): string
    {
        return <<<'TXT'
[B-04 Clarification Detection Policy]
Set clarification.required = true when:
- intent is Ambiguous, OR
- Product Search but critical slots missing (e.g. destination without dates when dates needed), OR
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
[B-10 Output Schema — Semantic JSON v1.0]
Respond with JSON only (no markdown):
{
  "intent": "Product Search | Knowledge | Ambiguous",
  "entity": {},
  "confidence": 0.0,
  "clarification": { "required": false, "reason": "" },
  "semantic_notes": ""
}
TXT;
    }

    private function blockGuardrails(): string
    {
        return <<<'TXT'
[B-11 Guardrails]
- Do NOT output dispatch_plan or execution_hint
- Do NOT generate reply话术 or clarification questions for the customer
- Do NOT change owner or conversation state
- Use natural language understanding, not keyword tables
TXT;
    }
}
