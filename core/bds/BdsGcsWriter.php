<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsJsonWriter.php';

/**
 * BDS Phase 5 — GCS Writer Controlled Mode.
 *
 * Produces GCS Upload Plan and Payload Preview only. No bucket writes.
 *
 * @see docs/BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md Phase 5
 * @see docs/BATS_DATA_SYNC_RUNBOOK.md §11
 */
final class BdsGcsWriter
{
  public const SCHEMA_VERSION = 'bds_gcs_upload_plan.v1';
  public const PAYLOAD_PREVIEW_SCHEMA_VERSION = 'bds_gcs_payload_preview.v1';
  public const DATA_CATEGORY = 'tenant_private_knowledge';
  public const KNOWLEDGE_PREFIX = 'tenants';
  public const KNOWLEDGE_SEGMENT = 'knowledge';

  /** @var string */
  private $outputRoot;

  public function __construct(string $outputRoot)
  {
    $this->outputRoot = rtrim($outputRoot, "/\\");
  }

  /**
   * @param array<string, array<string, mixed>> $knowledgeJson keyed by output filename
   * @param array<string, mixed> $validation BdsValidator result
   * @return array<string, mixed>
   */
  public function buildUploadPlan(string $tenantSno, array $knowledgeJson, array $validation): array
  {
    $validationOk = isset($validation['ok']) && $validation['ok'] === true;
    $plannedAt = gmdate('Y-m-d\TH:i:s\Z');

    if (!$validationOk) {
      return [
        'ok' => false,
        'status' => 'validation_failed',
        'controlled_mode' => true,
        'dry_run' => true,
        'gcs_write_enabled' => false,
        'tenant_sno' => $tenantSno,
        'data_category' => self::DATA_CATEGORY,
        'phase' => 5,
        'validation_ok' => false,
        'planned_at' => $plannedAt,
        'uploads' => [],
        'blocked_reason' => 'validation_failed',
      ];
    }

    $uploads = [];
    foreach (BdsJsonWriter::TAB_OUTPUT_FILES as $filename) {
      if (!isset($knowledgeJson[$filename]) || !is_array($knowledgeJson[$filename])) {
        return [
          'ok' => false,
          'status' => 'missing_knowledge_json',
          'controlled_mode' => true,
          'dry_run' => true,
          'gcs_write_enabled' => false,
          'tenant_sno' => $tenantSno,
          'data_category' => self::DATA_CATEGORY,
          'phase' => 5,
          'validation_ok' => true,
          'planned_at' => $plannedAt,
          'uploads' => [],
          'blocked_reason' => 'missing_knowledge_json:' . $filename,
        ];
      }

      $objectPath = $this->buildObjectPath($tenantSno, $filename);
      if (!$this->isAllowedObjectPath($tenantSno, $objectPath)) {
        throw new RuntimeException('Blocked object path: ' . $objectPath);
      }

      $uploads[] = [
        'object_path' => $objectPath,
        'filename' => $filename,
        'content_type' => 'application/json',
      ];
    }

    return [
      'ok' => true,
      'status' => 'upload_plan_ready',
      'schema_version' => self::SCHEMA_VERSION,
      'controlled_mode' => true,
      'dry_run' => true,
      'gcs_write_enabled' => false,
      'tenant_sno' => $tenantSno,
      'data_category' => self::DATA_CATEGORY,
      'phase' => 5,
      'validation_ok' => true,
      'planned_at' => $plannedAt,
      'uploads' => $uploads,
    ];
  }

  /**
   * @param array<string, array<string, mixed>> $knowledgeJson keyed by output filename
   * @param array<string, mixed> $validation
   * @return array<string, mixed>
   */
  public function writeControlledPreview(string $tenantSno, array $knowledgeJson, array $validation): array
  {
    $plan = $this->buildUploadPlan($tenantSno, $knowledgeJson, $validation);
    $gcsDir = $this->getGcsPreviewDir($tenantSno);

    if ($plan['ok'] !== true) {
      $this->clearPreviewFiles($gcsDir);

      return [
        'ok' => false,
        'status' => $plan['status'],
        'controlled_mode' => true,
        'dry_run' => true,
        'gcs_write_enabled' => false,
        'tenant_sno' => $tenantSno,
        'phase' => 5,
        'gcs_preview_dir' => $gcsDir,
        'upload_plan_path' => null,
        'payload_preview_path' => null,
        'upload_plan' => $plan,
        'blocked_reason' => $plan['blocked_reason'] ?? 'validation_failed',
      ];
    }

    $payloadPreview = $this->buildPayloadPreview($tenantSno, $knowledgeJson, $plan);
    $uploadPlanPath = $this->writeJsonFile($gcsDir . DIRECTORY_SEPARATOR . 'upload_plan.json', $plan);
    $payloadPreviewPath = $this->writeJsonFile($gcsDir . DIRECTORY_SEPARATOR . 'gcs_payload_preview.json', $payloadPreview);

    return [
      'ok' => true,
      'status' => 'controlled_preview_written',
      'controlled_mode' => true,
      'dry_run' => true,
      'gcs_write_enabled' => false,
      'tenant_sno' => $tenantSno,
      'phase' => 5,
      'gcs_preview_dir' => $gcsDir,
      'upload_plan_path' => $uploadPlanPath,
      'payload_preview_path' => $payloadPreviewPath,
      'upload_plan' => $plan,
      'payload_preview' => $payloadPreview,
    ];
  }

