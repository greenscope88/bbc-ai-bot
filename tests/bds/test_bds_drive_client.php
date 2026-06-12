<?php
declare(strict_types=1);

/**
 * BDS Phase 6B-2A — BdsDriveClient integration tests.
 *
 * @see docs/BATS_DRIVE_CONNECTOR_SCOPE.md Phase 6B
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveClient.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
  global $failures;
  if (!$cond) {
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
  }
}

function resolve_credentials_path_for_test(): ?string
{
  $candidates = [
    getenv('BDS_GOOGLE_APPLICATION_CREDENTIALS'),
    getenv('GOOGLE_APPLICATION_CREDENTIALS'),
  ];

  foreach ($candidates as $candidate) {
    $path = is_string($candidate) ? trim($candidate) : '';
    if ($path !== '' && is_file($path)) {
      return $path;
    }
  }

  $secretsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'secrets';
  if (!is_dir($secretsDir)) {
    return null;
  }

  $matches = glob($secretsDir . DIRECTORY_SEPARATOR . '*.json');
  if (!is_array($matches) || $matches === []) {
    return null;
  }

  sort($matches, SORT_STRING);

  return is_file($matches[0]) ? $matches[0] : null;
}

/**
 * @param array<string, mixed> $fileMetadata
 * @param array<string, list<array{id: string, name: string, mimeType: string}>> $childrenByFolder
 */
function build_fake_drive_service(array $fileMetadata, array $childrenByFolder): \Google\Service\Drive
{
  $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
  if (!is_file($autoload)) {
    throw new \RuntimeException('Composer autoload not found.');
  }
  require_once $autoload;

  $filesResource = new class($fileMetadata, $childrenByFolder) {
    /** @var array<string, mixed> */
    private $fileMetadata;

    /** @var array<string, list<array{id: string, name: string, mimeType: string}>> */
    private $childrenByFolder;

    /**
     * @param array<string, mixed> $fileMetadata
     * @param array<string, list<array{id: string, name: string, mimeType: string}>> $childrenByFolder
     */
    public function __construct(array $fileMetadata, array $childrenByFolder)
    {
      $this->fileMetadata = $fileMetadata;
      $this->childrenByFolder = $childrenByFolder;
    }

    public function get($fileId, $optParams = [])
    {
      if (!isset($this->fileMetadata[$fileId])) {
        throw new \Google\Service\Exception('File not found.', 404);
      }

      $meta = $this->fileMetadata[$fileId];
      $file = new \Google\Service\Drive\DriveFile();
      $file->setId((string) $meta['id']);
      $file->setName((string) $meta['name']);
      $file->setMimeType((string) $meta['mimeType']);

      return $file;
    }

    public function listFiles($optParams = [])
    {
      $query = isset($optParams['q']) ? (string) $optParams['q'] : '';
      $folderId = '';
      if (preg_match("/'([^']+)' in parents/", $query, $matches) === 1) {
        $folderId = $matches[1];
      }

      $allChildren = $this->childrenByFolder[$folderId] ?? [];
      $pageSize = isset($optParams['pageSize']) ? (int) $optParams['pageSize'] : 100;
      $pageToken = isset($optParams['pageToken']) ? (string) $optParams['pageToken'] : '';
      $offset = $pageToken !== '' ? (int) $pageToken : 0;

      $slice = array_slice($allChildren, $offset, $pageSize);
      $nextOffset = $offset + count($slice);
      $nextPageToken = $nextOffset < count($allChildren) ? (string) $nextOffset : null;

      $fileObjects = [];
      foreach ($slice as $child) {
        $file = new \Google\Service\Drive\DriveFile();
        $file->setId($child['id']);
        $file->setName($child['name']);
        $file->setMimeType($child['mimeType']);
        $fileObjects[] = $file;
      }

      $list = new \Google\Service\Drive\FileList();
      $list->setFiles($fileObjects);
      if ($nextPageToken !== null) {
        $list->setNextPageToken($nextPageToken);
      }

      return $list;
    }
  };

  $service = new \Google\Service\Drive(new \Google\Client());
  $service->files = $filesResource;

  return $service;
}

// --- Test 1: live client build (when credentials available) ---
$credentialsPath = resolve_credentials_path_for_test();
test_assert($credentialsPath !== null, 'service account credentials file is available for client build test');

