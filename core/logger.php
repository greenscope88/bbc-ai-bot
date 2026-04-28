<?php
declare(strict_types=1);

class Logger
{
    public static function log(string $fileName, string $step, array $context = []): void
    {
        $logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $path = $logDir . DIRECTORY_SEPARATOR . $fileName;
        $line = sprintf(
            "[%s][%s] %s\n",
            date('Y-m-d H:i:s'),
            $step,
            json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        @file_put_contents($path, $line, FILE_APPEND);
    }
}
