<?php
declare(strict_types=1);

/**
 * MVP: gateway.host_b.api_key loads from GATEWAY_HOSTB_API_KEY (getenv after .env bootstrap).
 * Does not send HTTP, SQL, or print full key material.
 */

$root = dirname(__DIR__, 2);
$configPath = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
$envPath = $root . DIRECTORY_SEPARATOR . '.env';

$failures = 0;

function t(bool $ok, string $msg): void
{
    global $failures;
    if (!$ok) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

function envFileDeclaresGatewayHostbApiKey(string $envPath): bool
{
    if (!is_file($envPath) || !is_readable($envPath)) {
        return false;
    }
    $raw = @file_get_contents($envPath);
    if ($raw === false) {
        return false;
    }

    return preg_match('/^\s*GATEWAY_HOSTB_API_KEY\s*=/m', $raw) === 1;
}

/**
 * Load config.php in a fresh PHP process with controlled GATEWAY_HOSTB_API_KEY.
 * Uses proc_open with a scrubbed environment (PHP 8+ getenv() list) so inherited OS values cannot leak.
 *
 * @param string|null $valueOrNull null = key absent in child env; non-null = synthetic (letters, digits, underscore)
 */
function runConfigChild(string $configPath, ?string $valueOrNull): string
{
    $core = '$cfg = require ' . var_export($configPath, true) . ";\n"
        . "\$hb = \$cfg['gateway']['host_b'];\n"
        . "if (!is_array(\$hb) || !array_key_exists('api_key', \$hb)) { fwrite(STDOUT, 'OUT_NO_KEY'); exit(0); }\n"
        . "\$v = \$hb['api_key'];\n"
        . "if (\$v === null) { fwrite(STDOUT, 'OUT_NULL'); }\n"
        . "elseif (is_string(\$v)) {\n"
        . "  fwrite(STDOUT, 'OUT_STR_LEN_' . (string) strlen(\$v));\n"
        . "  fwrite(STDOUT, '_PREFIX_' . substr(\$v, 0, 7));\n"
        . "} else { fwrite(STDOUT, 'OUT_BAD_TYPE'); }\n";

    $tmp = tempnam(sys_get_temp_dir(), 'gw_hb_api_cfg_');
    if ($tmp === false) {
        return 'CHILD_TMP_FAIL';
    }

    $body = "<?php\ndeclare(strict_types=1);\n" . $core;
    file_put_contents($tmp, $body);

    $phpExe = defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== ''
        ? PHP_BINARY
        : 'C:\\Web\\xampp\\php\\php.exe';

    if ($valueOrNull !== null && !preg_match('/^[A-Za-z0-9_]+$/', $valueOrNull)) {
        @unlink($tmp);

        return 'CHILD_BAD_SYNTHETIC';
    }

    $envBlock = null;
    $all = getenv();
    if (is_array($all)) {
        $clean = [];
        foreach ($all as $k => $v) {
            if (!is_string($k) || $k === '') {
                continue;
            }
            $clean[$k] = $v === false || $v === null ? '' : (is_scalar($v) ? (string) $v : '');
        }
        unset($clean['GATEWAY_HOSTB_API_KEY']);
        if ($valueOrNull !== null) {
            $clean['GATEWAY_HOSTB_API_KEY'] = $valueOrNull;
        }
        $envBlock = $clean;
    }

    $cmd = [$phpExe, '-f', $tmp];
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $proc = @proc_open($cmd, $desc, $pipes, null, $envBlock);
    if (!is_resource($proc)) {
        @unlink($tmp);

        return 'CHILD_PROC_FAIL';
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($proc);
    @unlink($tmp);

    return is_string($stdout) ? trim($stdout) : '';
}

// --- In-process: app_config exposes gateway.host_b.api_key (never echo value) ---
require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

$hostB = app_config_get('gateway.host_b', []);
t(is_array($hostB), 'gateway.host_b must be an array');
t(array_key_exists('api_key', $hostB), 'gateway.host_b must expose api_key key');
$ak = $hostB['api_key'] ?? '__absent__';
t($ak === null || is_string($ak), 'gateway.host_b.api_key must be null or string');

if (is_string($ak) && $ak !== '') {
    fwrite(STDOUT, 'NOTE: api_key is non-empty in this process; asserting length only (no key printed): len=' . (string) strlen($ak) . "\n");
}

// --- Child: unset / empty → null (only meaningful when .env does not declare the key) ---
if (!envFileDeclaresGatewayHostbApiKey($envPath)) {
    $outUnset = runConfigChild($configPath, null);
    t($outUnset === 'OUT_NULL', 'when GATEWAY_HOSTB_API_KEY unset and not in .env, api_key must be null (child: ' . $outUnset . ')');

    $synthetic = 'MvpCfg_' . bin2hex(random_bytes(8)) . '_unit_test_only';
    $outSet = runConfigChild($configPath, $synthetic);
    $expectedLen = (string) strlen($synthetic);
    $expectedPrefix = 'MvpCfg_';
    t(
        strpos($outSet, 'OUT_STR_LEN_' . $expectedLen . '_PREFIX_' . $expectedPrefix) === 0,
        'after putenv synthetic, api_key must be readable as string with expected length/prefix (child output must not contain full key)'
    );
    if (strpos($outSet, $synthetic) !== false) {
        ++$failures;
        fwrite(STDERR, "FAIL: child output leaked full synthetic key\n");
    }
} else {
    fwrite(STDOUT, "NOTE: .env declares GATEWAY_HOSTB_API_KEY; skipping putenv-only null/synthetic child assertions.\n");
    t(true, 'skip strict null/synthetic subprocess: .env owns GATEWAY_HOSTB_API_KEY');
}

if ($failures > 0) {
    fwrite(STDERR, "test_host_b_api_key_config_mvp failed ({$failures}).\n");
    exit(1);
}

fwrite(STDOUT, "test_host_b_api_key_config_mvp passed.\n");
exit(0);
