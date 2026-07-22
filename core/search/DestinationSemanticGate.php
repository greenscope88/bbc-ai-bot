<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'DestinationFeasibilityContracts.php';

/**
 * Deterministic semantic gate from structured destination semantics only.
 */
final class DestinationSemanticGate
{
    /**
     * @param list<array<string, mixed>> $destinationSemantics
     * @return array{decision: string, executable_destinations: list<string>}
     */
    public static function evaluate(string $destinationRelation, array $destinationSemantics): array
    {
        if ($destinationSemantics === []) {
            return self::failClosed();
        }

        $relation = trim($destinationRelation);
        if ($relation === DestinationFeasibilityContracts::RELATION_UNCERTAIN) {
            return [
                'decision' => DestinationFeasibilityContracts::SEMANTIC_DENY_RELATION_UNCERTAIN,
                'executable_destinations' => [],
            ];
        }

        $executable = [];
        $hasUncertain = false;
        $hasNonExecutableRequired = false;
        $hasExecutableRequired = false;

        foreach ($destinationSemantics as $raw) {
            if (!is_array($raw)) {
                return self::failClosed();
            }
            $label = isset($raw['label']) ? trim((string) $raw['label']) : '';
            $role = isset($raw['semantic_role']) ? trim((string) $raw['semantic_role']) : '';
            $status = '';
            if (isset($raw['travel_feasibility']) && is_array($raw['travel_feasibility'])) {
                $status = trim((string) ($raw['travel_feasibility']['status'] ?? ''));
            }
            if ($label === '' || $role === '' || $status === '') {
                return self::failClosed();
            }

            if ($role === DestinationFeasibilityContracts::ROLE_UNCERTAIN
                || $status === DestinationFeasibilityContracts::FEASIBILITY_UNCERTAIN
            ) {
                $hasUncertain = true;
                continue;
            }

            $isRequiredRole = in_array($role, DestinationFeasibilityContracts::executableDestinationRoles(), true);
            if (!$isRequiredRole) {
                continue;
            }

            if ($status === DestinationFeasibilityContracts::FEASIBILITY_NON_EXECUTABLE) {
                $hasNonExecutableRequired = true;
                continue;
            }
            if ($status === DestinationFeasibilityContracts::FEASIBILITY_EXECUTABLE) {
                $hasExecutableRequired = true;
                $executable[] = $label;
            }
        }

        $executable = array_values(array_unique($executable));

        if ($hasUncertain) {
            return [
                'decision' => DestinationFeasibilityContracts::SEMANTIC_DENY_UNCERTAIN,
                'executable_destinations' => [],
            ];
        }

        if ($hasNonExecutableRequired && $hasExecutableRequired) {
            return [
                'decision' => DestinationFeasibilityContracts::SEMANTIC_DENY_MIXED_DESTINATION,
                'executable_destinations' => [],
            ];
        }

        if ($hasNonExecutableRequired && !$hasExecutableRequired) {
            return [
                'decision' => DestinationFeasibilityContracts::SEMANTIC_DENY_NON_EXECUTABLE,
                'executable_destinations' => [],
            ];
        }

        if (!self::cardinalityMatchesRelation($relation, count($executable))) {
            return self::failClosed();
        }

        if ($relation === DestinationFeasibilityContracts::RELATION_SINGLE) {
            return [
                'decision' => DestinationFeasibilityContracts::SEMANTIC_ALLOW_SINGLE,
                'executable_destinations' => $executable,
            ];
        }
        if ($relation === DestinationFeasibilityContracts::RELATION_AND) {
            return [
                'decision' => DestinationFeasibilityContracts::SEMANTIC_ALLOW_AND,
                'executable_destinations' => $executable,
            ];
        }
        if ($relation === DestinationFeasibilityContracts::RELATION_OR) {
            return [
                'decision' => DestinationFeasibilityContracts::SEMANTIC_ALLOW_OR,
                'executable_destinations' => $executable,
            ];
        }
        if ($relation === DestinationFeasibilityContracts::RELATION_SEQUENTIAL) {
            return [
                'decision' => DestinationFeasibilityContracts::SEMANTIC_ALLOW_SEQUENTIAL,
                'executable_destinations' => $executable,
            ];
        }

        return self::failClosed();
    }

    /**
     * @return array{decision: string, executable_destinations: list<string>}
     */
    private static function failClosed(): array
    {
        return [
            'decision' => DestinationFeasibilityContracts::SEMANTIC_FAIL_CLOSED,
            'executable_destinations' => [],
        ];
    }

    private static function cardinalityMatchesRelation(string $relation, int $executableCount): bool
    {
        if ($relation === DestinationFeasibilityContracts::RELATION_SINGLE) {
            return $executableCount === 1;
        }
        if (in_array($relation, [
            DestinationFeasibilityContracts::RELATION_AND,
            DestinationFeasibilityContracts::RELATION_OR,
            DestinationFeasibilityContracts::RELATION_SEQUENTIAL,
        ], true)) {
            return $executableCount >= 2;
        }

        return false;
    }
}
