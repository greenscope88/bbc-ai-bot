<?php
declare(strict_types=1);

/**
 * Resolves Host B API Gateway tenantContext from static sno map (Stage 1-B-1).
 * No .env, no database, no Host B HTTP calls.
 */
final class TenantContextResolver
{
  private const FORBIDDEN_MOCK_PROVIDER_ID = 1;

  /** @var array<string, array<string, mixed>>|null */
  private ?array $mapOverride;

  private string $configPath;

  /**
   * @param array<string, array<string, mixed>>|null $mapOverride Optional in-memory map (tests only).
   */
  public function __construct(?array $mapOverride = null, ?string $configPath = null)
  {
    $this->mapOverride = $mapOverride;
    $this->configPath = $configPath ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'tenant_context_map.php';
  }

  /**
   * @return array{
   *   ok: bool,
   *   errorCode: string|null,
   *   message: string|null,
   *   tenantContext: array<string, mixed>|null
   * }
   */
  public function resolve(string $sno): array
  {
    try {
      $normalizedSno = trim($sno);
      if ($normalizedSno === '') {
        return $this->error('MISSING_SNO', 'Missing required sno.');
      }

      $load = $this->loadMap();
      if (!($load['ok'] ?? false)) {
        return $load;
      }

      /** @var array<string, array<string, mixed>> $map */
      $map = $load['map'];

      if (!array_key_exists($normalizedSno, $map)) {
        return $this->error('TENANT_NOT_FOUND', 'No tenant context mapping found for sno.');
      }

      $row = $map[$normalizedSno];
      if (!is_array($row)) {
        return $this->error('TENANT_CONTEXT_CONFIG_INVALID', 'Tenant row must be an array.');
      }

      if (!$this->hasRequiredScalar($row, 'depID')) {
        return $this->error('TENANT_MAPPING_INCOMPLETE_DEPID', 'Tenant mapping is missing depID.');
      }

      if (!$this->hasRequiredScalar($row, 'storeNo')) {
        return $this->error('TENANT_MAPPING_INCOMPLETE_STORENO', 'Tenant mapping is missing storeNo.');
      }

      if (!$this->hasRequiredScalar($row, 'store_uid')) {
        return $this->error('TENANT_MAPPING_INCOMPLETE_STORE_UID', 'Tenant mapping is missing store_uid.');
      }

      $provider = $row['provider_id_no'] ?? null;
      if ($provider === null || $provider === '') {
        return $this->error('TENANT_MAPPING_INCOMPLETE_PROVIDER', 'Tenant mapping is missing provider_id_no.');
      }

      if ((int) $provider === self::FORBIDDEN_MOCK_PROVIDER_ID) {
        return $this->error(
          'TENANT_MAPPING_INCOMPLETE_PROVIDER',
          'provider_id_no must be confirmed by BuySmart / Host B; mock value 1 is not allowed.'
        );
      }

      return [
        'ok' => true,
        'errorCode' => null,
        'message' => null,
        'tenantContext' => [
          'sno' => $normalizedSno,
          'depID' => $this->toInt($row['depID']),
          'storeNo' => $this->toInt($row['storeNo']),
          'store_uid' => $this->toInt($row['store_uid']),
          'provider_id_no' => $this->toInt($row['provider_id_no']),
        ],
      ];
    } catch (\Throwable $e) {
      return $this->error('TENANT_CONTEXT_CONFIG_INVALID', 'Tenant context resolver failed: ' . $e->getMessage());
    }
  }

  /**
   * @return array{ok: bool, errorCode: string|null, message: string|null, tenantContext: null}|array{ok: true, map: array<string, array<string, mixed>>}
   */
  private function loadMap(): array
  {
    if ($this->mapOverride !== null) {
      return ['ok' => true, 'map' => $this->mapOverride];
    }

    if (!is_file($this->configPath)) {
      return $this->error('TENANT_CONTEXT_CONFIG_MISSING', 'Tenant context map file is missing.');
    }

    $loaded = require $this->configPath;
    if (!is_array($loaded)) {
      return $this->error('TENANT_CONTEXT_CONFIG_INVALID', 'Tenant context map must return an array.');
    }

    return ['ok' => true, 'map' => $loaded];
  }

  /**
   * @param array<string, mixed> $row
   */
  private function hasRequiredScalar(array $row, string $key): bool
  {
    if (!array_key_exists($key, $row)) {
      return false;
    }

    $value = $row[$key];
    if ($value === null) {
      return false;
    }

    if (is_string($value) && trim($value) === '') {
      return false;
    }

    return true;
  }

  /**
   * @param mixed $value
   */
  private function toInt($value): int
  {
    return (int) $value;
  }

  /**
   * @return array{ok: false, errorCode: string, message: string, tenantContext: null}
   */
  private function error(string $errorCode, string $message): array
  {
    return [
      'ok' => false,
      'errorCode' => $errorCode,
      'message' => $message,
      'tenantContext' => null,
    ];
  }
}
