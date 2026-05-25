<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchCondition.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ChineseNumberHelper.php';

/**
 * Rule-based date extraction for tour search (Phase 2-A). No Gemini.
 */
final class DateParser
{
    private const PRECISION_SINGLE = 'single';
    private const PRECISION_RANGE = 'range';
    private const PRECISION_MONTH = 'month';
    private const PRECISION_HOLIDAY = 'holiday';
    private const PRECISION_FUZZY = 'fuzzy';

    private \DateTimeImmutable $reference;

    public function __construct(?\DateTimeImmutable $reference = null)
    {
        $this->reference = $reference ?? new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));
    }

    public function parse(string $message, ?SearchCondition $base = null): SearchCondition
    {
        $base = $base ?? SearchCondition::empty($message);
        $text = trim($message);
        if ($text === '') {
            return $base;
        }

        foreach ($this->holidayRules() as $label => $resolver) {
            if (mb_strpos($text, $label, 0, 'UTF-8') !== false) {
                $range = $resolver($this->reference);
                if ($range !== null) {
                    return $this->applyRange($base, $range[0], $range[1], self::PRECISION_HOLIDAY, $label, 0.78);
                }
            }
        }

        if (mb_strpos($text, '暑假', 0, 'UTF-8') !== false) {
            $year = (int) $this->reference->format('Y');
            $from = sprintf('%04d-07-01', $year);
            $to = sprintf('%04d-08-31', $year);

            return $this->applyRange($base, $from, $to, self::PRECISION_FUZZY, '暑假', 0.72);
        }

        if (preg_match('/(\d{1,2})[\/\-\.](\d{1,2})(?:[\/\-\.](\d{2,4}))?/u', $text, $m) === 1) {
            $month = (int) $m[1];
            $day = (int) $m[2];
            $year = isset($m[3]) && $m[3] !== '' ? $this->normalizeYear((int) $m[3]) : (int) $this->reference->format('Y');
            $range = $this->singleDayRange($year, $month, $day);

            return $this->applyRange($base, $range[0], $range[1], self::PRECISION_SINGLE, $m[0], 0.8);
        }

        if (preg_match('/(\d{1,2})月(\d{1,2})日/u', $text, $m) === 1) {
            $year = (int) $this->reference->format('Y');
            $range = $this->singleDayRange($year, (int) $m[1], (int) $m[2]);

            return $this->applyRange($base, $range[0], $range[1], self::PRECISION_SINGLE, $m[0], 0.82);
        }

        if (preg_match('/([一二三四五六七八九十兩]+)月([一二三四五六七八九十兩]+)(?:日|號)?/u', $text, $m) === 1) {
            $month = ChineseNumberHelper::parseInteger($m[1]);
            $day = ChineseNumberHelper::parseInteger($m[2]);
            if ($month !== null && $day !== null && $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                $year = (int) $this->reference->format('Y');
                $range = $this->singleDayRange($year, $month, $day);

                return $this->applyRange($base, $range[0], $range[1], self::PRECISION_SINGLE, $m[0], 0.85);
            }
        }

        if (preg_match('/([一二三四五六七八九十兩\d]{1,3})月(?:份)?(初|中|底|末)/u', $text, $m) === 1) {
            $month = ChineseNumberHelper::parseInteger($m[1]);
            if ($month !== null && $month >= 1 && $month <= 12) {
                $year = (int) $this->reference->format('Y');
                $range = $this->monthPartRange($year, $month, $m[2]);

                return $this->applyRange($base, $range[0], $range[1], self::PRECISION_RANGE, $m[0], 0.8);
            }
        }

        if (preg_match('/(\d{1,2})月(?:份)?(初|中|底|末)/u', $text, $m) === 1) {
            $year = (int) $this->reference->format('Y');
            $range = $this->monthPartRange($year, (int) $m[1], $m[2]);

            return $this->applyRange($base, $range[0], $range[1], self::PRECISION_RANGE, $m[0], 0.78);
        }

        if (preg_match('/([一二三四五六七八九十兩\d]{1,3})月(?:份)?(?!初|中|底|末)/u', $text, $m) === 1) {
            $month = ChineseNumberHelper::parseInteger($m[1]);
            if ($month !== null && $month >= 1 && $month <= 12) {
                $year = (int) $this->reference->format('Y');
                $range = $this->fullMonthRange($year, $month);

                return $this->applyRange($base, $range[0], $range[1], self::PRECISION_MONTH, $m[0], 0.7);
            }
        }

        return $base;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function singleDayRange(int $year, int $month, int $day): array
    {
        $adjusted = $this->adjustYearForPast($year, $month, $day);
        $date = sprintf('%04d-%02d-%02d', $adjusted, $month, $day);

        return [$date, $date];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function monthPartRange(int $year, int $month, string $part): array
    {
        $lastDay = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        if ($part === '初') {
            $from = sprintf('%04d-%02d-01', $year, $month);
            $to = sprintf('%04d-%02d-10', $year, $month);
        } elseif ($part === '中') {
            $from = sprintf('%04d-%02d-11', $year, $month);
            $to = sprintf('%04d-%02d-20', $year, $month);
        } else {
            $from = sprintf('%04d-%02d-21', $year, $month);
            $to = sprintf('%04d-%02d-%02d', $year, $month, $lastDay);
        }

        return $this->adjustRangeYearForPast($from, $to);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function fullMonthRange(int $year, int $month): array
    {
        $lastDay = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = sprintf('%04d-%02d-%02d', $year, $month, $lastDay);

        return $this->adjustRangeYearForPast($from, $to);
    }

    private function adjustYearForPast(int $year, int $month, int $day): int
    {
        try {
            $candidate = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), $this->reference->getTimezone());
        } catch (\Exception $e) {
            return $year;
        }

        $today = $this->reference->setTime(0, 0, 0);
        if ($candidate < $today) {
            return $year + 1;
        }

        return $year;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function adjustRangeYearForPast(string $from, string $to): array
    {
        try {
            $fromDt = new \DateTimeImmutable($from, $this->reference->getTimezone());
            $toDt = new \DateTimeImmutable($to, $this->reference->getTimezone());
        } catch (\Exception $e) {
            return [$from, $to];
        }

        $today = $this->reference->setTime(0, 0, 0);
        if ($toDt < $today) {
            $fromDt = $fromDt->modify('+1 year');
            $toDt = $toDt->modify('+1 year');
        }

        return [$fromDt->format('Y-m-d'), $toDt->format('Y-m-d')];
    }

    private function normalizeYear(int $year): int
    {
        if ($year < 100) {
            return 2000 + $year;
        }

        return $year;
    }

    private function applyRange(
        SearchCondition $base,
        string $from,
        string $to,
        string $precision,
        string $label,
        float $confidenceDelta
    ): SearchCondition {
        return $base
            ->with([
                'date_from' => $from,
                'date_to' => $to,
                'date_precision' => $precision,
                'date_label' => $label,
            ])
            ->flag('date_parsed')
            ->bumpConfidence($confidenceDelta);
    }

    /**
     * @return array<string, callable(\DateTimeImmutable): ?array{0: string, 1: string}> >
     */
    private function holidayRules(): array
    {
        return [
            '端午' => static function (\DateTimeImmutable $ref): ?array {
                $year = (int) $ref->format('Y');

                return self::staticHolidayForYear($year, 'dragon_boat');
            },
            '端午連假' => static function (\DateTimeImmutable $ref): ?array {
                $year = (int) $ref->format('Y');

                return self::staticHolidayForYear($year, 'dragon_boat');
            },
            '中秋' => static function (\DateTimeImmutable $ref): ?array {
                $year = (int) $ref->format('Y');

                return self::staticHolidayForYear($year, 'mid_autumn');
            },
            '中秋節' => static function (\DateTimeImmutable $ref): ?array {
                $year = (int) $ref->format('Y');

                return self::staticHolidayForYear($year, 'mid_autumn');
            },
            '過年' => static function (\DateTimeImmutable $ref): ?array {
                $year = (int) $ref->format('Y');

                return self::staticHolidayForYear($year, 'lunar_new_year');
            },
        ];
    }

    /**
     * Approximate Taiwan holiday windows (extend annually).
     *
     * @return ?array{0: string, 1: string}
     */
    private static function staticHolidayForYear(int $year, string $key): ?array
    {
        /** @var array<int, array<string, array{0: string, 1: string}>> $table */
        static $table = [
            2026 => [
                'dragon_boat' => ['2026-05-30', '2026-06-01'],
                'mid_autumn' => ['2026-09-25', '2026-09-27'],
                'lunar_new_year' => ['2027-02-06', '2027-02-14'],
            ],
            2027 => [
                'dragon_boat' => ['2027-06-18', '2027-06-20'],
                'mid_autumn' => ['2027-10-04', '2027-10-06'],
                'lunar_new_year' => ['2027-02-06', '2027-02-14'],
            ],
        ];

        if (!isset($table[$year][$key])) {
            return null;
        }

        return $table[$year][$key];
    }
}
