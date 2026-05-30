<?php
declare(strict_types=1);

/**
 * Supplies raw product source catalog JSON document (Phase 9-B-4).
 *
 * @phpstan-type CatalogDocument array{schema_version?: int, catalog_id?: string, sources?: list<array<string, mixed>>}
 */
interface ProductSourceCatalogProviderInterface
{
    /**
     * Fetch catalog root object (not yet validated or mapped to DTOs).
     *
     * @return CatalogDocument
     */
    public function fetchCatalogDocument(): array;

    /**
     * Provider identifier for logging / factory (e.g. local, gcs).
     */
    public function getProviderId(): string;
}
