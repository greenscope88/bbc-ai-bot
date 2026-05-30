<?php
declare(strict_types=1);

/**
 * GCS object path template for tenant product sources (Phase 9-B-5 skeleton).
 */
final class TenantProductSourcesGcsPathConfig
{
    public const DEFAULT_CONFIG_RELATIVE_PATH = 'config/product_source/tenant_product_sources.gcs.json';

    public const DEFAULT_OBJECT_PATH_TEMPLATE = 'tenants/{sno}/product_sources/product_sources.json';

    /**
     * @return array{schema_version: int, object_path_template: string, bucket_placeholder: string}
     */
    public static function load(): array
    {
        $configPath = self::projectRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::DEFAULT_CONFIG_RELATIVE_PATH);
        if (!is_file($configPath)) {
            return self::defaultConfig();
        }

        $raw = file_get_contents($configPath);
        if ($raw === false) {
            return self::defaultConfig();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::defaultConfig();
        }

        $template = isset($decoded['object_path_template']) ? trim((string) $decoded['object_path_template']) : '';
        if ($template === '') {
            $template = self::DEFAULT_OBJECT_PATH_TEMPLATE;
        }

        return [
            'schema_version' => isset($decoded['schema_version']) ? (int) $decoded['schema_version'] : 1,
            'object_path_template' => $template,
            'bucket_placeholder' => isset($decoded['bucket_placeholder'])
                ? trim((string) $decoded['bucket_placeholder'])
                : 'bbc-travel-data-center',
        ];
    }

    public static function resolveObjectPath(string $tenantSno, ?string $template = null): string
    {
        $sno = trim($tenantSno);
        if ($sno === '') {
            throw new \InvalidArgumentException('tenant_sno is required to resolve GCS object path.');
        }

        $resolvedTemplate = $template;
        if ($resolvedTemplate === null || trim($resolvedTemplate) === '') {
            $resolvedTemplate = self::load()['object_path_template'];
        }

        return str_replace('{sno}', $sno, trim($resolvedTemplate));
    }

    /**
     * @return array{schema_version: int, object_path_template: string, bucket_placeholder: string}
     */
    private static function defaultConfig(): array
    {
        return [
            'schema_version' => 1,
            'object_path_template' => self::DEFAULT_OBJECT_PATH_TEMPLATE,
            'bucket_placeholder' => 'bbc-travel-data-center',
        ];
    }

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
