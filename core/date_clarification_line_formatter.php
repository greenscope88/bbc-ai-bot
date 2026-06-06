<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridDateRequiredGate.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'AreaParser.php';

/**
 * Customer-facing LINE copy for Hybrid Date Clarification (Phase C-2).
 * Does not change gate rules; formats internal clarification context only.
 */
final class DateClarificationLineFormatter
{
    public static function isClarificationContext(string $tourContext): bool
    {
        $ctx = trim($tourContext);

        return $ctx !== '' && strpos($ctx, HybridDateRequiredGate::CLARIFICATION_MARKER) === 0;
    }

    public static function formatFromTourContext(string $tourContext): string
    {
        if (!self::isClarificationContext($tourContext)) {
            return '';
        }

        $destination = self::resolveDestinationLabel($tourContext);

        return self::buildCustomerMessage($destination);
    }

    /**
     * @return string|null Destination for 【】 prompt; null when unknown / non-destination keyword.
     */
    private static function resolveDestinationLabel(string $tourContext): ?string
    {
        if (preg_match('/目的地／關鍵字：(.+)/u', $tourContext, $m) !== 1) {
            return null;
        }

        $label = trim($m[1]);
        if ($label === '') {
            return null;
        }

        return self::isKnownDestinationLabel($label) ? $label : null;
    }

    private static function isKnownDestinationLabel(string $label): bool
    {
        foreach (AreaParser::regionCatalog() as $node) {
            foreach (['destination', 'area', 'keyword'] as $field) {
                $value = $node[$field] ?? null;
                if (is_string($value) && $value === $label) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function buildCustomerMessage(?string $destination): string
    {
        $lines = [];
        $lines[] = '您好 😊';
        $lines[] = '';

        if ($destination !== null && $destination !== '') {
            $lines[] = '請問您預計什麼時候出發【' . $destination . '】呢？';
            $lines[] = '';
            $lines[] = '您可以直接告訴我：';
            $lines[] = '';
            $lines[] = '📅 近期' . $destination;
            $lines[] = '📅 ' . $destination . '6月底';
            $lines[] = '📅 ' . $destination . '暑假';
            $lines[] = '📅 ' . $destination . '明年春節';
            $lines[] = '📅 ' . $destination . ' 2026/07/15';
        } else {
            $lines[] = '請問您預計什麼時候出發呢？';
            $lines[] = '';
            $lines[] = '您可以直接告訴我：';
            $lines[] = '';
            $lines[] = '📅 近期';
            $lines[] = '📅 6月底';
            $lines[] = '📅 暑假';
            $lines[] = '📅 明年春節';
            $lines[] = '📅 2026/07/15';
        }

        $lines[] = '';
        $lines[] = '我會依照您的出發時間幫您查詢適合的行程。';

        return implode("\n", $lines);
    }
}
