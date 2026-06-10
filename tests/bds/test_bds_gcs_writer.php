<?php
declare(strict_types=1);

/**
 * BDS Phase 5 — BdsGcsWriter controlled mode tests.
 *
 * T-Phase5-01 Path Mapping
 * T-Phase5-02 Validation Fail Block
 * T-Phase5-03 Controlled Write (preview only)
 * T-Phase5-04 No Shared Write
 * T-Phase5-05 No Drive Access
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsGcsWriter.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsJsonWriter.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsMockSheetParser.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsValidator.php';

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
      ['company_name' => 'GCS Writer Travel', 'summary' => 'Controlled preview demo'],
    ],
    'qa' => [
      ['question' => 'What is included?', 'answer' => 'Guided tour and breakfast.'],
    ],
    'external_product_links' => [
      ['name' => 'Booking Portal', 'url' => 'https://example.com/booking'],
    ],
    'service_items' => [
      ['service_id' => 'svc_tour', 'name' => 'City Tour'],
    ],
    'special_prices' => [
      ['item_name' => 'City Tour', 'price_amount' => 2500],
    ],
  ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function build_knowledge_json_from_tabs(string $tenantSno, array $tabs): array
{
  $parser = new BdsMockSheetParser();
  $validator = new BdsValidator();
  $normalized = $parser->parse($tenantSno, $tabs);
  $validation = $validator->validate($normalized);
  test_assert($validation['ok'] === true, 'fixture tabs validate');

  $publishedAt = gmdate('Y-m-d\TH:i:s\Z');
  $documents = [];

  $profile = isset($normalized['company_profile']) && is_array($normalized['company_profile'])
    ? $normalized['company_profile']
    : [];
  $documents['company_profile.json'] = [
    'schema_version' => BdsJsonWriter::SCHEMA_VERSION,
    'data_category' => BdsJsonWriter::DATA_CATEGORY,
    'tenant_sno' => $tenantSno,
    'source_tab' => 'company_profile',
    'published_at' => $publishedAt,
    'profile' => $profile,
  ];

  $itemTabs = [
    'qa' => 'service_qa.json',
    'external_product_links' => 'external_product_links.json',
    'service_items' => 'service_items.json',
    'special_prices' => 'special_prices.json',
  ];

  foreach ($itemTabs as $tab => $filename) {
    $items = isset($normalized[$tab]) && is_array($normalized[$tab]) ? $normalized[$tab] : [];
    $documents[$filename] = [
      'schema_version' => BdsJsonWriter::SCHEMA_VERSION,
      'data_category' => BdsJsonWriter::DATA_CATEGORY,
      'tenant_sno' => $tenantSno,
      'source_tab' => $tab,
      'published_at' => $publishedAt,
      'items' => $items,
    ];
  }

  return $documents;
}

$outputRoot = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'output';
$writer = new BdsGcsWriter($outputRoot);
$tenantSno = 'gcs_writer_demo_001';
$tenantDir = $outputRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $tenantSno;
remove_directory_recursive($tenantDir);

$knowledgeJson = build_knowledge_json_from_tabs($tenantSno, build_valid_tabs());
$validValidation = ['ok' => true, 'errors' => [], 'warnings' => []];

// T-Phase5-01 Path Mapping
$plan = $writer->buildUploadPlan($tenantSno, $knowledgeJson, $validValidation);
test_assert($plan['ok'] === true, 'T-Phase5-01 upload plan ok');
test_assert(isset($plan['uploads']) && is_array($plan['uploads']) && count($plan['uploads']) === 5, 'T-Phase5-01 five uploads planned');

$expectedPaths = [];
foreach (BdsJsonWriter::TAB_OUTPUT_FILES as $filename) {
  $expectedPaths[] = 'tenants/' . $tenantSno . '/knowledge/' . $filename;
}

$actualPaths = [];
foreach ($plan['uploads'] as $upload) {
  if (is_array($upload) && isset($upload['object_path'])) {
    $actualPaths[] = (string) $upload['object_path'];
  }
}
sort($expectedPaths);
sort($actualPaths);
test_assert($actualPaths === $expectedPaths, 'T-Phase5-01 path mapping exact');

foreach ($plan['uploads'] as $upload) {
  if (!is_array($upload) || !isset($upload['object_path'])) {
    continue;
  }
  test_assert($writer->isAllowedObjectPath($tenantSno, (string) $upload['object_path']), 'T-Phase5-01 allowed path: ' . $upload['object_path']);
}

// T-Phase5-03 Controlled Write (preview only)
$previewResult = $writer->writeControlledPreview($tenantSno, $knowledgeJson, $validValidation);
test_assert($previewResult['ok'] === true, 'T-Phase5-03 controlled preview ok');
test_assert($previewResult['controlled_mode'] === true, 'T-Phase5-03 controlled_mode true');
test_assert($previewResult['dry_run'] === true, 'T-Phase5-03 dry_run true');
test_assert($previewResult['gcs_write_enabled'] === false, 'T-Phase5-03 gcs_write_enabled false');

$gcsDir = $tenantDir . DIRECTORY_SEPARATOR . 'gcs';
$uploadPlanPath = $gcsDir . DIRECTORY_SEPARATOR . 'upload_plan.json';
$payloadPreviewPath = $gcsDir . DIRECTORY_SEPARATOR . 'gcs_payload_preview.json';
test_assert(is_file($uploadPlanPath), 'T-Phase5-03 upload_plan.json written locally');
test_assert(is_file($payloadPreviewPath), 'T-Phase5-03 gcs_payload_preview.json written locally');

$uploadPlanFile = json_decode((string) file_get_contents($uploadPlanPath), true);
$payloadPreviewFile = json_decode((string) file_get_contents($payloadPreviewPath), true);
test_assert(is_array($uploadPlanFile), 'upload_plan.json decodes');
test_assert(is_array($payloadPreviewFile), 'gcs_payload_preview.json decodes');
test_assert(isset($payloadPreviewFile['objects']) && count($payloadPreviewFile['objects']) === 5, 'payload preview has five objects');

// T-Phase5-02 Validation Fail Block
$invalidValidation = [
  'ok' => false,
  'errors' => [
    ['code' => 'BDC_MISSING_TAB', 'tab' => 'company_profile', 'field' => '', 'row_index' => null],
  ],
  'warnings' => [],
];
$failPlan = $writer->buildUploadPlan($tenantSno, $knowledgeJson, $invalidValidation);
test_assert($failPlan['ok'] === false, 'T-Phase5-02 validation fail blocks plan');
test_assert(isset($failPlan['uploads']) && count($failPlan['uploads']) === 0, 'T-Phase5-02 zero uploads on fail');

$invalidTenantSno = 'gcs_writer_invalid_002';
$invalidTenantDir = $outputRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $invalidTenantSno;
remove_directory_recursive($invalidTenantDir);
$failPreview = $writer->writeControlledPreview($invalidTenantSno, $knowledgeJson, $invalidValidation);
test_assert($failPreview['ok'] === false, 'T-Phase5-02 preview blocked on validation fail');
test_assert($failPreview['upload_plan_path'] === null, 'T-Phase5-02 no upload_plan file on fail');
test_assert($failPreview['payload_preview_path'] === null, 'T-Phase5-02 no payload preview file on fail');
test_assert(!is_file($invalidTenantDir . DIRECTORY_SEPARATOR . 'gcs' . DIRECTORY_SEPARATOR . 'upload_plan.json'), 'T-Phase5-02 upload_plan.json not created on fail');

// T-Phase5-04 No Shared Write
test_assert($writer->isAllowedObjectPath($tenantSno, 'shared/travel/knowledge/company_profile.json') === false, 'T-Phase5-04 blocks shared path');
test_assert($writer->isAllowedObjectPath($tenantSno, 'tenants/' . $tenantSno . '/shared/company_profile.json') === false, 'T-Phase5-04 blocks tenant shared segment');

$sharedBlocked = false;
try {
  $writer->buildObjectPath($tenantSno, '../shared/company_profile.json');
} catch (Throwable $e) {
  $sharedBlocked = true;
}
$sharedPath = $writer->buildObjectPath($tenantSno, 'company_profile.json');
test_assert(strpos($sharedPath, 'shared/') === false, 'T-Phase5-04 plan paths exclude shared/');

foreach ($plan['uploads'] as $upload) {
  if (!is_array($upload) || !isset($upload['object_path'])) {
    continue;
  }
  $path = (string) $upload['object_path'];
  test_assert(strpos($path, 'shared/') === false, 'T-Phase5-04 upload path has no shared/: ' . $path);
}

// T-Phase5-05 No Drive Access
$writerSource = file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsGcsWriter.php');
test_assert(is_string($writerSource), 'T-Phase5-05 gcs writer source readable');
test_assert(stripos($writerSource, 'StorageClient') === false, 'T-Phase5-05 no StorageClient');
test_assert(stripos($writerSource, 'putObject') === false, 'T-Phase5-05 no putObject');
test_assert(stripos($writerSource, '->upload(') === false, 'T-Phase5-05 no upload() call');
test_assert(stripos($writerSource, 'Google\\Service\\Drive') === false, 'T-Phase5-05 no Google Drive service');
test_assert(stripos($writerSource, 'private_drive_folder_id') === false, 'T-Phase5-05 no private_drive_folder_id');

test_assert($writer->isAllowedObjectPath($tenantSno, 'drive/folder/file.json') === false, 'T-Phase5-05 blocks drive path');

if ($failures === 0) {
  fwrite(STDOUT, "OK: test_bds_gcs_writer (all passed)\n");
  exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
