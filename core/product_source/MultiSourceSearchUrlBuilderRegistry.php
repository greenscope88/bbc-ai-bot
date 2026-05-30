<?php
declare(strict_types=1);

/**
 * Source-instance list for MultiSourceSearchUrlBuilder (Phase 9-B-14).
 *
 * Registry keys are tenant_instance identifiers (e.g. rechoice_agenttour),
 * not platform ids. Platform/template config lives in SearchUrlBuilderRegistry.
 */
final class MultiSourceSearchUrlBuilderRegistry
{
    /** @var list<string> */
    private $sourceInstanceKeys = [];

    public function registerSourceInstance(string $tenantInstanceKey): void
    {
        $key = trim($tenantInstanceKey);
        if ($key === '') {
            return;
        }

        if (!in_array($key, $this->sourceInstanceKeys, true)) {
            $this->sourceInstanceKeys[] = $key;
        }
    }

    /**
     * @param list<string> $tenantInstanceKeys
     */
    public function registerSourceInstances(array $tenantInstanceKeys): void
    {
        foreach ($tenantInstanceKeys as $tenantInstanceKey) {
            $this->registerSourceInstance((string) $tenantInstanceKey);
        }
    }

    /**
     * @return list<string>
     */
    public function getSourceInstanceKeys(): array
    {
        return $this->sourceInstanceKeys;
    }

    public function isEmpty(): bool
    {
        return $this->sourceInstanceKeys === [];
    }

    public function count(): int
    {
        return count($this->sourceInstanceKeys);
    }
}
