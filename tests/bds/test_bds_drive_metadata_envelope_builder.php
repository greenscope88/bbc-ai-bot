<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveMetadataEnvelopeBuilder.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$builder = new BdsDriveMetadataEnvelopeBuilder();

$tenantContext = [
    'owner_scope' => 'tenant',
    'industry_code' => 'travel',
    'tenant_key' => 'travel_b',
    'tenant_sno' => '5f99b8d665e8444d',
    'relative_path' => '',
    'legacy_folder' => true,
];

$drivePdf = [
    'id' => 'file_pdf_001',
    'name' => 'summer_tour.pdf',
    'mimeType' => 'application/pdf',
    'createdTime' => '2026-01-10T08:00:00.000Z',
    'modifiedTime' => '2026-06-01T12:30:00.000Z',
    'size' => 204800,
    'md5Checksum' => 'D41D8CD98F00B204E9800998ECF8427E',
];

$envelope = $builder->buildFromDriveFile($drivePdf, $tenantContext, 'bds-drive-test-001', '2026-06-12T08:00:00Z');

test_assert($envelope['schema_version'] === 'bds_drive_metadata.v1', 'schema_version');
test_assert($envelope['source_path'] === 'industries/travel/tenants/travel_b/01_Private_Layer/summer_tour.pdf', 'logical source_path for tenant PDF');
test_assert(strpos($envelope['source_path'], '旅行蜜優惠') === false, 'source_path must not use Drive display folder name');
test_assert($envelope['data_category'] === 'itinerary_data', 'tenant PDF category');
test_assert($envelope['checksum_method'] === 'drive_md5', 'checksum_method drive_md5');
test_assert($envelope['checksum'] === 'd41d8cd98f00b204e9800998ecf8427e', 'checksum lowercased');
test_assert(in_array('W_PATH_LEGACY_FOLDER', $envelope['warnings'], true), 'legacy folder warning');

$driveSheet = [
    'id' => 'file_xlsx_001',
    'name' => 'misc.xlsx',
    'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'createdTime' => '2026-02-01T08:00:00.000Z',
    'modifiedTime' => '2026-02-02T08:00:00.000Z',
];
$sheetEnvelope = $builder->buildFromDriveFile($driveSheet, $tenantContext, 'bds-drive-test-002');
test_assert($sheetEnvelope['data_category'] === 'itinerary_data', 'tenant spreadsheet category');
test_assert($sheetEnvelope['checksum_method'] === 'unavailable', 'missing md5 => unavailable');

$industryContext = $builder->buildScanContextForIndustry('hotel');
$industryEnvelope = $builder->buildFromDriveFile([
    'id' => 'file_industry_001',
    'name' => 'hotel_shared.pdf',
    'mimeType' => 'application/pdf',
    'createdTime' => '2026-03-01T08:00:00.000Z',
    'modifiedTime' => '2026-03-02T08:00:00.000Z',
], $industryContext, 'bds-drive-test-003');
test_assert(
    $industryEnvelope['source_path'] === 'industries/hotel/shared/02_Shared_Layer/hotel_shared.pdf',
    'industry shared logical path'
);
test_assert($industryEnvelope['data_category'] === 'shared_knowledge', 'industry shared category');
test_assert($industryEnvelope['tenant_key'] === null, 'industry tenant_key null');

$globalEnvelope = $builder->buildFromDriveFile([
    'id' => 'file_global_001',
    'name' => 'global_notice.pdf',
    'mimeType' => 'application/pdf',
    'createdTime' => '2026-04-01T08:00:00.000Z',
    'modifiedTime' => '2026-04-02T08:00:00.000Z',
], $builder->buildScanContextForGlobal(), 'bds-drive-test-004');
test_assert(
    $globalEnvelope['source_path'] === 'global/02_Global_Shared_Layer/global_notice.pdf',
    'global shared logical path'
);
test_assert($globalEnvelope['industry_code'] === 'global', 'global industry_code');

$platformEnvelope = $builder->buildFromDriveFile([
    'id' => 'file_reg_001',
    'name' => 'signup_export.csv',
    'mimeType' => 'text/csv',
    'createdTime' => '2026-05-01T08:00:00.000Z',
    'modifiedTime' => '2026-05-02T08:00:00.000Z',
], $builder->buildScanContextForPlatform(), 'bds-drive-test-005');
test_assert($platformEnvelope['source_path'] === 'registrations/signup_export.csv', 'platform logical path');
test_assert($platformEnvelope['data_category'] === 'customer_registration', 'platform registration category');

$subPath = $builder->buildLogicalSourcePath('tenant', 'travel', 'travel_c', 'doc.pdf', '2026/Q1');
test_assert(
    $subPath === 'industries/travel/tenants/travel_c/01_Private_Layer/2026/Q1/doc.pdf',
    'relative subpath under logical layer'
);

$fromRegistry = $builder->buildFromTenantKey('travel_b', $drivePdf, 'bds-drive-test-registry');
test_assert($fromRegistry['tenant_sno'] === '5f99b8d665e8444d', 'buildFromTenantKey uses registry sno');
test_assert($fromRegistry['tenant_key'] === 'travel_b', 'buildFromTenantKey uses registry tenant_key');

if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_bds_drive_metadata_envelope_builder (all passed)\n");
exit(0);
