<?php
declare(strict_types=1);

/**
 * BDS Phase 4.2 — Live Google Sheet pipeline dry-run integration test.
 *
 * Reader -> Parser -> Validator -> JsonWriter (local preview only).
 *
 * Required env:
 * - BDS_TEST_PRIVATE_KNOWLEDGE_SHEET_ID
 * - BDS_TEST_TENANT_SNO
 * - BDS_GOOGLE_APPLICATION_CREDENTIALS (optional; falls back to secrets/)
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsGoogleSheetReader.php';
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

function env_string(string $name): string
{
  $value = getenv($name);
  return is_string($value) ? trim($value) : '';
}

function print_tab_row_counts(array $tabs): void
{
  foreach (BdsGoogleSheetReader::REQUIRED_TABS as $tab) {
    $count = isset($tabs[$tab]) && is_array($tabs[$tab]) ? count($tabs[$tab]) : 0;
    fwrite(STDOUT, "TAB_ROWS|{$tab}|{$count}\n");
  }
}

function print_validation_errors(array $validation): void
{
  if (!isset($validation['errors']) || !is_array($validation['errors'])) {
    return;
  }

  foreach ($validation['errors'] as $error) {
    if (!is_array($error)) {
      continue;
    }

    $code = isset($error['code']) ? (string) $error['code'] : 'UNKNOWN';
    $tab = isset($error['tab']) ? (string) $error['tab'] : '';
    $field = isset($error['field']) ? (string) $error['field'] : '';
    $rowIndex = array_key_exists('row_index', $error) && $error['row_index'] !== null
      ? (string) $error['row_index']
      : '';

    fwrite(STDOUT, "VALIDATION_ERROR|{$code}|{$tab}|{$field}|{$rowIndex}\n");
  }
}

function print_generated_paths(array $result): void
{
  if (isset($result['generated_files']) && is_array($result['generated_files'])) {
    foreach ($result['generated_files'] as $path) {
      if (is_string($path) && $path !== '') {
        fwrite(STDOUT, 'GENERATED|' . basename($path) . "\n");
      }
    }
  }

  if (isset($result['report_files']) && is_array($result['report_files'])) {
    foreach ($result['report_files'] as $path) {
      if (is_string($path) && $path !== '') {
        fwrite(STDOUT, 'REPORT|' . basename($path) . "\n");
      }
    }
  }
}

$sheetId = env_string('BDS_TEST_PRIVATE_KNOWLEDGE_SHEET_ID');
$tenantSno = env_string('BDS_TEST_TENANT_SNO');
$credentialsPath = env_string('BDS_GOOGLE_APPLICATION_CREDENTIALS');

if ($sheetId === '' || $tenantSno === '') {
  fwrite(STDERR, "SKIP: set BDS_TEST_PRIVATE_KNOWLEDGE_SHEET_ID and BDS_TEST_TENANT_SNO for live pipeline dry-run\n");
  exit(0);
}

try {
  $reader = new BdsGoogleSheetReader($credentialsPath !== '' ? $credentialsPath : null);
  $tabs = $reader->readSheet($sheetId);
} catch (Throwable $e) {
  fwrite(STDERR, 'READ_FAILED|' . $e->getCode() . '|' . $e->getMessage() . "\n");
  exit(1);
}

fwrite(STDOUT, "READ_OK|yes\n");
print_tab_row_counts($tabs);

$parser = new BdsMockSheetParser();
$validator = new BdsValidator();
$outputRoot = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'output';
$writer = new BdsJsonWriter($parser, $validator, $outputRoot);

$normalized = $parser->parse($tenantSno, $tabs);
$validation = $validator->validate($normalized);
$result = $writer->writeDryRun($tenantSno, $normalized, $validation, $sheetId);

fwrite(STDOUT, 'VALIDATION_OK|' . (($validation['ok'] ?? false) ? 'yes' : 'no') . "\n");
print_validation_errors($validation);
print_generated_paths($result);

test_assert(is_array($tabs), 'reader returned tabs array');
test_assert(isset($result['dry_run']) && $result['dry_run'] === true, 'writer dry_run flag is true');
test_assert(isset($result['data_category']) && $result['data_category'] === 'tenant_private_knowledge', 'tenant_private_knowledge category');

$knowledgeDir = $outputRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $tenantSno . DIRECTORY_SEPARATOR . 'knowledge';
$reportsDir = $outputRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $tenantSno . DIRECTORY_SEPARATOR . 'reports';

if ($validation['ok'] === true) {
  foreach (BdsJsonWriter::TAB_OUTPUT_FILES as $filename) {
    test_assert(is_file($knowledgeDir . DIRECTORY_SEPARATOR . $filename), 'knowledge json exists: ' . $filename);
  }
  test_assert(!is_file($reportsDir . DIRECTORY_SEPARATOR . 'error_report.json'), 'no error_report on success');
} else {
  test_assert(!is_file($knowledgeDir . DIRECTORY_SEPARATOR . 'company_profile.json'), 'no knowledge json on validation fail');
  test_assert(is_file($reportsDir . DIRECTORY_SEPARATOR . 'error_report.json'), 'error_report exists on validation fail');
}

test_assert(is_file($reportsDir . DIRECTORY_SEPARATOR . 'sync_report.json'), 'sync_report exists');
test_assert(is_file($reportsDir . DIRECTORY_SEPARATOR . 'validation_report.json'), 'validation_report exists');

if ($failures > 0) {
  fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
  exit(1);
}

fwrite(STDOUT, "OK: test_bds_google_sheet_pipeline_dry_run (pipeline completed)\n");
exit(0);
