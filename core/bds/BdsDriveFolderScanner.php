<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsDriveClient.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsDriveFolderScanException.php';

/**
 * BDS Phase 6B-3 — Drive folder tree discovery / validation (Platform Layer).
 *
 * Read-only scan via BdsDriveClient; resolves folder IDs from Platform + Tenant Registries.
 * No Metadata Contract, promote, GCS, Shared processing, or Runtime Knowledge.
 *
 * @see docs/BATS_DATA_SOURCE_REGISTRY.md §6.5、§6.7
 * @see docs/BATS_DRIVE_CONNECTOR_SCOPE.md Phase 6B
 */
final class BdsDriveFolderScanner
{
    public const ROOT_ROLE_INDUSTRIES = 'industries';

    public const ROOT_ROLE_GLOBAL = 'global';

    public const ROOT_ROLE_REGISTRATIONS = 'registrations';

    /** @var BdsDriveClient */
    private $client;

    public function __construct(?BdsDriveClient $client = null, ?string $credentialsPath = null)
    {
        if ($client !== null) {
            $this->client = $client;
            return;
        }

        $this->client = new BdsDriveClient($credentialsPath);
    }

    public function getDriveClient(): BdsDriveClient
    {
        return $this->client;
    }

    /**
     * @return array{
     *   id: string,
     *   name: string,
     *   mimeType: string,
     *   parentIds: list<string>,
     *   childCount: int
     * }
     */
    public function scanFolder(string $folderId): array
    {
        $folderId = trim($folderId);
        if ($folderId === '') {
            throw new \InvalidArgumentException('folder_id is required.');
        }

        $metadata = $this->fetchFolderMetadataWithParents($folderId);

        if ($metadata['mimeType'] !== BdsDriveClient::MIME_FOLDER) {
            throw new BdsDriveFolderScanException(
                'file_id is not a folder (mimeType=' . $metadata['mimeType'] . ').',
                BdsDriveFolderScanException::NOT_A_FOLDER,
                $folderId
            );
        }

        return [
            'id' => $metadata['id'],
            'name' => $metadata['name'],
            'mimeType' => $metadata['mimeType'],
            'parentIds' => $metadata['parentIds'],
            'childCount' => $this->client->countChildren($folderId),
        ];
    }

    /**
     * @return array{files: list<array{id: string, name: string, mimeType: string}>}
     */
    public function scanChildren(string $folderId): array
    {
        $folderId = trim($folderId);
        if ($folderId === '') {
            throw new \InvalidArgumentException('folder_id is required.');
        }

        $allFiles = [];
        $pageToken = null;

        do {
            $options = ['pageSize' => 100];
            if ($pageToken !== null) {
                $options['pageToken'] = $pageToken;
            }

            $page = $this->client->listFiles($folderId, $options);
            foreach ($page['files'] as $file) {
                $allFiles[] = $file;
            }
            $pageToken = $page['nextPageToken'];
        } while ($pageToken !== null);

        return ['files' => $allFiles];
    }

    /**
     * Validate platform root folders from BdsPlatformDriveRegistryLoader.
     *
     * @return array{
     *   schema_version: string,
     *   roots: array<string, array<string, mixed>>,
     *   summary: array{total: int, ok: int, failed: int}
     * }
     */
    public function scanPlatformRoots(): array
    {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsPlatformDriveRegistryLoader.php';

        $registry = BdsPlatformDriveRegistryLoader::load();
        $rootsConfig = BdsPlatformDriveRegistryLoader::getPlatformRoots();

        $roles = [
            self::ROOT_ROLE_INDUSTRIES => [
                'registry_field' => 'industries_root_folder_id',
                'folder_id' => $rootsConfig['industries_root_folder_id'],
            ],
            self::ROOT_ROLE_GLOBAL => [
                'registry_field' => 'global_root_folder_id',
                'folder_id' => $rootsConfig['global_root_folder_id'],
            ],
            self::ROOT_ROLE_REGISTRATIONS => [
                'registry_field' => 'registrations_root_folder_id',
                'folder_id' => $rootsConfig['registrations_root_folder_id'],
            ],
        ];

        $roots = [];
        $ok = 0;
        $failed = 0;

        foreach ($roles as $role => $config) {
            $roots[$role] = $this->scanRegistryFolderEntry(
                $role,
                $config['registry_field'],
                $config['folder_id']
            );

            if (($roots[$role]['status'] ?? '') === 'ok') {
                ++$ok;
            } else {
                ++$failed;
            }
        }

        return [
            'schema_version' => $registry['schema_version'] ?? '',
            'roots' => $roots,
            'summary' => [
                'total' => count($roles),
                'ok' => $ok,
                'failed' => $failed,
            ],
        ];
    }

