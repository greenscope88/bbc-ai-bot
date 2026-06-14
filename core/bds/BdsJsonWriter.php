<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsMockSheetParser.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsValidator.php';

/**
 * BDS Phase 3 — JSON Writer Dry-run.
 *
 * Chains Parser + Validator and writes preview JSON + reports locally only.
 *
 * @see docs/BATS_DATA_CONTRACT.md §6
 * @see docs/BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md Phase 3
 */
final class BdsJsonWriter
{
  public const SCHEMA_VERSION = 'bds_data_contract.v1';
  public const DATA_CATEGORY = 'tenant_private_knowledge';

  /** @var array<string, string> */
  public const TAB_OUTPUT_FILES = [
    'company_profile' => 'company_profile.json',
    'qa' => 'service_qa.json',
    'external_product_links' => 'external_product_links.json',
    'service_items' => 'service_items.json',
    'special_prices' => 'special_prices.json',
  ];

  /** @var BdsMockSheetParser */
  private $parser;

  /** @var BdsValidator */
  private $validator;

  /** @var string */
  private $outputRoot;

  public function __construct(BdsMockSheetParser $parser, BdsValidator $validator, string $outputRoot)
  {
    $this->parser = $parser;
    $this->validator = $validator;
    $this->outputRoot = rtrim($outputRoot, "/\\");
  }

  /**
   * @param array<string, list<array<string, mixed>>> $tabs
   * @return array<string, mixed>
   */
  public function writeFromMockTabs(string $tenantSno, array $tabs, ?string $sourceSheetId = null): array
  {
    $normalized = $this->parser->parse($tenantSno, $tabs);
    $validation = $this->validator->validate($normalized);

    return $this->writeDryRun($tenantSno, $normalized, $validation, $sourceSheetId);
  }

  /**
   * @param array<string, mixed> $normalized
   * @param array<string, mixed> $validation
   * @param array<string, string>|null $uploadSource source_type, upload_session_id, stored_filename
   * @return array<string, mixed>
   */
  public function writeDryRun(
    string $tenantSno,
    array $normalized,
    array $validation,
    ?string $sourceSheetId = null,
    ?array $uploadSource = null
  ): array {
    $startedAt = gmdate('Y-m-d\TH:i:s\Z');
    $validationOk = isset($validation['ok']) && $validation['ok'] === true;
    $errors = isset($validation['errors']) && is_array($validation['errors']) ? $validation['errors'] : [];
    $warnings = isset($validation['warnings']) && is_array($validation['warnings']) ? $validation['warnings'] : [];

    $knowledgeDir = $this->getKnowledgeDir($tenantSno);
    $reportsDir = $this->getReportsDir($tenantSno);
    $this->ensureDirectory($reportsDir);

    $generatedFiles = [];
    $status = 'failed';

    if ($validationOk) {
      $this->clearKnowledgeJsonFiles($knowledgeDir);
      $this->ensureDirectory($knowledgeDir);
      $publishedAt = gmdate('Y-m-d\TH:i:s\Z');
      $generatedFiles = $this->writeKnowledgeJsonFiles($tenantSno, $normalized, $knowledgeDir, $publishedAt, $sourceSheetId, $uploadSource);
      $status = 'dry_run_success';
      $this->removeFileIfExists($reportsDir . DIRECTORY_SEPARATOR . 'error_report.json');
    } else {
      $this->clearKnowledgeJsonFiles($knowledgeDir);
      $this->writeErrorReport($reportsDir, $errors);
    }

    $finishedAt = gmdate('Y-m-d\TH:i:s\Z');

    $syncReportPath = $this->writeSyncReport(
      $reportsDir,
      $tenantSno,
      $startedAt,
      $finishedAt,
      $generatedFiles,
      $status,
      $uploadSource
    );

    $validationReportPath = $this->writeValidationReport(
      $reportsDir,
      $validationOk,
      $warnings,
      count($errors)
    );

    $reportFiles = [$syncReportPath, $validationReportPath];
    if (!$validationOk) {
      $reportFiles[] = $reportsDir . DIRECTORY_SEPARATOR . 'error_report.json';
    }

    return [
      'ok' => $validationOk,
      'status' => $status,
      'dry_run' => true,
      'tenant_sno' => $tenantSno,
      'data_category' => self::DATA_CATEGORY,
      'phase' => 3,
      'knowledge_dir' => $knowledgeDir,
      'reports_dir' => $reportsDir,
      'generated_files' => $generatedFiles,
      'report_files' => $reportFiles,
      'validation' => $validation,
      'started_at' => $startedAt,
      'finished_at' => $finishedAt,
    ];
  }

