<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveFileClassifier.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$classifier = new BdsDriveFileClassifier();

$tenantPdf = $classifier->classify('tenant', 'application/pdf', 'tour.pdf');
test_assert($tenantPdf['data_category'] === 'itinerary_data', 'tenant PDF => itinerary_data');
test_assert($tenantPdf['owner_scope'] === 'tenant', 'tenant PDF owner_scope');

$tenantSheet = $classifier->classify(
    'tenant',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'misc.xlsx'
);
test_assert($tenantSheet['data_category'] === 'itinerary_data', 'tenant spreadsheet => itinerary_data');
test_assert(in_array(BdsDriveFileClassifier::WARNING_EXCEL_AMBIGUOUS, $tenantSheet['warnings'], true), 'ambiguous excel warning');

$tenantSheetNamed = $classifier->classify(
    'tenant',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    '行程商品表.xlsx'
);
test_assert($tenantSheetNamed['data_category'] === 'itinerary_data', 'named itinerary spreadsheet');
test_assert($tenantSheetNamed['warnings'] === [], 'named itinerary spreadsheet has no ambiguous warning');

$industryPdf = $classifier->classify('industry', 'application/pdf', 'shared_faq.pdf');
test_assert($industryPdf['data_category'] === 'shared_knowledge', 'industry PDF => shared_knowledge');
test_assert($industryPdf['owner_scope'] === 'industry', 'industry owner_scope');

$globalImage = $classifier->classify('global', 'image/png', 'banner.png');
test_assert($globalImage['data_category'] === 'shared_knowledge', 'global image => shared_knowledge');
test_assert($globalImage['owner_scope'] === 'global', 'global owner_scope');

$platformAny = $classifier->classify('platform', 'application/pdf', 'registration_export.pdf');
test_assert($platformAny['data_category'] === 'customer_registration', 'platform => customer_registration');

$tenantWord = $classifier->classify('tenant', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'notes.docx');
test_assert($tenantWord['data_category'] === 'archive', 'tenant word => archive');

$tenantZip = $classifier->classify('tenant', 'application/zip', 'bundle.zip');
test_assert($tenantZip['data_category'] === 'archive', 'tenant zip => archive');

$tenantPpt = $classifier->classify('tenant', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'deck.pptx');
test_assert($tenantPpt['data_category'] === 'archive', 'tenant ppt => archive');

try {
    $classifier->classify('invalid_scope', 'application/pdf');
    test_assert(false, 'invalid owner_scope should throw');
} catch (InvalidArgumentException $e) {
    test_assert(true, 'invalid owner_scope throws InvalidArgumentException');
}

if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_bds_drive_file_classifier (all passed)\n");
exit(0);
