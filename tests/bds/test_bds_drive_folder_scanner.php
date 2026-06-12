<?php
declare(strict_types=1);

/**
 * BDS Phase 6B-3 — BdsDriveFolderScanner integration tests.
 *
 * @see docs/BATS_DATA_SOURCE_REGISTRY.md §6.5、§6.7
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveFolderScanner.php';

$failures = 0;

const EXPECTED_INDUSTRIES_ROOT = '1EWhnQONx5EQ5GYGd4dx114QoAgXHZU2P';
const EXPECTED_GLOBAL_ROOT = '1OJHWWKkgr9X9nXhHhYfPPIN-p7dnidqh';
const EXPECTED_REGISTRATIONS_ROOT = '17It5q4NQGJHLK5_IXC6PM2iT0dlj0qGG';
const EXPECTED_TRAVEL_B_PRIVATE = '17wrq-rrvc7ezclhWlbvdTKxHSf_Hw8pi';

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
 * @param array<string, array{id: string, name: string, mimeType: string, parents?: list<string>}>> $fileMetadata
 * @param array<string, list<array{id: string, name: string, mimeType: string}>> $childrenByFolder
 */
function build_fake_drive_service_for_scanner(array $fileMetadata, array $childrenByFolder): \Google\Service\Drive
{
    $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Composer autoload not found.');
    }
    require_once $autoload;

    $filesResource = new class($fileMetadata, $childrenByFolder) {
        /** @var array<string, array{id: string, name: string, mimeType: string, parents?: list<string>}>> */
        private $fileMetadata;

        /** @var array<string, list<array{id: string, name: string, mimeType: string}>> */
        private $childrenByFolder;

        /**
         * @param array<string, array{id: string, name: string, mimeType: string, parents?: list<string>}>> $fileMetadata
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
            if (isset($meta['parents']) && is_array($meta['parents'])) {
                $file->setParents($meta['parents']);
            }

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

/**
 * @param array<string, mixed> $report
 */
function render_folder_tree_validation_report(array $report): string
{
    $lines = [];
    $lines[] = '=== Folder Tree Validation Report ===';
    $lines[] = 'generated_at: ' . ($report['generated_at'] ?? '');
    $lines[] = '';

    $platform = $report['platform'] ?? [];
    $lines[] = '--- Platform Roots ---';
    $lines[] = 'schema_version: ' . ($platform['schema_version'] ?? '');
    foreach (($platform['roots'] ?? []) as $role => $root) {
        if (!is_array($root)) {
            continue;
        }
        $lines[] = sprintf('[%s] status=%s folder_id=%s', $role, $root['status'] ?? '', $root['folder_id'] ?? '');
        if (is_array($root['folder'] ?? null)) {
            $folder = $root['folder'];
            $lines[] = sprintf(
                '  name=%s mimeType=%s childCount=%d parentIds=%s',
                $folder['name'] ?? '',
                $folder['mimeType'] ?? '',
                (int) ($folder['childCount'] ?? 0),
                json_encode($folder['parentIds'] ?? [], JSON_UNESCAPED_UNICODE)
            );
        }
        if (($root['status'] ?? '') === 'error') {
            $lines[] = '  error_code=' . ($root['error_code'] ?? '') . ' error=' . ($root['error'] ?? '');
        }
    }
    $lines[] = sprintf(
        'platform summary: ok=%d failed=%d',
        (int) ($platform['summary']['ok'] ?? 0),
        (int) ($platform['summary']['failed'] ?? 0)
    );
    $lines[] = '';

    $lines[] = '--- Tenant Private Folders ---';
    foreach (($report['tenants'] ?? []) as $tenantKey => $tenant) {
        if (!is_array($tenant)) {
            continue;
        }
        $lines[] = sprintf('[%s] status=%s folder_id=%s', $tenantKey, $tenant['status'] ?? '', $tenant['folder_id'] ?? '');
        if (is_array($tenant['folder'] ?? null)) {
            $folder = $tenant['folder'];
            $lines[] = sprintf(
                '  name=%s mimeType=%s childCount=%d parentIds=%s',
                $folder['name'] ?? '',
                $folder['mimeType'] ?? '',
                (int) ($folder['childCount'] ?? 0),
                json_encode($folder['parentIds'] ?? [], JSON_UNESCAPED_UNICODE)
            );
        }
        if (($tenant['status'] ?? '') === 'error') {
            $lines[] = '  error_code=' . ($tenant['error_code'] ?? '') . ' error=' . ($tenant['error'] ?? '');
        }
    }

    $summary = $report['summary'] ?? [];
    $lines[] = '';
    $lines[] = sprintf(
        'overall: platform_ok=%d platform_failed=%d tenant_ok=%d tenant_failed=%d',
        (int) ($summary['platform_ok'] ?? 0),
        (int) ($summary['platform_failed'] ?? 0),
        (int) ($summary['tenant_ok'] ?? 0),
        (int) ($summary['tenant_failed'] ?? 0)
    );

    return implode("\n", $lines) . "\n";
}

// --- Mocked scanner tests ---
$industriesRoot = 'mock_industries_root';
$privateFolder = 'mock_private_folder';
$parentRoot = 'mock_parent_root';

$fakeMetadata = [
    $industriesRoot => [
        'id' => $industriesRoot,
        'name' => 'industries',
        'mimeType' => BdsDriveClient::MIME_FOLDER,
        'parents' => ['drive_root'],
    ],
    $privateFolder => [
        'id' => $privateFolder,
        'name' => '01_Private_Layer',
        'mimeType' => BdsDriveClient::MIME_FOLDER,
        'parents' => [$parentRoot],
    ],
    'not_a_folder' => [
        'id' => 'not_a_folder',
        'name' => 'sheet.xlsx',
        'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'parents' => [],
    ],
    'forbidden_folder' => [
        'id' => 'forbidden_folder',
        'name' => 'secret',
        'mimeType' => BdsDriveClient::MIME_FOLDER,
        'parents' => [],
    ],
];

$fakeChildren = [
    $industriesRoot => [
        ['id' => 'travel_industry', 'name' => 'travel', 'mimeType' => BdsDriveClient::MIME_FOLDER],
        ['id' => 'hotel_industry', 'name' => 'hotel', 'mimeType' => BdsDriveClient::MIME_FOLDER],
    ],
    $privateFolder => [
        ['id' => 'child_doc', 'name' => 'brochure.pdf', 'mimeType' => 'application/pdf'],
    ],
];

$fakeService = build_fake_drive_service_for_scanner($fakeMetadata, $fakeChildren);
$mockClient = new BdsDriveClient(null, $fakeService);
$scanner = new BdsDriveFolderScanner($mockClient);

$folder = $scanner->scanFolder($industriesRoot);
test_assert($folder['id'] === $industriesRoot, 'scanFolder returns id');
test_assert($folder['name'] === 'industries', 'scanFolder returns name');
test_assert($folder['mimeType'] === BdsDriveClient::MIME_FOLDER, 'scanFolder returns folder mimeType');
test_assert($folder['childCount'] === 2, 'scanFolder childCount matches children');
test_assert(in_array('drive_root', $folder['parentIds'], true), 'scanFolder returns parentIds');

$children = $scanner->scanChildren($privateFolder);
test_assert(count($children['files']) === 1, 'scanChildren returns files list');
test_assert($children['files'][0]['name'] === 'brochure.pdf', 'scanChildren file name correct');

try {
    $scanner->scanFolder('missing_folder_id');
    test_assert(false, 'missing folder should throw BdsDriveFolderScanException');
} catch (BdsDriveFolderScanException $e) {
    test_assert($e->getErrorCode() === BdsDriveFolderScanException::NOT_FOUND, 'missing folder error code is not_found');
}

// Permission denied mock — dedicated fake service
$permissionDeniedService = new class($fakeMetadata, $fakeChildren) extends \Google\Service\Drive {
    public $files;

    public function __construct(array $fileMetadata, array $childrenByFolder)
    {
        parent::__construct(new \Google\Client());
        $this->files = new class($fileMetadata, $childrenByFolder) {
            /** @var array<string, mixed> */
            private $fileMetadata;
            /** @var array<string, mixed> */
            private $childrenByFolder;

            public function __construct(array $fileMetadata, array $childrenByFolder)
            {
                $this->fileMetadata = $fileMetadata;
                $this->childrenByFolder = $childrenByFolder;
            }

            public function get($fileId, $optParams = [])
            {
                if ($fileId === 'forbidden_folder') {
                    throw new \Google\Service\Exception('Permission denied.', 403);
                }
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
                return new \Google\Service\Drive\FileList();
            }
        };
    }
};

$permissionScanner = new BdsDriveFolderScanner(new BdsDriveClient(null, $permissionDeniedService));
try {
    $permissionScanner->scanFolder('forbidden_folder');
    test_assert(false, 'permission denied folder should throw BdsDriveFolderScanException');
} catch (BdsDriveFolderScanException $e) {
    test_assert(
        $e->getErrorCode() === BdsDriveFolderScanException::PERMISSION_DENIED,
        'permission denied error code is permission_denied'
    );
}

try {
    $scanner->scanFolder('not_a_folder');
    test_assert(false, 'non-folder mimeType should throw NOT_A_FOLDER');
} catch (BdsDriveFolderScanException $e) {
    test_assert($e->getErrorCode() === BdsDriveFolderScanException::NOT_A_FOLDER, 'not a folder error code correct');
}

$unknownTenant = $scanner->scanTenantPrivateFolder('tenant_does_not_exist');
test_assert($unknownTenant['status'] === 'error', 'unknown tenant returns error status');
test_assert(
    $unknownTenant['error_code'] === BdsDriveFolderScanException::REGISTRY_NOT_FOUND,
    'unknown tenant error code is registry_not_found'
);

// --- Live Host A validation ---
$credentialsPath = resolve_credentials_path_for_test();
if ($credentialsPath === null) {
    fwrite(STDERR, "SKIP: Host A live FolderScanner validation (no credentials file)\n");
} else {
    try {
        $liveScanner = new BdsDriveFolderScanner(new BdsDriveClient($credentialsPath));
        $report = $liveScanner->buildFolderTreeValidationReport(['travel_b']);

        fwrite(STDOUT, render_folder_tree_validation_report($report));

        $platform = $report['platform'];
        test_assert(($platform['summary']['total'] ?? 0) === 3, 'platform roots total is 3');
        test_assert(($platform['summary']['ok'] ?? 0) === 3, 'all platform roots scan ok on Host A');

        $industries = $platform['roots']['industries'] ?? null;
        $global = $platform['roots']['global'] ?? null;
        $registrations = $platform['roots']['registrations'] ?? null;

        test_assert(is_array($industries) && ($industries['folder_id'] ?? '') === EXPECTED_INDUSTRIES_ROOT, 'industries root id matches registry');
        test_assert(is_array($global) && ($global['folder_id'] ?? '') === EXPECTED_GLOBAL_ROOT, 'global root id matches registry');
        test_assert(
            is_array($registrations) && ($registrations['folder_id'] ?? '') === EXPECTED_REGISTRATIONS_ROOT,
            'registrations root id matches registry'
        );

        foreach (['industries', 'global', 'registrations'] as $role) {
            $root = $platform['roots'][$role] ?? null;
            if (!is_array($root) || !is_array($root['folder'] ?? null)) {
                test_assert(false, $role . ' root folder metadata missing on Host A');
                continue;
            }
            $folderMeta = $root['folder'];
            test_assert($folderMeta['mimeType'] === BdsDriveClient::MIME_FOLDER, $role . ' root mimeType is folder');
            test_assert($folderMeta['name'] !== '', $role . ' root name is non-empty');
            test_assert($folderMeta['childCount'] >= 0, $role . ' root childCount is non-negative');
        }

        $travelB = $report['tenants']['travel_b'] ?? null;
        test_assert(is_array($travelB) && ($travelB['status'] ?? '') === 'ok', 'travel_b private folder scans ok on Host A');
        if (is_array($travelB)) {
            test_assert(($travelB['folder_id'] ?? '') === EXPECTED_TRAVEL_B_PRIVATE, 'travel_b folder id matches registry');
            if (is_array($travelB['folder'] ?? null)) {
                test_assert($travelB['folder']['childCount'] >= 0, 'travel_b childCount non-negative');
                test_assert(
                    $travelB['folder']['childCount'] === count($travelB['children']['files'] ?? []),
                    'travel_b childCount matches scanChildren file count'
                );
            }
        }
    } catch (\Throwable $e) {
        test_assert(false, 'Host A FolderScanner validation failed: ' . $e->getMessage());
    }
}

if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_bds_drive_folder_scanner (all passed)\n");
exit(0);
