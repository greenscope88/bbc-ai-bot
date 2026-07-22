<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'DestinationFeasibilityContracts.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'DestinationSemanticGate.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'DestinationRelationCapabilityRegistry.php';

final class DestinationExecutionGate
{
    /** @return array<string, mixed> */
    public static function evaluate(BatsSearchIntent $intent): array
    {
        $relation = trim($intent->getDestinationRelation());
        $semantics = $intent->getDestinationSemantics();
        $destinations = $intent->getDestination();

        if ($relation === '' || !in_array($relation, DestinationFeasibilityContracts::allowedRelations(), true)) {
            return self::failClosed($relation !== '' ? $relation : '(blank)');
        }

        if ($semantics === []) {
            return self::failClosed($relation);
        }

        $projection = DestinationFeasibilityContracts::roleAwareDestinationLabels($semantics);
        if (!$projection['ok']) {
            return self::failClosed($relation);
        }

        $roleAwareLabels = $projection['labels'];
        if ($roleAwareLabels === [] || $destinations === []) {
            return self::failClosed($relation);
        }

        if (!DestinationFeasibilityContracts::destinationLabelSetsEqual($destinations, $roleAwareLabels)) {
            return self::failClosed($relation);
        }

        $semantic = DestinationSemanticGate::evaluate($relation, $semantics);
        $semanticDecision = $semantic['decision'];
        $executable = $semantic['executable_destinations'];
        $capability = DestinationRelationCapabilityRegistry::evaluate($relation);
        $capabilityDecision = $capability['decision'];
        $executionDecision = self::combineExecution($semanticDecision, $capabilityDecision);
        $executionAllowed = $executionDecision === DestinationFeasibilityContracts::EXECUTION_ALLOW_SINGLE_SEARCH;
        $responseRoute = self::responseRouteFor($executionDecision);

        return [
            'execution_allowed' => $executionAllowed,
            'execution_decision' => $executionDecision,
            'response_route' => $responseRoute,
            'executable_destinations' => $executionAllowed ? $executable : [],
            'observability' => self::observability(
                $relation,
                $semanticDecision,
                $capabilityDecision,
                $executionDecision,
                $responseRoute,
                $executionAllowed
            ),
        ];
    }

    private static function failClosed(string $relation): array
    {
        return self::blocked(
            DestinationFeasibilityContracts::EXECUTION_FAIL_CLOSED,
            DestinationFeasibilityContracts::RESPONSE_ROUTE_FAIL_CLOSED,
            [],
            $relation,
            DestinationFeasibilityContracts::SEMANTIC_FAIL_CLOSED,
            DestinationFeasibilityContracts::CAPABILITY_INVALID
        );
    }

