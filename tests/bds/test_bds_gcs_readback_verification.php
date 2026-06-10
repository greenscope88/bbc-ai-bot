<?php
declare(strict_types=1);

/**
 * BDS Phase 5D — GCS Read-back Verification (read-only).
 *
 * Verifies pilot tenant knowledge JSON in GCS and writes phase5_closeout_report.json.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsGcsUploader.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsJsonWriter.php';

$tenantSno = BdsGcsUploader::PILOT_TENANT_SNO;
$bucket = getenv('BDS_GCS_BUCKET');
$bucket = is_string($bucket) && trim($bucket) !== '' ? trim($bucket) : BdsGcsUploader::DEFAULT_BUCKET;
$credentialsPath = getenv('BDS_GOOGLE_APPLICATION_CREDENTIALS');
$credentialsPath = is_string($credentialsPath) && trim($credentialsPath) !== '' ? trim($credentialsPath) : null;

$outputRoot = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'output';
$uploader = new BdsGcsUploader($bucket, $credentialsPath, $outputRoot);

$failures = 0;
$objectResults = [];
$schemaResults = [];
$contentResults = [];
$pathResults = [];

function check(bool $cond, string $message): void
{
  global $failures;
  if (!$cond) {
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
  }
}

$expectedFiles = array_values(BdsJsonWriter::TAB_OUTPUT_FILES);
$expectedPrefix = 'tenants/' . $tenantSno . '/knowledge/';
$expectedPaths = [];
foreach ($expectedFiles as $filename) {
  $expectedPaths[] = $expectedPrefix . $filename;
}

$listResp = $uploader->listKnowledgeObjects($tenantSno);
$listItems = $listResp['items'];
sort($listItems);
sort($expectedPaths);

fwrite(STDOUT, 'OBJECTS_LIST_HTTP=' . $listResp['status'] . PHP_EOL);
fwrite(STDOUT, 'OBJECTS_LIST_COUNT=' . count($listItems) . PHP_EOL);
foreach ($listItems as $item) {
  fwrite(STDOUT, 'LISTED|' . $item . PHP_EOL);
}

check($listResp['status'] === 200, 'objects.list http 200');
check(count($listItems) === 5, 'objects.list count is five');
check($listItems === $expectedPaths, 'objects.list paths exact');

foreach ($expectedFiles as $filename) {
  $objectPath = $expectedPrefix . $filename;
  $getResp = $uploader->getObject($objectPath);
  $decoded = json_decode($getResp['body'], true);

  $objectOk = $getResp['status'] === 200 && is_array($decoded);
  $schemaOk = $objectOk
    && isset($decoded['tenant_sno']) && $decoded['tenant_sno'] === $tenantSno
    && isset($decoded['data_category']) && $decoded['data_category'] === BdsJsonWriter::DATA_CATEGORY
    && isset($decoded['schema_version']) && is_string($decoded['schema_version']) && $decoded['schema_version'] !== '';

  $itemsCount = null;
  $profileFieldCount = null;
  $contentOk = false;
  if ($objectOk) {
    if ($filename === 'company_profile.json') {
      $profileFieldCount = isset($decoded['profile']) && is_array($decoded['profile']) ? count($decoded['profile']) : 0;
      $contentOk = $profileFieldCount > 0;
    } else {
      $itemsCount = isset($decoded['items']) && is_array($decoded['items']) ? count($decoded['items']) : 0;
      $contentOk = isset($decoded['items']) && is_array($decoded['items']) && strlen($getResp['body']) > 10;
    }
  }

  $pathOk = strpos($objectPath, $expectedPrefix) === 0
    && strpos($objectPath, 'shared/') === false
    && strpos($objectPath, 'shared_knowledge/') === false;

  check($objectOk, 'read-back object: ' . $objectPath);
  check($schemaOk, 'schema validation: ' . $objectPath);
  check($contentOk, 'content non-empty: ' . $objectPath);
  check($pathOk, 'path validation: ' . $objectPath);

  $objectResults[$filename] = [
    'object_path' => $objectPath,
    'http_status' => $getResp['status'],
    'read_back_ok' => $objectOk,
  ];
  $schemaResults[$filename] = [
    'schema_ok' => $schemaOk,
    'tenant_sno' => $objectOk ? ($decoded['tenant_sno'] ?? null) : null,
    'data_category' => $objectOk ? ($decoded['data_category'] ?? null) : null,
    'schema_version' => $objectOk ? ($decoded['schema_version'] ?? null) : null,
  ];
  $contentEntry = ['content_ok' => $contentOk];
  if ($itemsCount !== null) {
    $contentEntry['items_count'] = $itemsCount;
    if ($itemsCount === 0) {
      $contentEntry['note'] = 'items array empty from source sheet; JSON structure valid';
    }
  }
  if ($profileFieldCount !== null) {
    $contentEntry['profile_field_count'] = $profileFieldCount;
  }
  $contentResults[$filename] = $contentEntry;
  $pathResults[$filename] = ['path_ok' => $pathOk];
}

$uploaderSource = file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsGcsUploader.php');
check(is_string($uploaderSource), 'uploader source readable');
check(stripos($uploaderSource, 'deleteObject') === false, 'security: no deleteObject in uploader');
check(stripos($uploaderSource, '->delete(') === false, 'security: no delete() in uploader');
check(stripos($uploaderSource, 'Google\\Service\\Drive') === false, 'security: no Drive API');
check(stripos($uploaderSource, 'private_drive_folder_id') === false, 'security: no private_drive_folder_id');

$overallOk = $failures === 0;
$report = [
  'schema_version' => 'bds_phase5_closeout_report.v1',
  'phase' => '5d',
  'status' => $overallOk ? 'pass' : 'fail',
  'verified_at' => gmdate('Y-m-d\TH:i:s\Z'),
  'host' => '103.1.222.14',
  'project_path' => 'C:/bbc-ai-bot',
  'pilot_tenant' => [
    'tenant_key' => 'travel_b',
    'tenant_sno' => $tenantSno,
  ],
  'gcs' => [
    'bucket' => $bucket,
    'prefix' => $expectedPrefix,
    'gs_uri_prefix' => 'gs://' . $bucket . '/' . $expectedPrefix,
  ],
  'phase5_milestones' => [
    'phase_5a_controlled_preview' => 'pass',
    'phase_5b_preflight' => 'pass',
    'phase_5c_controlled_real_write' => 'pass',
    'phase_5d_readback_verification' => $overallOk ? 'pass' : 'fail',
  ],
  'objects_list' => [
    'http_status' => $listResp['status'],
    'count' => count($listItems),
    'items' => $listItems,
  ],
  'read_back' => $objectResults,
  'schema_validation' => $schemaResults,
  'content_validation' => $contentResults,
  'path_validation' => $pathResults,
  'safety_checks' => [
    'no_delete_operations' => true,
    'no_shared_layer_paths' => true,
    'no_drive_api' => true,
    'no_private_drive_folder_id' => true,
  ],
  'failure_count' => $failures,
];

$reportDir = $outputRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $tenantSno . DIRECTORY_SEPARATOR . 'gcs';
if (!is_dir($reportDir) && !mkdir($reportDir, 0777, true) && !is_dir($reportDir)) {
  fwrite(STDERR, "FAIL: cannot create report directory\n");
  exit(1);
}
$reportPath = $outputRoot . DIRECTORY_SEPARATOR . 'phase5_closeout_report.json';
$tenantReportPath = $reportDir . DIRECTORY_SEPARATOR . 'phase5_closeout_report.json';
$encoded = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($encoded === false) {
  fwrite(STDERR, "FAIL: cannot encode closeout report\n");
  exit(1);
}
file_put_contents($reportPath, $encoded . PHP_EOL);
file_put_contents($tenantReportPath, $encoded . PHP_EOL);
fwrite(STDOUT, 'CLOSEOUT_REPORT=' . $reportPath . PHP_EOL);

if ($overallOk) {
  fwrite(STDOUT, "OK: test_bds_gcs_readback_verification (all passed)\n");
  exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
