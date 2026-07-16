<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ClarificationContract.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ReplyType.php';

/**
 * Deterministic clarification Gemini output validator.
 *
 * Structural / set checks only. No wording keyword lists, no text semantic
 * classification, and no second Gemini/NLU judge.
 */
final class ClarificationOutputValidator
{
    public const MAX_REPLY_CHARS = 2000;

    /**
     * @param array<string, mixed> $output
     * @return array{
     *   ok: bool,
     *   failure_reason: ?string,
     *   failed_rules: list<string>,
     *   used_facts_count: int,
     *   referenced_fact_ids: list<string>,
     *   grounded: bool
     * }
     */
    public function validate(array $output, ClarificationContract $contract): array
    {
        $failed = [];

        foreach (
            [
                'schema_version',
                'reply_type',
                'clarification_reason',
                'asked_entity',
                'reply_text',
                'acknowledged_entities',
                'search_claimed',
                'product_facts_used',
            ] as $required
        ) {
            if (!array_key_exists($required, $output)) {
                $failed[] = 'missing_field:' . $required;
            }
        }

        if ($failed !== []) {
            return $this->fail('clarification_output_malformed', $failed);
        }

        if (!is_int($output['schema_version']) && !(is_string($output['schema_version']) && ctype_digit((string) $output['schema_version']))) {
            $failed[] = 'schema_version_type';
        } elseif ((int) $output['schema_version'] !== ClarificationContract::SCHEMA_VERSION) {
            $failed[] = 'schema_version_value';
        }

        if (!is_string($output['reply_type']) || $output['reply_type'] !== ReplyType::CLARIFICATION) {
            $failed[] = 'reply_type';
        }

        if (!is_string($output['clarification_reason'])
            || $output['clarification_reason'] !== $contract->getClarificationReason()
        ) {
            $failed[] = 'clarification_reason_mismatch';
        }

        $askedEntity = is_string($output['asked_entity']) ? $output['asked_entity'] : null;
        if ($askedEntity === null || $askedEntity === '') {
            $failed[] = 'asked_entity_type';
        } else {
            if ($askedEntity !== $contract->getMissingEntity()) {
                $failed[] = 'asked_entity_missing_mismatch';
            }
            if (!in_array($askedEntity, $contract->getMustAsk(), true)) {
                $failed[] = 'asked_entity_not_in_must_ask';
            }
            if (in_array($askedEntity, $contract->getMustNotAsk(), true)) {
                $failed[] = 'asked_entity_in_must_not_ask';
            }
        }

        if (!is_bool($output['search_claimed']) || $output['search_claimed'] !== false) {
            $failed[] = 'search_claimed_must_be_false';
        }
        if (!is_bool($output['product_facts_used']) || $output['product_facts_used'] !== false) {
            $failed[] = 'product_facts_used_must_be_false';
        }

        if (!is_string($output['reply_text'])) {
            $failed[] = 'reply_text_type';
            $replyText = '';
        } else {
            $replyText = trim($output['reply_text']);
            if ($replyText === '') {
                $failed[] = 'reply_text_empty';
            } elseif (mb_strlen($replyText, 'UTF-8') > self::MAX_REPLY_CHARS) {
                $failed[] = 'reply_text_too_long';
            }
            if ($this->containsUrl($replyText)) {
                $failed[] = 'reply_text_url_leakage';
            }
        }

        if (!is_array($output['acknowledged_entities'])) {
            $failed[] = 'acknowledged_entities_type';
            $acknowledged = [];
        } else {
            $acknowledged = $output['acknowledged_entities'];
            $subsetCheck = $this->assertAcknowledgedSubset($acknowledged, $contract->getKnownEntities(), $askedEntity);
            foreach ($subsetCheck['failed_rules'] as $rule) {
                $failed[] = $rule;
            }
        }

        if ($failed !== []) {
            $reason = in_array('reply_text_url_leakage', $failed, true)
                || $this->hasPrefix($failed, 'missing_field:')
                || in_array('schema_version_type', $failed, true)
                || in_array('reply_text_type', $failed, true)
                || in_array('acknowledged_entities_type', $failed, true)
                ? 'clarification_output_malformed'
                : 'clarification_validation_failed';

            return $this->fail($reason, array_values(array_unique($failed)));
        }

        $factAccounting = $this->buildFactAccounting($acknowledged, $contract->getGroundedFacts());

        return [
            'ok' => true,
            'failure_reason' => null,
            'failed_rules' => [],
            'used_facts_count' => $factAccounting['used_facts_count'],
            'referenced_fact_ids' => $factAccounting['referenced_fact_ids'],
            'grounded' => $factAccounting['used_facts_count'] > 0,
        ];
    }

