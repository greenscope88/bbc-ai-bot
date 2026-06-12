<?php
declare(strict_types=1);

/**
 * BDS Phase 6B-2A — Google Drive read-only client (Platform Layer).
 *
 * Service Account auth, files.get(), files.list(), folder metadata read.
 * No Scanner, Metadata Contract, promote, GCS, or Shared Layer logic.
 *
 * @see docs/BATS_DRIVE_CONNECTOR_SCOPE.md §5 Phase 6B
 * @see docs/BATS_DATA_SOURCE_REGISTRY.md §6.5
 */
final class BdsDriveClient
{
  public const DRIVE_READONLY_SCOPE = 'https://www.googleapis.com/auth/drive.readonly';

  /** Pilot travel_b private_knowledge_folder_id (01_Private_Layer). */
  public const PILOT_PRIVATE_KNOWLEDGE_FOLDER_ID = '17wrq-rrvc7ezclhWlbvdTKxHSf_Hw8pi';

  public const MIME_FOLDER = 'application/vnd.google-apps.folder';

  private const DEFAULT_LIST_PAGE_SIZE = 100;

  private const MAX_RETRIES = 3;

  private const RETRY_BASE_DELAY_MS = 500;

  /** @var \Google\Service\Drive */
  private $driveService;

  /** @var string|null */
  private $credentialsPath;

  /**
   * @param string|null $credentialsPath Service account JSON path; resolved from env / secrets/ when null.
   * @param \Google\Service\Drive|null $driveService Optional injected Drive client (tests).
   */
  public function __construct(?string $credentialsPath = null, ?\Google\Service\Drive $driveService = null)
  {
    $this->credentialsPath = $credentialsPath !== null ? trim($credentialsPath) : null;

    if ($driveService !== null) {
      $this->driveService = $driveService;
      return;
    }

    $this->driveService = $this->createDriveService($this->resolveCredentialsPath());
  }

  /**
   * @return \Google\Service\Drive
   */
  public function getDriveService()
  {
    return $this->driveService;
  }

  /**
   * @return string|null Resolved credentials path (diagnostics only; never returns key contents).
   */
  public function getCredentialsPath(): ?string
  {
    if ($this->credentialsPath !== null && $this->credentialsPath !== '') {
      return $this->credentialsPath;
    }

    return $this->resolveCredentialsPath();
  }

  /**
   * files.get() — minimal file / folder metadata.
   *
   * @return array{id: string, name: string, mimeType: string}
   */
  public function getFileMetadata(string $fileId): array
  {
    $fileId = trim($fileId);
    if ($fileId === '') {
      throw new \InvalidArgumentException('file_id is required.');
    }

    $file = $this->executeWithRetry(function () use ($fileId) {
      return $this->driveService->files->get($fileId, [
        'fields' => 'id,name,mimeType',
        'supportsAllDrives' => true,
      ]);
    });

    return [
      'id' => (string) $file->getId(),
      'name' => (string) $file->getName(),
      'mimeType' => (string) $file->getMimeType(),
    ];
  }

  /**
   * Folder metadata read: id, name, mimeType, child_count (direct children only).
   *
   * @return array{
   *   id: string,
   *   name: string,
   *   mimeType: string,
   *   child_count: int
   * }
   */
  public function readFolderMetadata(string $folderId): array
  {
    $metadata = $this->getFileMetadata($folderId);

    if ($metadata['mimeType'] !== self::MIME_FOLDER) {
      throw new \InvalidArgumentException(
        'file_id is not a folder (mimeType=' . $metadata['mimeType'] . ').'
      );
    }

    $metadata['child_count'] = $this->countChildren($folderId);

    return $metadata;
  }

