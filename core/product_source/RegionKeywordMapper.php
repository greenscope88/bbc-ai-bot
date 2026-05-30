<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'RegionKeywordMapperException.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'RegionMappingRegistry.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchUrlBuilder.php';

/**
 * Platform-scoped keyword mapping (Phase 9-B-13).
 *
 * - agenttour: keyword → destination + RegionCode (J/C/K…)
 * - other platforms: keyword only (no region_code mapping)
 */
final class RegionKeywordMapper implements SearchKeywordMapperInterface
{
    private RegionMappingRegistry $registry;

    public function __construct(?RegionMappingRegistry $registry = null)
    {
        $this->registry = $registry ?? RegionMappingRegistry::createDefault();
    }

    public function getRegistry(): RegionMappingRegistry
    {
        return $this->registry;
    }

    /**
     * @return array<string, string> agenttour: destination, region_code, keyword; others: keyword only
     */
    public function map(string $platformCode, string $keyword): array
    {
        $platform = self::normalizePlatformCode($platformCode);
        $normalizedKeyword = trim($keyword);

        if ($normalizedKeyword === '') {
            throw new RegionKeywordMapperException(
                RegionKeywordMapperException::KEYWORD_NOT_FOUND,
                'Region keyword mapping not found: empty keyword'
            );
        }

        if (!$this->registry->hasPlatformScope($platform)) {
            return ['keyword' => $normalizedKeyword];
        }

        $mapped = $this->registry->resolve($platform, $normalizedKeyword);

        return [
            'destination' => $mapped['destination'],
            'region_code' => $mapped['region_code'],
            'keyword' => $normalizedKeyword,
        ];
    }

    public function supportsRegionMapping(string $platformCode): bool
    {
        return $this->registry->hasPlatformScope(self::normalizePlatformCode($platformCode));
    }

    public function resolveRegionCode(string $keyword, string $platformId): ?string
    {
        $platform = self::normalizePlatformCode($platformId);

        if (!$this->registry->hasPlatformScope($platform)) {
            return null;
        }

        try {
            return $this->registry->resolve($platform, $keyword)['region_code'];
        } catch (RegionKeywordMapperException $e) {
            if ($e->getErrorCode() === RegionKeywordMapperException::KEYWORD_NOT_FOUND) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $searchCondition
     * @return array<string, mixed>
     */
    public function applyToSearchCondition(array $searchCondition, string $platformCode, string $keyword): array
    {
        $mapped = $this->map($platformCode, $keyword);
        $out = $searchCondition;

        $out['keyword'] = $mapped['keyword'];

        if (isset($mapped['destination'])) {
            if (!isset($out['destination']) || trim((string) $out['destination']) === '') {
                $out['destination'] = $mapped['destination'];
            }
        }

        if (isset($mapped['region_code'])) {
            if (!isset($out['region_code']) || trim((string) $out['region_code']) === '') {
                $out['region_code'] = $mapped['region_code'];
            }
        } elseif (array_key_exists('region_code', $out) && $this->supportsRegionMapping($platformCode)) {
            unset($out['region_code']);
        }

        if (!isset($out['platform']) || trim((string) $out['platform']) === '') {
            $out['platform'] = self::normalizePlatformCode($platformCode);
        }

        return $out;
    }

    private static function normalizePlatformCode(string $platformCode): string
    {
        return strtolower(trim($platformCode));
    }
}
