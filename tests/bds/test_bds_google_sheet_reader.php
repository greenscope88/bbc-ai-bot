<?php
declare(strict_types=1);

/**
 * BDS Phase 4 — BdsGoogleSheetReader integration tests.
 *
 * @see docs/BATS_DATA_SYNC_TEST_PLAN.md Phase 4
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsGoogleSheetReader.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
  global $failures;
  if (!$cond) {
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
  }
}

/**
 * @param list<string> $availableTabs
 * @param array<string, list<list<mixed>>> $tabValues keyed by tab title
 */
function build_fake_sheets_service(array $availableTabs, array $tabValues): \Google\Service\Sheets
{
  $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
  if (!is_file($autoload)) {
    throw new \RuntimeException('Composer autoload not found.');
  }
  require_once $autoload;

  $spreadsheet = new \Google\Service\Sheets\Spreadsheet();
  $sheetObjects = [];
  foreach ($availableTabs as $title) {
    $properties = new \Google\Service\Sheets\SheetProperties();
    $properties->setTitle($title);
    $sheet = new \Google\Service\Sheets\Sheet();
    $sheet->setProperties($properties);
    $sheetObjects[] = $sheet;
  }
  $spreadsheet->setSheets($sheetObjects);

  $spreadsheetsResource = new class($spreadsheet) {
    /** @var \Google\Service\Sheets\Spreadsheet */
    private $spreadsheet;

    public function __construct(\Google\Service\Sheets\Spreadsheet $spreadsheet)
    {
      $this->spreadsheet = $spreadsheet;
    }

    public function get($spreadsheetId, $optParams = [])
    {
      return $this->spreadsheet;
    }
  };

  $valuesResource = new class($tabValues) {
    /** @var array<string, list<list<mixed>>> */
    private $tabValues;

    /**
     * @param array<string, list<list<mixed>>> $tabValues
     */
    public function __construct(array $tabValues)
    {
      $this->tabValues = $tabValues;
    }

    public function get($spreadsheetId, $range)
    {
      $tabName = '';
      if (preg_match("/'([^']+)'!/", (string) $range, $matches) === 1) {
        $tabName = $matches[1];
      }

      $valueRange = new \Google\Service\Sheets\ValueRange();
      if ($tabName !== '' && isset($this->tabValues[$tabName])) {
        $valueRange->setValues($this->tabValues[$tabName]);
      } else {
        $valueRange->setValues([]);
      }

      return $valueRange;
    }
  };

  $service = new \Google\Service\Sheets(new \Google\Client());
  $service->spreadsheets = $spreadsheetsResource;
  $service->spreadsheets_values = $valuesResource;

  return $service;
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

// --- Test 1: Google API client builds successfully ---
$credentialsPath = resolve_credentials_path_for_test();
test_assert($credentialsPath !== null, 'service account credentials file is available for client build test');

if ($credentialsPath !== null) {
  try {
    $liveReader = new BdsGoogleSheetReader($credentialsPath);
    $service = $liveReader->getSheetsService();
    test_assert($service instanceof \Google\Service\Sheets, 'Google Sheets service instance created');
    test_assert($liveReader->getCredentialsPath() === $credentialsPath, 'credentials path resolved without exposing key contents');
  } catch (\Throwable $e) {
    test_assert(false, 'Google API client build failed: ' . $e->getMessage());
  }
} else {
  fwrite(STDERR, "SKIP: live client build test (no credentials file)\n");
}

// --- Tests 2-5: structured read with fake Sheets service ---
$fakeTabs = [
  'company_profile',
  'qa',
  'external_product_links',
  'service_items',
];

$fakeValues = [
  'company_profile' => [
    ['company_name', 'phone'],
    ['Reader Travel Co', '02-1234-5678'],
  ],
  'qa' => [
    ['question', 'answer'],
    ['What are your hours?', 'Mon-Fri 09:00-18:00'],
  ],
  'external_product_links' => [
    ['name', 'url'],
    ['Partner Portal', 'https://example.com/partner'],
  ],
  'service_items' => [
    ['name', 'price_amount'],
    ['Passport Service', '1600'],
  ],
];

$fakeService = build_fake_sheets_service($fakeTabs, $fakeValues);
$reader = new BdsGoogleSheetReader(null, $fakeService);
$result = $reader->readSheet('fake_sheet_id_for_test');

foreach (BdsGoogleSheetReader::REQUIRED_TABS as $tab) {
  test_assert(array_key_exists($tab, $result), 'output contains tab key: ' . $tab);
  test_assert(is_array($result[$tab]), 'tab value is array: ' . $tab);
}

test_assert(isset($result['company_profile'][0]['company_name']), 'company_profile row parsed with header');
test_assert($result['company_profile'][0]['company_name'] === 'Reader Travel Co', 'company_profile value readable');

test_assert(isset($result['qa'][0]['question']), 'qa row parsed with header');
test_assert($result['qa'][0]['question'] === 'What are your hours?', 'qa value readable');

test_assert($result['special_prices'] === [], 'missing special_prices tab returns empty array without fatal');

$missingTabService = build_fake_sheets_service(['company_profile'], [
  'company_profile' => [
    ['company_name'],
    ['Only Profile Tab'],
  ],
]);
$missingTabReader = new BdsGoogleSheetReader(null, $missingTabService);

try {
  $missingTabResult = $missingTabReader->readSheet('missing_tabs_sheet');
  $nonFatal = true;
} catch (\Throwable $e) {
  $nonFatal = false;
  $missingTabResult = [];
}

test_assert($nonFatal, 'missing required tabs do not throw fatal errors');
test_assert($missingTabResult['qa'] === [], 'qa missing tab is empty array');
test_assert($missingTabResult['company_profile'][0]['company_name'] === 'Only Profile Tab', 'existing tab still readable when others missing');

// --- Optional live read when sheet id is configured ---
$liveSheetId = getenv('BDS_TEST_PRIVATE_KNOWLEDGE_SHEET_ID');
$liveSheetId = is_string($liveSheetId) ? trim($liveSheetId) : '';

if ($credentialsPath !== null && $liveSheetId !== '') {
  try {
    $liveReader = new BdsGoogleSheetReader($credentialsPath);
    $liveResult = $liveReader->readSheet($liveSheetId);
  } catch (\Throwable $e) {
    fwrite(STDERR, "WARN: live sheet read skipped: " . $e->getMessage() . "\n");
    $liveResult = null;
  }

  if (is_array($liveResult)) {
    foreach (BdsGoogleSheetReader::REQUIRED_TABS as $tab) {
      test_assert(array_key_exists($tab, $liveResult), 'live read contains tab: ' . $tab);
    }
  }
} else {
  fwrite(STDERR, "SKIP: live sheet read (set BDS_TEST_PRIVATE_KNOWLEDGE_SHEET_ID to enable)\n");
}

if ($failures > 0) {
  fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
  exit(1);
}

fwrite(STDOUT, "OK: test_bds_google_sheet_reader (all passed)\n");
exit(0);
