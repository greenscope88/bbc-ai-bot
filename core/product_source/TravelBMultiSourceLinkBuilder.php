<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'MultiSourceSearchUrlBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'MultiSourceSearchUrlBuilderRegistry.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchConditionContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SourceQueryMapper.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchUrlBuilderRegistry.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantSourceRuntimeBridge.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchCondition.php';

/**
 * Phase 9-B-27: travel_b Hybrid SearchCondition → multi-source search URLs for Gemini context.
 */
final class TravelBMultiSourceLinkBuilder
{
    public const TRAVEL_B_SNO = '5f99b8d665e8444d';

    /** @var array<string, string> */
    private const PRODUCT_TYPE_TO_CATEGORY = [
        '自由行' => 'fit',
        '跟團' => 'group_tour',
        '半自助' => 'fit',
        '包車' => 'car_rental',
        '郵輪' => 'cruise',
        '團體' => 'group_tour',
        '迷你團' => 'mini_group',
    ];

    /** @var array<string, mixed> */
    private array $config;

    private ?MultiSourceSearchUrlBuilder $multiSourceBuilder;

    private ?TenantSourceRuntimeBridge $runtimeBridge;

    public function __construct(
        ?array $config = null,
        ?MultiSourceSearchUrlBuilder $multiSourceBuilder = null,
        ?TenantSourceRuntimeBridge $runtimeBridge = null
    ) {
        $this->config = $config ?? self::loadConfig();
        $this->multiSourceBuilder = $multiSourceBuilder;
        $this->runtimeBridge = $runtimeBridge;
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

        $runtimeConfig = $this->resolveRuntimeMultiSourceConfig($tenantSno);
        if (($runtimeConfig['enabled'] ?? false) !== true) {
            return [];
        }

        $sourceKeys = $runtimeConfig['source_instance_keys'] ?? [];
        if (!is_array($sourceKeys) || $sourceKeys === []) {
            return [];
        }

        $builderConfig = array_merge($this->config, [
            'tenant_sno' => trim((string) ($runtimeConfig['tenant_sno'] ?? $tenantSno)),
            'source_instance_keys' => array_values(array_filter(array_map('strval', $sourceKeys))),
            'enabled' => true,
        ]);

        $searchDocument = self::hybridConditionToSearchDocument($condition);
        $builder = $this->multiSourceBuilder ?? self::createDefaultMultiSourceBuilder($builderConfig);

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
        $mustHave = $condition->getMustHave();

        $keyword = $condition->getKeyword();
        $keywordTrimmed = ($keyword !== null && trim($keyword) !== '') ? trim($keyword) : '';

        $productType = $condition->getProductType();
        $productCategory = 'group_tour';
        if ($productType !== null && isset(self::PRODUCT_TYPE_TO_CATEGORY[$productType])) {
            $productCategory = self::PRODUCT_TYPE_TO_CATEGORY[$productType];
        }

        $document = [
            'keyword' => $keywordTrimmed,
            'product_category' => $productCategory,
        ];

        $destination = $condition->getDestination();
        if ($destination !== null && trim($destination) !== '') {
            $document['destination'] = trim($destination);
        }

        $area = $condition->getArea();
        if ($area !== null && trim($area) !== '') {
            $document['area'] = trim($area);
        }

        $freeText = $condition->getFreeText();
        if ($freeText !== null && trim($freeText) !== '') {
            $document['free_text'] = trim($freeText);
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

        $budgetMin = $condition->getBudgetMin();
        if ($budgetMin !== null && $budgetMin > 0) {
            $document['budget_min'] = $budgetMin;
        }
        $budgetMax = $condition->getBudgetMax();
        if ($budgetMax !== null && $budgetMax > 0) {
            $document['budget_max'] = $budgetMax;
        }

        $duration = $condition->getDuration();
        if ($duration !== null && trim($duration) !== '') {
            $document['duration'] = trim($duration);
        }

        $peopleCount = $condition->getPeopleCount();
        if ($peopleCount !== null && $peopleCount > 0) {
            $document['people_count'] = $peopleCount;
        }

        $peopleLabel = $condition->getPeopleLabel();
        if ($peopleLabel !== null && trim($peopleLabel) !== '') {
            $document['people_label'] = trim($peopleLabel);
        }

        if ($productType !== null && trim($productType) !== '') {
            $document['product_type'] = trim($productType);
        }

        $travelStyle = $condition->getTravelStyle();
        if ($travelStyle !== []) {
            $document['travel_style'] = $travelStyle;
        }

        if ($mustHave !== []) {
            $document['must_have'] = $mustHave;
        }

        $normalized = SearchConditionContract::normalize($document);
        $normalized['departure_path_code'] = self::resolveBbctravelDeparturePathCode($departureCity);
        $normalized = SourceQueryMapper::enrichSearchDocument($normalized);

        return $normalized;
    }

    /**
     * bbctravel /searchlist/{code}/ — 中文出發地 → path code；未指定時 all（不限出發地）。
     */
    private static function resolveBbctravelDeparturePathCode(?string $departureCity): string
    {
        $city = $departureCity !== null ? trim($departureCity) : '';
        if ($city === '') {
            return 'all';
        }

        $map = [
            '台北' => 'tpetsa',
            '桃園' => 'tpe',
            '松山' => 'tsa',
            '台中' => 'RMG',
            '高雄' => 'khh',
            '台南' => 'tnn',
        ];

        return $map[$city] ?? 'all';
    }

    /**
     * @return array{enabled: bool, tenant_sno: string, source_instance_keys: list<string>, resolver_id: string}
     */
    private function resolveRuntimeMultiSourceConfig(string $tenantSno): array
    {
        $bridge = $this->runtimeBridge ?? new TenantSourceRuntimeBridge();

        return $bridge->resolveMultiSourceConfig($tenantSno);
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
