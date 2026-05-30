<?php
declare(strict_types=1);

/**
 * Unified URL build result per product source (Phase 9-B-2).
 */
final class ProductSourceUrlResult
{
    /** @var string */
    private $sourceId;

    /** @var string */
    private $productCategory;

    /** @var string|null */
    private $searchUrl;

    /** @var string|null */
    private $detailUrl;

    public function __construct(
        string $sourceId,
        string $productCategory,
        ?string $searchUrl,
        ?string $detailUrl
    ) {
        $this->sourceId = $sourceId;
        $this->productCategory = $productCategory;
        $this->searchUrl = $searchUrl !== null && $searchUrl !== '' ? $searchUrl : null;
        $this->detailUrl = $detailUrl !== null && $detailUrl !== '' ? $detailUrl : null;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getProductCategory(): string
    {
        return $this->productCategory;
    }

    public function getSearchUrl(): ?string
    {
        return $this->searchUrl;
    }

    public function getDetailUrl(): ?string
    {
        return $this->detailUrl;
    }

    public function hasSearchUrl(): bool
    {
        return $this->searchUrl !== null;
    }

    public function hasDetailUrl(): bool
    {
        return $this->detailUrl !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'product_category' => $this->productCategory,
            'search_url' => $this->searchUrl,
            'detail_url' => $this->detailUrl,
        ];
    }
}
