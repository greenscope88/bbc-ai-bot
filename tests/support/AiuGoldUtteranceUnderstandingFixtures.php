<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuDestinationSemanticsTestFixtures.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiuPromptRequest.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiuGeminiUnderstandingClientStub.php';

/**
 * Gold regression utterance → full Gemini structured fixtures (tests only).
 */
final class AiuGoldUtteranceUnderstandingFixtures
{
    /** @return callable(AiuPromptRequest): array */
    public static function resolver(): callable
    {
        return static function (AiuPromptRequest $request): array {
            $text = trim($request->getCustomerUtterance());
            $fixture = self::fixtureForUtterance($text);
            if ($fixture !== null) {
                return $fixture;
            }

            return AiuGeminiUnderstandingClientStub::defaultResolver($request);
        };
    }

    /** @return array<string, mixed>|null */
    private static function fixtureForUtterance(string $utterance): ?array
    {
        $map = self::fixtureMap();

        if (isset($map[$utterance])) {
            return $map[$utterance];
        }

        if (mb_strpos($utterance, 'BATS測試', 0, 'UTF-8') === 0) {
            $stripped = trim(mb_substr($utterance, mb_strlen('BATS測試', 'UTF-8'), null, 'UTF-8'));

            return $map[$stripped] ?? null;
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> */
    private static function fixtureMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $map = [
            '北海道 8月' => self::productSearch(
                [
                    'date_range' => ['from' => '2026-08-01', 'to' => '2026-08-31'],
                    'date_expression' => '8月',
                    'date_from' => '2026-08-01',
                    'date_to' => '2026-08-31',
                ],
                ['北海道']
            ),
            '北海道 9月出發' => self::productSearch(
                [
                    'date_range' => ['from' => '2026-09-01', 'to' => '2026-09-30'],
                    'date_expression' => '9月',
                    'date_from' => '2026-09-01',
                    'date_to' => '2026-09-30',
                ],
                ['北海道']
            ),
            '北海道 2026年9月出發' => self::productSearch(
                [
                    'date_range' => ['from' => '2026-09-01', 'to' => '2026-09-30'],
                    'date_expression' => '2026年9月',
                    'date_from' => '2026-09-01',
                    'date_to' => '2026-09-30',
                ],
                ['北海道']
            ),
            '北海道 9月初出發' => self::productSearch(
                [
                    'date_range' => ['from' => '2026-09-01', 'to' => '2026-09-10'],
                    'date_expression' => '9月初',
                    'date_from' => '2026-09-01',
                    'date_to' => '2026-09-10',
                ],
                ['北海道']
            ),
            '北海道 9月中出發' => self::productSearch(
                [
                    'date_range' => ['from' => '2026-09-11', 'to' => '2026-09-20'],
                    'date_expression' => '9月中',
                    'date_from' => '2026-09-11',
                    'date_to' => '2026-09-20',
                ],
                ['北海道']
            ),
            '北海道 9月底出發' => self::productSearch(
                [
                    'date_range' => ['from' => '2026-09-21', 'to' => '2026-09-30'],
                    'date_expression' => '9月底',
                    'date_from' => '2026-09-21',
                    'date_to' => '2026-09-30',
                ],
                ['北海道']
            ),
            '北海道' => self::productSearch([], ['北海道'], 'single', true, 'missing_travel_dates'),
            'BATS測試 我想去北海道玩5天，9月出發' => self::productSearch(
                [
                    'duration' => '5日',
                    'duration_days' => 5,
                    'date_range' => ['from' => '2026-09-01', 'to' => '2026-09-30'],
                    'date_expression' => '9月',
                    'date_from' => '2026-09-01',
                    'date_to' => '2026-09-30',
                ],
                ['北海道']
            ),
            '我想去北海道玩5天，9月出發' => self::productSearch(
                [
                    'duration' => '5日',
                    'duration_days' => 5,
                    'date_range' => ['from' => '2026-09-01', 'to' => '2026-09-30'],
                    'date_expression' => '9月',
                    'date_from' => '2026-09-01',
                    'date_to' => '2026-09-30',
                ],
                ['北海道']
            ),
            '火星五日遊 8月' => self::marsAugust(),
            '火星五日遊8月' => self::marsAugust(),
            '我想去東京自由行' => self::productSearch([], ['東京'], 'single', true, 'missing_travel_dates'),
            '大阪 八月' => self::productSearch(
                [
                    'date_range' => ['from' => '2026-08-01', 'to' => '2026-08-31'],
                    'date_expression' => '八月',
                    'date_from' => '2026-08-01',
                    'date_to' => '2026-08-31',
                ],
                ['大阪']
            ),
            '大阪 7月五日遊' => self::productSearch(
                [
                    'duration' => '5日',
                    'duration_days' => 5,
                    'date_range' => ['from' => '2026-07-01', 'to' => '2026-07-31'],
                    'date_expression' => '7月',
                    'date_from' => '2026-07-01',
                    'date_to' => '2026-07-31',
                ],
                ['大阪']
            ),
            '大阪7月' => self::productSearch(
                [
                    'date_range' => ['from' => '2026-07-01', 'to' => '2026-07-31'],
                    'date_expression' => '7月',
                    'date_from' => '2026-07-01',
                    'date_to' => '2026-07-31',
                ],
                ['大阪']
            ),
            '大阪' => self::productSearch([], ['大阪'], 'single', true, 'missing_travel_dates'),
        ];

        return $map;
    }

    /**
     * @param array<string, mixed> $entities
     * @param list<string> $labels
     * @return array<string, mixed>
     */
    private static function productSearch(
        array $entities,
        array $labels,
        string $relation = 'single',
        bool $clarificationRequired = false,
        string $clarificationReason = ''
    ): array {
        if ($labels === [] && !array_key_exists('destination_semantics', $entities)) {
            $entities = AiuDestinationSemanticsTestFixtures::missingDestinationContract();
        } elseif ($labels !== []) {
            $entities = AiuDestinationSemanticsTestFixtures::mergeEntities($entities, $labels, $relation);
        }

        return [
            'intent' => 'product_search',
            'entities' => $entities,
            'confidence' => $clarificationRequired ? 0.5 : 0.92,
            'clarification' => [
                'required' => $clarificationRequired,
                'reason' => $clarificationReason,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function marsAugust(): array
    {
        return [
            'intent' => 'product_search',
            'entities' => [
                'destination_relation' => 'single',
                'destination' => ['火星'],
                'destination_semantics' => [
                    [
                        'label' => '火星',
                        'semantic_role' => 'travel_destination',
                        'travel_feasibility' => [
                            'status' => 'non_executable',
                            'feasibility_reason' => 'not_a_travel_destination',
                        ],
                    ],
                ],
                'duration' => '5日',
                'duration_days' => 5,
                'date_range' => ['from' => '2026-08-01', 'to' => '2026-08-31'],
                'date_expression' => '8月',
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-31',
            ],
            'confidence' => 0.92,
            'clarification' => ['required' => false, 'reason' => ''],
        ];
    }
}