if ($credentialsPath !== null) {
  try {
    $liveClient = new BdsDriveClient($credentialsPath);
    $service = $liveClient->getDriveService();
    test_assert($service instanceof \Google\Service\Drive, 'Google Drive service instance created');
    test_assert($liveClient->getCredentialsPath() === $credentialsPath, 'credentials path resolved without exposing key contents');
  } catch (\Throwable $e) {
    test_assert(false, 'Google Drive API client build failed: ' . $e->getMessage());
  }
} else {
  fwrite(STDERR, "SKIP: live client build test (no credentials file)\n");
}

// --- Tests 2-4: mocked Drive service ---
$folderId = 'folder_test_001';
$fakeMetadata = [
  $folderId => [
    'id' => $folderId,
    'name' => '01_Private_Layer',
    'mimeType' => BdsDriveClient::MIME_FOLDER,
  ],
  'child_doc_1' => [
    'id' => 'child_doc_1',
    'name' => 'sample.pdf',
    'mimeType' => 'application/pdf',
  ],
];

$fakeChildren = [
  $folderId => [
    ['id' => 'child_doc_1', 'name' => 'sample.pdf', 'mimeType' => 'application/pdf'],
    ['id' => 'child_folder_2', 'name' => 'archive', 'mimeType' => BdsDriveClient::MIME_FOLDER],
  ],
];

$fakeService = build_fake_drive_service($fakeMetadata, $fakeChildren);
$client = new BdsDriveClient(null, $fakeService);

$metadata = $client->getFileMetadata($folderId);
test_assert($metadata['id'] === $folderId, 'getFileMetadata returns folder id');
test_assert($metadata['name'] === '01_Private_Layer', 'getFileMetadata returns folder name');
test_assert($metadata['mimeType'] === BdsDriveClient::MIME_FOLDER, 'getFileMetadata returns mimeType');

$listPage = $client->listFiles($folderId, ['pageSize' => 10]);
test_assert(count($listPage['files']) === 2, 'listFiles returns direct children');
test_assert($listPage['files'][0]['name'] === 'sample.pdf', 'listFiles returns child name');
test_assert($listPage['nextPageToken'] === null, 'single page has no nextPageToken');

$folderMeta = $client->readFolderMetadata($folderId);
test_assert($folderMeta['child_count'] === 2, 'readFolderMetadata child_count matches children');
test_assert(isset($folderMeta['id'], $folderMeta['name'], $folderMeta['mimeType']), 'readFolderMetadata returns required fields');

try {
  $client->getFileMetadata('');
  test_assert(false, 'empty file id should throw InvalidArgumentException');
} catch (\InvalidArgumentException $e) {
  test_assert(true, 'empty file id throws InvalidArgumentException');
}

// --- Live preflight: travel_b private_knowledge_folder_id ---
$liveFolderId = getenv('BDS_TEST_DRIVE_FOLDER_ID');
$liveFolderId = is_string($liveFolderId) && trim($liveFolderId) !== ''
  ? trim($liveFolderId)
  : BdsDriveClient::PILOT_PRIVATE_KNOWLEDGE_FOLDER_ID;

if ($credentialsPath !== null) {
  try {
    $liveClient = new BdsDriveClient($credentialsPath);
    $liveFolder = $liveClient->readFolderMetadata($liveFolderId);
    $liveList = $liveClient->listFiles($liveFolderId, ['pageSize' => 20]);

    fwrite(STDOUT, "LIVE folder metadata:\n");
    fwrite(STDOUT, '  id: ' . $liveFolder['id'] . "\n");
    fwrite(STDOUT, '  name: ' . $liveFolder['name'] . "\n");
    fwrite(STDOUT, '  mimeType: ' . $liveFolder['mimeType'] . "\n");
    fwrite(STDOUT, '  child_count: ' . (string) $liveFolder['child_count'] . "\n");
    fwrite(STDOUT, '  list_page_files: ' . (string) count($liveList['files']) . "\n");

    test_assert($liveFolder['id'] === $liveFolderId, 'live folder id matches request');
    test_assert($liveFolder['mimeType'] === BdsDriveClient::MIME_FOLDER, 'live folder mimeType is folder');
    test_assert($liveFolder['name'] !== '', 'live folder name is non-empty');
    test_assert($liveFolder['child_count'] >= 0, 'live child_count is non-negative');
    test_assert(is_array($liveList['files']), 'live listFiles returns files array');
  } catch (\Throwable $e) {
    test_assert(false, 'live Drive preflight failed: ' . $e->getMessage());
  }
} else {
  fwrite(STDERR, "SKIP: live Drive preflight (no credentials file)\n");
}

if ($failures > 0) {
  fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
  exit(1);
}

fwrite(STDOUT, "OK: test_bds_drive_client (all passed)\n");
exit(0);
