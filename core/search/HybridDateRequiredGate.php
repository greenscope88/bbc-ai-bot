<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchCondition.php';

/**
 * Hybrid Layer gate: Date Semantic Required Rule (BATS_HYBRID_DATE_POLICY.md).
 *
 * Reads SearchCondition.date_from / date_to only; does not parse natural language dates.
 */
final class HybridDateRequiredGate
{
    public const REASON_MISSING_DATE_RANGE = 'missing_date_range';

    /** Marker for tests and Gemini prompt routing (P1 clarification path). */
    public const CLARIFICATION_MARKER = '[BATS_DATE_CLARIFICATION]';

    public function evaluate(SearchCondition $condition): HybridDateRequiredGateResult
    {
        if ($this->hasResolvedDateRange($condition)) {
            return HybridDateRequiredGateResult::allowSearch();
        }

        return HybridDateRequiredGateResult::requireClarification(self::REASON_MISSING_DATE_RANGE);
    }

    public static function buildClarificationContext(SearchCondition $condition, string $userText): string
    {
        $keyword = $condition->getKeyword();
        if ($keyword === null || trim($keyword) === '') {
            $keyword = $condition->getDestination();
        }
        if ($keyword === null || trim($keyword) === '') {
            $keyword = $condition->getArea();
        }

        $destination = trim((string) ($keyword ?? ''));
        $destinationLine = $destination !== '' ? "目的地／關鍵字：{$destination}\n" : '';

        return self::CLARIFICATION_MARKER . "\n"
            . "【日期澄清】\n"
            . $destinationLine
            . "使用者查詢：" . trim($userText) . "\n"
            . "尚未提供可搜尋的出發日期或日期語意（date_from / date_to 皆未成立）。\n"
            . "請先禮貌詢問旅客預計出發時間（例如：近期、本月、六月底、或具体日期），取得日期後再進行行程搜尋。\n"
            . "本回合不得引用搜尋結果、不得提供商品源 Search URL。";
    }

    private function hasResolvedDateRange(SearchCondition $condition): bool
    {
        $from = $condition->getDateFrom();
        $to = $condition->getDateTo();

        if ($from !== null && trim($from) !== '') {
            return true;
        }

        if ($to !== null && trim($to) !== '') {
            return true;
        }

        return false;
    }
}

final class HybridDateRequiredGateResult
{
    private bool $requiresDateClarification;

    private string $reasonCode;

    private function __construct(bool $requiresDateClarification, string $reasonCode)
    {
        $this->requiresDateClarification = $requiresDateClarification;
        $this->reasonCode = $reasonCode;
    }

    public static function allowSearch(): self
    {
        return new self(false, '');
    }

    public static function requireClarification(string $reasonCode): self
    {
        return new self(true, $reasonCode);
    }

    public function requiresDateClarification(): bool
    {
        return $this->requiresDateClarification;
    }

    public function allowsSearch(): bool
    {
        return !$this->requiresDateClarification;
    }

    public function getReasonCode(): string
    {
        return $this->reasonCode;
    }
}
