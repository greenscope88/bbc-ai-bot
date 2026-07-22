<?php
declare(strict_types=1);

/**
 * Frozen enums for B0-LINE-01D destination feasibility and relation gates.
 */
final class DestinationFeasibilityContracts
{
    public const RELATION_SINGLE = 'single';
    public const RELATION_AND = 'and';
    public const RELATION_OR = 'or';
    public const RELATION_SEQUENTIAL = 'sequential';
    public const RELATION_UNCERTAIN = 'uncertain';

    public const ROLE_TRAVEL_DESTINATION = 'travel_destination';
    public const ROLE_NAMED_PLACE_OR_POI = 'named_place_or_poi';
    public const ROLE_PREFERENCE_OR_DESCRIPTOR = 'preference_or_descriptor';
    public const ROLE_UNCERTAIN = 'uncertain';

    public const FEASIBILITY_EXECUTABLE = 'executable';
    public const FEASIBILITY_NON_EXECUTABLE = 'non_executable';
    public const FEASIBILITY_UNCERTAIN = 'uncertain';

    public const SEMANTIC_ALLOW_SINGLE = 'allow_single';
    public const SEMANTIC_ALLOW_AND = 'allow_and';
    public const SEMANTIC_ALLOW_OR = 'allow_or';
    public const SEMANTIC_ALLOW_SEQUENTIAL = 'allow_sequential';
    public const SEMANTIC_DENY_NON_EXECUTABLE = 'deny_non_executable';
    public const SEMANTIC_DENY_MIXED_DESTINATION = 'deny_mixed_destination';
    public const SEMANTIC_DENY_UNCERTAIN = 'deny_uncertain';
    public const SEMANTIC_DENY_RELATION_UNCERTAIN = 'deny_relation_uncertain';
    public const SEMANTIC_FAIL_CLOSED = 'fail_closed';

    public const CAPABILITY_SUPPORTED = 'supported';
    public const CAPABILITY_UNSUPPORTED_AND = 'unsupported_and_projection';
    public const CAPABILITY_UNSUPPORTED_OR = 'unsupported_or_grouped_search';
    public const CAPABILITY_UNSUPPORTED_SEQUENTIAL = 'unsupported_sequential_search';
    public const CAPABILITY_INVALID = 'invalid_relation_capability';

    public const EXECUTION_ALLOW_SINGLE_SEARCH = 'allow_single_search';
    public const EXECUTION_ALLOW_AND_SEARCH = 'allow_and_search';
    public const EXECUTION_ALLOW_OR_GROUPED_SEARCH = 'allow_or_grouped_search';
    public const EXECUTION_ALLOW_SEQUENTIAL_SEARCH = 'allow_sequential_search';
    public const EXECUTION_DENY_NON_EXECUTABLE = 'deny_non_executable';
    public const EXECUTION_DENY_MIXED_DESTINATION = 'deny_mixed_destination';
    public const EXECUTION_DENY_UNCERTAIN = 'deny_uncertain';
    public const EXECUTION_DENY_RELATION_UNCERTAIN = 'deny_relation_uncertain';
    public const EXECUTION_DENY_RELATION_CAPABILITY_UNAVAILABLE = 'deny_relation_capability_unavailable';
    public const EXECUTION_FAIL_CLOSED = 'fail_closed';

    public const RESPONSE_ROUTE_SINGLE_SEARCH = 'single_search';
    public const RESPONSE_ROUTE_CLARIFICATION = 'destination_gate_clarification';
    public const RESPONSE_ROUTE_NON_EXECUTABLE = 'destination_non_executable';
    public const RESPONSE_ROUTE_CAPABILITY_UNAVAILABLE = 'destination_relation_capability_unavailable';
    public const RESPONSE_ROUTE_FAIL_CLOSED = 'destination_gate_fail_closed';

    /** @return list<string> */
    public static function allowedRelations(): array
    {
        return [
            self::RELATION_SINGLE,
            self::RELATION_AND,
            self::RELATION_OR,
            self::RELATION_SEQUENTIAL,
            self::RELATION_UNCERTAIN,
        ];
    }

    /** @return list<string> */
    public static function executableDestinationRoles(): array
    {
        return [self::ROLE_TRAVEL_DESTINATION, self::ROLE_NAMED_PLACE_OR_POI];
    }

    /**
     * Labels projected into entities.destination — role-only (not feasibility-filtered).
     *
     * @return list<string>
     */
    public static function destinationProjectionRoles(): array
    {
        return self::executableDestinationRoles();
    }

    /** @return list<string> */
    public static function allowedSemanticRoles(): array
    {
        return [
            self::ROLE_TRAVEL_DESTINATION,
            self::ROLE_NAMED_PLACE_OR_POI,
            self::ROLE_PREFERENCE_OR_DESCRIPTOR,
            self::ROLE_UNCERTAIN,
        ];
    }

    /** @return list<string> */
    public static function allowedFeasibilityStatuses(): array
    {
        return [
            self::FEASIBILITY_EXECUTABLE,
            self::FEASIBILITY_NON_EXECUTABLE,
            self::FEASIBILITY_UNCERTAIN,
        ];
    }

    /**
     * @param list<array<string, mixed>> $semantics
     * @return array{ok: bool, labels: list<string>}
     */
    public static function roleAwareDestinationLabels(array $semantics): array
    {
        $labels = [];
        foreach ($semantics as $raw) {
            if (!is_array($raw)) {
                return ['ok' => false, 'labels' => []];
            }
            $label = isset($raw['label']) ? trim((string) $raw['label']) : '';
            if ($label === '') {
                return ['ok' => false, 'labels' => []];
            }
            $role = isset($raw['semantic_role']) ? trim((string) $raw['semantic_role']) : '';
            if ($role === '' || !in_array($role, self::allowedSemanticRoles(), true)) {
                return ['ok' => false, 'labels' => []];
            }
            $feasibility = $raw['travel_feasibility'] ?? null;
            if (!is_array($feasibility)) {
                return ['ok' => false, 'labels' => []];
            }
            $status = isset($feasibility['status']) ? trim((string) $feasibility['status']) : '';
            if ($status === '' || !in_array($status, self::allowedFeasibilityStatuses(), true)) {
                return ['ok' => false, 'labels' => []];
            }
            $reason = isset($feasibility['feasibility_reason'])
                ? trim((string) $feasibility['feasibility_reason'])
                : '';
            if ($reason === '') {
                return ['ok' => false, 'labels' => []];
            }
            if (in_array($role, self::destinationProjectionRoles(), true)) {
                $labels[] = $label;
            }
        }

        return ['ok' => true, 'labels' => self::orderPreservingUniqueLabels($labels)];
    }

    /**
     * Role-aware projection output: trim, first-seen dedupe, preserve Gemini candidate order.
     *
     * @param list<string> $labels
     * @return list<string>
     */
    public static function orderPreservingUniqueLabels(array $labels): array
    {
        $out = [];
        $seen = [];
        foreach ($labels as $label) {
            $s = trim((string) $label);
            if ($s === '' || isset($seen[$s])) {
                continue;
            }
            $seen[$s] = true;
            $out[] = $s;
        }

        return $out;
    }

    /**
     * Parity helper: trim, dedupe, sort for order-insensitive set equality.
     *
     * @param list<string> $labels
     * @return list<string>
     */
    public static function normalizeLabelSet(array $labels): array
    {
        $out = self::orderPreservingUniqueLabels($labels);
        sort($out);

        return $out;
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    public static function destinationLabelSetsEqual(array $left, array $right): bool
    {
        return self::normalizeLabelSet($left) === self::normalizeLabelSet($right);
    }
}
