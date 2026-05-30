<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceDefinition.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceRegistry.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'UrlTemplateRegistry.php';

/**
 * Resolves catalog source_id / definition to search & detail url_template_id (Phase 9-B-2).
 */
final class UrlTemplateResolver
{
    private UrlTemplateRegistry $templateRegistry;

    public function __construct(?UrlTemplateRegistry $templateRegistry = null)
    {
        $this->templateRegistry = $templateRegistry ?? UrlTemplateRegistry::fromSampleFile();
    }

    public function resolveSearchTemplateId(ProductSourceDefinition $definition): string
    {
        return $definition->getUrlTemplateId();
    }

    public function resolveDetailTemplateId(ProductSourceDefinition $definition): ?string
    {
        if (!$definition->supportsDetail()) {
            return null;
        }

        $searchTemplateId = $definition->getUrlTemplateId();
        if (strpos($searchTemplateId, '_search_') !== false) {
            $detailId = str_replace('_search_', '_detail_', $searchTemplateId);
            if ($this->templateRegistry->hasTemplate($detailId)) {
                return $detailId;
            }
        }

        $fallback = $definition->getSourceId() . '_detail_v1';

        return $this->templateRegistry->hasTemplate($fallback) ? $fallback : null;
    }

    public function resolveSearchTemplateIdBySourceId(string $sourceId, ProductSourceRegistry $registry): ?string
    {
        $definition = $registry->getCatalogSource($sourceId);
        if ($definition === null) {
            return null;
        }

        return $this->resolveSearchTemplateId($definition);
    }

    public function getTemplateRegistry(): UrlTemplateRegistry
    {
        return $this->templateRegistry;
    }
}
