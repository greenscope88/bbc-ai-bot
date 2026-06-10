<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsGcsWriter.php';

/**
 * BDS Phase 5C — Controlled Real GCS Write.
 *
 * Uploads validated tenant_private_knowledge JSON to GCS under strict gates.
 * No Drive API, no shared/, no delete operations.
 *
 * @see docs/BATS_DATA_SYNC_RUNBOOK.md §11
 */
final class BdsGcsUploader
{
  public const PILOT_TENANT_SNO = '5f99b8d665e8444d';
  public const DEFAULT_BUCKET = 'bbc-ai-saas-data';
  public const STORAGE_SCOPE = 'https://www.googleapis.com/auth/devstorage.read_write';

  /** @var string */
  private $bucket;

  /** @var string */
  private $credentialsPath;

  /** @var string */
  private $outputRoot;

  /** @var BdsGcsWriter */
  private $gcsWriter;

  public function __construct(?string $bucket = null, ?string $credentialsPath = null, ?string $outputRoot = null)
  {
    $this->bucket = $this->resolveBucket($bucket);
    $this->credentialsPath = $this->resolveCredentialsPath($credentialsPath);
    $this->outputRoot = rtrim($outputRoot ?? $this->defaultOutputRoot(), "/\\");
    $this->gcsWriter = new BdsGcsWriter($this->outputRoot);
  }

  /**
   * @return array{open: bool, dry_run: bool, gcs_write_enabled: bool, target_sno: string, reason: string}
   */
  public static function evaluateWriteGate(): array
  {
    $dryRunRaw = self::envString('BDS_DRY_RUN');
    $writeEnabledRaw = self::envString('BDS_GCS_WRITE_ENABLED');
    $targetSno = self::envString('BDS_TARGET_SNO');

    $dryRun = !self::isEnvFalse($dryRunRaw);
    $gcsWriteEnabled = self::isEnvTrue($writeEnabledRaw);

    if ($dryRun) {
      return [
        'open' => false,
        'dry_run' => true,
        'gcs_write_enabled' => $gcsWriteEnabled,
        'target_sno' => $targetSno,
        'reason' => 'dry_run_not_false',
      ];
    }

    if (!$gcsWriteEnabled) {
      return [
        'open' => false,
        'dry_run' => false,
        'gcs_write_enabled' => false,
        'target_sno' => $targetSno,
        'reason' => 'gcs_write_not_enabled',
      ];
    }

    if ($targetSno === '') {
      return [
        'open' => false,
        'dry_run' => false,
        'gcs_write_enabled' => true,
        'target_sno' => '',
        'reason' => 'target_sno_missing',
      ];
    }

    if ($targetSno !== self::PILOT_TENANT_SNO) {
      return [
        'open' => false,
        'dry_run' => false,
        'gcs_write_enabled' => true,
        'target_sno' => $targetSno,
        'reason' => 'target_sno_not_pilot',
      ];
    }

    return [
      'open' => true,
      'dry_run' => false,
      'gcs_write_enabled' => true,
      'target_sno' => $targetSno,
      'reason' => 'gate_open',
    ];
  }

