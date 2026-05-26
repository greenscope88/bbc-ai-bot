<?php
declare(strict_types=1);

/**
 * Global SaaS configuration loader.
 */

$envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
if (!is_file($envPath)) {
    throw new RuntimeException('.env file not found at project root.');
}

$env = [];
$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) {
    throw new RuntimeException('Failed to read .env file.');
}

$startsWith = static function (string $value, string $prefix): bool {
    return substr($value, 0, strlen($prefix)) === $prefix;
};
$endsWith = static function (string $value, string $suffix): bool {
    if ($suffix === '') {
        return true;
    }
    return substr($value, -strlen($suffix)) === $suffix;
};

foreach ($lines as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || $startsWith($trimmed, '#')) {
        continue;
    }

    $parts = explode('=', $trimmed, 2);
    $key = trim($parts[0]);
    $value = isset($parts[1]) ? trim($parts[1]) : '';
    if ($key === '') {
        continue;
    }

    if (($startsWith($value, '"') && $endsWith($value, '"')) || ($startsWith($value, "'") && $endsWith($value, "'"))) {
        $value = substr($value, 1, -1);
    }

    $env[$key] = $value;
    $_ENV[$key] = $value;
    putenv("{$key}={$value}");
}

$requiredKeys = [
    'LINE_CHANNEL_ACCESS_TOKEN',
    'LINE_CHANNEL_SECRET',
    'LINE_REPLY_API_URL',
];

$missingKeys = [];
foreach ($requiredKeys as $requiredKey) {
    if (!array_key_exists($requiredKey, $env) || $env[$requiredKey] === '') {
        $missingKeys[] = $requiredKey;
    }
}
if ($missingKeys !== []) {
    throw new RuntimeException('Missing required environment variables: ' . implode(', ', $missingKeys));
}

return [
    'app' => [
        'env_path' => $envPath,
        'is_multi_tenant_ready' => true,
    ],
    'line' => [
        'channel_access_token' => $env['LINE_CHANNEL_ACCESS_TOKEN'],
        'channel_secret' => $env['LINE_CHANNEL_SECRET'],
        'reply_api_url' => $env['LINE_REPLY_API_URL'],
    ],
    'gemini' => [
        'api_key' => (isset($env['GEMINI_API_KEY']) ? $env['GEMINI_API_KEY'] : ''),
        'api_url_template' => (isset($env['GEMINI_API_URL_TEMPLATE']) ? $env['GEMINI_API_URL_TEMPLATE'] : ''),
    ],
    'database' => [
        'driver' => 'sqlsrv',
        'host' => (isset($env['DB_HOST']) ? $env['DB_HOST'] : ''),
        'name' => (isset($env['DB_NAME']) ? $env['DB_NAME'] : ''),
        'user' => (isset($env['DB_USER']) ? $env['DB_USER'] : ''),
        'pass' => (isset($env['DB_PASS']) ? $env['DB_PASS'] : ''),
    ],
    'storefront' => [
        'bonusmee_data_key' => isset($env['BONUSMEE_STOREFRONT_DATA_KEY'])
            ? trim((string) $env['BONUSMEE_STOREFRONT_DATA_KEY'])
            : '',
    ],
    'short_url' => [
        'enabled' => isset($env['SHORT_URL_ENABLED']) && filter_var($env['SHORT_URL_ENABLED'], FILTER_VALIDATE_BOOLEAN),
        'item_links_enabled' => isset($env['SHORT_URL_ITEM_LINKS_ENABLED'])
            && filter_var($env['SHORT_URL_ITEM_LINKS_ENABLED'], FILTER_VALIDATE_BOOLEAN),
        'schedule_links_enabled' => isset($env['SHORT_URL_SCHEDULE_LINKS_ENABLED'])
            && filter_var($env['SHORT_URL_SCHEDULE_LINKS_ENABLED'], FILTER_VALIDATE_BOOLEAN),
        'public_base' => isset($env['SHORT_URL_PUBLIC_BASE']) && trim((string) $env['SHORT_URL_PUBLIC_BASE']) !== ''
            ? rtrim(trim((string) $env['SHORT_URL_PUBLIC_BASE']), '/') . '/'
            : 'https://bbcshops.com/',
    ],
    'gateway' => [
        'host_b' => [
            'http_enabled' => isset($env['GATEWAY_HOSTB_HTTP_ENABLED']) && filter_var($env['GATEWAY_HOSTB_HTTP_ENABLED'], FILTER_VALIDATE_BOOLEAN),
            'base_url' => isset($env['GATEWAY_HOSTB_BASE_URL']) ? trim((string) $env['GATEWAY_HOSTB_BASE_URL']) : '',
            'api_key' => getenv('GATEWAY_HOSTB_API_KEY') ?: null,
            'connect_timeout_sec' => isset($env['GATEWAY_HOSTB_CONNECT_TIMEOUT_SEC']) ? max(0, (int) $env['GATEWAY_HOSTB_CONNECT_TIMEOUT_SEC']) : 3,
            'read_timeout_sec' => isset($env['GATEWAY_HOSTB_READ_TIMEOUT_SEC']) ? max(0, (int) $env['GATEWAY_HOSTB_READ_TIMEOUT_SEC']) : 10,
        ],
        'audit_log' => [
            'persistence_mode' => isset($env['GATEWAY_AUDIT_LOG_PERSISTENCE_MODE'])
                ? strtolower(trim((string) $env['GATEWAY_AUDIT_LOG_PERSISTENCE_MODE']))
                : 'disabled',
        ],
        'rate_limit' => [
            'persistence_mode' => isset($env['GATEWAY_RATE_LIMIT_PERSISTENCE_MODE'])
                ? strtolower(trim((string) $env['GATEWAY_RATE_LIMIT_PERSISTENCE_MODE']))
                : 'disabled',
        ],
        'hybrid_search' => [
            'enabled' => false,
            'allowed_sno' => [],
            'allowed_channels' => [],
            'dry_run_log_enabled' => true,
            'intent_log_enabled' => true,
            'api_debug_log_enabled' => true,
        ],
    ],
];
