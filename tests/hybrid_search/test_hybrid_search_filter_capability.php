<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchFilterCapability.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'api_gateway' . DIRECTORY_SEPARATOR . 'production' . DIRECTORY_SEPARATOR . 'TourSearchRequestBuilder.php';

$report = HybridSearchFilterCapability::allowlistReport();
hybrid_test_assert($report['TourDateS'] === true, 'capability: allowlist TourDateS');
hybrid_test_assert($report['TourDateE'] === true, 'capability: allowlist TourDateE');
hybrid_test_assert($report['AmountMax'] === true, 'capability: allowlist AmountMax');
hybrid_test_assert($report['AmountMin'] === true, 'capability: allowlist AmountMin');
hybrid_test_assert($report['Departure'] === true, 'capability: allowlist Departure');

$violations = HybridSearchFilterCapability::detectViolations(
    [
        ['title' => '12月東北樹冰', 'price' => 71800, 'tourDate' => '2026-12-05'],
        ['title' => '東京親子五日', 'price' => 28000, 'tourDate' => '2026-06-25'],
    ],
    [
        'date_from' => '2026-06-21',
        'date_to' => '2026-06-30',
        'budget_max' => 30000,
    ]
);
hybrid_test_assert(count($violations) >= 1, 'capability: detects out-of-range item');
hybrid_test_assert(($violations[0]['type'] ?? '') === 'date_after_range' || ($violations[0]['type'] ?? '') === 'price_above_max', 'capability: violation type');

$effective = HybridSearchFilterCapability::filterLikelyEffective(
    [['title' => '東京團', 'price' => 25000, 'tourDate' => '2026-06-22']],
    ['date_from' => '2026-06-21', 'date_to' => '2026-06-30', 'budget_max' => 30000]
);
hybrid_test_assert($effective === true, 'capability: in-range items effective');

hybrid_test_finish('Hybrid search filter capability');