  /**
   * @param array<string, array<string, mixed>> $knowledgeJson
   * @param array<string, mixed> $validation
   * @return array<string, mixed>
   */
  public function uploadKnowledge(string $tenantSno, array $knowledgeJson, array $validation, ?string $sourceSheetId = null): array
  {
    $startedAt = gmdate('Y-m-d\TH:i:s\Z');
    $gate = self::evaluateWriteGate();

    if ($gate['open'] !== true) {
      return $this->blockedResult($tenantSno, $startedAt, $gate['reason'], $gate);
    }

    if ($tenantSno !== self::PILOT_TENANT_SNO || $tenantSno !== $gate['target_sno']) {
      return $this->blockedResult($tenantSno, $startedAt, 'tenant_not_allowed', $gate);
    }

    $plan = $this->gcsWriter->buildUploadPlan($tenantSno, $knowledgeJson, $validation);
    if (($plan['ok'] ?? false) !== true) {
      return [
        'ok' => false,
        'status' => 'validation_failed',
        'phase' => '5c',
        'tenant_sno' => $tenantSno,
        'bucket' => $this->bucket,
        'dry_run' => false,
        'gcs_write_enabled' => true,
        'started_at' => $startedAt,
        'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'uploaded_objects' => [],
        'blocked_reason' => $plan['blocked_reason'] ?? 'validation_failed',
        'validation' => $validation,
      ];
    }

    $accessToken = $this->fetchAccessToken();
    $backupDir = $this->getRollbackBackupDir($tenantSno);
    $this->ensureDirectory($backupDir);

    $uploaded = [];
    $errors = [];

    foreach ($plan['uploads'] as $upload) {
      if (!is_array($upload)) {
        continue;
      }

      $objectPath = isset($upload['object_path']) ? (string) $upload['object_path'] : '';
      $filename = isset($upload['filename']) ? (string) $upload['filename'] : '';
      $contentType = isset($upload['content_type']) ? (string) $upload['content_type'] : 'application/json';

      if ($objectPath === '' || $filename === '' || !isset($knowledgeJson[$filename])) {
        $errors[] = ['object_path' => $objectPath, 'error' => 'missing_payload'];
        continue;
      }

      if (!$this->gcsWriter->isAllowedObjectPath($tenantSno, $objectPath)) {
        $errors[] = ['object_path' => $objectPath, 'error' => 'path_not_allowed'];
        continue;
      }

      $this->backupExistingObject($accessToken, $objectPath, $backupDir, $filename);

      $body = json_encode($knowledgeJson[$filename], JSON_UNESCAPED_UNICODE);
      if ($body === false) {
        $errors[] = ['object_path' => $objectPath, 'error' => 'json_encode_failed'];
        continue;
      }

      $uploadResp = $this->uploadObject($accessToken, $objectPath, $body, $contentType);
      if ($uploadResp['status'] < 200 || $uploadResp['status'] >= 300) {
        $errors[] = [
          'object_path' => $objectPath,
          'error' => 'upload_failed',
          'http_status' => $uploadResp['status'],
        ];
        continue;
      }

      $uploaded[] = [
        'object_path' => $objectPath,
        'filename' => $filename,
        'content_type' => $contentType,
        'gs_uri' => 'gs://' . $this->bucket . '/' . $objectPath,
        'http_status' => $uploadResp['status'],
        'md5_hash' => $this->extractMd5Hash($uploadResp['body']),
      ];
    }

    $finishedAt = gmdate('Y-m-d\TH:i:s\Z');
    $ok = count($uploaded) === 5 && count($errors) === 0;

    $result = [
      'ok' => $ok,
      'status' => $ok ? 'gcs_write_success' : 'gcs_write_partial_or_failed',
      'phase' => '5c',
      'tenant_sno' => $tenantSno,
      'bucket' => $this->bucket,
      'dry_run' => false,
      'gcs_write_enabled' => true,
      'data_category' => BdsGcsWriter::DATA_CATEGORY,
      'started_at' => $startedAt,
      'finished_at' => $finishedAt,
      'upload_plan' => $plan,
      'uploaded_objects' => $uploaded,
      'errors' => $errors,
      'rollback_backup_dir' => $backupDir,
    ];

    if ($sourceSheetId !== null && $sourceSheetId !== '') {
      $result['source_sheet_id'] = $sourceSheetId;
    }

    $reportPath = $this->writeJsonReport($tenantSno, $result);
    $result['write_report_path'] = $reportPath;

    return $result;
  }

  /**
   * @return array{status: int, body: string}
   */
  public function getObject(string $objectPath): array
  {
    return $this->getObjectWithToken($this->fetchAccessToken(), $objectPath);
  }

  /**
   * @return array{status: int, body: string, items: list<string>}
   */
  public function listKnowledgeObjects(string $tenantSno): array
  {
    $prefix = 'tenants/' . $tenantSno . '/knowledge/';
    $url = 'https://storage.googleapis.com/storage/v1/b/' . rawurlencode($this->bucket)
      . '/o?prefix=' . rawurlencode($prefix) . '&maxResults=20';

    $resp = $this->httpRequest('GET', $url, $this->fetchAccessToken());
    $items = [];

    if ($resp['status'] === 200) {
      $json = json_decode($resp['body'], true);
      if (is_array($json) && isset($json['items']) && is_array($json['items'])) {
        foreach ($json['items'] as $item) {
          if (is_array($item) && isset($item['name'])) {
            $items[] = (string) $item['name'];
          }
        }
      }
    }

    return [
      'status' => $resp['status'],
      'body' => $resp['body'],
      'items' => $items,
    ];
  }

  public function getBucket(): string
  {
    return $this->bucket;
  }

  /**
   * @param array<string, mixed> $gate
   * @return array<string, mixed>
   */
  private function blockedResult(string $tenantSno, string $startedAt, string $reason, array $gate): array
  {
    return [
      'ok' => false,
      'status' => 'write_blocked',
      'phase' => '5c',
      'tenant_sno' => $tenantSno,
      'bucket' => $this->bucket,
      'dry_run' => $gate['dry_run'] ?? true,
      'gcs_write_enabled' => $gate['gcs_write_enabled'] ?? false,
      'started_at' => $startedAt,
      'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
      'uploaded_objects' => [],
      'blocked_reason' => $reason,
      'gate' => $gate,
    ];
  }

