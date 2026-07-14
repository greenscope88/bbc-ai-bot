<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'TravelConsultantPersonaFormatter.php';

/**
 * Phase 9-C-2A: travel consultant persona reply composer (grounded, mock runtime).
 */
final class TravelConsultantPersonaRuntime
{
    private TravelConsultantPersonaFormatter $personaFormatter;

    public function __construct(?TravelConsultantPersonaFormatter $personaFormatter = null)
    {
        $this->personaFormatter = $personaFormatter ?? TravelConsultantPersonaFormatter::createRandomized();
    }

    /**
     * @param array<string, mixed> $recommendationSummary
     */
    public function composeProductRecommendation(array $recommendationSummary): string
    {
        if (($recommendationSummary['product_type_mismatch'] ?? false) === true) {
            return $this->composeProductTypeMismatchMessage($recommendationSummary);
        }

        if (($recommendationSummary['hard_constraints_complete'] ?? false) === true
            || ($recommendationSummary['no_result_composer'] ?? false) === true) {
            return $this->composeHardConstraintNoResultsMessage($recommendationSummary);
        }

        $resultCount = isset($recommendationSummary['result_count'])
            ? max(0, (int) $recommendationSummary['result_count'])
            : 0;
        if ($resultCount <= 0) {
            return $this->composeNoResultsMessage();
        }

        $lines = $this->personaFormatter->openingLines();
        $reason = trim((string) ($recommendationSummary['recommendation_reason'] ?? ''));
        if ($reason !== '') {
            if ($lines !== [] && end($lines) !== '') {
                $lines[] = '';
            }
            $lines[] = $reason;
        }

        $topProducts = isset($recommendationSummary['top_products']) && is_array($recommendationSummary['top_products'])
            ? $recommendationSummary['top_products']
            : [];
        if ($topProducts !== []) {
            $lines[] = '';
        }
        foreach ($topProducts as $product) {
            if (!is_array($product)) {
                continue;
            }
            $title = trim((string) ($product['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $emoji = trim((string) ($product['display_emoji'] ?? '✈️'));
            $lines[] = $emoji . ' ' . $title;
        }

        $primaryUrl = trim((string) ($recommendationSummary['primary_url'] ?? ''));
        if ($primaryUrl !== '') {
            $lines[] = '';
            $lines[] = '您可以參考以下完整行程：';
            $lines[] = $primaryUrl;
        }

        $hints = isset($recommendationSummary['preference_hints']) && is_array($recommendationSummary['preference_hints'])
            ? $recommendationSummary['preference_hints']
            : [];
        if ($hints !== []) {
            $lines[] = '';
            $lines[] = '如果您偏好：';
            foreach ($hints as $hint) {
                if (!is_string($hint) || trim($hint) === '') {
                    continue;
                }
                $lines[] = '* ' . trim($hint);
            }
        }

        $lines[] = '';
        foreach ($this->personaFormatter->closingLines() as $closingLine) {
            $lines[] = $closingLine;
        }

        return implode("\n", $lines);
    }


    /**
     * @param array<string, mixed> $recommendationSummary
     */
    public function composeHardConstraintNoResultsMessage(array $recommendationSummary): string
    {
        if (($recommendationSummary['product_type_mismatch'] ?? false) === true) {
            return $this->composeProductTypeMismatchMessage($recommendationSummary);
        }

        $lines = [];
        $reason = trim((string) ($recommendationSummary['recommendation_reason'] ?? ''));
        $lines[] = $reason !== '' ? $reason : '目前沒有找到符合條件的商品。';

        $alternatives = isset($recommendationSummary['alternative_recommendations'])
            && is_array($recommendationSummary['alternative_recommendations'])
            ? $recommendationSummary['alternative_recommendations']
            : [];
        if ($alternatives !== []) {
            $lines[] = '';
            $lines[] = '您可以參考以下替代方案：';
            foreach ($alternatives as $group) {
                if (!is_array($group)) {
                    continue;
                }
                $label = trim((string) ($group['label'] ?? ''));
                if ($label !== '') {
                    $lines[] = '* ' . $label;
                }
                $products = isset($group['top_products']) && is_array($group['top_products']) ? $group['top_products'] : [];
                foreach ($products as $product) {
                    if (!is_array($product)) {
                        continue;
                    }
                    $title = trim((string) ($product['title'] ?? ''));
                    if ($title !== '') {
                        $lines[] = '  - ' . $title;
                    }
                }
            }
        }

        $lines[] = '';
        foreach ($this->personaFormatter->closingLines() as $closingLine) {
            $lines[] = $closingLine;
        }

        return implode("\n", $lines);
    }
    public function composeNoResultsMessage(): string
    {
        return implode("\n", [
            '目前尚未找到符合條件的商品。',
            '',
            '若您願意提供：',
            '',
            '* 日期',
            '* 預算',
            '* 出發地',
            '',
            '我再幫您查詢 😊',
        ]);
    }

    /**
     * @param array<string, mixed> $recommendationSummary
     */
    public function composeProductTypeMismatchMessage(array $recommendationSummary): string
    {
        $lines = [];
        $reason = trim((string) ($recommendationSummary['recommendation_reason'] ?? ''));
        $lines[] = $reason !== '' ? $reason : '目前沒有找到符合您需求的商品。';

        $alternatives = isset($recommendationSummary['alternative_recommendations'])
            && is_array($recommendationSummary['alternative_recommendations'])
            ? $recommendationSummary['alternative_recommendations']
            : [];
        if ($alternatives !== []) {
            $lines[] = '';
            $lines[] = '您可以參考以下替代方案：';
            foreach ($alternatives as $group) {
                if (!is_array($group)) {
                    continue;
                }
                $label = trim((string) ($group['label'] ?? ''));
                if ($label !== '') {
                    $lines[] = '* ' . $label;
                }
                $products = isset($group['top_products']) && is_array($group['top_products'])
                    ? $group['top_products']
                    : [];
                foreach ($products as $product) {
                    if (!is_array($product)) {
                        continue;
                    }
                    $title = trim((string) ($product['title'] ?? ''));
                    if ($title !== '') {
                        $lines[] = '  - ' . $title;
                    }
                }
            }
        }

        $lines[] = '';
        foreach ($this->personaFormatter->closingLines() as $closingLine) {
            $lines[] = $closingLine;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $batsSearchIntent
     */
    public function isProductSearchIntent(array $batsSearchIntent): bool
    {
        if (($batsSearchIntent['clarification_required'] ?? false) === true) {
            return false;
        }

        $intent = isset($batsSearchIntent['intent']) ? trim((string) $batsSearchIntent['intent']) : '';
        if ($intent !== '' && $intent !== 'tour_search') {
            return false;
        }

        $destination = isset($batsSearchIntent['destination']) && is_array($batsSearchIntent['destination'])
            ? $batsSearchIntent['destination']
            : [];

        return $destination !== [];
    }
}