  public function getOutputRoot(): string
  {
    return $this->outputRoot;
  }

  private function getKnowledgeDir(string $tenantSno): string
  {
    return $this->outputRoot
      . DIRECTORY_SEPARATOR . 'tenants'
      . DIRECTORY_SEPARATOR . $tenantSno
      . DIRECTORY_SEPARATOR . 'knowledge';
  }

  private function getReportsDir(string $tenantSno): string
  {
    return $this->outputRoot
      . DIRECTORY_SEPARATOR . 'tenants'
      . DIRECTORY_SEPARATOR . $tenantSno
      . DIRECTORY_SEPARATOR . 'reports';
  }

  /**
   * @param array<string, mixed> $normalized
   * @return list<string>
   */
  private function writeKnowledgeJsonFiles(
    string $tenantSno,
    array $normalized,
    string $knowledgeDir,
    string $publishedAt,
    ?string $sourceSheetId,
    ?array $uploadSource = null
  ): array {
    $paths = [];

    $companyProfile = $this->buildCompanyProfileDocument($tenantSno, $normalized, $publishedAt, $sourceSheetId, $uploadSource);
    $paths[] = $this->writeJsonFile($knowledgeDir . DIRECTORY_SEPARATOR . self::TAB_OUTPUT_FILES['company_profile'], $companyProfile);

    $itemTabs = [
      'qa' => isset($normalized['qa']) && is_array($normalized['qa']) ? $normalized['qa'] : [],
      'external_product_links' => isset($normalized['external_product_links']) && is_array($normalized['external_product_links']) ? $normalized['external_product_links'] : [],
      'service_items' => isset($normalized['service_items']) && is_array($normalized['service_items']) ? $normalized['service_items'] : [],
      'special_prices' => isset($normalized['special_prices']) && is_array($normalized['special_prices']) ? $normalized['special_prices'] : [],
    ];

    foreach ($itemTabs as $tab => $items) {
      $document = $this->buildItemsDocument($tenantSno, $tab, $items, $publishedAt, $sourceSheetId, $uploadSource);
      $paths[] = $this->writeJsonFile($knowledgeDir . DIRECTORY_SEPARATOR . self::TAB_OUTPUT_FILES[$tab], $document);
    }

    return $paths;
  }

  /**
   * @param array<string, mixed> $normalized
   * @return array<string, mixed>
   */
  private function buildCompanyProfileDocument(
    string $tenantSno,
    array $normalized,
    string $publishedAt,
    ?string $sourceSheetId,
    ?array $uploadSource = null
  ): array {
    $profile = isset($normalized['company_profile']) && is_array($normalized['company_profile'])
      ? $normalized['company_profile']
      : [];

    $document = [
      'schema_version' => self::SCHEMA_VERSION,
      'data_category' => self::DATA_CATEGORY,
      'tenant_sno' => $tenantSno,
      'source_tab' => 'company_profile',
      'published_at' => $publishedAt,
      'profile' => $profile,
    ];

    if ($sourceSheetId !== null && $sourceSheetId !== '') {
      $document['source_sheet_id'] = $sourceSheetId;
    }
    $this->applyUploadSourceMetadata($document, $uploadSource);

    return $document;
  }

  /**
   * @param list<array<string, mixed>> $items
   * @return array<string, mixed>
   */
  private function buildItemsDocument(
    string $tenantSno,
    string $sourceTab,
    array $items,
    string $publishedAt,
    ?string $sourceSheetId,
    ?array $uploadSource = null
  ): array {
    $document = [
      'schema_version' => self::SCHEMA_VERSION,
      'data_category' => self::DATA_CATEGORY,
      'tenant_sno' => $tenantSno,
      'source_tab' => $sourceTab,
      'published_at' => $publishedAt,
      'items' => $items,
    ];

    if ($sourceSheetId !== null && $sourceSheetId !== '') {
      $document['source_sheet_id'] = $sourceSheetId;
    }
    $this->applyUploadSourceMetadata($document, $uploadSource);

    return $document;
  }

