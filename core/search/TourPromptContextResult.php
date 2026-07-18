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

    /** @var array<string, mixed> */
    private array $searchPolicyMeta;

    private string $sourceId;

    private ?string $searchUrl;

    private string $searchUrlRole;

    /** @var list<array<string, mixed>> */
    private array $multiSourceLinks;

    /** @var int|null */
    private $storeNo;

    /**
     * @param list<array<string, mixed>> $searchResults
     * @param array<string, mixed> $searchPolicyMeta
     */
    public function __construct(
        BatsSearchIntent $intent,
        ?SearchCondition $searchCondition,
        array $searchResults,
        string $legacyContext,
        bool $clarificationRequired,
        ?string $clarificationReason,
        array $searchPolicyMeta = [],
        string $sourceId = '',
        ?string $searchUrl = null,
        string $searchUrlRole = '',
        array $multiSourceLinks = [],
        $storeNo = null
    ) {
        $this->intent = $intent;
        $this->searchCondition = $searchCondition;
        $this->searchResults = self::normalizeSearchResults($searchResults);
        $this->legacyContext = $legacyContext;
        $this->clarificationRequired = $clarificationRequired;
        $this->clarificationReason = $clarificationReason;
        $this->searchPolicyMeta = $searchPolicyMeta;
        $this->sourceId = trim($sourceId);
        $this->searchUrl = $searchUrl !== null && trim($searchUrl) !== '' ? trim($searchUrl) : null;
        $this->searchUrlRole = trim($searchUrlRole);
        $this->multiSourceLinks = self::normalizeSearchResults($multiSourceLinks);
        $this->storeNo = $this->normalizeStoreNo($storeNo);
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
        string $legacyContext,
        array $searchPolicyMeta = [],
        string $sourceId = '',
        ?string $searchUrl = null,
        string $searchUrlRole = '',
        array $multiSourceLinks = [],
        $storeNo = null
    ): self {
        return new self(
            $intent,
            $searchCondition,
            $searchResults,
            $legacyContext,
            false,
            null,
            $searchPolicyMeta,
            $sourceId,
            $searchUrl,
            $searchUrlRole,
            $multiSourceLinks,
            $storeNo
        );
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
    public function getSearchPolicyMeta(): array
    {
        return $this->searchPolicyMeta;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getSearchUrl(): ?string
    {
        return $this->searchUrl;
    }

    public function getSearchUrlRole(): string
    {
        return $this->searchUrlRole;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getMultiSourceLinks(): array
    {
        return $this->multiSourceLinks;
    }

    public function getStoreNo(): ?int
    {
        return $this->storeNo;
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
            'search_policy' => $this->searchPolicyMeta,
            'source_id' => $this->sourceId,
            'search_url' => $this->searchUrl,
            'search_url_role' => $this->searchUrlRole,
            'multi_source_links' => $this->multiSourceLinks,
            'storeNo' => $this->storeNo,
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

    /**
     * @param mixed $value
     */
    private function normalizeStoreNo($value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $n = (int) trim($value);

            return $n > 0 ? $n : null;
        }

        return null;
    }
}