  /**
   * files.list() — direct children of a folder (paginated; returns one page).
   *
   * @param array{pageSize?: int, pageToken?: string, fields?: string} $options
   * @return array{
   *   files: list<array{id: string, name: string, mimeType: string}>,
   *   nextPageToken: string|null
   * }
   */
  public function listFiles(string $folderId, array $options = []): array
  {
    $folderId = trim($folderId);
    if ($folderId === '') {
      throw new \InvalidArgumentException('folder_id is required.');
    }

    $pageSize = isset($options['pageSize']) ? (int) $options['pageSize'] : self::DEFAULT_LIST_PAGE_SIZE;
    if ($pageSize < 1) {
      $pageSize = self::DEFAULT_LIST_PAGE_SIZE;
    }

    $listParams = [
      'q' => $this->buildChildrenQuery($folderId),
      'pageSize' => $pageSize,
      'fields' => isset($options['fields']) && is_string($options['fields']) && $options['fields'] !== ''
        ? $options['fields']
        : 'nextPageToken,files(id,name,mimeType)',
      'supportsAllDrives' => true,
      'includeItemsFromAllDrives' => true,
    ];

    if (isset($options['pageToken']) && is_string($options['pageToken']) && $options['pageToken'] !== '') {
      $listParams['pageToken'] = $options['pageToken'];
    }

    $response = $this->executeWithRetry(function () use ($listParams) {
      return $this->driveService->files->listFiles($listParams);
    });

    $files = [];
    $rawFiles = $response->getFiles();
    if (is_array($rawFiles)) {
      foreach ($rawFiles as $file) {
        $files[] = [
          'id' => (string) $file->getId(),
          'name' => (string) $file->getName(),
          'mimeType' => (string) $file->getMimeType(),
        ];
      }
    }

    $nextPageToken = $response->getNextPageToken();

    return [
      'files' => $files,
      'nextPageToken' => is_string($nextPageToken) && $nextPageToken !== '' ? $nextPageToken : null,
    ];
  }

  /**
   * Count all direct children (all pages).
   */
  public function countChildren(string $folderId): int
  {
    $total = 0;
    $pageToken = null;

    do {
      $options = ['pageSize' => self::DEFAULT_LIST_PAGE_SIZE];
      if ($pageToken !== null) {
        $options['pageToken'] = $pageToken;
      }

      $page = $this->listFiles($folderId, $options);
      $total += count($page['files']);
      $pageToken = $page['nextPageToken'];
    } while ($pageToken !== null);

    return $total;
  }

  private function buildChildrenQuery(string $folderId): string
  {
    $escapedId = str_replace("'", "\\'", $folderId);

    return "'" . $escapedId . "' in parents and trashed = false";
  }

  /**
   * @template T
   * @param callable(): T $operation
   * @return T
   */
  private function executeWithRetry(callable $operation)
  {
    $attempt = 0;
    $lastException = null;

    while ($attempt < self::MAX_RETRIES) {
      try {
        return $operation();
      } catch (\Google\Service\Exception $exception) {
        $lastException = $exception;
        if (!$this->isRetryableGoogleException($exception) || $attempt >= self::MAX_RETRIES - 1) {
          throw $exception;
        }
      } catch (\Throwable $exception) {
        $lastException = $exception;
        if (!$this->isRetryableThrowable($exception) || $attempt >= self::MAX_RETRIES - 1) {
          throw $exception;
        }
      }

      usleep(self::RETRY_BASE_DELAY_MS * 1000 * (2 ** $attempt));
      ++$attempt;
    }

    if ($lastException instanceof \Throwable) {
      throw $lastException;
    }

    throw new \RuntimeException('Drive API retry exhausted without exception.');
  }

  private function isRetryableGoogleException(\Google\Service\Exception $exception): bool
  {
    $code = (int) $exception->getCode();
    if (in_array($code, [429, 500, 502, 503, 504], true)) {
      return true;
    }

    $message = strtolower($exception->getMessage());
    if (strpos($message, 'rate limit') !== false) {
      return true;
    }
    if (strpos($message, 'backend error') !== false) {
      return true;
    }

    return false;
  }

  private function isRetryableThrowable(\Throwable $exception): bool
  {
    if ($exception instanceof \Google\Service\Exception) {
      return $this->isRetryableGoogleException($exception);
    }

    $message = strtolower($exception->getMessage());
    if (strpos($message, 'connection') !== false) {
      return true;
    }
    if (strpos($message, 'timed out') !== false) {
      return true;
    }

    return false;
  }

  private function createDriveService(string $credentialsPath): \Google\Service\Drive
  {
    if (!is_file($credentialsPath)) {
      throw new \RuntimeException('Google service account credentials file not found.');
    }

    $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (!is_file($autoload)) {
      throw new \RuntimeException('Composer autoload not found; run composer install in project root.');
    }

    require_once $autoload;

    if (!class_exists(\Google\Service\Drive::class)) {
      throw new \RuntimeException(
        'Google Drive API client is not installed; add "Drive" to composer.json extra.google/apiclient-services and run composer update.'
      );
    }

    $client = new \Google\Client();
    $client->setApplicationName('BBC AI Bot BDS');
    $client->setScopes([self::DRIVE_READONLY_SCOPE]);
    $client->setAuthConfig($credentialsPath);
    $client->setAccessType('offline');

    return new \Google\Service\Drive($client);
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
