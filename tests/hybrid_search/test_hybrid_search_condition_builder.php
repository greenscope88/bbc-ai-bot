<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchConditionBuilder.php';

$ref = new DateTimeImmutable('2026-05-25', new DateTimeZone('Asia/Taipei'));
$builder = new HybridSearchConditionBuilder();

$c1 = $builder->parse('六月底高雄出發的東京親子團', ['reference_date' => $ref]);
hybrid_test_assert($c1->getDateFrom() === '2026-06-21' && $c1->getDateTo() === '2026-06-30', 'builder: 六月底 range');
hybrid_test_assert($c1->getDestination() === '東京', 'builder: 東京 destination');
hybrid_test_assert($c1->getDepartureCity() === '高雄', 'builder: 高雄 departure');
hybrid_test_assert(in_array('親子', $c1->getTravelStyle(), true), 'builder: 親子 style');
hybrid_test_assert(($c1->getParserFlags()['hybrid_built'] ?? false) === true, 'builder: hybrid_built flag');

$c2 = $builder->parse('有沒有歐洲的行程', ['reference_date' => $ref]);
hybrid_test_assert($c2->getArea() === '歐洲', 'builder: 歐洲 area');

hybrid_test_finish('HybridSearchConditionBuilder');
