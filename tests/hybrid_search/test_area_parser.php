<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'AreaParser.php';

$parser = new AreaParser();

$r1 = $parser->parse('你們有沒有歐洲的行程');
hybrid_test_assert($r1->getArea() === '歐洲' && $r1->getKeyword() === '歐洲', '歐洲 area+keyword');

$r2 = $parser->parse('有沒有東歐團');
hybrid_test_assert($r2->getArea() === '歐洲' && $r2->getDestination() === '東歐', '東歐 under 歐洲');

$r3 = $parser->parse('想去西歐');
hybrid_test_assert($r3->getDestination() === '西歐', '西歐');

$r4 = $parser->parse('日本團');
hybrid_test_assert($r4->getArea() === '日本' && $r4->getKeyword() === '日本', '日本');

$r5 = $parser->parse('東京自由行');
hybrid_test_assert($r5->getDestination() === '東京' && $r5->getArea() === '日本', '東京');

$r6 = $parser->parse('關西行程');
hybrid_test_assert($r6->getDestination() === '關西', '關西');

$r7 = $parser->parse('北海道賞雪');
hybrid_test_assert($r7->getDestination() === '北海道', '北海道');

$r8 = $parser->parse('韓國團');
hybrid_test_assert($r8->getArea() === '韓國', '韓國');

$r9 = $parser->parse('東南亞行程');
hybrid_test_assert($r9->getArea() === '東南亞', '東南亞');

hybrid_test_finish('AreaParser');
