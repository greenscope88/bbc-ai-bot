<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ClarificationContract.php';

/**
 * Builds the Gemini prompt for generative clarification JSON.
 *
 * Does not invent facts, claim Search completed, or provide fixed reply templates.
 */
final class ClarificationGeminiPromptBuilder
{
    public function build(ClarificationContract $contract): string
    {
        $payload = [
            'task' => 'clarification_reply_json',
            'schema_version' => ClarificationContract::SCHEMA_VERSION,
            'clarification_reason' => $contract->getClarificationReason(),
            'missing_entity' => $contract->getMissingEntity(),
            'must_ask' => $contract->getMustAsk(),
            'must_not_ask' => $contract->getMustNotAsk(),
            'known_entities' => $contract->getKnownEntities(),
            'search_executed' => $contract->isSearchExecuted(),
            'tone' => $contract->getTone(),
            'tenant' => [
                'company_name' => (string) ($contract->getTenant()['company_name'] ?? ''),
                'persona' => (string) ($contract->getTone()['persona'] ?? ''),
            ],
            'required_output_fields' => [
                'schema_version',
                'reply_type',
                'clarification_reason',
                'asked_entity',
                'reply_text',
                'acknowledged_entities',
                'search_claimed',
                'product_facts_used',
            ],
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            $encoded = '{}';
        }

        $rules = [
            'Write natural Traditional Chinese clarification wording with variable phrasing.',
            'Ask ONLY for the missing_entity listed in must_ask.',
            'Do NOT ask for any entity in must_not_ask or any known_entities key.',
            'Do NOT guess missing facts. Acknowledge only values present in known_entities.',
            'Do NOT claim Search completed or that products were found.',
            'Do NOT invent product names, prices, URLs, or itineraries.',
            'Do NOT use a fixed Date / Destination normal template.',
            'search_claimed must be false. product_facts_used must be false.',
            'reply_type must be clarification. asked_entity must equal missing_entity.',
            'acknowledged_entities must be an object subset of known_entities (same values).',
            'reply_text length must be 1 to 2000 characters.',
            'Return a single JSON object only, no markdown fences.',
        ];

        return "You are generating a LINE clarification reply JSON for a travel consultant.\n"
            . "Input contract (JSON):\n"
            . $encoded . "\n\n"
            . "Hard rules:\n- "
            . implode("\n- ", $rules) . "\n\n"
            . "Output JSON example shape (values illustrative only):\n"
            . "{\n"
            . "  \"schema_version\": 1,\n"
            . "  \"reply_type\": \"clarification\",\n"
            . "  \"clarification_reason\": \"destination_unknown\",\n"
            . "  \"asked_entity\": \"destination\",\n"
            . "  \"reply_text\": \"\",\n"
            . "  \"acknowledged_entities\": {},\n"
            . "  \"search_claimed\": false,\n"
            . "  \"product_facts_used\": false\n"
            . "}\n";
    }
}
