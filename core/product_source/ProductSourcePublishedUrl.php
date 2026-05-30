<?php
declare(strict_types=1);

/**
 * Long + public (short) URLs after publish step (Phase 9-B-3).
 */
final class ProductSourcePublishedUrl
{
    /** @var string */
    private $sourceId;

    /** @var string */
    private $productCategory;

    /** @var string */
    private $domainNamespace;

    /** @var string|null */
    private $longSearchUrl;

    /** @var string|null */
    private $longDetailUrl;

    /** @var string|null */
    private $shortSearchUrl;

    /** @var string|null */
    private $shortDetailUrl;

    public function __construct(
        string $sourceId,
        string $productCategory,
        string $domainNamespace,
        ?string $longSearchUrl,
        ?string $longDetailUrl,
        ?string $shortSearchUrl,
        ?string $shortDetailUrl
    ) {
        $this->sourceId = $sourceId;
        $this->productCategory = $productCategory;
        $this->domainNamespace = $domainNamespace;
        $this->longSearchUrl = self::normalize($longSearchUrl);
        $this->longDetailUrl = self::normalize($longDetailUrl);
        $this->shortSearchUrl = self::normalize($shortSearchUrl);
        $this->shortDetailUrl = self::normalize($shortDetailUrl);
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getProductCategory(): string
    {
        return $this->productCategory;
    }

    public function getDomainNamespace(): string
    {
        return $this->domainNamespace;
    }

    public function getLongSearchUrl(): ?string
    {
        return $this->longSearchUrl;
    }

    public function getLongDetailUrl(): ?string
    {
        return $this->longDetailUrl;
    }

    public function getShortSearchUrl(): ?string
    {
        return $this->shortSearchUrl;
    }

    public function getShortDetailUrl(): ?string
    {
        return $this->shortDetailUrl;
    }

    public function isSearchShortened(): bool
    {
        return $this->longSearchUrl !== null
            && $this->shortSearchUrl !== null
            && $this->longSearchUrl !== $this->shortSearchUrl;
    }

    public function isDetailShortened(): bool
    {
        return $this->longDetailUrl !== null
            && $this->shortDetailUrl !== null
            && $this->longDetailUrl !== $this->shortDetailUrl;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'product_category' => $this->productCategory,
            'domain_namespace' => $this->domainNamespace,
            'long_search_url' => $this->longSearchUrl,
            'long_detail_url' => $this->longDetailUrl,
            'short_search_url' => $this->shortSearchUrl,
            'short_detail_url' => $this->shortDetailUrl,
        ];
    }

    private static function normalize(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $trimmed = trim($url);

        return $trimmed !== '' ? $trimmed : null;
    }
}
