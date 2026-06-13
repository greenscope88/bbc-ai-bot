<?php
declare(strict_types=1);

/**
 * BDS Phase 6B-3 — BdsDriveFolderScanner integration tests.
 *
 * @see docs/BATS_DATA_SOURCE_REGISTRY.md §6.5、§6.7
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveFolderScanner.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsPlatformDriveRegistryLoader.php';

$failures = 0;

const EXPECTED_INDUSTRIES_ROOT = '1EWhnQONx5EQ5GYGd4dx114QoAgXHZU2P';
const EXPECTED_GLOBAL_ROOT = '1OJHWWKkgr9X9nXhHhYfPPIN-p7dnidqh';
const EXPECTED_REGISTRATIONS_ROOT = '17It5q4NQGJHLK5_IXC6PM2iT0dlj0qGG';
const EXPECTED_TRAVEL_B_PRIVATE = '17wrq-rrvc7ezclhWlbvdTKxHSf_Hw8pi';
const EXPECTED_TRAVEL_SHARED = '1i-yIs1H4pJsyOXyCO7eRLPh3eTbmSy7T';

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

// --- Phase 6E-3: Shared folder scanner (mocked) ---
$travelSharedFolder = 'mock_travel_shared_layer';
$fakeMetadata[$travelSharedFolder] = [
    'id' => $travelSharedFolder,
    'name' => '02_Shared_Layer',
    'mimeType' => BdsDriveClient::MIME_FOLDER,
    'parents' => ['mock_travel_industry_root'],
];
$fakeChildren[$travelSharedFolder] = [
    ['id' => 'shared_pdf_001', 'name' => 'travel_faq.pdf', 'mimeType' => 'application/pdf'],
];

$sharedRegistryBody = "<?php return [
    'schema_version' => 'bds_platform_drive_registry.v1',
    'platform_roots' => [],
    'industries' => [
        'travel' => [
            'industry_folder_id' => null,
            'shared_layer_folder_id' => '{$travelSharedFolder}',
        ],
        'hotel' => [
            'industry_folder_id' => null,
            'shared_layer_folder_id' => null,
        ],
    ],
    'global' => ['shared_layer_folder_id' => null],
];";

$previousPlatformRegistryPath = getenv('BDS_PLATFORM_DRIVE_REGISTRY_PATH');
$tmpRegistry = tempnam(sys_get_temp_dir(), 'bds_platform_registry_shared_');
if ($tmpRegistry === false) {
    throw new RuntimeException('Failed to create temp platform registry file.');
}
$sharedRegistryPath = $tmpRegistry . '.php';
rename($tmpRegistry, $sharedRegistryPath);
file_put_contents($sharedRegistryPath, $sharedRegistryBody);
putenv('BDS_PLATFORM_DRIVE_REGISTRY_PATH=' . $sharedRegistryPath);

$registryRef = new ReflectionClass(BdsPlatformDriveRegistryLoader::class);
$registryProp = $registryRef->getProperty('registry');
$registryProp->setAccessible(true);
$registryProp->setValue(null);

$sharedFakeService = build_fake_drive_service_for_scanner($fakeMetadata, $fakeChildren);
$sharedScanner = new BdsDriveFolderScanner(new BdsDriveClient(null, $sharedFakeService));

$travelSharedScan = $sharedScanner->scanIndustrySharedFolder('travel');
test_assert(($travelSharedScan['status'] ?? '') === BdsDriveFolderScanner::STATUS_OK, 'mock travel shared scan ok');
test_assert(($travelSharedScan['scan_scope'] ?? '') === BdsDriveFolderScanner::SCAN_SCOPE_INDUSTRY_SHARED, 'travel shared scan_scope');
test_assert(($travelSharedScan['folder_id'] ?? '') === $travelSharedFolder, 'travel shared folder id from registry');
test_assert(is_array($travelSharedScan['folder'] ?? null), 'travel shared folder metadata present');
test_assert(is_array($travelSharedScan['children'] ?? null), 'travel shared children present');
test_assert(count($travelSharedScan['children']['files'] ?? []) === 1, 'travel shared child count');

$hotelSharedScan = $sharedScanner->scanIndustrySharedFolder('hotel');
test_assert(($hotelSharedScan['status'] ?? '') === BdsDriveFolderScanner::STATUS_MISSING, 'hotel shared missing when folder id null');
test_assert(($hotelSharedScan['error_code'] ?? '') === BdsDriveFolderScanException::REGISTRY_MISSING, 'hotel shared registry_missing');
test_assert($hotelSharedScan['folder'] === null, 'hotel shared no folder metadata');
test_assert($hotelSharedScan['children'] === null, 'hotel shared no children');

$globalSharedScan = $sharedScanner->scanGlobalSharedFolder();
test_assert(($globalSharedScan['status'] ?? '') === BdsDriveFolderScanner::STATUS_MISSING, 'global shared missing when folder id null');
test_assert(($globalSharedScan['error_code'] ?? '') === BdsDriveFolderScanException::REGISTRY_MISSING, 'global shared registry_missing');

$unknownIndustrySharedScan = $sharedScanner->scanIndustrySharedFolder('beauty');
test_assert(($unknownIndustrySharedScan['status'] ?? '') === BdsDriveFolderScanner::STATUS_MISSING, 'unknown industry shared missing');
test_assert(
    ($unknownIndustrySharedScan['error_code'] ?? '') === BdsDriveFolderScanException::REGISTRY_NOT_FOUND,
    'unknown industry shared registry_not_found'
);

if ($previousPlatformRegistryPath === false) {
    putenv('BDS_PLATFORM_DRIVE_REGISTRY_PATH');
} else {
    putenv('BDS_PLATFORM_DRIVE_REGISTRY_PATH=' . $previousPlatformRegistryPath);
}
$registryProp->setValue(null);
if (is_file($sharedRegistryPath)) {
    unlink($sharedRegistryPath);
}

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

        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsPlatformDriveRegistryLoader.php';
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveMetadataEnvelopeBuilder.php';
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveMetadataValidator.php';

        $travelShared = $liveScanner->scanIndustrySharedFolder('travel');
        fwrite(STDOUT, "\n--- Industry Shared (travel) ---\n");
        fwrite(STDOUT, 'status=' . ($travelShared['status'] ?? '') . ' folder_id=' . ($travelShared['folder_id'] ?? '') . "\n");
        if (is_array($travelShared['folder'] ?? null)) {
            $sharedFolder = $travelShared['folder'];
            fwrite(STDOUT, sprintf(
                "name=%s mimeType=%s childCount=%d\n",
                $sharedFolder['name'] ?? '',
                $sharedFolder['mimeType'] ?? '',
                (int) ($sharedFolder['childCount'] ?? 0)
            ));
        }

        test_assert(($travelShared['status'] ?? '') === BdsDriveFolderScanner::STATUS_OK, 'travel shared folder scans ok on Host A');
        test_assert(($travelShared['folder_id'] ?? '') === EXPECTED_TRAVEL_SHARED, 'travel shared folder id matches registry');
        test_assert(is_array($travelShared['folder'] ?? null), 'travel shared folder metadata readable on Host A');
        test_assert(is_array($travelShared['children'] ?? null), 'travel shared children list present on Host A');
        if (is_array($travelShared['folder'] ?? null) && is_array($travelShared['children'] ?? null)) {
            test_assert(
                $travelShared['folder']['childCount'] === count($travelShared['children']['files'] ?? []),
                'travel shared childCount matches scanChildren file count'
            );
        }

        $hotelSharedLive = $liveScanner->scanIndustrySharedFolder('hotel');
        $restaurantSharedLive = $liveScanner->scanIndustrySharedFolder('restaurant');
        $globalSharedLive = $liveScanner->scanGlobalSharedFolder();
        test_assert(($hotelSharedLive['status'] ?? '') === BdsDriveFolderScanner::STATUS_MISSING, 'hotel shared safe missing on Host A');
        test_assert(($restaurantSharedLive['status'] ?? '') === BdsDriveFolderScanner::STATUS_MISSING, 'restaurant shared safe missing on Host A');
        test_assert(($globalSharedLive['status'] ?? '') === BdsDriveFolderScanner::STATUS_MISSING, 'global shared safe missing on Host A');
        test_assert($hotelSharedLive['folder'] === null && $hotelSharedLive['children'] === null, 'hotel shared zero scan');
        test_assert($globalSharedLive['folder'] === null && $globalSharedLive['children'] === null, 'global shared zero scan');

        $envelopeBuilder = new BdsDriveMetadataEnvelopeBuilder();
        $metadataValidator = new BdsDriveMetadataValidator();
        $industryContext = $envelopeBuilder->buildScanContextForIndustry('travel');
        $sharedFiles = is_array($travelShared['children']['files'] ?? null) ? $travelShared['children']['files'] : [];
        $validatedCount = 0;
        $envelopeCount = 0;
        foreach ($sharedFiles as $driveFile) {
            if (!is_array($driveFile)) {
                continue;
            }
            ++$envelopeCount;
            $envelope = $envelopeBuilder->buildFromDriveFile($driveFile, $industryContext, 'bds-drive-shared-scan-6e3');
            test_assert(($envelope['owner_scope'] ?? '') === 'industry', 'shared child owner_scope=industry');
            test_assert(($envelope['industry_code'] ?? '') === 'travel', 'shared child industry_code=travel');
            test_assert(($envelope['data_category'] ?? '') === 'shared_knowledge', 'shared child data_category=shared_knowledge');
            test_assert(
                strpos((string) ($envelope['source_path'] ?? ''), 'industries/travel/shared/02_Shared_Layer/') === 0,
                'shared child source_path uses logical industry shared prefix'
            );
            $validation = $metadataValidator->validate($envelope);
            if (!empty($validation['ok'])) {
                ++$validatedCount;
            } else {
                fwrite(STDOUT, 'shared file validation note: ' . ($driveFile['name'] ?? '') . ' errors=' . json_encode($validation['errors'] ?? []) . ' warnings=' . json_encode($validation['warnings'] ?? []) . "\n");
            }
        }
        if ($envelopeCount > 0) {
            fwrite(STDOUT, 'shared metadata envelopes validated=' . $validatedCount . ' total=' . $envelopeCount . "\n");
        } else {
            fwrite(STDOUT, "travel shared folder has zero children (scan ok, metadata N/A)\n");
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