    /**
     * Validate tenant private_knowledge_folder_id from BdsSourceRegistryLoader.
     *
     * @return array<string, mixed>
     */
    public function scanTenantPrivateFolder(string $tenantKey): array
    {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsSourceRegistryLoader.php';

        $tenantKey = trim($tenantKey);
        if ($tenantKey === '') {
            throw new \InvalidArgumentException('tenant_key is required.');
        }

        $entry = BdsSourceRegistryLoader::loadByTenantKey($tenantKey);
        if ($entry === null) {
            return [
                'tenant_key' => $tenantKey,
                'status' => 'error',
                'error_code' => BdsDriveFolderScanException::REGISTRY_NOT_FOUND,
                'error' => 'Tenant registry entry not found.',
                'folder_id' => null,
                'folder' => null,
                'children' => null,
            ];
        }

        $folderId = $entry['private_knowledge_folder_id'] ?? null;
        if (!is_string($folderId) || trim($folderId) === '') {
            return [
                'tenant_key' => $tenantKey,
                'status' => 'error',
                'error_code' => BdsDriveFolderScanException::REGISTRY_MISSING,
                'error' => 'private_knowledge_folder_id is not configured.',
                'folder_id' => null,
                'folder' => null,
                'children' => null,
                'industry_code' => $entry['industry_code'] ?? '',
                'sno' => $entry['sno'] ?? '',
            ];
        }

        $result = [
            'tenant_key' => $tenantKey,
            'folder_id' => $folderId,
            'industry_code' => $entry['industry_code'] ?? '',
            'sno' => $entry['sno'] ?? '',
            'status' => 'ok',
            'error_code' => null,
            'error' => null,
            'folder' => null,
            'children' => null,
        ];

        try {
            $result['folder'] = $this->scanFolder($folderId);
            $result['children'] = $this->scanChildren($folderId);
        } catch (BdsDriveFolderScanException $exception) {
            $result['status'] = 'error';
            $result['error_code'] = $exception->getErrorCode();
            $result['error'] = $exception->getMessage();
        } catch (\Throwable $exception) {
            $result['status'] = 'error';
            $result['error_code'] = BdsDriveFolderScanException::SCAN_FAILED;
            $result['error'] = $exception->getMessage();
        }

        return $result;
    }

    /**
     * @return array{
     *   generated_at: string,
     *   platform: array<string, mixed>,
     *   tenants: array<string, array<string, mixed>>,
     *   summary: array{platform_ok: int, platform_failed: int, tenant_ok: int, tenant_failed: int}
     * }
     * @param list<string> $tenantKeys
     */
    public function buildFolderTreeValidationReport(array $tenantKeys = ['travel_b']): array
    {
        $platform = $this->scanPlatformRoots();

        $tenants = [];
        $tenantOk = 0;
        $tenantFailed = 0;

        foreach ($tenantKeys as $tenantKey) {
            if (!is_string($tenantKey) || trim($tenantKey) === '') {
                continue;
            }

            $scan = $this->scanTenantPrivateFolder(trim($tenantKey));
            $tenants[$tenantKey] = $scan;

            if (($scan['status'] ?? '') === 'ok') {
                ++$tenantOk;
            } else {
                ++$tenantFailed;
            }
        }

        return [
            'generated_at' => gmdate('c'),
            'platform' => $platform,
            'tenants' => $tenants,
            'summary' => [
                'platform_ok' => (int) ($platform['summary']['ok'] ?? 0),
                'platform_failed' => (int) ($platform['summary']['failed'] ?? 0),
                'tenant_ok' => $tenantOk,
                'tenant_failed' => $tenantFailed,
            ],
        ];
    }

    /**
     * @return array{id: string, name: string, mimeType: string, parentIds: list<string>}
     */
    private function fetchFolderMetadataWithParents(string $folderId): array
    {
        try {
            $file = $this->client->getDriveService()->files->get($folderId, [
                'fields' => 'id,name,mimeType,parents',
                'supportsAllDrives' => true,
            ]);
        } catch (\Google\Service\Exception $exception) {
            throw $this->mapGoogleException($exception, $folderId);
        }

        $parents = $file->getParents();
        $parentIds = [];
        if (is_array($parents)) {
            foreach ($parents as $parentId) {
                if (is_string($parentId) && $parentId !== '') {
                    $parentIds[] = $parentId;
                }
            }
        }

        return [
            'id' => (string) $file->getId(),
            'name' => (string) $file->getName(),
            'mimeType' => (string) $file->getMimeType(),
            'parentIds' => $parentIds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scanRegistryFolderEntry(string $role, string $registryField, ?string $folderId): array
    {
        $entry = [
            'role' => $role,
            'registry_field' => $registryField,
            'folder_id' => $folderId,
            'status' => 'ok',
            'error_code' => null,
            'error' => null,
            'folder' => null,
            'children' => null,
        ];

        if ($folderId === null || trim($folderId) === '') {
            $entry['status'] = 'error';
            $entry['error_code'] = BdsDriveFolderScanException::REGISTRY_MISSING;
            $entry['error'] = $registryField . ' is not configured in Platform Drive Registry.';

            return $entry;
        }

        try {
            $entry['folder'] = $this->scanFolder($folderId);
            $entry['children'] = $this->scanChildren($folderId);
        } catch (BdsDriveFolderScanException $exception) {
            $entry['status'] = 'error';
            $entry['error_code'] = $exception->getErrorCode();
            $entry['error'] = $exception->getMessage();
        } catch (\Throwable $exception) {
            $entry['status'] = 'error';
            $entry['error_code'] = BdsDriveFolderScanException::SCAN_FAILED;
            $entry['error'] = $exception->getMessage();
        }

        return $entry;
    }

    private function mapGoogleException(\Google\Service\Exception $exception, string $folderId): BdsDriveFolderScanException
    {
        $code = (int) $exception->getCode();

        if ($code === 404) {
            return new BdsDriveFolderScanException(
                'Drive folder not found: ' . $folderId,
                BdsDriveFolderScanException::NOT_FOUND,
                $folderId,
                $exception
            );
        }

        if ($code === 403) {
            return new BdsDriveFolderScanException(
                'Drive permission denied for folder: ' . $folderId,
                BdsDriveFolderScanException::PERMISSION_DENIED,
                $folderId,
                $exception
            );
        }

        return new BdsDriveFolderScanException(
            'Drive scan failed for folder: ' . $folderId . ' (' . $exception->getMessage() . ')',
            BdsDriveFolderScanException::SCAN_FAILED,
            $folderId,
            $exception
        );
    }
}
