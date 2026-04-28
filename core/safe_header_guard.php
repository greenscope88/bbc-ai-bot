<?php
declare(strict_types=1);

require_once __DIR__ . '/logger.php';

class SafeHeaderGuard
{
    /** @var array<int, string> */
    private static $protectedFiles = [
        'C:/bbc-ai-bot/webhook/callback.php',
        'C:/bbc-ai-bot/webhook/callback_core.php',
        'C:/Web/xampp/htdocs/www/bbc-ai-bot/webhook/callback.php',
    ];

    /** @return array{ok: bool, violations: array<int, string>} */
    public static function scanProtectedFiles(): array
    {
        $violations = [];

        foreach (self::$protectedFiles as $filePath) {
            $result = self::checkFile($filePath);
            if (!$result['ok']) {
                foreach ($result['violations'] as $v) {
                    $violations[] = $filePath . ': ' . $v;
                }
            }
        }

        if ($violations !== []) {
            Logger::log('saas_router.log', 'safe_header_guard_alert', [
                'violations' => $violations,
            ]);
        }

        return [
            'ok' => $violations === [],
            'violations' => $violations,
        ];
    }

    /** @return array{ok: bool, violations: array<int, string>} */
    private static function checkFile(string $filePath): array
    {
        $violations = [];

        if (!is_file($filePath)) {
            $violations[] = 'file not found';
            return ['ok' => false, 'violations' => $violations];
        }

        $bytes = @file_get_contents($filePath);
        if (!is_string($bytes)) {
            $violations[] = 'cannot read file';
            return ['ok' => false, 'violations' => $violations];
        }

        // BOM check: EF BB BF
        if (strlen($bytes) >= 3 && ord($bytes[0]) === 239 && ord($bytes[1]) === 187 && ord($bytes[2]) === 191) {
            $violations[] = 'UTF-8 BOM detected';
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $bytes);
        $lines = explode("\n", $normalized);

        $line1 = isset($lines[0]) ? trim($lines[0]) : '';
        $line2 = isset($lines[1]) ? trim($lines[1]) : '';

        if ($line1 !== '<?php') {
            $violations[] = 'line 1 must be <?php';
        }

        if ($line2 !== 'declare(strict_types=1);') {
            $violations[] = 'line 2 must be declare(strict_types=1);';
        }

        $firstDeclarePos = strpos($normalized, 'declare(strict_types=1);');
        $firstOpenTagPos = strpos($normalized, '<?php');
        if ($firstOpenTagPos !== 0) {
            $violations[] = 'content exists before <?php';
        }
        if ($firstDeclarePos === false) {
            $violations[] = 'declare(strict_types=1); not found';
        } elseif ($firstDeclarePos < 6) {
            $violations[] = 'declare position is invalid';
        }

        return [
            'ok' => $violations === [],
            'violations' => $violations,
        ];
    }
}