    /**
     * @param array<string, mixed> $acknowledged
     * @param array<string, mixed> $known
     * @return array{failed_rules: list<string>}
     */
    private function assertAcknowledgedSubset(array $acknowledged, array $known, ?string $askedEntity): array
    {
        $failed = [];

        foreach ($acknowledged as $key => $value) {
            if (!is_string($key)) {
                $failed[] = 'acknowledged_key_type';
                continue;
            }

            if (!array_key_exists($key, $known)) {
                $failed[] = 'acknowledged_not_in_known:' . $key;
                continue;
            }

            if (!$this->isAcknowledgedValueAllowed($key, $value, $known[$key])) {
                $failed[] = 'acknowledged_value_mismatch:' . $key;
            }

            if ($askedEntity !== null && $this->keyMapsToAskedEntity($key, $askedEntity)) {
                $failed[] = 'acknowledged_includes_asked_entity';
            }
        }

        return ['failed_rules' => $failed];
    }

    /**
     * @param mixed $ackValue
     * @param mixed $knownValue
     */
    private function isAcknowledgedValueAllowed(string $key, $ackValue, $knownValue): bool
    {
        if ($key === 'destination' && is_array($knownValue)) {
            if (is_array($ackValue)) {
                foreach ($ackValue as $item) {
                    if (!$this->arrayContainsValue($knownValue, $item)) {
                        return false;
                    }
                }

                return $ackValue !== [];
            }

            return $this->arrayContainsValue($knownValue, $ackValue);
        }

        return $this->valuesEqual($ackValue, $knownValue);
    }

    /**
     * @param list<mixed> $haystack
     * @param mixed       $needle
     */
    private function arrayContainsValue(array $haystack, $needle): bool
    {
        foreach ($haystack as $item) {
            if ($this->valuesEqual($item, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function keyMapsToAskedEntity(string $key, string $askedEntity): bool
    {
        if ($askedEntity === ClarificationContract::ENTITY_DATE) {
            return $key === 'date_from' || $key === 'date_to' || $key === 'date';
        }

        if ($askedEntity === ClarificationContract::ENTITY_DESTINATION) {
            return $key === 'destination';
        }

        return $key === $askedEntity;
    }

    /**
     * @param mixed $a
     * @param mixed $b
     */
    private function valuesEqual($a, $b): bool
    {
        if (is_array($a) || is_array($b)) {
            if (!is_array($a) || !is_array($b)) {
                return false;
            }

            return json_encode($a, JSON_UNESCAPED_UNICODE) === json_encode($b, JSON_UNESCAPED_UNICODE);
        }

        if (is_string($a) || is_string($b)) {
            return trim((string) $a) === trim((string) $b);
        }

        return $a === $b;
    }

    /**
     * @param array<string, mixed>     $acknowledged
     * @param list<array<string, mixed>> $groundedFacts
     * @return array{used_facts_count: int, referenced_fact_ids: list<string>}
     */
    private function buildFactAccounting(array $acknowledged, array $groundedFacts): array
    {
        $ids = [];

        foreach ($groundedFacts as $fact) {
            $entityKey = isset($fact['entity_key']) ? (string) $fact['entity_key'] : '';
            if ($entityKey === '' || !array_key_exists($entityKey, $acknowledged)) {
                continue;
            }

            $factValue = $fact['value'] ?? null;
            $ackValue = $acknowledged[$entityKey];

            if ($entityKey === 'destination' && is_array($ackValue)) {
                foreach ($ackValue as $item) {
                    if ($this->valuesEqual($factValue, $item)) {
                        $ids[] = (string) ($fact['fact_id'] ?? '');
                        break;
                    }
                }
                continue;
            }

            if ($this->valuesEqual($factValue, $ackValue)) {
                $ids[] = (string) ($fact['fact_id'] ?? '');
            }
        }

        $ids = array_values(array_filter($ids, static function (string $id): bool {
            return $id !== '';
        }));

        return [
            'used_facts_count' => count($ids),
            'referenced_fact_ids' => $ids,
        ];
    }

    private function containsUrl(string $text): bool
    {
        return preg_match('#https?://#i', $text) === 1;
    }

    /**
     * @param list<string> $rules
     */
    private function hasPrefix(array $rules, string $prefix): bool
    {
        foreach ($rules as $rule) {
            if (strpos($rule, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $failed
     * @return array{
     *   ok: bool,
     *   failure_reason: ?string,
     *   failed_rules: list<string>,
     *   used_facts_count: int,
     *   referenced_fact_ids: list<string>,
     *   grounded: bool
     * }
     */
    private function fail(string $reason, array $failed): array
    {
        return [
            'ok' => false,
            'failure_reason' => $reason,
            'failed_rules' => $failed,
            'used_facts_count' => 0,
            'referenced_fact_ids' => [],
            'grounded' => false,
        ];
    }
}
