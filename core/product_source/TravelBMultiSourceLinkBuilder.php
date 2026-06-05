<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'MultiSourceSearchUrlBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'MultiSourceSearchUrlBuilderRegistry.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchConditionContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchUrlBuilderRegistry.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchCondition.php';

/**
 * Phase 9-B-27: travel_b Hybrid SearchCondition → multi-source search URLs for Gemini context.
 */
final class TravelBMultiSourceLinkBuilder
{
    public const TRAVEL_B_SNO = '5f99b8d665e8444d';

    /** @var array<string, mixed> */
    private array $config;

    private ?MultiSourceSearchUrlBuilder $multiSourceBuilder;

    public function __construct(?array $config = null, ?MultiSourceSearchUrlBuilder $multiSourceBuilder = null)
    {
        $this->config = $config ?? self::loadConfig();
        $this->multiSourceBuilder = $multiSourceBuilder;
    }

    public static function isEnabledForSno(string $tenantSno, ?array $config = null): bool
    {
        $cfg = $config ?? self::loadConfig();
        if (!isset($cfg['enabled']) || (bool) $cfg['enabled'] !== true) {
            return false;
        }

        $allowedSno = isset($cfg['tenant_sno']) ? trim((string) $cfg['tenant_sno']) : self::TRAVEL_B_SNO;

        return trim($tenantSno) === $allowedSno;
    }

    /**
     * @return list<array{platform: string, search_url: string}>
     */
    public function buildFromHybridCondition(SearchCondition $condition, string $tenantSno = self::TRAVEL_B_SNO): array
    {
        if (!self::isEnabledForSno($tenantSno, $this->config)) {
            return [];
        }

        $searchDocument = self::hybridConditionToSearchDocument($condition);
        $builder = $this->multiSourceBuilder ?? self::createDefaultMultiSourceBuilder($this->config);

        try {
            $rows = $builder->build($searchDocument);
        } catch (\Throwable $e) {
            return [];
        }

        $links = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $platform = isset($row['platform']) ? trim((string) $row['platform']) : '';
            $searchUrl = isset($row['search_url']) ? trim((string) $row['search_url']) : '';
            if ($platform === '' || $searchUrl === '') {
                continue;
            }
            $links[] = [
                'platform' => $platform,
                'search_url' => $searchUrl,
            ];
        }

        return $links;
    }

    /**
     * @return array<string, mixed>
     */
    public static function hybridConditionToSearchDocument(SearchCondition $condition): array
    {
        $keyword = $condition->getKeyword();
        if ($keyword === null || trim($keyword) === '') {
            $keyword = $condition->getDestination();
        }
        if ($keyword === null || trim($keyword) === '') {
            $keyword = $condition->getArea();
        }
        if ($keyword === null || trim($keyword) === '') {
            $keyword = $condition->getFreeText();
        }

        $document = [
            'keyword' => $keyword !== null ? trim($keyword) : '',
            'product_category' => 'group_tour',
        ];

        $destination = $condition->getDestination();
        if ($destination !== null && trim($destination) !== '') {
            $document['destination'] = trim($destination);
        }

        $departureCity = $condition->getDepartureCity();
        if ($departureCity !== null && trim($departureCity) !== '') {
            $document['departure_city'] = trim($departureCity);
        }
        $dateFrom = $condition->getDateFrom();
        if ($dateFrom !== null && trim($dateFrom) !== '') {
            $document['date_from'] = trim($dateFrom);
        }

        $dateTo = $condition->getDateTo();
        if ($dateTo !== null && trim($dateTo) !== '') {
            $document['date_to'] = trim($dateTo);
        }

        $normalized = SearchConditionContract::normalize($document);
        $normalized['departure_path_code'] = self::resolveBbctravelDeparturePathCode($departureCity);

        return $normalized;
    }

    /**
     * bbctravel /searchlist/{code}/ — 中文出發地 → path code；未指定時預設台北 tpetsa。
     */
    private static function resolveBbctravelDeparturePathCode(?string $departureCity): string
    {
        $city = $departureCity !== null ? trim($departureCity) : '';
        $map = [
            '高雄' => 'khh',
            '台南' => 'tnn',
            '台北' => 'tpetsa',
            '桃園' => 'tpe',
            '松山' => 'tsa',
        ];

        return $map[$city] ?? 'tpetsa';
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function createDefaultMultiSourceBuilder(array $config): MultiSourceSearchUrlBuilder
    {
        $registryData = self::loadRegistryData();
        $searchUrlRegistry = new SearchUrlBuilderRegistry(
            $registryData['platforms'],
            $registryData['instances'],
            $registryData['templates']
        );

        $sourceKeys = isset($config['source_instance_keys']) && is_array($config['source_instance_keys'])
            ? array_values(array_filter(array_map('strval', $config['source_instance_keys'])))
            : [];

        $sourceRegistry = new MultiSourceSearchUrlBuilderRegistry();
        if ($sourceKeys !== []) {
            $sourceRegistry->registerSourceInstances($sourceKeys);
        }

        return new MultiSourceSearchUrlBuilder($sourceRegistry, $searchUrlRegistry);
    }

    /**
     * @return array{
     *   platforms: array<string, array<string, mixed>>,
     *   instances: array<string, array<string, mixed>>,
     *   templates: array<string, array<string, mixed>>
     * }
     */
    private static function loadRegistryData(): array
    {
        $path = dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . 'config'
            . DIRECTORY_SEPARATOR . 'product_source'
            . DIRECTORY_SEPARATOR . 'travel_b_search_registry.php';

        if (!is_file($path)) {
            return [
                'platforms' => [],
                'instances' => [],
                'templates' => [],
            ];
        }

        /** @var mixed $loaded */
        $loaded = require $path;

        if (!is_array($loaded)) {
            return [
                'platforms' => [],
                'instances' => [],
                'templates' => [],
            ];
        }

        return [
            'platforms' => isset($loaded['platforms']) && is_array($loaded['platforms']) ? $loaded['platforms'] : [],
            'instances' => isset($loaded['instances']) && is_array($loaded['instances']) ? $loaded['instances'] : [],
            'templates' => isset($loaded['templates']) && is_array($loaded['templates']) ? $loaded['templates'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadConfig(): array
    {
        $path = dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . 'config'
            . DIRECTORY_SEPARATOR . 'product_source'
            . DIRECTORY_SEPARATOR . 'travel_b_multi_source_links.php';

        if (!is_file($path)) {
            return [
                'enabled' => false,
                'tenant_sno' => self::TRAVEL_B_SNO,
                'source_instance_keys' => [],
            ];
        }

        /** @var mixed $loaded */
        $loaded = require $path;

        return is_array($loaded) ? $loaded : [
            'enabled' => false,
            'tenant_sno' => self::TRAVEL_B_SNO,
            'source_instance_keys' => [],
        ];
    }
}
