<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductCategoryContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchUrlBuilderException.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchUrlBuilderRegistry.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SourceInstanceUrlTemplateBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SourceQueryMapper.php';

/**
 * Resolves search URLs from tenant instance registry + URL template builder (Phase 9-B-11).
 *
 * Does not modify SourceInstanceUrlTemplateBuilder. Keyword ??region mapping reserved for future phase.
 */
final class ProductSourceSearchUrlBuilder
{
    private SearchUrlBuilderRegistry $registry;

    private SourceInstanceUrlTemplateBuilder $urlTemplateBuilder;

    /** @var SearchKeywordMapperInterface|null */
    private $keywordMapper;

    public function __construct(
        ?SearchUrlBuilderRegistry $registry = null,
        ?SourceInstanceUrlTemplateBuilder $urlTemplateBuilder = null,
        ?SearchKeywordMapperInterface $keywordMapper = null
    ) {
        $this->registry = $registry ?? new SearchUrlBuilderRegistry();
        $this->urlTemplateBuilder = $urlTemplateBuilder ?? new SourceInstanceUrlTemplateBuilder();
        $this->keywordMapper = $keywordMapper;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   platform: string,
     *   tenant_instance: string,
     *   search_url: string,
     *   product_category: string
     * }
     */
    public function buildSearchUrl(array $input): array
    {
        $tenantInstanceKey = isset($input['tenant_instance']) ? trim((string) $input['tenant_instance']) : '';
        if ($tenantInstanceKey === '') {
            throw new SearchUrlBuilderException(
                'SEARCH_URL_BUILDER_INVALID_INPUT',
                'tenant_instance is required.'
            );
        }

        $instance = $this->registry->getInstanceByTenantKey($tenantInstanceKey);
        if ($instance === null) {
            throw new SearchUrlBuilderException(
                'SEARCH_URL_BUILDER_UNKNOWN_INSTANCE',
                'Unknown tenant_instance: ' . $tenantInstanceKey
            );
        }

        $platformId = isset($input['platform']) ? trim((string) $input['platform']) : '';
        $instancePlatformId = isset($instance['platform_id']) ? trim((string) $instance['platform_id']) : '';
        $resolvedPlatformId = $platformId !== '' ? $platformId : $instancePlatformId;
        if ($resolvedPlatformId === '') {
            throw new SearchUrlBuilderException(
                'SEARCH_URL_BUILDER_INVALID_INPUT',
                'platform is required when instance has no platform_id.'
            );
        }

        if ($platformId !== '' && $instancePlatformId !== '' && $platformId !== $instancePlatformId) {
            throw new SearchUrlBuilderException(
                'SEARCH_URL_BUILDER_INVALID_INPUT',
                'platform does not match tenant_instance platform_id.'
            );
        }

        $platform = $this->registry->getPlatform($resolvedPlatformId);
        if ($platform === null) {
            throw new SearchUrlBuilderException(
                'SEARCH_URL_BUILDER_UNKNOWN_INSTANCE',
                'Unknown platform for tenant_instance: ' . $resolvedPlatformId
            );
        }

        $productCategory = $this->resolveProductCategory($input, $instance);
        ProductCategoryContract::assertValidProductCategory($productCategory);

        $templateId = isset($instance['url_template_id']) ? trim((string) $instance['url_template_id']) : '';
        if ($templateId === '' && isset($platform['url_template_id'])) {
            $templateId = trim((string) $platform['url_template_id']);
        }
        if ($templateId === '') {
            throw new SearchUrlBuilderException(
                'SEARCH_URL_BUILDER_INVALID_INPUT',
                'url_template_id missing for tenant_instance.'
            );
        }

        $urlTemplate = $this->registry->getUrlTemplate($templateId);
        if ($urlTemplate === null) {
            throw new SearchUrlBuilderException(
                'SEARCH_URL_BUILDER_INVALID_INPUT',
                'Unknown url_template_id: ' . $templateId
            );
        }

        $runtimeInstance = $this->applyRuntimeInput($instance, $input, $productCategory, $resolvedPlatformId);

        $this->resolveKeywordRegionCode($input, $platform);

        $built = $this->urlTemplateBuilder->build($platform, $runtimeInstance, $urlTemplate);

        return [
            'platform' => $resolvedPlatformId,
            'tenant_instance' => $tenantInstanceKey,
            'search_url' => $built['entry_url'],
            'product_category' => $productCategory,
        ];
    }

    /**
     * Reserved for Phase 9-B-12+: keyword ??region_code mapping (not implemented).
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $platform
     */
    private function resolveKeywordRegionCode(array $input, array $platform): void
    {
        if ($this->keywordMapper === null) {
            return;
        }

        $keyword = isset($input['keyword']) ? trim((string) $input['keyword']) : '';
        if ($keyword === '') {
            return;
        }

        $platformId = isset($platform['platform_id']) ? trim((string) $platform['platform_id']) : '';
        $this->keywordMapper->resolveRegionCode($keyword, $platformId);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $instance
     */
    private function resolveProductCategory(array $input, array $instance): string
    {
        if (isset($input['product_category']) && trim((string) $input['product_category']) !== '') {
            return trim((string) $input['product_category']);
        }

        $values = $instance['identifier_values'] ?? [];
        if (is_array($values) && isset($values['product_category']) && trim((string) $values['product_category']) !== '') {
            return trim((string) $values['product_category']);
        }

        return 'group_tour';
    }

    /**
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function applyRuntimeInput(
        array $instance,
        array $input,
        string $productCategory,
        string $platformId
    ): array {
        $runtime = $instance;
        $values = isset($instance['identifier_values']) && is_array($instance['identifier_values'])
            ? $instance['identifier_values']
            : [];

        $values['product_category'] = $productCategory;

        if (isset($input['region_code']) && trim((string) $input['region_code']) !== '') {
            $values['region_code'] = trim((string) $input['region_code']);
        }

        $wireKeyword = SourceQueryMapper::resolveWireKeyword($input, $platformId);
        if ($wireKeyword !== '') {
            $values['keyword'] = $wireKeyword;
        }

        $departurePathCode = isset($input['departure_path_code']) ? trim((string) $input['departure_path_code']) : '';
        if ($departurePathCode !== '') {
            $values['departure_path_code'] = $departurePathCode;
        }

        if (array_key_exists('departure_id', $input)) {
            $values['departure_id'] = trim((string) $input['departure_id']);
        }

        $dateFrom = isset($input['date_from']) ? trim((string) $input['date_from']) : '';
        if ($dateFrom !== '') {
            $values['date_from'] = $dateFrom;
        }

        $dateTo = isset($input['date_to']) ? trim((string) $input['date_to']) : '';
        if ($dateTo !== '') {
            $values['date_to'] = $dateTo;
        }

        $runtime['identifier_values'] = $values;

        return $runtime;
    }
}

/**
 * Future keyword ??region mapping (Phase 9-B-12+). Not used in 9-B-11 core.
 */
interface SearchKeywordMapperInterface
{
    public function resolveRegionCode(string $keyword, string $platformId): ?string;
}
