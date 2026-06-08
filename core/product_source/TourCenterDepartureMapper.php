<?php
declare(strict_types=1);

/**
 * TourCenter platform departure mapping (Phase 1C).
 *
 * Maps Hybrid departure_city to TourCenter DepartureID query values.
 * Spoken variants 松山/桃園/台北出發 normalize to 台北 -> TPE.
 * Applies only when invoked by platform_id=tourcenter callers.
 */
final class TourCenterDepartureMapper
{
    /** @var list<string> */
    private const TPE_SPOKEN_ALIASES = [
        '台北',
        '松山',
        '桃園',
        '台北出發',
        '松山出發',
        '桃園出發',
    ];

    /**
     * Normalize TourCenter-specific spoken variants to canonical 台北.
     */
    public static function normalizeDepartureCity(?string $departureCity): ?string
    {
        if ($departureCity === null) {
            return null;
        }

        $city = trim($departureCity);
        if ($city === '') {
            return null;
        }

        if (in_array($city, self::TPE_SPOKEN_ALIASES, true)) {
            return '台北';
        }

        return $city;
    }

    /**
     * Map departure_city to TourCenter DepartureID. Empty string = unlimited departure.
     */
    public static function mapToDepartureId(?string $departureCity): string
    {
        $normalized = self::normalizeDepartureCity($departureCity);
        if ($normalized === null) {
            return '';
        }

        $map = [
            '台北' => 'TPE',
            '台中' => 'TCH',
            '台南' => 'TNN',
            '高雄' => 'KHH',
        ];

        return $map[$normalized] ?? '';
    }
}
