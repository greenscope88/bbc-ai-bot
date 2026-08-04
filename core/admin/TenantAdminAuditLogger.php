<?php
declare(strict_types=1);

/**
 * Admin Audit logger — no secrets, tokens, full phones, or sheet content.
 */
final class TenantAdminAuditLogger
{
    /** @var string */
    private $logPath;

    public function __construct(?string $logPath = null)
    {
        $this->logPath = $logPath ?? (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tenant_admin_audit.log');
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function write(array $entry): void
    {
        $safe = [
            'time' => gmdate('c'),
            'operation' => (string) ($entry['operation'] ?? ''),
            'admin_wire_sno_masked' => (string) ($entry['admin_wire_sno_masked'] ?? ''),
            'tenant_key' => (string) ($entry['tenant_key'] ?? ''),
            'changed_fields' => array_values(array_map('strval', (array) ($entry['changed_fields'] ?? []))),
            'result' => (string) ($entry['result'] ?? ''),
            'summary' => (string) ($entry['summary'] ?? ''),
        ];
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($line)) {
            return;
        }
        @file_put_contents($this->logPath, $line . "\n", FILE_APPEND);
    }
}
