<?php
declare(strict_types=1);

/**
 * Platform-scoped keyword → destination / region_code registry (Phase 9-B-13).
 *
 * Each platform_code owns its own mapping table. AgentTour RegionCode tables
 * must not be applied to grp / bbctravel / tourcenter / other platforms.
 */
final class RegionMappingRegistry
{
    public const PLATFORM_AGENTTOUR = 'agenttour';

    /**
     * @var array<string, array<string, array{destination: string, region_code: string}>>
     */
    private $byPlatformKeyword = [];

    /**
     * @param array<string, array<string, array{destination: string, region_code: string}>> $mappingsByPlatform
     */
    public function __construct(array $mappingsByPlatform = [])
    {
        foreach ($mappingsByPlatform as $platformCode => $mappingsByKeyword) {
            foreach ($mappingsByKeyword as $keyword => $mapping) {
                $this->register($platformCode, $keyword, $mapping);
            }
        }
    }

    public static function createDefault(): self
    {
        $registry = new self();
        $registry->registerPlatformDefaults(self::PLATFORM_AGENTTOUR, self::agenttourMappings());

        return $registry;
    }

    /**
     * @param array<string, array{destination: string, region_code: string}> $mappingsByKeyword
     */
    public function registerPlatformDefaults(string $platformCode, array $mappingsByKeyword): void
    {
        foreach ($mappingsByKeyword as $keyword => $mapping) {
            $this->register($platformCode, $keyword, $mapping);
        }
    }

    /**
     * @param array{destination: string, region_code: string}|array<string, string> $mapping
     */
    public function register(string $platformCode, string $keyword, array $mapping): void
    {
        $platform = self::normalizePlatformCode($platformCode);
        $key = self::normalizeKeyword($keyword);
        if ($platform === '' || $key === '') {
            return;
        }

        $destination = isset($mapping['destination']) ? trim((string) $mapping['destination']) : $key;
        $regionCode = isset($mapping['region_code']) ? trim((string) $mapping['region_code']) : '';

        if ($destination === '' || $regionCode === '') {
            return;
        }

        if (!isset($this->byPlatformKeyword[$platform])) {
            $this->byPlatformKeyword[$platform] = [];
        }

        $this->byPlatformKeyword[$platform][$key] = [
            'destination' => $destination,
            'region_code' => $regionCode,
        ];
    }

    public function hasPlatformScope(string $platformCode): bool
    {
        $platform = self::normalizePlatformCode($platformCode);

        return $platform !== '' && isset($this->byPlatformKeyword[$platform]);
    }

    public function hasKeyword(string $platformCode, string $keyword): bool
    {
        $platform = self::normalizePlatformCode($platformCode);
        $key = self::normalizeKeyword($keyword);

        return $platform !== ''
            && $key !== ''
            && isset($this->byPlatformKeyword[$platform][$key]);
    }

    /**
     * @return array{destination: string, region_code: string}
     */
    public function resolve(string $platformCode, string $keyword): array
    {
        $platform = self::normalizePlatformCode($platformCode);
        $key = self::normalizeKeyword($keyword);

        if ($platform === '' || !isset($this->byPlatformKeyword[$platform])) {
            throw new RegionKeywordMapperException(
                RegionKeywordMapperException::PLATFORM_SCOPE_NOT_FOUND,
                'Platform mapping scope not found: ' . $platformCode
            );
        }

        if ($key === '' || !isset($this->byPlatformKeyword[$platform][$key])) {
            throw new RegionKeywordMapperException(
                RegionKeywordMapperException::KEYWORD_NOT_FOUND,
                'Region keyword mapping not found for platform ' . $platform . ': ' . $keyword
            );
        }

        return $this->byPlatformKeyword[$platform][$key];
    }

    /**
     * @return array<string, array<string, array{destination: string, region_code: string}>>
     */
    public function getAllMappings(): array
    {
        return $this->byPlatformKeyword;
    }

    /**
     * @return array<string, array{destination: string, region_code: string}>
     */
    public function getMappingsForPlatform(string $platformCode): array
    {
        $platform = self::normalizePlatformCode($platformCode);
        if ($platform === '' || !isset($this->byPlatformKeyword[$platform])) {
            return [];
        }

        return $this->byPlatformKeyword[$platform];
    }

    /**
     * AgentTour.com.tw RegionCode table — scoped to platform_code=agenttour only.
     *
     * @return array<string, array{destination: string, region_code: string}>
     */
    private static function agenttourMappings(): array
    {
        return [
            '東京' => ['destination' => '東京', 'region_code' => 'J'],
            '日本' => ['destination' => '日本', 'region_code' => 'J'],
            '大阪' => ['destination' => '大阪', 'region_code' => 'J'],
            '北海道' => ['destination' => '北海道', 'region_code' => 'J'],
            '曼谷' => ['destination' => '曼谷', 'region_code' => 'C'],
            '清邁' => ['destination' => '清邁', 'region_code' => 'C'],
            '普吉島' => ['destination' => '普吉島', 'region_code' => 'C'],
            '泰國' => ['destination' => '泰國', 'region_code' => 'C'],
            '首爾' => ['destination' => '首爾', 'region_code' => 'K'],
            '釜山' => ['destination' => '釜山', 'region_code' => 'K'],
            '韓國' => ['destination' => '韓國', 'region_code' => 'K'],
        ];
    }

    private static function normalizePlatformCode(string $platformCode): string
    {
        return strtolower(trim($platformCode));
    }

    private static function normalizeKeyword(string $keyword): string
    {
        return trim($keyword);
    }
}
