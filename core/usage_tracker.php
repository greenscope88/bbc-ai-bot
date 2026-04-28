<?php
declare(strict_types=1);

require_once __DIR__ . '/logger.php';

class UsageTracker
{
    public static function track(string $sno, string $eventType, int $inputChars, int $outputChars): void
    {
        Logger::log('saas_router.log', 'usage_tracker', [
            'sno' => $sno,
            'event_type' => $eventType,
            'input_chars' => $inputChars,
            'output_chars' => $outputChars,
        ]);
    }
}
