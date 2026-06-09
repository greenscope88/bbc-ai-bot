<?php
declare(strict_types=1);

/**
 * BDS Phase 1 — BdsMockSheetParser minimal tests.
 *
 * @see docs/BATS_DATA_SYNC_TEST_PLAN.md T-01, T-09
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsMockSheetParser.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
  global $failures;
  if (!$cond) {
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
  }
}

function build_mock_tabs(): array
{
  return [
    'company_profile' => [
      ['company_name' => 'Sample Travel Co', 'phone' => '02-1111-2222', 'summary' => 'Demo agency'],
    ],
    'qa' => [
      ['question' => 'What are your business hours?', 'answer' => 'Mon-Fri 09:00-18:00', 'enabled' => 'true', 'sort_order' => '10'],
    ],
    'external_product_links' => [
      ['name' => 'Partner Search', 'url' => 'https://example.com/search', 'platform' => 'custom'],
    ],
    'service_items' => [
      ['name' => 'Passport Service', 'price_amount' => '1600', 'price_currency' => 'TWD'],
    ],
    'special_prices' => [
      ['item_name' => 'Passport Service', 'price_amount' => '1500', 'description' => 'Summer promo'],
    ],
  ];
}

$parser = new BdsMockSheetParser();
$tenantSno = 'a1b2c3d4e5f64789';
$result = $parser->parse($tenantSno, build_mock_tabs());

test_assert($result['tenant_sno'] === $tenantSno, 'tenant_sno comes from caller parameter');
test_assert(count(BdsMockSheetParser::REQUIRED_TABS) === 5, 'parser defines 5 required tabs');

foreach (BdsMockSheetParser::REQUIRED_TABS as $tab) {
  test_assert(array_key_exists($tab, $result), 'parsed output contains tab: ' . $tab);
}

test_assert(is_array($result['company_profile']), 'company_profile is an array object');
test_assert(!isset($result['company_profile'][0]), 'company_profile is not a numeric items list');
test_assert($result['company_profile']['company_name'] === 'Sample Travel Co', 'company_profile.company_name parsed');

$itemTabs = ['qa', 'external_product_links', 'service_items', 'special_prices'];
foreach ($itemTabs as $tab) {
  test_assert(isset($result[$tab]) && is_array($result[$tab]), $tab . ' is items array');
  test_assert(isset($result[$tab][0]) && is_array($result[$tab][0]), $tab . ' contains at least one item');
}

test_assert($result['qa'][0]['question'] === 'What are your business hours?', 'qa question parsed');
test_assert($result['qa'][0]['answer'] === 'Mon-Fri 09:00-18:00', 'qa answer parsed');
test_assert($result['qa'][0]['qa_id'] === 'qa_1', 'qa_id auto-generated');
test_assert($result['qa'][0]['enabled'] === true, 'qa enabled coerced to boolean');
test_assert($result['qa'][0]['sort_order'] === 10, 'qa sort_order coerced to integer');

test_assert($result['external_product_links'][0]['link_id'] === 'link_1', 'link_id auto-generated');
test_assert($result['service_items'][0]['service_id'] === 'svc_1', 'service_id auto-generated');
test_assert($result['service_items'][0]['price_amount'] === 1600.0, 'service_items price_amount numeric');

test_assert($result['special_prices'][0]['price_id'] === 'price_1', 'price_id auto-generated');
test_assert($result['special_prices'][0]['price_amount'] === 1500.0, 'special_prices price_amount numeric');

$keyValueTabs = [
  'company_profile' => [
    ['key' => 'company_name', 'value' => 'Key Value Travel'],
    ['key' => 'email', 'value' => 'ops@example.com'],
  ],
  'qa' => [],
  'external_product_links' => [],
  'service_items' => [],
  'special_prices' => [],
];

$keyValueResult = $parser->parse('tenant_key_value_demo', $keyValueTabs);
test_assert($keyValueResult['company_profile']['company_name'] === 'Key Value Travel', 'company_profile key-value rows parsed');
test_assert($keyValueResult['company_profile']['email'] === 'ops@example.com', 'company_profile key-value email parsed');

$otherSno = 'hotel_tenant_99';
$otherResult = $parser->parse($otherSno, build_mock_tabs());
test_assert($otherResult['tenant_sno'] === $otherSno, 'parser works for non-travel_b tenant sno');
test_assert($otherResult['tenant_sno'] !== '5f99b8d665e8444d', 'parser does not force travel_b pilot sno');

$parserSource = file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsMockSheetParser.php');
test_assert(is_string($parserSource), 'parser source readable for hardcode check');
test_assert(strpos($parserSource, 'travel_b') === false, 'parser does not hardcode travel_b');
test_assert(strpos($parserSource, '5f99b8d665e8444d') === false, 'parser does not hardcode pilot sno');

if ($failures === 0) {
  fwrite(STDOUT, "OK: test_bds_mock_sheet_parser (all passed)\n");
  exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
