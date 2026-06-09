<?php
declare(strict_types=1);

/**
 * BDS Phase 3 — BdsJsonWriter dry-run tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsJsonWriter.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
  global $failures;
  if (!$cond) {
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
  }
}

function remove_directory_recursive(string $directory): void
{
  if (!is_dir($directory)) {
    return;
  }

  $items = scandir($directory);
  if ($items === false) {
    return;
  }

  foreach ($items as $item) {
    if ($item === '.' || $item === '..') {
      continue;
    }

    $path = $directory . DIRECTORY_SEPARATOR . $item;
    if (is_dir($path)) {
      remove_directory_recursive($path);
      continue;
    }

    unlink($path);
  }

  rmdir($directory);
}

function build_valid_tabs(): array
{
  return [
    'company_profile' => [
      ['company_name' => 'JSON Writer Travel', 'summary' => 'Dry-run demo'],
    ],
    'qa' => [
      ['question' => 'Where are you located?', 'answer' => 'Taipei main office.'],
    ],
    'external_product_links' => [
      ['name' => 'Partner Portal', 'url' => 'https://example.com/partner'],
    ],
    'service_items' => [
      ['service_id' => 'svc_visa', 'name' => 'Visa Assistance'],
    ],
    'special_prices' => [
      ['item_name' => 'Visa Assistance', 'price_amount' => 1200],
    ],
  ];
}

$outputRoot = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'output';
$parser = new BdsMockSheetParser();
$validator = new BdsValidator();
$writer = new BdsJsonWriter($parser, $validator, $outputRoot);

$validTenantSno = 'json_writer_demo_001';
$validTenantDir = $outputRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $validTenantSno;
remove_directory_recursive($validTenantDir);

$validResult = $writer->writeFromMockTabs($validTenantSno, build_valid_tabs(), 'mock_sheet_id_001');
test_assert($validResult['ok'] === true, 'valid data dry-run ok');
test_assert($validResult['dry_run'] === true, 'dry_run flag is true');

$knowledgeDir = $validTenantDir . DIRECTORY_SEPARATOR . 'knowledge';
$reportsDir = $validTenantDir . DIRECTORY_SEPARATOR . 'reports';

$expectedKnowledgeFiles = [
  'company_profile.json',
  'service_qa.json',
  'external_product_links.json',
  'service_items.json',
  'special_prices.json',
];

foreach ($expectedKnowledgeFiles as $filename) {
  $path = $knowledgeDir . DIRECTORY_SEPARATOR . $filename;
  test_assert(is_file($path), 'generated knowledge json: ' . $filename);
}

test_assert(is_file($reportsDir . DIRECTORY_SEPARATOR . 'sync_report.json'), 'generated sync_report.json');
test_assert(is_file($reportsDir . DIRECTORY_SEPARATOR . 'validation_report.json'), 'generated validation_report.json');
test_assert(!is_file($reportsDir . DIRECTORY_SEPARATOR . 'error_report.json'), 'valid run does not create error_report.json');

$companyProfileJson = json_decode((string) file_get_contents($knowledgeDir . DIRECTORY_SEPARATOR . 'company_profile.json'), true);
test_assert(is_array($companyProfileJson), 'company_profile.json decodes');
test_assert(isset($companyProfileJson['profile']) && is_array($companyProfileJson['profile']), 'company_profile uses profile object');
test_assert(!isset($companyProfileJson['items']), 'company_profile does not use items');
test_assert($companyProfileJson['schema_version'] === BdsJsonWriter::SCHEMA_VERSION, 'company_profile schema_version');
test_assert($companyProfileJson['data_category'] === BdsJsonWriter::DATA_CATEGORY, 'company_profile data_category');
test_assert($companyProfileJson['tenant_sno'] === $validTenantSno, 'company_profile tenant_sno parameterized');
test_assert($companyProfileJson['source_tab'] === 'company_profile', 'company_profile source_tab');
test_assert(isset($companyProfileJson['published_at']) && is_string($companyProfileJson['published_at']) && $companyProfileJson['published_at'] !== '', 'company_profile published_at');
test_assert($companyProfileJson['profile']['company_name'] === 'JSON Writer Travel', 'company_profile content preserved');

$serviceQaJson = json_decode((string) file_get_contents($knowledgeDir . DIRECTORY_SEPARATOR . 'service_qa.json'), true);
test_assert(is_array($serviceQaJson), 'service_qa.json decodes');
test_assert(isset($serviceQaJson['items']) && is_array($serviceQaJson['items']), 'service_qa uses items array');
test_assert($serviceQaJson['source_tab'] === 'qa', 'service_qa source_tab is qa');

$syncReport = json_decode((string) file_get_contents($reportsDir . DIRECTORY_SEPARATOR . 'sync_report.json'), true);
test_assert(is_array($syncReport), 'sync_report decodes');
test_assert($syncReport['tenant_sno'] === $validTenantSno, 'sync_report tenant_sno');
test_assert(isset($syncReport['started_at']) && isset($syncReport['finished_at']), 'sync_report timestamps');
test_assert(isset($syncReport['generated_files']) && is_array($syncReport['generated_files']) && count($syncReport['generated_files']) === 5, 'sync_report generated_files');
test_assert($syncReport['status'] === 'dry_run_success', 'sync_report status');

$validationReport = json_decode((string) file_get_contents($reportsDir . DIRECTORY_SEPARATOR . 'validation_report.json'), true);
test_assert(is_array($validationReport), 'validation_report decodes');
test_assert($validationReport['validation_ok'] === true, 'validation_report validation_ok true');
test_assert(isset($validationReport['warnings']) && is_array($validationReport['warnings']), 'validation_report warnings array');
test_assert(isset($validationReport['error_count']) && $validationReport['error_count'] === 0, 'validation_report error_count zero');

$invalidTenantSno = 'json_writer_invalid_002';
$invalidTenantDir = $outputRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $invalidTenantSno;
remove_directory_recursive($invalidTenantDir);

$invalidTabs = build_valid_tabs();
unset($invalidTabs['company_profile']);
$invalidResult = $writer->writeFromMockTabs($invalidTenantSno, $invalidTabs);

test_assert($invalidResult['ok'] === false, 'invalid data dry-run fails');
test_assert(is_file($invalidTenantDir . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'error_report.json'), 'invalid run creates error_report.json');

$errorReport = json_decode((string) file_get_contents($invalidTenantDir . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'error_report.json'), true);
test_assert(is_array($errorReport), 'error_report decodes');
test_assert(isset($errorReport['errors']) && is_array($errorReport['errors']) && count($errorReport['errors']) > 0, 'error_report contains errors');
test_assert(isset($errorReport['generated_at']) && is_string($errorReport['generated_at']) && $errorReport['generated_at'] !== '', 'error_report generated_at');

$invalidKnowledgeDir = $invalidTenantDir . DIRECTORY_SEPARATOR . 'knowledge';
foreach ($expectedKnowledgeFiles as $filename) {
  test_assert(!is_file($invalidKnowledgeDir . DIRECTORY_SEPARATOR . $filename), 'validation fail does not write knowledge json: ' . $filename);
}

$invalidValidationReport = json_decode((string) file_get_contents($invalidTenantDir . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'validation_report.json'), true);
test_assert(is_array($invalidValidationReport), 'invalid validation_report decodes');
test_assert($invalidValidationReport['validation_ok'] === false, 'invalid validation_report validation_ok false');
test_assert($invalidValidationReport['error_count'] > 0, 'invalid validation_report error_count > 0');

$writerSource = file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsJsonWriter.php');
test_assert(is_string($writerSource), 'json writer source readable for hardcode check');
test_assert(strpos($writerSource, 'travel_b') === false, 'json writer does not hardcode travel_b');
test_assert(strpos($writerSource, '5f99b8d665e8444d') === false, 'json writer does not hardcode pilot sno');

if ($failures === 0) {
  fwrite(STDOUT, "OK: test_bds_json_writer (all passed)\n");
  exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
