<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'DestinationFeasibilityContracts.php';

/**
 * Versioned relation execution capability registry (not feature flags).
 */
final class DestinationRelationCapabilityRegistry
{
    public const VERSION = 'b0-line-01d-2c-v1';

    /**
     * @return array{version: string, decision: string, relation: string}
     */
    public static function evaluate(string $destinationRelation): array
    {
        $relation = trim($destinationRelation);
        if ($relation === DestinationFeasibilityContracts::RELATION_SINGLE) {
            return [
                'version' => self::VERSION,
                'relation' => $relation,
                'decision' => DestinationFeasibilityContracts::CAPABILITY_SUPPORTED,
            ];
        }
        if ($relation === DestinationFeasibilityContracts::RELATION_AND) {
            return [
                'version' => self::VERSION,
                'relation' => $relation,
                'decision' => DestinationFeasibilityContracts::CAPABILITY_UNSUPPORTED_AND,
            ];
        }
        if ($relation === DestinationFeasibilityContracts::RELATION_OR) {
            return [
                'version' => self::VERSION,
                'relation' => $relation,
                'decision' => DestinationFeasibilityContracts::CAPABILITY_UNSUPPORTED_OR,
            ];
        }
        if ($relation === DestinationFeasibilityContracts::RELATION_SEQUENTIAL) {
            return [
                'version' => self::VERSION,
                'relation' => $relation,
                'decision' => DestinationFeasibilityContracts::CAPABILITY_UNSUPPORTED_SEQUENTIAL,
            ];
        }

        return [
            'version' => self::VERSION,
            'relation' => $relation,
            'decision' => DestinationFeasibilityContracts::CAPABILITY_INVALID,
        ];
    }
}
