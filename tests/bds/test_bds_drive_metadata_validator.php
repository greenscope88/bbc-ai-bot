<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveMetadataEnvelopeBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveMetadataValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveFolderScanner.php';

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
 * @return array<string, mixed>|null
 */
function fetch_drive_file_metadata_for_test(BdsDriveClient $client, string $fileId): ?array
{
    try {
        $file = $client->getDriveService()->files->get($fileId, [
            'fields' => 'id,name,mimeType,createdTime,modifiedTime,size,md5Checksum',
            'supportsAllDrives' => true,
        ]);
    } catch (Throwable $e) {
        return null;
    }

    return [
        'id' => (string) $file->getId(),
        'name' => (string) $file->getName(),
        'mimeType' => (string) $file->getMimeType(),
        'createdTime' => (string) $file->getCreatedTime(),
        'modifiedTime' => (string) $file->getModifiedTime(),
        'size' => $file->getSize(),
        'md5Checksum' => $file->getMd5Checksum(),
    ];
}

$builder = new BdsDriveMetadataEnvelopeBuilder();
$validator = new BdsDriveMetadataValidator();

$valid = $builder->buildFromTenantKey('travel_b', [
    'id' => 'valid_file',
    'name' => 'brochure.pdf',
    'mimeType' => 'application/pdf',
    'createdTime' => '2026-01-10T08:00:00.000Z',
    'modifiedTime' => '2026-06-01T12:30:00.000Z',
    'md5Checksum' => 'abc123def456abc123def456abc12345',
], 'bds-drive-validator-001');

$validResult = $validator->validate($valid);
test_assert($validResult['ok'] === true, 'valid tenant envelope passes');

$invalidScope = $valid;
$invalidScope['owner_scope'] = 'not_a_scope';
$invalidScope['data_category'] = 'itinerary_data';
$invalidScopeResult = $validator->validate($invalidScope);
test_assert($invalidScopeResult['ok'] === false, 'invalid owner_scope fails');

$invalidPath = $valid;
$invalidPath['source_path'] = 'industries/travel/tenants/travel_b/旅行蜜優惠/brochure.pdf';
$invalidPathResult = $validator->validate($invalidPath);
test_assert($invalidPathResult['ok'] === false, 'invalid display-name source_path fails');

$invalidTenant = $valid;
$invalidTenant['tenant_sno'] = '0000000000000000';
$invalidTenantResult = $validator->validate($invalidTenant);
test_assert($invalidTenantResult['ok'] === false, 'invalid tenant_sno fails tenant boundary');

$invalidCategory = $builder->buildFromDriveFile([
    'id' => 'bad_cat',
    'name' => 'x.pdf',
    'mimeType' => 'application/pdf',
    'createdTime' => '2026-01-10T08:00:00.000Z',
    'modifiedTime' => '2026-06-01T12:30:00.000Z',
], $builder->buildScanContextForPlatform(), 'job-bad-cat');
$invalidCategory['data_category'] = 'itinerary_data';
$invalidCategoryResult = $validator->validate($invalidCategory);
test_assert($invalidCategoryResult['ok'] === false, 'platform with itinerary_data fails isolation');

$missingField = $valid;
unset($missingField['file_id']);
$missingFieldResult = $validator->validate($missingField);
test_assert($missingFieldResult['ok'] === false, 'missing required field fails');

// Host A live validation
$credentialsPath = resolve_credentials_path_for_test();
const PILOT_FOLDER_ID = '17wrq-rrvc7ezclhWlbvdTKxHSf_Hw8pi';

if ($credentialsPath === null) {
    fwrite(STDERR, "SKIP: Host A metadata validator live test (no credentials)\n");
} else {
    try {
        $scanner = new BdsDriveFolderScanner(new BdsDriveClient($credentialsPath));
        $children = $scanner->scanChildren(PILOT_FOLDER_ID);
        $syncJobId = 'bds-drive-hosta-' . gmdate('Ymd-His');

        $validatedCount = 0;
        foreach ($children['files'] as $child) {
            $meta = fetch_drive_file_metadata_for_test($scanner->getDriveClient(), $child['id']);
            if ($meta === null) {
                continue;
            }

            $envelope = $builder->buildFromTenantKey('travel_b', $meta, $syncJobId);
            $result = $validator->validate($envelope);

            fwrite(STDOUT, "HOST_A file={$envelope['file_name']} category={$envelope['data_category']} path={$envelope['source_path']} ok=" . ($result['ok'] ? 'yes' : 'no') . "\n");

            if (!$result['ok']) {
                foreach ($result['errors'] as $error) {
                    fwrite(STDERR, '  error: ' . ($error['code'] ?? '') . ' ' . ($error['message'] ?? '') . "\n");
                }
            }

            test_assert($result['ok'] === true, 'Host A envelope validates for ' . $envelope['file_name']);
            test_assert(strpos($envelope['source_path'], '01_Private_Layer/') !== false, 'Host A logical path uses 01_Private_Layer');
            test_assert(strpos($envelope['source_path'], '旅行蜜優惠') === false, 'Host A path excludes display folder name');
            ++$validatedCount;
        }

        test_assert($validatedCount > 0, 'Host A validated at least one travel_b file');
    } catch (Throwable $e) {
        test_assert(false, 'Host A metadata validation failed: ' . $e->getMessage());
    }
}

if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_bds_drive_metadata_validator (all passed)\n");
exit(0);
