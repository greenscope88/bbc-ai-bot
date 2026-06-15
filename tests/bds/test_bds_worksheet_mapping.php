<?php
declare(strict_types=1);

/**
 * BDS Phase 7-1e.7 — Worksheet Mapping Layer tests (A–H).
 *
 * @see docs/BATS_DATA_CONTRACT.md §4.4
 * @see docs/BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md §8.8.6.5.6
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsWorksheetNameMapper.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsXlsxReader.php';

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

/**
 * @param array<string, list<list<string>>> $sheets
 */
function build_test_xlsx(string $targetPath, array $sheets): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive required for test xlsx builder');
    }

    $sheetEntries = [];
    $sheetIndex = 1;
    $workbookSheets = [];
    $workbookRels = [];
    $contentOverrides = [
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>',
    ];

    foreach ($sheets as $sheetName => $rows) {
        $sheetFile = 'sheet' . $sheetIndex . '.xml';
        $sheetPath = 'xl/worksheets/' . $sheetFile;
        $rId = 'rId' . $sheetIndex;

        $sheetEntries[$sheetPath] = worksheet_xml_from_rows($rows);
        $workbookSheets[] = '<sheet name="' . xml_escape($sheetName) . '" sheetId="' . $sheetIndex . '" r:id="' . $rId . '"/>';
        $workbookRels[] = '<Relationship Id="' . $rId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/' . $sheetFile . '"/>';
        $contentOverrides[] = '<Override PartName="/xl/worksheets/' . $sheetFile . '" ContentType="application/vnd.openxmlformats-officedocument/spreadsheetml.worksheet+xml"/>';
        ++$sheetIndex;
    }

    $zip = new ZipArchive();
    if ($zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create test xlsx');
    }

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . implode('', $contentOverrides)
        . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . implode('', $workbookSheets) . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . implode('', $workbookRels)
        . '</Relationships>');

    foreach ($sheetEntries as $path => $xml) {
        $zip->addFromString($path, $xml);
    }

    $zip->close();
}

/**
 * @param list<list<string>> $rows
 */
function worksheet_xml_from_rows(array $rows): string
{
    $sheetRows = [];
    $rowNumber = 1;
    foreach ($rows as $row) {
        $cells = [];
        $columnIndex = 1;
        foreach ($row as $cellValue) {
            $ref = column_letter($columnIndex) . $rowNumber;
            $cells[] = '<c r="' . $ref . '" t="inlineStr"><is><t>' . xml_escape((string) $cellValue) . '</t></is></c>';
            ++$columnIndex;
        }
        $sheetRows[] = '<row r="' . $rowNumber . '">' . implode('', $cells) . '</row>';
        ++$rowNumber;
    }

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . implode('', $sheetRows) . '</sheetData></worksheet>';
}

function column_letter(int $index): string
{
    $letters = '';
    while ($index > 0) {
        --$index;
        $letters = chr(65 + ($index % 26)) . $letters;
        $index = intdiv($index, 26);
    }

    return $letters;
}

function xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * @return array<string, list<list<string>>>
 */
function build_valid_sheet_grids(): array
{
    return [
        'company_profile' => [
            ['company_name', 'summary'],
            ['Upload Mode Travel', 'Mapping demo'],
        ],
        'qa' => [
            ['question', 'answer'],
            ['Where are you?', 'Taipei office'],
        ],
        'external_product_links' => [
            ['name', 'url'],
            ['Partner', 'https://example.com/partner'],
        ],
        'service_items' => [
            ['name', 'price_amount'],
            ['Visa Help', '1200'],
        ],
        'special_prices' => [
            ['item_name', 'price_amount'],
            ['Visa Help', '1100'],
        ],
    ];
}

/**
 * @param array<string, list<list<string>>> $grids keyed by internal grid key
 * @param array<string, string> $sheetNames grid key => worksheet display name
 * @return array<string, list<list<string>>>
 */
function rename_sheet_grids(array $grids, array $sheetNames): array
{
    $renamed = [];
    foreach ($sheetNames as $gridKey => $displayName) {
        if (!isset($grids[$gridKey])) {
            throw new InvalidArgumentException('unknown grid key: ' . $gridKey);
        }
        $renamed[$displayName] = $grids[$gridKey];
    }

    return $renamed;
}

function read_xlsx_or_fail(string $path): array
{
    $reader = new BdsXlsxReader();
    return $reader->readFile($path);
}

function expect_read_fail(string $path, string $expectedSubstring): void
{
    try {
        read_xlsx_or_fail($path);
        test_assert(false, 'expected read failure for ' . $path);
    } catch (Throwable $e) {
        $message = $e->getMessage();
        test_assert(
            strpos($message, $expectedSubstring) !== false,
            'error message should contain: ' . $expectedSubstring . ' got: ' . $message
        );
        test_assert(
            strpos($message, 'company_profile missing') === false
            && strpos($message, 'worksheet not found') === false
            && strpos($message, 'invalid worksheet') === false
            && strpos($message, 'Missing required worksheet tabs') === false,
            'error must not expose english technical keys: ' . $message
        );
    }
}

