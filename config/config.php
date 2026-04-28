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
];
