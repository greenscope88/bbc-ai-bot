<?php
declare(strict_types=1);

/**
 * BDS Phase 4 — Google Sheet Reader (tenant_private_knowledge only).
 *
 * Reads the five required Google Sheet tabs via Sheets API and returns raw row
 * arrays for downstream Parser / Validator. No GCS, no Drive API, no Shared Layer.
 *
 * @see docs/BATS_DATA_CONTRACT.md
 * @see docs/BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md Phase 4
 */
final class BdsGoogleSheetReader
{
  /** @var list<string> */
  public const REQUIRED_TABS = [
    'company_profile',
    'qa',
    'external_product_links',
    'service_items',
    'special_prices',
  ];

  private const SHEETS_SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';

  /** @var \Google\Service\Sheets */
  private $sheetsService;

  /** @var string|null */
  private $credentialsPath;

  /**
   * @param string|null $credentialsPath Service account JSON path; resolved from env / secrets/ when null.
   * @param \Google\Service\Sheets|null $sheetsService Optional injected Sheets client (tests).
   */
  public function __construct(?string $credentialsPath = null, ?\Google\Service\Sheets $sheetsService = null)
  {
    $this->credentialsPath = $credentialsPath !== null ? trim($credentialsPath) : null;

    if ($sheetsService !== null) {
      $this->sheetsService = $sheetsService;
      return;
    }

    $this->sheetsService = $this->createSheetsService($this->resolveCredentialsPath());
  }

  /**
   * Read all required tabs from a tenant private knowledge Google Sheet.
   *
   * Missing tabs return an empty array; individual tab read failures do not abort
   * the full read. Invalid spreadsheet id or auth failures throw.
   *
   * @return array{
   *   company_profile: list<array<string, mixed>>,
   *   qa: list<array<string, mixed>>,
   *   external_product_links: list<array<string, mixed>>,
   *   service_items: list<array<string, mixed>>,
   *   special_prices: list<array<string, mixed>>
   * }
   */
  public function readSheet(string $sheetId): array
  {
    $sheetId = trim($sheetId);
    if ($sheetId === '') {
      throw new \InvalidArgumentException('sheet_id is required.');
    }

    $output = $this->emptyTabPayload();
    $availableTabs = $this->listTabTitles($sheetId);

    foreach (self::REQUIRED_TABS as $tabName) {
      if (!isset($availableTabs[$tabName])) {
        continue;
      }

      $output[$tabName] = $this->readTabRows($sheetId, $tabName);
    }

    return $output;
  }

  /**
   * @return \Google\Service\Sheets
   */
  public function getSheetsService()
  {
    return $this->sheetsService;
  }

  /**
   * @return string|null Resolved credentials path (for diagnostics; never returns key contents).
   */
  public function getCredentialsPath(): ?string
  {
    if ($this->credentialsPath !== null && $this->credentialsPath !== '') {
      return $this->credentialsPath;
    }

    return $this->resolveCredentialsPath();
  }

  /**
   * @return array{
   *   company_profile: list<array<string, mixed>>,
   *   qa: list<array<string, mixed>>,
   *   external_product_links: list<array<string, mixed>>,
   *   service_items: list<array<string, mixed>>,
   *   special_prices: list<array<string, mixed>>
   * }
   */
  private function emptyTabPayload(): array
  {
    return [
      'company_profile' => [],
      'qa' => [],
      'external_product_links' => [],
      'service_items' => [],
      'special_prices' => [],
    ];
  }

