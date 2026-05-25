<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'KeywordNormalizer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'AreaParser.php';

$normalizer = new KeywordNormalizer();
$area = (new AreaParser())->parse('東京親子樂園');

$r1 = $normalizer->normalize('想去東京迪士尼親子團', $area);
hybrid_test_assert($r1->getDestination() === '東京' || $r1->getKeyword() === '東京', '東京 canonical');
hybrid_test_assert(in_array('親子', $r1->getTravelStyle(), true), '親子 style');
hybrid_test_assert(in_array('迪士尼', $r1->getSpecialTags(), true) || in_array('關東', $r1->getSpecialTags(), true), '東京 tags expanded');

$r2 = $normalizer->normalize('大阪京都家庭旅遊');
hybrid_test_assert($r2->getKeyword() === '大阪' || $r2->getDestination() === '大阪', '大阪 from 京都 alias');
hybrid_test_assert(in_array('親子', $r2->getTravelStyle(), true), 'family maps to 親子 style');

$r3 = $normalizer->normalize('帶長輩輕鬆慢活');
hybrid_test_assert(in_array('長輩', $r3->getTravelStyle(), true), '長輩 style');

$base = SearchCondition::empty('富士山')->with(['destination' => '東京', 'keyword' => '東京']);
$r4b = $normalizer->normalize('富士山雪景', $base);
hybrid_test_assert(in_array('富士山', $r4b->getSpecialTags(), true) || $r4b->getKeyword() === '東京', '富士山 → 東京 tags');

hybrid_test_finish('KeywordNormalizer');
