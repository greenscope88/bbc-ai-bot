<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ClarificationContract.php';

/**
 * Builds ClarificationContract from Runtime clarification direction only.
 *
 * Never parses the customer utterance. Known entities and grounded facts are
 * derived solely from structured non-empty values supplied by Runtime/AIU.
 */
final class ClarificationContractFactory
{
    /** @var array<string, string> */
    private const REASON_TO_MISSING = [
        ClarificationContract::REASON_DATE_REQUIRED => ClarificationContract::ENTITY_DATE,
        ClarificationContract::REASON_DESTINATION_UNKNOWN => ClarificationContract::ENTITY_DESTINATION,
    ];

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input): ClarificationContract
    {
        $reason = isset($input['clarification_reason'])
            ? trim((string) $input['clarification_reason'])
            : '';

        if ($reason === '' || !isset(self::REASON_TO_MISSING[$reason])) {
            throw new \InvalidArgumentException('unsupported_clarification_reason');
        }

        if (array_key_exists('clarification_required', $input)
            && (bool) $input['clarification_required'] !== true
        ) {
            throw new \InvalidArgumentException('clarification_input_invalid');
        }

        $missingEntity = self::REASON_TO_MISSING[$reason];
        $knownEntities = $this->normalizeKnownEntities(
            isset($input['known_entities']) && is_array($input['known_entities'])
                ? $input['known_entities']
                : [],
            $missingEntity
        );

        $mustAsk = [$missingEntity];
        $mustNotAsk = $this->buildMustNotAsk($knownEntities);
        $groundedFacts = $this->buildGroundedFacts($knownEntities);

        $toneRaw = isset($input['tone']) && is_array($input['tone']) ? $input['tone'] : [];
        $tone = [
            'persona' => trim((string) ($toneRaw['persona'] ?? 'travel_consultant')),
            'allow_emoji' => array_key_exists('allow_emoji', $toneRaw)
                ? (bool) $toneRaw['allow_emoji']
                : true,
        ];

        $tenant = isset($input['tenant']) && is_array($input['tenant']) ? $input['tenant'] : [];

        $traceRaw = isset($input['trace_metadata']) && is_array($input['trace_metadata'])
            ? $input['trace_metadata']
            : [];

        $traceMetadata = [
            'trace_id' => trim((string) ($input['trace_id'] ?? ($traceRaw['trace_id'] ?? ''))),
            'tenant_sno' => trim((string) (
                $input['tenant_sno']
                ?? ($tenant['tenant_sno'] ?? ($traceRaw['tenant_sno'] ?? ''))
            )),
            'conversation_id' => trim((string) (
                $input['conversation_id'] ?? ($traceRaw['conversation_id'] ?? '')
            )),
        ];

        return new ClarificationContract(
            $reason,
            $missingEntity,
            $knownEntities,
            $mustAsk,
            $mustNotAsk,
            $tone,
            $tenant,
            $traceMetadata,
            $groundedFacts,
            false,
            ClarificationContract::SCHEMA_VERSION
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalizeKnownEntities(array $raw, string $missingEntity): array
    {
        $out = [];

        foreach (ClarificationContract::ALLOWED_KNOWN_KEYS as $key) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }

            if ($this->isExcludedByMissingEntity($key, $missingEntity)) {
                continue;
            }

            $value = $raw[$key];
            if (!$this->isNonEmptyKnownValue($value)) {
                continue;
            }

            if ($key === 'destination' && is_array($value)) {
                $clean = [];
                foreach ($value as $item) {
                    if ($this->isNonEmptyKnownValue($item)) {
                        $clean[] = is_string($item) ? trim($item) : $item;
                    }
                }
                if ($clean === []) {
                    continue;
                }
                $out[$key] = array_values($clean);
                continue;
            }

            if (is_string($value)) {
                $out[$key] = trim($value);
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    private function isExcludedByMissingEntity(string $key, string $missingEntity): bool
    {
        if ($missingEntity === ClarificationContract::ENTITY_DATE) {
            return $key === 'date_from' || $key === 'date_to';
        }

        if ($missingEntity === ClarificationContract::ENTITY_DESTINATION) {
            return $key === 'destination';
        }

        return false;
    }

    /**
     * @param mixed $value
     */
    private function isNonEmptyKnownValue($value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        if (is_bool($value)) {
            return true;
        }

        if (is_int($value) || is_float($value)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $knownEntities
     * @return list<string>
     */
    private function buildMustNotAsk(array $knownEntities): array
    {
        $entities = [];

        if (isset($knownEntities['date_from']) || isset($knownEntities['date_to'])) {
            $entities[] = ClarificationContract::ENTITY_DATE;
        }
        if (isset($knownEntities['departure'])) {
            $entities[] = 'departure';
        }
        if (isset($knownEntities['destination'])) {
            $entities[] = ClarificationContract::ENTITY_DESTINATION;
        }

        return array_values(array_unique($entities));
    }

    /**
     * @param array<string, mixed> $knownEntities
     * @return list<array<string, mixed>>
     */
    private function buildGroundedFacts(array $knownEntities): array
    {
        $facts = [];

        foreach (ClarificationContract::ALLOWED_KNOWN_KEYS as $key) {
            if (!array_key_exists($key, $knownEntities)) {
                continue;
            }

            $value = $knownEntities[$key];

            if ($key === 'destination' && is_array($value)) {
                foreach (array_values($value) as $index => $item) {
                    $facts[] = [
                        'fact_id' => 'known.destination.' . $index,
                        'fact_type' => 'known_entity',
                        'entity_key' => 'destination',
                        'value' => $item,
                        'source_ref' => 'known_entities.destination',
                    ];
                }
                continue;
            }

            $facts[] = [
                'fact_id' => 'known.' . $key,
                'fact_type' => 'known_entity',
                'entity_key' => $key,
                'value' => $value,
                'source_ref' => 'known_entities.' . $key,
            ];
        }

        return $facts;
    }
}