$testRoot = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'worksheet_mapping_7_1e_7';
remove_directory_recursive($testRoot);
mkdir($testRoot, 0777, true);

$grids = build_valid_sheet_grids();
$reader = new BdsXlsxReader();
$mapper = new BdsWorksheetNameMapper();

// A — all English
$pathA = $testRoot . DIRECTORY_SEPARATOR . 'A_english.xlsx';
build_test_xlsx($pathA, rename_sheet_grids($grids, [
    'company_profile' => 'company_profile',
    'qa' => 'qa',
    'external_product_links' => 'external_product_links',
    'service_items' => 'service_items',
    'special_prices' => 'special_prices',
]));
$tabsA = read_xlsx_or_fail($pathA);
test_assert(isset($tabsA['company_profile'][0]['company_name']), 'A: all English worksheets PASS');

// B — all Chinese
$pathB = $testRoot . DIRECTORY_SEPARATOR . 'B_chinese.xlsx';
build_test_xlsx($pathB, rename_sheet_grids($grids, [
    'company_profile' => '公司基本資料',
    'qa' => '問與答',
    'external_product_links' => '其他商品源',
    'service_items' => '服務項目',
    'special_prices' => '特殊價格',
]));
$tabsB = read_xlsx_or_fail($pathB);
test_assert(isset($tabsB['qa'][0]['question']), 'B: all Chinese worksheets PASS');

// C — mixed Chinese / English
$pathC = $testRoot . DIRECTORY_SEPARATOR . 'C_mixed.xlsx';
build_test_xlsx($pathC, rename_sheet_grids($grids, [
    'company_profile' => '公司基本資料',
    'qa' => 'QA',
    'service_items' => '服務項目',
    'special_prices' => 'special_prices',
    'external_product_links' => 'external_product_links',
]));
$tabsC = read_xlsx_or_fail($pathC);
test_assert(count($tabsC) === 5, 'C: mixed Chinese/English PASS');

// D — shuffled tab order
$pathD = $testRoot . DIRECTORY_SEPARATOR . 'D_shuffled.xlsx';
build_test_xlsx($pathD, [
    '特殊價格' => $grids['special_prices'],
    '其他商品源' => $grids['external_product_links'],
    '問與答' => $grids['qa'],
    '服務項目' => $grids['service_items'],
    '公司資料' => $grids['company_profile'],
]);
$tabsD = read_xlsx_or_fail($pathD);
test_assert(isset($tabsD['special_prices'][0]['item_name']), 'D: shuffled worksheet order PASS');

// E — arbitrary filename (content identical to A)
$pathE = $testRoot . DIRECTORY_SEPARATOR . '大億旅行社QA.xlsx';
copy($pathA, $pathE);
$tabsE = read_xlsx_or_fail($pathE);
test_assert(isset($tabsE['company_profile'][0]['company_name']), 'E: arbitrary filename PASS');

// F — missing company_profile
$pathF = $testRoot . DIRECTORY_SEPARATOR . 'F_missing_profile.xlsx';
build_test_xlsx($pathF, rename_sheet_grids($grids, [
    'qa' => '問與答',
    'external_product_links' => '其他商品源',
    'service_items' => '服務項目',
    'special_prices' => '特殊價格',
]));
expect_read_fail($pathF, '缺少「公司基本資料」工作表');

// G — duplicate QA mapping (QA + 問與答)
$pathG = $testRoot . DIRECTORY_SEPARATOR . 'G_duplicate_qa.xlsx';
build_test_xlsx($pathG, rename_sheet_grids($grids, [
    'company_profile' => '公司基本資料',
    'qa' => 'QA',
    'external_product_links' => '其他商品源',
    'service_items' => '服務項目',
    'special_prices' => '特殊價格',
]) + [
    '問與答' => $grids['qa'],
]);
expect_read_fail($pathG, '皆對應「問與答」');

// H — extra ignored worksheet
$pathH = $testRoot . DIRECTORY_SEPARATOR . 'H_extra_sheet.xlsx';
build_test_xlsx($pathH, rename_sheet_grids($grids, [
    'company_profile' => '公司基本資料',
    'qa' => '問與答',
    'external_product_links' => '其他商品源',
    'service_items' => '服務項目',
    'special_prices' => '特殊價格',
]) + [
    '備註' => [
        ['note'],
        ['ignored sheet'],
    ],
]);
$tabsH = read_xlsx_or_fail($pathH);
test_assert(count($tabsH) === 5, 'H: extra worksheet ignored PASS');

// Mapper unit: English case insensitivity
test_assert($mapper->matchCanonicalKey('Qa') === 'qa', 'mapper: Qa -> qa');
test_assert($mapper->matchCanonicalKey('qA') === 'qa', 'mapper: qA -> qa');

if ($failures > 0) {
    fwrite(STDERR, "Tests failed: {$failures}\n");
    exit(1);
}

fwrite(STDOUT, "All worksheet mapping tests passed (A-H).\n");
exit(0);