  private function fetchAccessToken(): string
  {
    $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (!is_file($autoload)) {
      throw new RuntimeException('Composer autoload not found.');
    }
    require_once $autoload;

    if (!is_file($this->credentialsPath)) {
      throw new RuntimeException('Google service account credentials file not found.');
    }

    $client = new \Google\Client();
    $client->setAuthConfig($this->credentialsPath);
    $client->setScopes([self::STORAGE_SCOPE]);
    $token = $client->fetchAccessTokenWithAssertion();

    if (isset($token['error']) || !isset($token['access_token'])) {
      $error = isset($token['error']) ? (string) $token['error'] : 'missing_access_token';
      throw new RuntimeException('Failed to fetch GCS access token: ' . $error);
    }

    return (string) $token['access_token'];
  }

  /**
   * @return array{status: int, body: string}
   */
  private function uploadObject(string $accessToken, string $objectPath, string $body, string $contentType): array
  {
    $url = 'https://storage.googleapis.com/upload/storage/v1/b/' . rawurlencode($this->bucket)
      . '/o?uploadType=media&name=' . rawurlencode($objectPath);

    return $this->httpRequest('POST', $url, $accessToken, $body, [
      'Content-Type: ' . $contentType,
    ]);
  }

  /**
   * @return array{status: int, body: string}
   */
  private function getObjectWithToken(string $accessToken, string $objectPath): array
  {
    $url = 'https://storage.googleapis.com/storage/v1/b/' . rawurlencode($this->bucket)
      . '/o/' . rawurlencode($objectPath) . '?alt=media';

    return $this->httpRequest('GET', $url, $accessToken);
  }

  private function backupExistingObject(string $accessToken, string $objectPath, string $backupDir, string $filename): void
  {
    $existing = $this->getObjectWithToken($accessToken, $objectPath);
    if ($existing['status'] !== 200 || $existing['body'] === '') {
      return;
    }

    $backupPath = $backupDir . DIRECTORY_SEPARATOR . $filename;
    file_put_contents($backupPath, $existing['body']);
  }

  /**
   * @param list<string> $extraHeaders
   * @return array{status: int, body: string}
   */
  private function httpRequest(string $method, string $url, string $accessToken, ?string $body = null, array $extraHeaders = []): array
  {
    $headers = array_merge(['Authorization: Bearer ' . $accessToken], $extraHeaders);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($body !== null) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $responseBody = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
      'status' => $status,
      'body' => is_string($responseBody) ? $responseBody : '',
    ];
  }

  private function extractMd5Hash(string $body): string
  {
    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json['md5Hash'])) {
      return '';
    }

    return (string) $json['md5Hash'];
  }

  private function getRollbackBackupDir(string $tenantSno): string
  {
    return $this->outputRoot
      . DIRECTORY_SEPARATOR . 'tenants'
      . DIRECTORY_SEPARATOR . $tenantSno
      . DIRECTORY_SEPARATOR . 'gcs'
      . DIRECTORY_SEPARATOR . 'rollback_backup';
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function writeJsonReport(string $tenantSno, array $payload): string
  {
    $dir = $this->outputRoot
      . DIRECTORY_SEPARATOR . 'tenants'
      . DIRECTORY_SEPARATOR . $tenantSno
      . DIRECTORY_SEPARATOR . 'gcs';
    $this->ensureDirectory($dir);
    $path = $dir . DIRECTORY_SEPARATOR . 'write_report.json';
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
      throw new RuntimeException('Failed to encode write report JSON.');
    }
    file_put_contents($path, $json . PHP_EOL);

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

  private function defaultOutputRoot(): string
  {
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'output';
  }

  private function resolveBucket(?string $bucket): string
  {
    $candidate = $bucket !== null ? trim($bucket) : self::envString('BDS_GCS_BUCKET');
    if ($candidate === '') {
      $candidate = self::DEFAULT_BUCKET;
    }

    return $candidate;
  }

  private function resolveCredentialsPath(?string $credentialsPath): string
  {
    if ($credentialsPath !== null && trim($credentialsPath) !== '') {
      return trim($credentialsPath);
    }

    $envCandidates = [
      self::envString('BDS_GOOGLE_APPLICATION_CREDENTIALS'),
      self::envString('GOOGLE_APPLICATION_CREDENTIALS'),
    ];

    foreach ($envCandidates as $candidate) {
      if ($candidate !== '' && is_file($candidate)) {
        return $candidate;
      }
    }

    $secretsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'secrets';
    if (is_dir($secretsDir)) {
      $matches = glob($secretsDir . DIRECTORY_SEPARATOR . '*.json');
      if (is_array($matches)) {
        sort($matches, SORT_STRING);
        foreach ($matches as $match) {
          if (is_file($match)) {
            return $match;
          }
        }
      }
    }

    throw new RuntimeException('Google service account credentials path is not configured.');
  }

  private static function envString(string $name): string
  {
    $value = getenv($name);
    return is_string($value) ? trim($value) : '';
  }

  private static function isEnvTrue(string $value): bool
  {
    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
  }

  private static function isEnvFalse(string $value): bool
  {
    if ($value === '') {
      return false;
    }

    return in_array(strtolower($value), ['0', 'false', 'no', 'off'], true);
  }
}
