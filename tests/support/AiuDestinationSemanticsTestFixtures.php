<?php
declare(strict_types=1);

/**
 * Explicit destination_semantics fixtures for product_search tests (no legacy backfill).
 */
final class AiuDestinationSemanticsTestFixtures
{
    public const REASON_FIXTURE = 'test_fixture_executable';

    /**
     * @return array<string, mixed>
     */
    public static function candidate(
        string $label,
        string $role = 'travel_destination',
        string $status = 'executable',
        string $reason = self::REASON_FIXTURE
    ): array {
        return [
            'label' => $label,
            'semantic_role' => $role,
            'travel_feasibility' => [
                'status' => $status,
                'feasibility_reason' => $reason,
            ],
        ];
    }

    /**
     * @param list<string> $labelsInOrder
     * @return array{destination_relation: string, destination_semantics: list<array<string, mixed>>, destination: list<string>}
     */
    public static function productSearchDestinationPatch(array $labelsInOrder, string $relation = 'single'): array
    {
        $semantics = [];
        foreach ($labelsInOrder as $label) {
            $semantics[] = self::candidate($label);
        }

        return [
            'destination_relation' => $relation,
            'destination_semantics' => $semantics,
            'destination' => $labelsInOrder,
        ];
    }

    /**
     * @param array<string, mixed> $entities
     * @param list<string> $labelsInOrder
     * @return array<string, mixed>
     */
    public static function mergeEntities(array $entities, array $labelsInOrder, string $relation = 'single'): array
    {
        return array_merge($entities, self::productSearchDestinationPatch($labelsInOrder, $relation));
    }

    /**
     * @return array<string, mixed>
     */
    public static function missingDestinationContract(): array
    {
        return [
            'destination' => [],
            'destination_relation' => 'uncertain',
            'destination_semantics' => [],
        ];
    }
}
