<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchCondition.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';

/**
 * Test-only fixtures representing Gemini-normalized Runtime contracts (no utterance parsing).
 */
final class GeminiDerivedSearchConditionFixtures
{
    public static function tokyoLateJuneBudget(): SearchCondition
    {
        return SearchCondition::empty('六月底東京三萬以下')->with([
            'destination' => ['東京'],
            'keyword' => '東京',
            'date_from' => '2026-06-21',
            'date_to' => '2026-06-30',
            'budget_max' => 30000,
        ]);
    }

    public static function tokyoLateJuneTour(): SearchCondition
    {
        return SearchCondition::empty('六月底東京團')->with([
            'destination' => ['東京'],
            'keyword' => '東京',
            'date_from' => '2026-06-21',
            'date_to' => '2026-06-30',
        ]);
    }

    public static function europe(): SearchCondition
    {
        return SearchCondition::empty('歐洲')->with([
            'destination' => ['歐洲'],
            'keyword' => '歐洲',
            'area' => '歐洲',
        ]);
    }

    public static function hokkaidoJuly(): SearchCondition
    {
        return SearchCondition::empty('北海道7月')->with([
            'destination' => ['北海道'],
            'keyword' => '北海道',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]);
    }

    public static function osakaDestinationOnly(): SearchCondition
    {
        return SearchCondition::empty('大阪')->with([
            'destination' => ['大阪'],
            'keyword' => '大阪',
        ]);
    }

    public static function tokyoDestinationOnly(): SearchCondition
    {
        return SearchCondition::empty('東京')->with([
            'destination' => ['東京'],
            'keyword' => '東京',
        ]);
    }

    public static function tokyoTourDestinationOnly(): SearchCondition
    {
        return SearchCondition::empty('東京行程')->with([
            'destination' => ['東京'],
            'keyword' => '東京',
        ]);
    }

    public static function recentTokyo(): SearchCondition
    {
        return SearchCondition::empty('近期東京')->with([
            'destination' => ['東京'],
            'keyword' => '東京',
            'date_from' => '2026-06-06',
            'date_to' => '2026-08-05',
        ]);
    }

    public static function recentOsaka(): SearchCondition
    {
        return SearchCondition::empty('大阪近期')->with([
            'destination' => ['大阪'],
            'keyword' => '大阪',
            'date_from' => '2026-06-06',
            'date_to' => '2026-08-05',
        ]);
    }

    public static function kaohsiungTokyoLateJune(): SearchCondition
    {
        return SearchCondition::empty('高雄東京6月底')->with([
            'destination' => ['東京'],
            'keyword' => '東京',
            'departure_city' => '高雄',
            'date_from' => '2026-06-21',
            'date_to' => '2026-06-30',
        ]);
    }

    public static function tokyoJulyAuthoritativeIntent(): BatsSearchIntent
    {
        return BatsSearchIntent::empty('東京 7月')->with([
            'destination' => ['東京'],
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]);
    }

    public static function tokyoLateJuneBudget50k(): SearchCondition
    {
        return SearchCondition::empty('六月底東京五萬以下')->with([
            'destination' => ['東京'],
            'keyword' => '東京',
            'date_from' => '2026-06-21',
            'date_to' => '2026-06-30',
            'budget_max' => 50000,
        ]);
    }

    public static function kaohsiungFamilyTokyoLateJune(): SearchCondition
    {
        return SearchCondition::empty('六月底高雄出發的東京親子團三萬以下')->with([
            'destination' => ['東京'],
            'keyword' => '東京',
            'departure_city' => '高雄',
            'date_from' => '2026-06-21',
            'date_to' => '2026-06-30',
            'budget_max' => 30000,
            'product_type' => '親子團',
        ]);
    }

    public static function kaohsiungTokyoRecent(): SearchCondition
    {
        return SearchCondition::empty('高雄東京近期')->with([
            'destination' => ['東京'],
            'keyword' => '東京',
            'departure_city' => '高雄',
            'date_from' => '2026-06-06',
            'date_to' => '2026-08-05',
        ]);
    }

    public static function tainanTokyoLateJune(): SearchCondition
    {
        return SearchCondition::empty('台南東京6月底')->with([
            'destination' => ['東京'],
            'keyword' => '東京',
            'departure_city' => '台南',
            'date_from' => '2026-06-21',
            'date_to' => '2026-06-30',
        ]);
    }

    public static function taichungTokyoLateJune(): SearchCondition
    {
        return SearchCondition::empty('台中東京6月底')->with([
            'destination' => ['東京'],
            'keyword' => '東京',
            'departure_city' => '台中',
            'date_from' => '2026-06-21',
            'date_to' => '2026-06-30',
        ]);
    }

    public static function tokyoLateJuneOnly(): SearchCondition
    {
        return SearchCondition::empty('東京6月底')->with([
            'destination' => ['東京'],
            'keyword' => '東京',
            'date_from' => '2026-06-21',
            'date_to' => '2026-06-30',
        ]);
    }

