<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'DestinationFeasibilityContracts.php';

final class AiuDestinationSemanticsNormalizer
{
    public const FAILURE_CONTRACT = 'destination_semantics_contract_invalid';

    public const REASON_MISSING_DESTINATION_KEY = 'missing_destination_key';
    public const REASON_MISSING_RELATION_KEY = 'missing_relation_key';
    public const REASON_MISSING_SEMANTICS_KEY = 'missing_semantics_key';
    public const REASON_INVALID_RELATION = 'invalid_relation';
    public const REASON_INVALID_CANDIDATE_SHAPE = 'invalid_candidate_shape';
    public const REASON_MISSING_LABEL = 'missing_label';
    public const REASON_INVALID_ROLE = 'invalid_role';
    public const REASON_INVALID_FEASIBILITY_SHAPE = 'invalid_feasibility_shape';
    public const REASON_INVALID_FEASIBILITY_STATUS = 'invalid_feasibility_status';
    public const REASON_MISSING_FEASIBILITY_REASON = 'missing_feasibility_reason';
    public const REASON_DESTINATION_PROJECTION_MISMATCH = 'destination_projection_mismatch';

    /**
     * @param array<string, mixed> $entities Normalized frozen entities (destination list used for parity cross-check context)
     * @param array<string, mixed> $raw Raw Gemini entities map
     * @return array<string, mixed>
     */
    public static function apply(array $entities, array $raw): array
    {
        unset($entities);

        if (!array_key_exists('destination', $raw)) {
            self::fail(self::REASON_MISSING_DESTINATION_KEY);
        }
        if (!array_key_exists('destination_relation', $raw)) {
            self::fail(self::REASON_MISSING_RELATION_KEY);
        }
        if (!array_key_exists('destination_semantics', $raw)) {
            self::fail(self::REASON_MISSING_SEMANTICS_KEY);
        }

        $rawDestination = self::stringList($raw['destination']);
        $relation = self::normalizeRelation($raw['destination_relation']);

        $rawSemantics = $raw['destination_semantics'];
        if (!is_array($rawSemantics)) {
            self::fail(self::REASON_INVALID_CANDIDATE_SHAPE);
        }

        $normalizedCandidates = [];
        foreach ($rawSemantics as $item) {
            if (!is_array($item)) {
                self::fail(self::REASON_INVALID_CANDIDATE_SHAPE);
            }
            $normalizedCandidates[] = self::normalizeCandidate($item);
        }

        $projection = DestinationFeasibilityContracts::roleAwareDestinationLabels($normalizedCandidates);
        if (!$projection['ok']) {
            self::fail(self::REASON_INVALID_CANDIDATE_SHAPE);
        }

        $projectedLabels = $projection['labels'];
        if (!DestinationFeasibilityContracts::destinationLabelSetsEqual($rawDestination, $projectedLabels)) {
            self::fail(self::REASON_DESTINATION_PROJECTION_MISMATCH);
        }

        return [
            'destination' => $projectedLabels,
            'destination_relation' => $relation,
            'destination_semantics' => $normalizedCandidates,
        ];
    }

    public static function failureMessage(string $reasonCode): string
    {
        return self::FAILURE_CONTRACT . ':' . $reasonCode;
    }

    public static function isContractFailureMessage(string $message): bool
    {
        return $message === self::FAILURE_CONTRACT
            || strpos($message, self::FAILURE_CONTRACT . ':') === 0;
    }

    public static function reasonCodeFromMessage(string $message): ?string
    {
        $prefix = self::FAILURE_CONTRACT . ':';
        if (strpos($message, $prefix) !== 0) {
            return null;
        }

        $code = substr($message, strlen($prefix));

        return is_string($code) && $code !== '' ? $code : null;
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private static function normalizeCandidate(array $item): array
    {
        $label = '';
        if (isset($item['label']) && is_scalar($item['label'])) {
            $label = trim((string) $item['label']);
        }
        if ($label === '') {
            self::fail(self::REASON_MISSING_LABEL);
        }

        $role = isset($item['semantic_role']) ? trim((string) $item['semantic_role']) : '';
        if ($role === '' || !in_array($role, self::allowedRoles(), true)) {
            self::fail(self::REASON_INVALID_ROLE);
        }

        $feasibility = $item['travel_feasibility'] ?? null;
        if (!is_array($feasibility)) {
            self::fail(self::REASON_INVALID_FEASIBILITY_SHAPE);
        }

        $status = isset($feasibility['status']) ? trim((string) $feasibility['status']) : '';
        if ($status === '' || !in_array($status, self::allowedFeasibilityStatuses(), true)) {
            self::fail(self::REASON_INVALID_FEASIBILITY_STATUS);
        }

        $reason = isset($feasibility['feasibility_reason']) ? trim((string) $feasibility['feasibility_reason']) : '';
        if ($reason === '') {
            self::fail(self::REASON_MISSING_FEASIBILITY_REASON);
        }

        return [
            'label' => $label,
            'semantic_role' => $role,
            'travel_feasibility' => ['status' => $status, 'feasibility_reason' => $reason],
        ];
    }

    /** @param mixed $relation */
    private static function normalizeRelation($relation): string
    {
        if ($relation === null) {
            self::fail(self::REASON_INVALID_RELATION);
        }
        if (!is_scalar($relation)) {
            self::fail(self::REASON_INVALID_RELATION);
        }
        $value = trim((string) $relation);
        if ($value === '' || !in_array($value, DestinationFeasibilityContracts::allowedRelations(), true)) {
            self::fail(self::REASON_INVALID_RELATION);
        }

        return $value;
    }

    /** @return never */
    private static function fail(string $reasonCode): void
    {
        throw new \InvalidArgumentException(self::failureMessage($reasonCode));
    }

    /** @return list<string> */
    private static function allowedRoles(): array
    {
        return DestinationFeasibilityContracts::allowedSemanticRoles();
    }

    /** @return list<string> */
    private static function allowedFeasibilityStatuses(): array
    {
        return DestinationFeasibilityContracts::allowedFeasibilityStatuses();
    }

    /** @param mixed $value @return list<string> */
    private static function stringList($value): array
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
