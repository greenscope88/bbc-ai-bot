<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundingAssemblyContext.php';

/**
 * Phase 2-F Step 2-F-1 — assembles conversation_context from injected snapshots.
 *
 * SSOT: docs/BATS_AI_GROUNDING_LAYER.md §15 / §20.
 *
 * Does not call upstream runtimes; only maps values already present in context.
 */
final class GroundingContextBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(GroundingAssemblyContext $context): array
    {
        $memorySlots = $this->mapMemorySnapshot($context->getMemorySnapshot());
        $aiuSlots = $this->mapAiuEntity($context->getAiuProjection());
        $searchSlots = $this->mapBatsSearchIntent($context->getBatsSearchIntent());
        $contextSnapshotSlots = $this->mapAiuContextSnapshot($context->getAiuProjection());

        $merged = $this->mergeConversationSlots($memorySlots, $aiuSlots, $contextSnapshotSlots, $searchSlots);

        $merged['current_requirement'] = $this->composeCurrentRequirement(
            $merged['current_requirement'] ?? null,
            $aiuSlots,
            $context->getCustomerQuery()
        );

        return $this->omitEmptyScalars($merged);
    }

    /**
     * @param array<string, mixed> $memory
     *
     * @return array<string, mixed>
     */
    private function mapMemorySnapshot(array $memory): array
    {
        if ($memory === []) {
            return [];
        }

        $out = [];
        $scalarKeys = [
            'current_requirement',
            'conversation_stage',
            'destination',
            'travel_dates',
            'party_size',
            'budget',
            'duration',
            'ai_summary',
        ];
        foreach ($scalarKeys as $key) {
            if (!array_key_exists($key, $memory)) {
                continue;
            }
            $value = $this->nullableTrimmedString($memory[$key]);
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        foreach (['completed_items', 'outstanding_issues', 'recently_recommended_products'] as $listKey) {
            if (!isset($memory[$listKey]) || !is_array($memory[$listKey])) {
                continue;
            }
            $list = $this->toStringList($memory[$listKey]);
            if ($list !== []) {
                $out[$listKey] = $list;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $aiuProjection
     *
     * @return array<string, mixed>
     */
    private function mapAiuEntity(array $aiuProjection): array
    {
        $entity = isset($aiuProjection['entity']) && is_array($aiuProjection['entity'])
            ? $aiuProjection['entity']
            : [];

        if ($entity === []) {
            return [];
        }

        $out = [];
        $destination = $this->nullableTrimmedString($entity['destination'] ?? null);
        if ($destination !== null) {
            $out['destination'] = $destination;
        }

        $travelDates = $this->nullableTrimmedString(
            $entity['travel_dates'] ?? $entity['dates'] ?? $entity['date_from'] ?? null
        );
        if ($travelDates !== null) {
            $out['travel_dates'] = $travelDates;
        }

        $partySize = $this->nullableTrimmedString(
            $entity['party_size'] ?? $entity['people_count'] ?? null
        );
        if ($partySize !== null) {
            $out['party_size'] = is_numeric($partySize) ? (string) (int) $partySize : $partySize;
        }

        $duration = $this->nullableTrimmedString($entity['duration'] ?? $entity['days'] ?? null);
        if ($duration !== null) {
            $out['duration'] = $duration;
        }

        $budget = $this->nullableTrimmedString($entity['budget'] ?? null);
        if ($budget !== null) {
            $out['budget'] = $budget;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $aiuProjection
     *
     * @return array<string, mixed>
     */
    private function mapAiuContextSnapshot(array $aiuProjection): array
    {
        $snapshot = isset($aiuProjection['context_snapshot']) && is_array($aiuProjection['context_snapshot'])
            ? $aiuProjection['context_snapshot']
            : [];

        return $this->mapMemorySnapshot($snapshot);
    }

    /**
     * @param array<string, mixed>|null $searchIntent
     *
     * @return array<string, mixed>
     */
    private function mapBatsSearchIntent(?array $searchIntent): array
    {
        if ($searchIntent === null || $searchIntent === []) {
            return [];
        }

        $out = [];
        $destination = $this->nullableTrimmedString($searchIntent['destination'] ?? null);
        if ($destination !== null) {
            $out['destination'] = $destination;
        }

        $dateFrom = $this->nullableTrimmedString($searchIntent['date_from'] ?? null);
        if ($dateFrom !== null) {
            $out['travel_dates'] = $dateFrom;
        }

        $peopleCount = $searchIntent['people_count'] ?? null;
        if ($peopleCount !== null && $peopleCount !== '') {
            $out['party_size'] = (string) (int) $peopleCount;
        }

        return $out;
    }

    /**
     * P1 memory → P2 aiu → P3 context_snapshot → P2.5 bats_search_intent (empty slot only).
     *
     * @param array<string, mixed> $memory
     * @param array<string, mixed> $aiu
     * @param array<string, mixed> $contextSnapshot
     * @param array<string, mixed> $search
     *
     * @return array<string, mixed>
     */
    private function mergeConversationSlots(
        array $memory,
        array $aiu,
        array $contextSnapshot,
        array $search
    ): array {
        $merged = [];

        $scalarKeys = [
            'current_requirement',
            'conversation_stage',
            'destination',
            'travel_dates',
            'party_size',
            'budget',
            'duration',
            'ai_summary',
        ];

        foreach ($scalarKeys as $key) {
            foreach ([$memory, $aiu, $contextSnapshot, $search] as $source) {
                if (!isset($source[$key])) {
                    continue;
                }
                $value = $this->nullableTrimmedString($source[$key]);
                if ($value !== null) {
                    $merged[$key] = $value;
                    break;
                }
            }
        }

        foreach (['completed_items', 'outstanding_issues', 'recently_recommended_products'] as $listKey) {
            if (isset($memory[$listKey]) && is_array($memory[$listKey]) && $memory[$listKey] !== []) {
                $merged[$listKey] = $memory[$listKey];
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $aiuSlots
     */
    private function composeCurrentRequirement(
        ?string $memoryRequirement,
        array $aiuSlots,
        string $customerQuery
    ): string {
        if ($memoryRequirement !== null && $memoryRequirement !== '') {
            return $memoryRequirement;
        }

        $parts = [];
        foreach (['destination', 'travel_dates', 'duration', 'party_size', 'budget'] as $key) {
            if (!isset($aiuSlots[$key])) {
                continue;
            }
            $value = $this->nullableTrimmedString($aiuSlots[$key]);
            if ($value !== null) {
                $parts[] = $value;
            }
        }

        if ($parts !== []) {
            return implode(' ', $parts);
        }

        return trim($customerQuery);
    }

    /**
     * @param array<string, mixed> $merged
     *
     * @return array<string, mixed>
     */
    private function omitEmptyScalars(array $merged): array
    {
        $out = [];
        foreach ($merged as $key => $value) {
            if (is_array($value)) {
                if ($value !== []) {
                    $out[$key] = $value;
                }
                continue;
            }
            $text = $this->nullableTrimmedString($value);
            if ($text !== null) {
                $out[$key] = $text;
            }
        }

        return $out;
    }

    /**
     * @param mixed $value
     */
    private function nullableTrimmedString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private function toStringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                continue;
            }
            $text = trim((string) $item);
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }
}