    public static function kaohsiungTokyoDeparture(): SearchCondition
    {
        return SearchCondition::empty('高雄出發東京')->with([
            'destination' => ['東京'],
            'keyword' => '東京',
            'departure_city' => '高雄',
        ]);
    }

    public static function japanFamilyTour(): SearchCondition
    {
        return SearchCondition::empty('日本親子團')->with([
            'destination' => ['日本'],
            'keyword' => '日本',
            'product_type' => '親子團',
        ]);
    }

    public static function tokyoJune30Departure(): SearchCondition
    {
        return SearchCondition::empty('六月三十出發的東京團')->with([
            'destination' => ['東京'],
            'keyword' => '東京',
            'date_from' => '2026-06-30',
            'date_to' => '2026-06-30',
        ]);
    }

    public static function europeTour(): SearchCondition
    {
        return SearchCondition::empty('歐洲團')->with([
            'destination' => ['歐洲'],
            'keyword' => '歐洲',
            'area' => '歐洲',
        ]);
    }

    public static function switzerlandJuly(): SearchCondition
    {
        return SearchCondition::empty('瑞士')->with([
            'destination' => ['瑞士'],
            'keyword' => '瑞士',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-03',
        ]);
    }

    public static function freeTravelGeneric(): SearchCondition
    {
        return SearchCondition::empty('自由行')->with([
            'keyword' => '自由行',
            'product_type' => '自由行',
        ]);
    }

    public static function tokyoClarifyIntent(): BatsSearchIntent
    {
        return BatsSearchIntent::empty('東京')->with([
            'destination' => ['東京'],
            'clarification_required' => true,
            'clarification_reason' => 'date_required',
        ]);
    }

    public static function tokyoTourClarifyIntent(): BatsSearchIntent
    {
        return BatsSearchIntent::empty('東京行程')->with([
            'destination' => ['東京'],
            'clarification_required' => true,
            'clarification_reason' => 'date_required',
        ]);
    }

    public static function hokkaidoClarifyIntent(): BatsSearchIntent
    {
        return BatsSearchIntent::empty('北海道')->with([
            'destination' => ['北海道'],
            'clarification_required' => true,
            'clarification_reason' => 'date_required',
        ]);
    }

    public static function osakaRecentIntent(): BatsSearchIntent
    {
        return BatsSearchIntent::empty('大阪近期')->with([
            'destination' => ['大阪'],
            'date_from' => '2026-06-06',
            'date_to' => '2026-08-05',
        ]);
    }

    public static function kaohsiungTokyoLateJuneIntent(): BatsSearchIntent
    {
        return BatsSearchIntent::empty('高雄東京6月底')->with([
            'destination' => ['東京'],
            'departure_city' => '高雄',
            'date_from' => '2026-06-21',
            'date_to' => '2026-06-30',
        ]);
    }

    public static function tokyoThisMonthIntent(): BatsSearchIntent
    {
        return BatsSearchIntent::empty('東京本月')->with([
            'destination' => ['東京'],
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]);
    }

    public static function hokkaidoSummerIntent(): BatsSearchIntent
    {
        return BatsSearchIntent::empty('北海道暑假')->with([
            'destination' => ['北海道'],
            'date_from' => '2026-07-01',
            'date_to' => '2026-08-31',
        ]);
    }

    public static function kaohsiungTokyoRecentIntent(): BatsSearchIntent
    {
        return BatsSearchIntent::empty('高雄東京近期')->with([
            'destination' => ['東京'],
            'departure_city' => '高雄',
            'date_from' => '2026-06-06',
            'date_to' => '2026-08-05',
        ]);
    }

    public static function songshanTokyoLateJune(): SearchCondition
    {
        return SearchCondition::empty('松山東京6月底')->with([
            'destination' => ['東京'],
            'keyword' => '東京',
            'departure_city' => '松山',
            'date_from' => '2026-06-21',
            'date_to' => '2026-06-30',
        ]);
    }

    public static function tainanTokyoDeparture(): SearchCondition
    {
        return SearchCondition::empty('台南出發東京')->with([
            'destination' => ['東京'],
            'keyword' => '東京',
            'departure_city' => '台南',
        ]);
    }

    public static function tainanTokyoLateJuneBare(): SearchCondition
    {
        return SearchCondition::empty('台南出發東京6月底')->with([
            'destination' => ['東京'],
            'keyword' => '東京',
            'departure_city' => '台南',
            'date_from' => '2026-06-21',
            'date_to' => '2026-06-30',
        ]);
    }

    public static function taichungTokyoDepartureLateJune(): SearchCondition
    {
        return SearchCondition::empty('台中出發東京6月底')->with([
            'destination' => ['東京'],
            'keyword' => '東京',
            'departure_city' => '台中',
            'date_from' => '2026-06-21',
            'date_to' => '2026-06-30',
        ]);
    }

    public static function recentTokyoCondition(): SearchCondition
    {
        return SearchCondition::empty('東京近期')->with([
            'destination' => ['東京'],
            'keyword' => '東京',
            'date_from' => '2026-06-06',
            'date_to' => '2026-08-05',
        ]);
    }
}
