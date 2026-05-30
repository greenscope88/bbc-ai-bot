<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceDefinition.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourcePublishedUrl.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceUrlResult.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ShortUrlProviderInterface.php';

/**
 * Thin layer: ProductSourceUrlResult (long) → ProductSourcePublishedUrl (long + short).
 *
 * Reads catalog short_url_* from ProductSourceDefinition. Does not modify ShortUrlService.
 */
final class ProductSourceUrlPublisher
{
    private ShortUrlProviderInterface $shortUrlProvider;

    public function __construct(ShortUrlProviderInterface $shortUrlProvider)
    {
        $this->shortUrlProvider = $shortUrlProvider;
    }

    public function publish(ProductSourceUrlResult $longUrls, ProductSourceDefinition $definition): ProductSourcePublishedUrl
    {
        $longSearch = $longUrls->getSearchUrl();
        $longDetail = $longUrls->getDetailUrl();

        $context = [
            'source_id' => $definition->getSourceId(),
            'short_url_domain' => $definition->getShortUrlDomain(),
            'domain_namespace' => $definition->getDomainNamespace(),
            'product_category' => $longUrls->getProductCategory(),
        ];

        if (!$definition->isShortUrlEnabled()) {
            return new ProductSourcePublishedUrl(
                $longUrls->getSourceId(),
                $longUrls->getProductCategory(),
                $definition->getDomainNamespace(),
                $longSearch,
                $longDetail,
                $longSearch,
                $longDetail
            );
        }

        $shortSearch = $longSearch;
        if ($longSearch !== null) {
            $shortSearch = $this->shortUrlProvider->shortenSearchUrl($longSearch, $context);
        }

        $shortDetail = $longDetail;
        if ($longDetail !== null) {
            $shortDetail = $this->shortUrlProvider->shortenDetailUrl($longDetail, $context);
        }

        return new ProductSourcePublishedUrl(
            $longUrls->getSourceId(),
            $longUrls->getProductCategory(),
            $definition->getDomainNamespace(),
            $longSearch,
            $longDetail,
            $shortSearch,
            $shortDetail
        );
    }

    /**
     * Publish multiple results with per-source definitions from registry.
     *
     * @param list<ProductSourceUrlResult> $longResults
     * @param array<string, ProductSourceDefinition> $definitionsBySourceId
     * @return list<ProductSourcePublishedUrl>
     */
    public function publishMany(array $longResults, array $definitionsBySourceId): array
    {
        $out = [];
        foreach ($longResults as $result) {
            if (!$result instanceof ProductSourceUrlResult) {
                continue;
            }
            $def = $definitionsBySourceId[$result->getSourceId()] ?? null;
            if ($def === null) {
                continue;
            }
            $out[] = $this->publish($result, $def);
        }

        return $out;
    }
}
