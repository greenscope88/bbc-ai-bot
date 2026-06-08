<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'MultiSourceSearchUrlBuilderException.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'MultiSourceSearchUrlBuilderRegistry.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'RegionKeywordMapper.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TourCenterDepartureMapper.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchUrlBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchUrlBuilderRegistry.php';

/**
 * Builds search URL lists across registered source instances (Phase 9-B-14).
 *
 * Flow: SearchCondition → Platform Mapper (RegionKeywordMapper) → SearchUrlBuilder → URL list.
 * Does not fetch pages, call APIs, or run Gemini/LINE/Publisher.
 */
final class MultiSourceSearchUrlBuilder
{
    private MultiSourceSearchUrlBuilderRegistry $sourceInstanceRegistry;

    private SearchUrlBuilderRegistry $searchUrlBuilderRegistry;

    private ProductSourceSearchUrlBuilder $searchUrlBuilder;

    private RegionKeywordMapper $keywordMapper;

    public function __construct(
        MultiSourceSearchUrlBuilderRegistry $sourceInstanceRegistry,
        SearchUrlBuilderRegistry $searchUrlBuilderRegistry,
        ?ProductSourceSearchUrlBuilder $searchUrlBuilder = null,
        ?RegionKeywordMapper $keywordMapper = null
    ) {
        $this->sourceInstanceRegistry = $sourceInstanceRegistry;
        $this->searchUrlBuilderRegistry = $searchUrlBuilderRegistry;
        $this->keywordMapper = $keywordMapper ?? new RegionKeywordMapper();
        $this->searchUrlBuilder = $searchUrlBuilder ?? new ProductSourceSearchUrlBuilder(
            $searchUrlBuilderRegistry,
            null,
            $this->keywordMapper
        );
    }

    /**
     * @param array<string, mixed> $searchCondition
     * @return list<array{platform: string, tenant_instance: string, search_url: string}>
     */
    public function build(array $searchCondition): array
    {
        if ($this->sourceInstanceRegistry->isEmpty()) {
            throw new MultiSourceSearchUrlBuilderException(
                MultiSourceSearchUrlBuilderException::NO_SOURCE_INSTANCE,
                'No source instance registered for multi-source search.'
            );
        }

        $results = [];
        foreach ($this->sourceInstanceRegistry->getSourceInstanceKeys() as $tenantInstanceKey) {
            $results[] = $this->buildForSourceInstance($tenantInstanceKey, $searchCondition);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $searchCondition
     * @return array{platform: string, tenant_instance: string, search_url: string}
     */
    private function buildForSourceInstance(string $tenantInstanceKey, array $searchCondition): array
    {
        $instance = $this->searchUrlBuilderRegistry->getInstanceByTenantKey($tenantInstanceKey);
        if ($instance === null) {
            throw new MultiSourceSearchUrlBuilderException(
                MultiSourceSearchUrlBuilderException::UNKNOWN_SOURCE_INSTANCE,
                'Unknown source instance: ' . $tenantInstanceKey
            );
        }

        $platformId = isset($instance['platform_id']) ? trim((string) $instance['platform_id']) : '';
        if ($platformId === '') {
            throw new MultiSourceSearchUrlBuilderException(
                MultiSourceSearchUrlBuilderException::UNKNOWN_SOURCE_INSTANCE,
                'Source instance missing platform_id: ' . $tenantInstanceKey
            );
        }

        $keyword = isset($searchCondition['keyword']) ? trim((string) $searchCondition['keyword']) : '';
        $buildInput = $this->prepareBuildInput($searchCondition, $platformId, $keyword);
        $buildInput['tenant_instance'] = $tenantInstanceKey;
        $buildInput['platform'] = $platformId;

        $built = $this->searchUrlBuilder->buildSearchUrl($buildInput);

        return [
            'platform' => $built['platform'],
            'tenant_instance' => $built['tenant_instance'],
            'search_url' => $built['search_url'],
        ];
    }

    /**
     * Delegates keyword handling to platform-scoped mapper; unknown agenttour keywords do not abort.
     *
     * @param array<string, mixed> $searchCondition
     * @return array<string, mixed>
     */
    private function prepareBuildInput(array $searchCondition, string $platformId, string $keyword): array
    {
        $input = $searchCondition;

        if ($keyword !== '') {
            try {
                $input = $this->keywordMapper->applyToSearchCondition($searchCondition, $platformId, $keyword);
            } catch (RegionKeywordMapperException $e) {
                if ($e->getErrorCode() !== RegionKeywordMapperException::KEYWORD_NOT_FOUND) {
                    throw $e;
                }

                $input = $searchCondition;
                $input['keyword'] = $keyword;
            }
        }

        if ($platformId === 'tourcenter') {
            $departureCity = null;
            if (isset($input['departure_city'])) {
                $rawCity = trim((string) $input['departure_city']);
                if ($rawCity !== '') {
                    $departureCity = $rawCity;
                }
            }
            $input['departure_id'] = TourCenterDepartureMapper::mapToDepartureId($departureCity);
        }

        return $input;
    }
}
