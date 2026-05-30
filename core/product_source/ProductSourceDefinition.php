<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceCatalogContract.php';

/**
 * Immutable product source definition from platform catalog (Phase 9-B-1a / 9-B-1b).
 */
final class ProductSourceDefinition
{
    /** @var string */
    private $sourceId;

    /** @var string */
    private $sourceName;

    /** @var string */
    private $sourceType;

    /** @var string */
    private $adapter;

    /** @var string */
    private $urlTemplateId;

    /** @var list<string> */
    private $productCategories;

    private int $catalogPriority;

    private bool $shortUrlEnabled;

    /** @var string */
    private $shortUrlDomain;

    /** @var string */
    private $domainNamespace;

    private bool $supportsSearch;

    private bool $supportsDetail;

    private bool $supportsInventory;

    private bool $supportsRegistrationFlow;

    private bool $supportsGoogleSheetRegistration;

    private bool $catalogEnabled;

    /**
     * @param list<string> $productCategories
     */
    public function __construct(
        string $sourceId,
        string $sourceName,
        string $sourceType,
        string $adapter,
        string $urlTemplateId,
        array $productCategories,
        int $catalogPriority,
        bool $shortUrlEnabled,
        string $shortUrlDomain,
        string $domainNamespace,
        bool $supportsSearch,
        bool $supportsDetail,
        bool $supportsInventory,
        bool $supportsRegistrationFlow,
        bool $supportsGoogleSheetRegistration,
        bool $catalogEnabled = true
    ) {
        $this->sourceId = $sourceId;
        $this->sourceName = $sourceName;
        $this->sourceType = $sourceType;
        $this->adapter = $adapter;
        $this->urlTemplateId = $urlTemplateId;
        $this->productCategories = $productCategories;
        $this->catalogPriority = $catalogPriority;
        $this->shortUrlEnabled = $shortUrlEnabled;
        $this->shortUrlDomain = $shortUrlDomain;
        $this->domainNamespace = $domainNamespace;
        $this->supportsSearch = $supportsSearch;
        $this->supportsDetail = $supportsDetail;
        $this->supportsInventory = $supportsInventory;
        $this->supportsRegistrationFlow = $supportsRegistrationFlow;
        $this->supportsGoogleSheetRegistration = $supportsGoogleSheetRegistration;
        $this->catalogEnabled = $catalogEnabled;
    }

    /**
     * Build from a contract-validated catalog row.
     *
     * @param array<string, mixed> $row
     */
    public static function fromCatalogRow(array $row): self
    {
        $sourceId = trim((string) ($row['source_id'] ?? ''));
        $sourceName = self::resolveSourceName($row);
        $sourceType = trim((string) ($row['source_type'] ?? ''));
        $adapter = trim((string) ($row['adapter'] ?? ''));
        $urlTemplateId = trim((string) ($row['url_template_id'] ?? ''));
        $categories = self::resolveProductCategories($row);
        $priority = (int) ($row['priority'] ?? 100);

        return new self(
            $sourceId,
            $sourceName,
            $sourceType,
            $adapter,
            $urlTemplateId,
            $categories,
            $priority,
            (bool) ($row['short_url_enabled'] ?? false),
            isset($row['short_url_domain']) ? trim((string) $row['short_url_domain']) : '',
            isset($row['domain_namespace']) ? trim((string) $row['domain_namespace']) : '',
            (bool) ($row['supports_search'] ?? false),
            (bool) ($row['supports_detail'] ?? false),
            (bool) ($row['supports_inventory'] ?? false),
            (bool) ($row['supports_registration_flow'] ?? false),
            (bool) ($row['supports_google_sheet_registration'] ?? false),
            !isset($row['enabled']) || (bool) $row['enabled']
        );
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getSourceName(): string
    {
        return $this->sourceName;
    }

    /**
     * Backward-compatible alias for source_name.
     */
    public function getDisplayName(): string
    {
        return $this->sourceName;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function getAdapter(): string
    {
        return $this->adapter;
    }

    public function getUrlTemplateId(): string
    {
        return $this->urlTemplateId;
    }

    /**
     * Primary category (first listed).
     */
    public function getProductCategory(): string
    {
        return $this->productCategories[0];
    }

    /**
     * @return list<string>
     */
    public function getProductCategories(): array
    {
        return $this->productCategories;
    }

    public function getCatalogPriority(): int
    {
        return $this->catalogPriority;
    }

    public function supportsProductCategory(string $category): bool
    {
        $needle = trim($category);
        if ($needle === '') {
            return false;
        }

        return in_array($needle, $this->productCategories, true);
    }

    public function isShortUrlEnabled(): bool
    {
        return $this->shortUrlEnabled;
    }

    public function getShortUrlDomain(): string
    {
        return $this->shortUrlDomain;
    }

    public function getDomainNamespace(): string
    {
        return $this->domainNamespace;
    }

    public function supportsSearch(): bool
    {
        return $this->supportsSearch;
    }

    public function supportsDetail(): bool
    {
        return $this->supportsDetail;
    }

    public function supportsInventory(): bool
    {
        return $this->supportsInventory;
    }

    public function supportsRegistrationFlow(): bool
    {
        return $this->supportsRegistrationFlow;
    }

    public function supportsGoogleSheetRegistration(): bool
    {
        return $this->supportsGoogleSheetRegistration;
    }

    public function isCatalogEnabled(): bool
    {
        return $this->catalogEnabled;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'source_name' => $this->sourceName,
            'source_type' => $this->sourceType,
            'adapter' => $this->adapter,
            'url_template_id' => $this->urlTemplateId,
            'product_category' => $this->getProductCategory(),
            'product_categories' => $this->productCategories,
            'priority' => $this->catalogPriority,
            'short_url_enabled' => $this->shortUrlEnabled,
            'short_url_domain' => $this->shortUrlDomain,
            'domain_namespace' => $this->domainNamespace,
            'supports_search' => $this->supportsSearch,
            'supports_detail' => $this->supportsDetail,
            'supports_inventory' => $this->supportsInventory,
            'supports_registration_flow' => $this->supportsRegistrationFlow,
            'supports_google_sheet_registration' => $this->supportsGoogleSheetRegistration,
            'catalog_enabled' => $this->catalogEnabled,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function resolveSourceName(array $row): string
    {
        if (isset($row['source_name']) && trim((string) $row['source_name']) !== '') {
            return trim((string) $row['source_name']);
        }
        if (isset($row['display_name']) && trim((string) $row['display_name']) !== '') {
            return trim((string) $row['display_name']);
        }

        return trim((string) ($row['source_id'] ?? ''));
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private static function resolveProductCategories(array $row): array
    {
        if (isset($row['product_categories']) && is_array($row['product_categories'])) {
            $out = [];
            foreach ($row['product_categories'] as $item) {
                if (is_string($item)) {
                    $trimmed = trim($item);
                    if ($trimmed !== '' && !in_array($trimmed, $out, true)) {
                        $out[] = $trimmed;
                    }
                }
            }

            if ($out !== []) {
                return $out;
            }
        }

        if (isset($row['product_category']) && is_string($row['product_category'])) {
            $single = trim($row['product_category']);

            return $single !== '' ? [$single] : [];
        }

        return ['group_tour'];
    }
}
