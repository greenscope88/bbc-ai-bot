<?php
declare(strict_types=1);

/**
 * Loads gateway service definitions from config/api_gateway_services.php (MVP).
 * Does not connect to Host B, SQL, or HTTP.
 */
final class ServiceRegistry
{
    /** @var array<string, array<string, mixed>>|null */
    private static $services = null;

    public static function get(string $serviceName): ?array
    {
        if ($serviceName === '') {
            return null;
        }

        $all = self::loadAll();
        if (!array_key_exists($serviceName, $all)) {
            return null;
        }

        $definition = $all[$serviceName];
        if (!is_array($definition)) {
            return null;
        }

        if (!isset($definition['enabled']) || $definition['enabled'] !== true) {
            return null;
        }

        return $definition;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function loadAll(): array
    {
        if (self::$services !== null) {
            return self::$services;
        }

        $path = dirname(__DIR__, 3)
            . DIRECTORY_SEPARATOR
            . 'config'
            . DIRECTORY_SEPARATOR
            . 'api_gateway_services.php';

        if (!is_file($path)) {
            throw new RuntimeException('Service registry config not found: ' . $path);
        }

        /** @var mixed $loaded */
        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new RuntimeException('Service registry config must return an array.');
        }

        self::validateDefinitions($loaded);

        /** @var array<string, array<string, mixed>> $loaded */
        self::$services = $loaded;

        return self::$services;
    }

    /**
     * @param array<mixed, mixed> $loaded
     */
    private static function validateDefinitions(array $loaded): void
    {
        foreach ($loaded as $name => $definition) {
            if (!is_string($name) || $name === '') {
                throw new RuntimeException('Service registry: each service key must be a non-empty string.');
            }
            if (!is_array($definition)) {
                throw new RuntimeException('Service registry: definition for "' . $name . '" must be an array.');
            }
        }
    }
}