    private static function combineExecution(string $semanticDecision, string $capabilityDecision): string
    {
        if ($semanticDecision === DestinationFeasibilityContracts::SEMANTIC_FAIL_CLOSED) {
            return DestinationFeasibilityContracts::EXECUTION_FAIL_CLOSED;
        }
        if ($semanticDecision === DestinationFeasibilityContracts::SEMANTIC_DENY_NON_EXECUTABLE) {
            return DestinationFeasibilityContracts::EXECUTION_DENY_NON_EXECUTABLE;
        }
        if ($semanticDecision === DestinationFeasibilityContracts::SEMANTIC_DENY_MIXED_DESTINATION) {
            return DestinationFeasibilityContracts::EXECUTION_DENY_MIXED_DESTINATION;
        }
        if ($semanticDecision === DestinationFeasibilityContracts::SEMANTIC_DENY_UNCERTAIN) {
            return DestinationFeasibilityContracts::EXECUTION_DENY_UNCERTAIN;
        }
        if ($semanticDecision === DestinationFeasibilityContracts::SEMANTIC_DENY_RELATION_UNCERTAIN) {
            return DestinationFeasibilityContracts::EXECUTION_DENY_RELATION_UNCERTAIN;
        }

        if ($capabilityDecision !== DestinationFeasibilityContracts::CAPABILITY_SUPPORTED) {
            if (in_array($semanticDecision, [
                DestinationFeasibilityContracts::SEMANTIC_ALLOW_SINGLE,
                DestinationFeasibilityContracts::SEMANTIC_ALLOW_AND,
                DestinationFeasibilityContracts::SEMANTIC_ALLOW_OR,
                DestinationFeasibilityContracts::SEMANTIC_ALLOW_SEQUENTIAL,
            ], true)) {
                return DestinationFeasibilityContracts::EXECUTION_DENY_RELATION_CAPABILITY_UNAVAILABLE;
            }

            return DestinationFeasibilityContracts::EXECUTION_FAIL_CLOSED;
        }

        if ($semanticDecision === DestinationFeasibilityContracts::SEMANTIC_ALLOW_SINGLE) {
            return DestinationFeasibilityContracts::EXECUTION_ALLOW_SINGLE_SEARCH;
        }
        if ($semanticDecision === DestinationFeasibilityContracts::SEMANTIC_ALLOW_AND) {
            return DestinationFeasibilityContracts::EXECUTION_ALLOW_AND_SEARCH;
        }
        if ($semanticDecision === DestinationFeasibilityContracts::SEMANTIC_ALLOW_OR) {
            return DestinationFeasibilityContracts::EXECUTION_ALLOW_OR_GROUPED_SEARCH;
        }
        if ($semanticDecision === DestinationFeasibilityContracts::SEMANTIC_ALLOW_SEQUENTIAL) {
            return DestinationFeasibilityContracts::EXECUTION_ALLOW_SEQUENTIAL_SEARCH;
        }

        return DestinationFeasibilityContracts::EXECUTION_FAIL_CLOSED;
    }

    private static function responseRouteFor(string $executionDecision): string
    {
        if ($executionDecision === DestinationFeasibilityContracts::EXECUTION_ALLOW_SINGLE_SEARCH) {
            return DestinationFeasibilityContracts::RESPONSE_ROUTE_SINGLE_SEARCH;
        }
        if ($executionDecision === DestinationFeasibilityContracts::EXECUTION_DENY_NON_EXECUTABLE) {
            return DestinationFeasibilityContracts::RESPONSE_ROUTE_NON_EXECUTABLE;
        }
        if ($executionDecision === DestinationFeasibilityContracts::EXECUTION_DENY_RELATION_CAPABILITY_UNAVAILABLE) {
            return DestinationFeasibilityContracts::RESPONSE_ROUTE_CAPABILITY_UNAVAILABLE;
        }
        if ($executionDecision === DestinationFeasibilityContracts::EXECUTION_FAIL_CLOSED) {
            return DestinationFeasibilityContracts::RESPONSE_ROUTE_FAIL_CLOSED;
        }

        return DestinationFeasibilityContracts::RESPONSE_ROUTE_CLARIFICATION;
    }

    /** @param list<string> $executable @return array<string, mixed> */
    private static function blocked(
        string $executionDecision,
        string $responseRoute,
        array $executable,
        string $relation,
        string $semanticDecision,
        string $capabilityDecision
    ): array {
        return [
            'execution_allowed' => false,
            'execution_decision' => $executionDecision,
            'response_route' => $responseRoute,
            'executable_destinations' => $executable,
            'observability' => self::observability(
                $relation,
                $semanticDecision,
                $capabilityDecision,
                $executionDecision,
                $responseRoute,
                false
            ),
        ];
    }

    /** @return array<string, mixed> */
    private static function observability(
        string $relation,
        string $semanticDecision,
        string $capabilityDecision,
        string $executionDecision,
        string $responseRoute,
        bool $executionAllowed
    ): array {
        return [
            'destination_relation' => $relation,
            'semantic_gate_decision' => $semanticDecision,
            'capability_gate_decision' => $capabilityDecision,
            'execution_gate_decision' => $executionDecision,
            'relation_capability_version' => DestinationRelationCapabilityRegistry::VERSION,
            'search_group_count' => 0,
            'search_condition_created' => $executionAllowed,
            'product_source_executed' => false,
            'host_b_executed' => false,
            'multi_source_links_built' => false,
            'response_route' => $responseRoute,
            'understanding_source' => 'gemini_aiu',
        ];
    }
}