  /**
   * @param array<string, mixed> $document
   * @param array<string, string>|null $uploadSource
   */
  private function applyUploadSourceMetadata(array &$document, ?array $uploadSource): void
  {
    if ($uploadSource === null) {
      return;
    }

    if (!empty($uploadSource['source_type'])) {
      $document['source_type'] = (string) $uploadSource['source_type'];
    }
    if (!empty($uploadSource['upload_session_id'])) {
      $document['upload_session_id'] = (string) $uploadSource['upload_session_id'];
    }
    if (!empty($uploadSource['stored_filename'])) {
      $document['stored_filename'] = (string) $uploadSource['stored_filename'];
    }
  }

  /**
   * @param list<string> $generatedFiles
   * @param array<string, string>|null $uploadSource
   */
  private function writeSyncReport(
    string $reportsDir,
    string $tenantSno,
    string $startedAt,
    string $finishedAt,
    array $generatedFiles,
    string $status,
    ?array $uploadSource = null
  ): string {
    $payload = [
      'tenant_sno' => $tenantSno,
      'data_category' => self::DATA_CATEGORY,
      'phase' => 3,
      'dry_run' => true,
      'status' => $status,
      'started_at' => $startedAt,
      'finished_at' => $finishedAt,
      'generated_files' => array_map(static function (string $path): string {
        return basename($path);
      }, $generatedFiles),
    ];

    if ($uploadSource !== null) {
      if (!empty($uploadSource['source_type'])) {
        $payload['source_type'] = (string) $uploadSource['source_type'];
      }
      if (!empty($uploadSource['upload_session_id'])) {
        $payload['upload_session_id'] = (string) $uploadSource['upload_session_id'];
      }
      if (!empty($uploadSource['stored_filename'])) {
        $payload['stored_filename'] = (string) $uploadSource['stored_filename'];
      }
    }

    $path = $reportsDir . DIRECTORY_SEPARATOR . 'sync_report.json';
    $this->writeJsonFile($path, $payload);

    return $path;
  }

  /**
   * @param list<string> $warnings
   */
  private function writeValidationReport(string $reportsDir, bool $validationOk, array $warnings, int $errorCount): string
  {
    $payload = [
      'validation_ok' => $validationOk,
      'warnings' => $warnings,
      'error_count' => $errorCount,
      'validated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    $path = $reportsDir . DIRECTORY_SEPARATOR . 'validation_report.json';
    $this->writeJsonFile($path, $payload);

    return $path;
  }

  /**
   * @param list<array<string, mixed>> $errors
   */
  private function writeErrorReport(string $reportsDir, array $errors): string
  {
    $payload = [
      'errors' => $errors,
      'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    $path = $reportsDir . DIRECTORY_SEPARATOR . 'error_report.json';
    $this->writeJsonFile($path, $payload);

    return $path;
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function writeJsonFile(string $path, array $payload): string
  {
    $this->ensureDirectory(dirname($path));
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
      throw new RuntimeException('Failed to encode JSON for ' . $path);
    }

    if (file_put_contents($path, $json . PHP_EOL) === false) {
      throw new RuntimeException('Failed to write JSON file: ' . $path);
    }

    return $path;
  }

  private function ensureDirectory(string $directory): void
  {
    if (is_dir($directory)) {
      return;
    }

    if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
      throw new RuntimeException('Failed to create directory: ' . $directory);
    }
  }

  private function clearKnowledgeJsonFiles(string $knowledgeDir): void
  {
    if (!is_dir($knowledgeDir)) {
      return;
    }

    foreach (self::TAB_OUTPUT_FILES as $filename) {
      $this->removeFileIfExists($knowledgeDir . DIRECTORY_SEPARATOR . $filename);
    }
  }

  private function removeFileIfExists(string $path): void
  {
    if (is_file($path)) {
      unlink($path);
    }
  }
}
