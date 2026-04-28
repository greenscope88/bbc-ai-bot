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

if (!function_exists('sqlsrv_dsn')) {
    function sqlsrv_dsn(): string
    {
        $host = (string) app_config_get('database.host', '');
        $name = (string) app_config_get('database.name', '');

        if ($host === '' || $name === '') {
            throw new RuntimeException('Database host/name is missing in configuration.');
        }

        return "sqlsrv:Server={$host};Database={$name}";
    }
}
