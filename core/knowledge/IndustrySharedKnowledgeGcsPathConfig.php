<?php
declare(strict_types=1);

/**
 * GCS object paths for industry shared knowledge (Phase 9-C-2D).
 */
final class IndustrySharedKnowledgeGcsPathConfig
{
    public const FAQ_FILE = 'faq.json';

    public static function resolveObjectPath(string $industryCode, string $filename = self::FAQ_FILE): string
    {
        $industryCode = trim($industryCode);
        $filename = trim($filename);
        if ($industryCode === '' || $filename === '') {
            throw new InvalidArgumentException('industry_code and filename are required.');
        }

        return 'shared/' . $industryCode . '/knowledge/' . $filename;
    }
}
