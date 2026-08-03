<?php
declare(strict_types=1);

/**
 * BDS Phase 5C — Controlled Real GCS Write integration test.
 *
 * Reader -> Parser -> Validator -> GCS Upload (BDS Authority resolved tenant).
 *
 * Required env for live write:
 * - BDS_DRY_RUN=false
 * - BDS_GCS_WRITE_ENABLED=true
 * - BDS_TARGET_SNO=<resolved tenant sno from bds_source_registry.php>
 * - BDS_GCS_BUCKET=bbc-ai-saas-data (optional; defaults to bbc-ai-saas-data)
 * - BDS_TEST_PRIVATE_KNOWLEDGE_SHEET_ID
 * - BDS_TEST_TENANT_SNO=<same resolved tenant sno as BDS_TARGET_SNO>
 * - BDS_GOOGLE_APPLICATION_CREDENTIALS (optional; falls back to secrets/)
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsGoogleSheetReader.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsMockSheetParser.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsKnowledgeDocumentBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsGcsUploader.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsJsonWriter.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsSourceRegistryLoader.php';

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

$sheetId = env_string('BDS_TEST_PRIVATE_KNOWLEDGE_SHEET_ID');
$tenantSno = env_string('BDS_TEST_TENANT_SNO');
$credentialsPath = env_string('BDS_GOOGLE_APPLICATION_CREDENTIALS');

if ($sheetId === '' || $tenantSno === '') {
  fwrite(STDERR, "SKIP: set BDS_TEST_PRIVATE_KNOWLEDGE_SHEET_ID and BDS_TEST_TENANT_SNO\n");
  exit(0);
}

$resolvedEntry = BdsSourceRegistryLoader::loadBySno($tenantSno);
if ($resolvedEntry === null) {
  fwrite(STDERR, "SKIP: BDS_TEST_TENANT_SNO does not resolve to a BDS Authority tenant\n");
  exit(0);
}
$resolvedContext = [
  'tenant_key' => (string) ($resolvedEntry['tenant_key'] ?? ''),
  'sno' => (string) ($resolvedEntry['sno'] ?? ''),
  'gcs_prefix' => (string) ($resolvedEntry['gcs_prefix'] ?? ''),
];

$gate = BdsGcsUploader::evaluateResolvedIdentityGate($resolvedContext);
fwrite(STDOUT, 'GATE_OPEN|' . ($gate['open'] ? 'yes' : 'no') . '|' . $gate['reason'] . PHP_EOL);

if ($gate['open'] !== true) {
  fwrite(STDERR, "SKIP: write gate not open ({$gate['reason']}); set BDS_DRY_RUN=false, BDS_GCS_WRITE_ENABLED=true, BDS_TARGET_SNO=<resolved tenant sno>\n");
  exit(0);
}

try {
  $reader = new BdsGoogleSheetReader($credentialsPath !== '' ? $credentialsPath : null);
  $tabs = $reader->readSheet($sheetId);
} catch (Throwable $e) {
  fwrite(STDERR, 'READ_FAILED|' . $e->getMessage() . PHP_EOL);
  exit(1);
}

$parser = new BdsMockSheetParser();
$validator = new BdsValidator();
$normalized = $parser->parse($tenantSno, $tabs);
$validation = $validator->validate($normalized);

fwrite(STDOUT, 'VALIDATION_OK|' . (($validation['ok'] ?? false) ? 'yes' : 'no') . PHP_EOL);
if (($validation['ok'] ?? false) !== true) {
  fwrite(STDERR, "ABORT: validation_failed; no GCS write attempted\n");
  exit(1);
}

$knowledgeJson = BdsKnowledgeDocumentBuilder::fromNormalized($tenantSno, $normalized, $sheetId);
$outputRoot = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'output';
$uploader = new BdsGcsUploader(null, $credentialsPath !== '' ? $credentialsPath : null, $outputRoot);

$result = $uploader->uploadKnowledge($tenantSno, $knowledgeJson, $validation, $resolvedContext, $sheetId);
test_assert(($result['ok'] ?? false) === true, 'upload result ok');
test_assert(($result['status'] ?? '') === 'gcs_write_success', 'upload status gcs_write_success');
test_assert(isset($result['uploaded_objects']) && is_array($result['uploaded_objects']) && count($result['uploaded_objects']) === 5, 'five objects uploaded');

$expectedPaths = [];
foreach (BdsJsonWriter::TAB_OUTPUT_FILES as $filename) {
  $expectedPaths[] = 'tenants/' . $tenantSno . '/knowledge/' . $filename;
}

$actualPaths = [];
foreach ($result['uploaded_objects'] as $uploaded) {
  if (!is_array($uploaded) || !isset($uploaded['object_path'])) {
    continue;
  }
  $path = (string) $uploaded['object_path'];
  $actualPaths[] = $path;
  fwrite(STDOUT, 'UPLOADED|' . $path . PHP_EOL);
  test_assert(($uploaded['content_type'] ?? '') === 'application/json', 'content type application/json: ' . $path);
  test_assert(strpos($path, 'shared/') === false, 'no shared path: ' . $path);
}

sort($expectedPaths);
sort($actualPaths);
test_assert($actualPaths === $expectedPaths, 'uploaded object paths exact');

foreach ($expectedPaths as $objectPath) {
  $getResp = $uploader->getObject($objectPath);
  test_assert($getResp['status'] === 200, 'read back object: ' . $objectPath);
  $decoded = json_decode($getResp['body'], true);
  test_assert(is_array($decoded), 'read back json decodes: ' . $objectPath);
  test_assert(isset($decoded['tenant_sno']) && $decoded['tenant_sno'] === $tenantSno, 'read back tenant_sno: ' . $objectPath);
  test_assert(isset($decoded['data_category']) && $decoded['data_category'] === 'tenant_private_knowledge', 'read back data_category: ' . $objectPath);
}

$listResp = $uploader->listKnowledgeObjects($tenantSno);
test_assert($listResp['status'] === 200, 'objects.list http 200');
$listItems = $listResp['items'];
sort($listItems);
test_assert($listItems === $expectedPaths, 'objects.list shows five knowledge objects');

$uploaderSource = file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsGcsUploader.php');
test_assert(is_string($uploaderSource), 'uploader source readable');
test_assert(stripos($uploaderSource, 'deleteObject') === false, 'no deleteObject');
test_assert(stripos($uploaderSource, '->delete(') === false, 'no delete() call');
test_assert(stripos($uploaderSource, 'Google\\Service\\Drive') === false, 'no Drive API');
test_assert(stripos($uploaderSource, 'private_drive_folder_id') === false, 'no private_drive_folder_id');

if ($failures > 0) {
  fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
  exit(1);
}

fwrite(STDOUT, "OK: test_bds_gcs_real_write (controlled real write passed)\n");
exit(0);
