<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'DateParser.php';

$ref = new \DateTimeImmutable('2026-05-25', new \DateTimeZone('Asia/Taipei'));
$parser = new DateParser($ref);

$r1 = $parser->parse('六月三十出發');
hybrid_test_assert($r1->getDateFrom() === '2026-06-30' && $r1->getDateTo() === '2026-06-30', '六月三十 → 2026-06-30');
hybrid_test_assert(($r1->getParserFlags()['date_parsed'] ?? false) === true, '六月三十 flag');

$r2 = $parser->parse('六月底出發');
hybrid_test_assert($r2->getDateFrom() === '2026-06-21' && $r2->getDateTo() === '2026-06-30', '六月底 range');

$r3 = $parser->parse('七月初');
hybrid_test_assert($r3->getDateFrom() === '2026-07-01' && $r3->getDateTo() === '2026-07-10', '七月初 range');

$r4 = $parser->parse('6/30 出發');
hybrid_test_assert($r4->getDateFrom() === '2026-06-30', '6/30 single day');

$r5 = $parser->parse('6月30日');
hybrid_test_assert($r5->getDateFrom() === '2026-06-30', '6月30日');

$r6 = $parser->parse('暑假親子團');
hybrid_test_assert($r6->getDateFrom() === '2026-07-01' && $r6->getDateTo() === '2026-08-31', '暑假 range');

$r7 = $parser->parse('端午連假');
hybrid_test_assert($r7->getDateFrom() !== null && $r7->getDateTo() !== null, '端午 has range');

$r8 = $parser->parse('中秋節');
hybrid_test_assert($r8->getDateFrom() !== null, '中秋 parsed');

$r9 = $parser->parse('過年去哪玩');
hybrid_test_assert($r9->getDateFrom() !== null, '過年 parsed');

$refPast = new \DateTimeImmutable('2026-07-15', new \DateTimeZone('Asia/Taipei'));
$pastParser = new DateParser($refPast);
$r10 = $pastParser->parse('六月三十');
hybrid_test_assert($r10->getDateFrom() === '2027-06-30', 'past ref bumps June 30 to next year');

$refFuzzy = new \DateTimeImmutable('2026-06-06', new \DateTimeZone('Asia/Taipei'));
$fuzzyParser = new DateParser($refFuzzy);

$f1 = $fuzzyParser->parse('大阪近期');
hybrid_test_assert($f1->getDateFrom() === '2026-06-06' && $f1->getDateTo() === '2026-08-05', '大阪近期 today+60');

$f2 = $fuzzyParser->parse('大阪最近');
hybrid_test_assert($f2->getDateFrom() === '2026-06-06' && $f2->getDateTo() === '2026-08-05', '大阪最近 today+60');

$f3 = $fuzzyParser->parse('東京本月');
hybrid_test_assert($f3->getDateFrom() === '2026-06-01' && $f3->getDateTo() === '2026-06-30', '東京本月');

$f4 = $fuzzyParser->parse('東京下月');
hybrid_test_assert($f4->getDateFrom() === '2026-07-01' && $f4->getDateTo() === '2026-07-31', '東京下月');

$f5 = $fuzzyParser->parse('東京月底');
hybrid_test_assert($f5->getDateFrom() === '2026-06-21' && $f5->getDateTo() === '2026-06-30', '東京月底');

$f6 = $fuzzyParser->parse('東京月初');
hybrid_test_assert($f6->getDateFrom() === '2026-06-01' && $f6->getDateTo() === '2026-06-10', '東京月初');

$f7 = $fuzzyParser->parse('東京月中');
hybrid_test_assert($f7->getDateFrom() === '2026-06-11' && $f7->getDateTo() === '2026-06-20', '東京月中');

$f8 = $fuzzyParser->parse('東京暑假');
hybrid_test_assert($f8->getDateFrom() === '2026-07-01' && $f8->getDateTo() === '2026-08-31', '東京暑假');

$f9 = $fuzzyParser->parse('東京寒假');
hybrid_test_assert($f9->getDateFrom() === '2026-01-15' && $f9->getDateTo() === '2026-02-15', '東京寒假');

$f10 = $fuzzyParser->parse('東京明年');
hybrid_test_assert($f10->getDateFrom() === '2027-01-01' && $f10->getDateTo() === '2027-12-31', '東京明年');

$f11 = $fuzzyParser->parse('六月底東京');
hybrid_test_assert($f11->getDateFrom() === '2026-06-21' && $f11->getDateTo() === '2026-06-30', '六月底 still month-specific not standalone 月底');

hybrid_test_finish('DateParser');