  public function buildObjectPath(string $tenantSno, string $filename): string
  {
    return self::KNOWLEDGE_PREFIX
      . '/' . $tenantSno
      . '/' . self::KNOWLEDGE_SEGMENT
      . '/' . $filename;
  }

  public function isAllowedObjectPath(string $tenantSno, string $objectPath): bool
  {
    $normalized = str_replace('\\', '/', $objectPath);
    $expectedPrefix = self::KNOWLEDGE_PREFIX . '/' . $tenantSno . '/' . self::KNOWLEDGE_SEGMENT . '/';

    if (strpos($normalized, 'shared/') === 0 || strpos($normalized, '/shared/') !== false) {
      return false;
    }

    if (strpos($normalized, 'drive') !== false || strpos($normalized, 'google') !== false) {
      return false;
    }

    if (strpos($normalized, $expectedPrefix) !== 0) {
      return false;
    }

    $basename = basename($normalized);
    return in_array($basename, BdsJsonWriter::TAB_OUTPUT_FILES, true);
  }

  /**
   * @param array<string, array<string, mixed>> $knowledgeJson
   * @param array<string, mixed> $plan
   * @return array<string, mixed>
   */
  private function buildPayloadPreview(string $tenantSno, array $knowledgeJson, array $plan): array
  {
    $objects = [];

    if (!isset($plan['uploads']) || !is_array($plan['uploads'])) {
      return [
        'schema_version' => self::PAYLOAD_PREVIEW_SCHEMA_VERSION,
        'controlled_mode' => true,
        'tenant_sno' => $tenantSno,
        'phase' => 5,
        'objects' => [],
      ];
    }

    foreach ($plan['uploads'] as $upload) {
      if (!is_array($upload)) {
        continue;
      }

      $filename = isset($upload['filename']) ? (string) $upload['filename'] : '';
      $objectPath = isset($upload['object_path']) ? (string) $upload['object_path'] : '';

      if ($filename === '' || $objectPath === '' || !isset($knowledgeJson[$filename])) {
        continue;
      }

      $objects[] = [
        'object_path' => $objectPath,
        'filename' => $filename,
        'content_type' => 'application/json',
        'payload' => $knowledgeJson[$filename],
      ];
    }

    return [
      'schema_version' => self::PAYLOAD_PREVIEW_SCHEMA_VERSION,
      'controlled_mode' => true,
      'dry_run' => true,
      'gcs_write_enabled' => false,
      'tenant_sno' => $tenantSno,
      'data_category' => self::DATA_CATEGORY,
      'phase' => 5,
      'previewed_at' => gmdate('Y-m-d\TH:i:s\Z'),
      'objects' => $objects,
    ];
  }

  private function getGcsPreviewDir(string $tenantSno): string
  {
    return $this->outputRoot
      . DIRECTORY_SEPARATOR . 'tenants'
      . DIRECTORY_SEPARATOR . $tenantSno
      . DIRECTORY_SEPARATOR . 'gcs';
  }

  private function clearPreviewFiles(string $gcsDir): void
  {
    $this->removeFileIfExists($gcsDir . DIRECTORY_SEPARATOR . 'upload_plan.json');
    $this->removeFileIfExists($gcsDir . DIRECTORY_SEPARATOR . 'gcs_payload_preview.json');
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function writeJsonFile(string $path, array $payload): string
  {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
      throw new RuntimeException('Failed to create directory: ' . $directory);
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
      throw new RuntimeException('Failed to encode JSON for ' . $path);
    }

    if (file_put_contents($path, $json . PHP_EOL) === false) {
      throw new RuntimeException('Failed to write JSON file: ' . $path);
    }

    return $path;
  }

  private function removeFileIfExists(string $path): void
  {
    if (is_file($path)) {
      unlink($path);
    }
  }
}
