<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceRegistry.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceUrlResult.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'UrlTemplateRegistry.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'UrlTemplateResolver.php';

/**
 * Builds long search/detail URLs per product source via sample templates (Phase 9-B-2).
 *
 * Does not call ShortUrlService, Host B, Hybrid, or LINE.
 */
final class MultiSourceSearchUrlBuilder
{
    private UrlTemplateRegistry $templateRegistry;

    private UrlTemplateResolver $templateResolver;

    public function __construct(?UrlTemplateRegistry $templateRegistry = null, ?UrlTemplateResolver $templateResolver = null)
    {
        $this->templateRegistry = $templateRegistry ?? UrlTemplateRegistry::fromSampleFile();
        $this->templateResolver = $templateResolver ?? new UrlTemplateResolver($this->templateRegistry);
    }

    /**
     * @param array<string, mixed> $detailContext tour_seq_no (string), item_id (string), etc.
     */
    public function buildSearchUrl(
        string $sourceId,
        string $keyword,
        string $category,
        string $tenantSno,
        ?ProductSourceRegistry $registry = null
    ): ProductSourceUrlResult {
        $definition = $this->resolveDefinition($sourceId, $registry);
        $category = $this->normalizeCategory($category, $definition);

        if ($definition === null || !$definition->supportsSearch()) {
            return new ProductSourceUrlResult(trim($sourceId), $category, null, null);
        }

        $templateId = $this->templateResolver->resolveSearchTemplateId($definition);
        $searchUrl = $this->renderTemplate($templateId, $keyword, $category, $tenantSno, []);

        return new ProductSourceUrlResult(
            $definition->getSourceId(),
            $category,
            $searchUrl,
            null
        );
    }

    /**
     * @param array<string, mixed> $detailContext
     */
    public function buildDetailUrl(
        string $sourceId,
        string $keyword,
        string $category,
        string $tenantSno,
        ?ProductSourceRegistry $registry = null,
        array $detailContext = []
    ): ProductSourceUrlResult {
        $definition = $this->resolveDefinition($sourceId, $registry);
        $category = $this->normalizeCategory($category, $definition);

        if ($definition === null || !$definition->supportsDetail()) {
            return new ProductSourceUrlResult(trim($sourceId), $category, null, null);
        }

        $templateId = $this->templateResolver->resolveDetailTemplateId($definition);
        if ($templateId === null) {
            return new ProductSourceUrlResult($definition->getSourceId(), $category, null, null);
        }

        $detailUrl = $this->renderTemplate($templateId, $keyword, $category, $tenantSno, $detailContext);

        return new ProductSourceUrlResult(
            $definition->getSourceId(),
            $category,
            null,
            $detailUrl
        );
    }

    /**
     * Search + detail URLs for one source.
     *
     * @param array<string, mixed> $detailContext
     */
    public function buildForSource(
        string $sourceId,
        string $keyword,
        string $category,
        string $tenantSno,
        ?ProductSourceRegistry $registry = null,
        array $detailContext = []
    ): ProductSourceUrlResult {
        $search = $this->buildSearchUrl($sourceId, $keyword, $category, $tenantSno, $registry);
        $detail = $this->buildDetailUrl($sourceId, $keyword, $category, $tenantSno, $registry, $detailContext);

        return new ProductSourceUrlResult(
            $search->getSourceId(),
            $search->getProductCategory(),
            $search->getSearchUrl(),
            $detail->getDetailUrl()
        );
    }

    /**
     * All tenant-enabled sources for category (registry required).
     *
     * @param array<string, mixed> $detailContext
     * @return list<ProductSourceUrlResult>
     */
    public function buildAllForTenant(
        ProductSourceRegistry $registry,
        string $keyword,
        string $category,
        array $detailContext = []
    ): array {
        $tenantSno = $registry->getTenantSno();
        $results = [];

        foreach ($registry->getEnabledSourcesByCategory($category) as $definition) {
            $results[] = $this->buildForSource(
                $definition->getSourceId(),
                $keyword,
                $category,
                $tenantSno,
                $registry,
                $detailContext
            );
        }

        return $results;
    }

    private function resolveDefinition(string $sourceId, ?ProductSourceRegistry $registry): ?ProductSourceDefinition
    {
        $key = trim($sourceId);
        if ($key === '') {
            return null;
        }

        if ($registry !== null) {
            $enabled = $registry->getEnabledSource($key);
            if ($enabled !== null) {
                return $enabled;
            }

            return $registry->getCatalogSource($key);
        }

        $catalog = ProductSourceRegistry::fromLocalFiles();

        return $catalog->getCatalogSource($key);
    }

    private function normalizeCategory(string $category, ?ProductSourceDefinition $definition): string
    {
        $needle = trim($category);
        if ($needle !== '') {
            return $needle;
        }
        if ($definition !== null) {
            return $definition->getProductCategory();
        }

        return 'group_tour';
    }

    /**
     * @param array<string, mixed> $detailContext
     */
    private function renderTemplate(
        string $templateId,
        string $keyword,
        string $category,
        string $tenantSno,
        array $detailContext
    ): ?string {
        $templateUrl = $this->templateRegistry->getTemplateUrl($templateId);
        if ($templateUrl === null) {
            return null;
        }

        $vars = $this->buildTemplateVariables($keyword, $category, $tenantSno, $detailContext);

        $out = $templateUrl;
        foreach ($vars as $name => $value) {
            $out = str_replace('{' . $name . '}', $value, $out);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $detailContext
     * @return array<string, string>
     */
    private function buildTemplateVariables(
        string $keyword,
        string $category,
        string $tenantSno,
        array $detailContext
    ): array {
        $keyword = trim($keyword);
        $category = trim($category);
        $tenantSno = trim($tenantSno);

        $tourSeqNo = isset($detailContext['tour_seq_no']) ? trim((string) $detailContext['tour_seq_no']) : '';
        if ($tourSeqNo === '') {
            $tourSeqNo = '0';
        }

        return [
            'sno' => $tenantSno,
            'keyword' => rawurlencode($keyword),
            'keyword_raw' => $keyword,
            'product_category' => $category,
            'area_no' => $this->resolveAreaNo($keyword),
            'category_code' => $this->resolveCategoryCode($category),
            'category_slug' => $this->resolveCategorySlug($category),
            'tour_seq_no' => rawurlencode($tourSeqNo),
        ];
    }

    private function resolveAreaNo(string $keyword): string
    {
        if ($keyword === '') {
            return 'ALL';
        }

        $map = [
            '東京' => 'TOKYO',
            '大阪' => 'OSAKA',
            '北海道' => 'HOKKAIDO',
            '京都' => 'KYOTO',
        ];

        return $map[$keyword] ?? rawurlencode($keyword);
    }

    private function resolveCategoryCode(string $category): string
    {
        $map = [
            'group_tour' => 'tpe',
            'fit' => 'fit',
            'hotel' => 'hotel',
            'car_rental' => 'car',
            'private_group' => 'private',
            'other' => 'other',
        ];

        return $map[$category] ?? 'tpe';
    }

    private function resolveCategorySlug(string $category): string
    {
        $map = [
            'group_tour' => 'group-tour-travel',
            'fit' => 'fit-travel',
            'hotel' => 'hotel-travel',
            'car_rental' => 'car-rental-travel',
            'private_group' => 'private-group-travel',
            'other' => 'other-travel',
        ];

        return $map[$category] ?? 'group-tour-travel';
    }
}