  /**
   * @return array<string, true> tab title => true
   */
  private function listTabTitles(string $sheetId): array
  {
    $spreadsheet = $this->sheetsService->spreadsheets->get($sheetId, [
      'fields' => 'sheets.properties.title',
    ]);

    $titles = [];
    $sheets = $spreadsheet->getSheets();
    if (!is_array($sheets)) {
      return $titles;
    }

    foreach ($sheets as $sheet) {
      $properties = $sheet->getProperties();
      if ($properties === null) {
        continue;
      }

      $title = trim((string) $properties->getTitle());
      if ($title !== '') {
        $titles[$title] = true;
      }
    }

    return $titles;
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function readTabRows(string $sheetId, string $tabName): array
  {
    try {
      $range = $this->quoteSheetRange($tabName) . '!A:ZZZ';
      $response = $this->sheetsService->spreadsheets_values->get($sheetId, $range);
      $values = $response->getValues();

      return $this->valuesToRows(is_array($values) ? $values : []);
    } catch (\Google\Service\Exception $e) {
      if ($this->isNonFatalTabError($e)) {
        return [];
      }

      throw $e;
    } catch (\Throwable $e) {
      return [];
    }
  }

  private function isNonFatalTabError(\Google\Service\Exception $exception): bool
  {
    $code = (int) $exception->getCode();
    if ($code === 400 || $code === 404) {
      return true;
    }

    $message = strtolower($exception->getMessage());
    if (strpos($message, 'unable to parse range') !== false) {
      return true;
    }
    if (strpos($message, 'not found') !== false) {
      return true;
    }

    return false;
  }

  /**
   * @param list<list<mixed>> $values
   * @return list<array<string, mixed>>
   */
  private function valuesToRows(array $values): array
  {
    if ($values === []) {
      return [];
    }

    $headerRow = array_shift($values);
    if (!is_array($headerRow) || $headerRow === []) {
      return [];
    }

    $headers = $this->normalizeHeaders($headerRow);
    if ($headers === []) {
      return [];
    }

    $rows = [];
    foreach ($values as $rawRow) {
      if (!is_array($rawRow)) {
        continue;
      }

      $row = $this->combineRow($headers, $rawRow);
      if ($this->isNonEmptyRow($row)) {
        $rows[] = $row;
      }
    }

    return $rows;
  }

  /**
   * @param list<mixed> $headerRow
   * @return list<string>
   */
  private function normalizeHeaders(array $headerRow): array
  {
    $headers = [];

    foreach ($headerRow as $index => $cell) {
      $name = trim((string) $cell);
      if ($name === '') {
        $name = 'column_' . ((int) $index + 1);
      }
      $headers[] = $name;
    }

    return $headers;
  }

  /**
   * @param list<string> $headers
   * @param list<mixed> $rawRow
   * @return array<string, mixed>
   */
  private function combineRow(array $headers, array $rawRow): array
  {
    $row = [];
    $columnCount = count($headers);

    for ($index = 0; $index < $columnCount; $index++) {
      $row[$headers[$index]] = array_key_exists($index, $rawRow) ? $rawRow[$index] : '';
    }

    return $row;
  }

  /**
   * @param array<string, mixed> $row
   */
  private function isNonEmptyRow(array $row): bool
  {
    foreach ($row as $value) {
      if ($value !== null && trim((string) $value) !== '') {
        return true;
      }
    }

    return false;
  }

  private function quoteSheetRange(string $tabName): string
  {
    return "'" . str_replace("'", "''", $tabName) . "'";
  }

  private function createSheetsService(string $credentialsPath): \Google\Service\Sheets
  {
    if (!is_file($credentialsPath)) {
      throw new \RuntimeException('Google service account credentials file not found.');
    }

    $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (!is_file($autoload)) {
      throw new \RuntimeException('Composer autoload not found; run composer install in project root.');
    }

    require_once $autoload;

    $client = new \Google\Client();
    $client->setApplicationName('BBC AI Bot BDS');
    $client->setScopes([self::SHEETS_SCOPE]);
    $client->setAuthConfig($credentialsPath);
    $client->setAccessType('offline');

    return new \Google\Service\Sheets($client);
  }

  private function resolveCredentialsPath(): string
  {
    if ($this->credentialsPath !== null && $this->credentialsPath !== '') {
      return $this->credentialsPath;
    }

    $envCandidates = [
      getenv('BDS_GOOGLE_APPLICATION_CREDENTIALS'),
      getenv('GOOGLE_APPLICATION_CREDENTIALS'),
    ];

    foreach ($envCandidates as $candidate) {
      $path = is_string($candidate) ? trim($candidate) : '';
      if ($path !== '' && is_file($path)) {
        $this->credentialsPath = $path;
        return $path;
      }
    }

    $secretsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'secrets';
    if (is_dir($secretsDir)) {
      $matches = glob($secretsDir . DIRECTORY_SEPARATOR . '*.json');
      if (is_array($matches)) {
        sort($matches, SORT_STRING);
        foreach ($matches as $match) {
          if (is_file($match)) {
            $this->credentialsPath = $match;
            return $match;
          }
        }
      }
    }

    throw new \RuntimeException('Google service account credentials path is not configured.');
  }
}
