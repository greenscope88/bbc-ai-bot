<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchCondition.php';

/**
 * Phase 9-C-1d-α structured result for TourPromptContextService (RD-002).
 */
final class TourPromptContextResult
{
    private BatsSearchIntent $intent;

    private ?SearchCondition $searchCondition;

    /** @var list<array<string, mixed>> */
    private array $searchResults;

    private string $legacyContext;

    private bool $clarificationRequired;

    private ?string $clarificationReason;

    /**
     * @param list<array<string, mixed>> $searchResults
     */
    public function __construct(
        BatsSearchIntent $intent,
        ?SearchCondition $searchCondition,
        array $searchResults,
        string $legacyContext,
        bool $clarificationRequired,
        ?string $clarificationReason
    ) {
        $this->intent = $intent;
        $this->searchCondition = $searchCondition;
        $this->searchResults = self::normalizeSearchResults($searchResults);
        $this->legacyContext = $legacyContext;
        $this->clarificationRequired = $clarificationRequired;
        $this->clarificationReason = $clarificationReason;
    }

    public static function empty(string $freeText = ''): self
    {
        return new self(
            BatsSearchIntent::empty($freeText),
            null,
            [],
            '',
            false,
            null
        );
    }

    public static function clarificationRequired(BatsSearchIntent $intent, string $legacyContext): self
    {
        return new self(
            $intent,
            null,
            [],
            $legacyContext,
            true,
            $intent->getClarificationReason()
        );
    }

    /**
     * @param list<array<string, mixed>> $searchResults
     */
    public static function searchable(
        BatsSearchIntent $intent,
        SearchCondition $searchCondition,
        array $searchResults,
        string $legacyContext
    ): self {
        return new self($intent, $searchCondition, $searchResults, $legacyContext, false, null);
    }

    public function getIntent(): BatsSearchIntent
    {
        return $this->intent;
    }

    public function getSearchCondition(): ?SearchCondition
    {
        return $this->searchCondition;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSearchResults(): array
    {
        return $this->searchResults;
    }

    public function getLegacyContext(): string
    {
        return $this->legacyContext;
    }

    public function isClarificationRequired(): bool
    {
        return $this->clarificationRequired;
    }

    public function getClarificationReason(): ?string
    {
        return $this->clarificationReason;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent->toArray(),
            'search_condition' => $this->searchCondition !== null ? $this->searchCondition->toArray() : null,
            'search_results' => $this->searchResults,
            'legacy_context' => $this->legacyContext,
            'clarification_required' => $this->clarificationRequired,
            'clarification_reason' => $this->clarificationReason,
        ];
    }

    /**
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    private static function normalizeSearchResults($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
