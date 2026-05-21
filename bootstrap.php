<?php
declare(strict_types=1);

/**
 * Application bootstrap for shared config/state.
 *
 * Include once in every entrypoint/module:
 *   require_once __DIR__ . '/bootstrap.php';
 *   $cfg = app_config();
 */

if (!function_exists('app_config')) {
    /** @return array<string, mixed> */
    function app_config(): array
    {
        static $config = null;

        if ($config === null) {
            $config = require __DIR__ . '/config/config.php';
        }

        return $config;
    }
}

if (!function_exists('app_config_get')) {
    /**
     * Read nested config by dot path.
     *
     * Example: app_config_get('database.host')
     *
     * @param mixed $default
     * @return mixed
     */
    function app_config_get(string $path, $default = null)
    {
        $segments = explode('.', $path);
        $value = app_config();

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}

if (!function_exists('build_sqlsrv_dsn')) {
    /**
     * ODBC Driver 18 defaults to Encrypt=yes; TrustServerCertificate=1 is required
     * when the server uses a self-signed or internal CA certificate (pdo_sqlsrv accepts 1, not yes).
     */
    function build_sqlsrv_dsn(string $host, string $database): string
    {
        if ($host === '' || $database === '') {
            throw new RuntimeException('Database host/name is missing in configuration.');
        }

        return "sqlsrv:Server={$host};Database={$database};TrustServerCertificate=1";
    }
}

if (!function_exists('sqlsrv_dsn')) {
    function sqlsrv_dsn(): string
    {
        return build_sqlsrv_dsn(
            (string) app_config_get('database.host', ''),
            (string) app_config_get('database.name', '')
        );
    }
}
