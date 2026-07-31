<?php
declare(strict_types=1);

/**
 * B0-LINE-01D-3J-11: authoritative tenant-scoped Product-Set Context.
 *
 * Immutable transport shape carrying the runtime-grounded searchable product-set
 * facts (categories + executable search dimensions) that Gemini must ground its
 * semantic-role decision against. Values are supplied only by
 * AiuProductSetContextResolver from the existing product-source authority
 * (ProductSourceRegistry / SearchConditionContract) — never invented here.
 */
final class AiuProductSetContext
{
    public const CONTEXT_VERSION = 1;

    public const RESOLUTION_STATUS_RESOLVED = 'resolved';

    private string $tenantSno;

    private string $searchDomain;

    /** @var list<string> */
    private array $searchableProductCategories;

    /** @var list<string> */
    private array $executableSearchDimensions;

    private string $resolutionStatus;

    /**
     * @param list<string> $searchableProductCategories
     * @param list<string> $executableSearchDimensions
     */
    public function __construct(
        string $tenantSno,
        string $searchDomain,
        array $searchableProductCategories,
        array $executableSearchDimensions,
        string $resolutionStatus
    ) {
        $tenantSno = trim($tenantSno);
        if ($tenantSno === '') {
            throw new \InvalidArgumentException('AiuProductSetContext: tenantSno must not be blank');
        }

        $searchDomain = trim($searchDomain);
        if ($searchDomain === '') {
            throw new \InvalidArgumentException('AiuProductSetContext: searchDomain must not be blank');
        }

        if ($resolutionStatus !== self::RESOLUTION_STATUS_RESOLVED) {
            throw new \InvalidArgumentException(
                'AiuProductSetContext: resolutionStatus must be exactly "' . self::RESOLUTION_STATUS_RESOLVED . '"'
            );
        }

        $this->tenantSno = $tenantSno;
        $this->searchDomain = $searchDomain;
        $this->searchableProductCategories = self::assertValidStringList(
            $searchableProductCategories,
            'searchableProductCategories'
        );
        $this->executableSearchDimensions = self::assertValidStringList(
            $executableSearchDimensions,
            'executableSearchDimensions'
        );
        $this->resolutionStatus = $resolutionStatus;
    }

    public function getTenantSno(): string
    {
        return $this->tenantSno;
    }

    public function getSearchDomain(): string
    {
        return $this->searchDomain;
    }

    /**
     * @return list<string>
     */
    public function getSearchableProductCategories(): array
    {
        return $this->searchableProductCategories;
    }

    /**
     * @return list<string>
     */
    public function getExecutableSearchDimensions(): array
    {
        return $this->executableSearchDimensions;
    }

    public function getResolutionStatus(): string
    {
        return $this->resolutionStatus;
    }

    /**
     * Prompt-facing facts only — no tenantSno, no paths, no credentials.
     *
     * @return array{
     *   context_version: int,
     *   search_domain: string,
     *   searchable_product_categories: list<string>,
     *   executable_search_dimensions: list<string>,
     *   resolution_status: string
     * }
     */
    public function toPromptFactsArray(): array
    {
        return [
            'context_version' => self::CONTEXT_VERSION,
            'search_domain' => $this->searchDomain,
            'searchable_product_categories' => $this->searchableProductCategories,
            'executable_search_dimensions' => $this->executableSearchDimensions,
            'resolution_status' => $this->resolutionStatus,
        ];
    }

    /**
     * B0-LINE-01D-3J-11O1: deterministic SAFE observation facts — no tenant, no
     * search_domain, no categories/dimensions/paths/payload values. Fingerprints are
     * computed over sorted COPIES of the category/dimension lists so two contexts with
     * the same sets in a different order fingerprint identically; the original arrays
     * and toPromptFactsArray() order are never mutated.
     *
     * @return array{
     *   context_version: int,
     *   resolution_status: string,
     *   category_count: int,
     *   category_fingerprint: string,
     *   executable_dimension_count: int,
     *   executable_dimension_fingerprint: string,
     *   resolved_context_fingerprint: string
     * }
     */
    public function toSafeObservationFacts(): array
    {
        $sortedCategories = $this->sortedCopy($this->searchableProductCategories);
        $sortedDimensions = $this->sortedCopy($this->executableSearchDimensions);

        return [
            'context_version' => self::CONTEXT_VERSION,
            'resolution_status' => $this->resolutionStatus,
            'category_count' => count($this->searchableProductCategories),
            'category_fingerprint' => $this->canonicalFingerprint($sortedCategories),
            'executable_dimension_count' => count($this->executableSearchDimensions),
            'executable_dimension_fingerprint' => $this->canonicalFingerprint($sortedDimensions),
            'resolved_context_fingerprint' => $this->resolvedContextFingerprint($sortedCategories, $sortedDimensions),
        ];
    }

    /**
     * Single source of truth for the canonical resolved-context fingerprint. Used by
     * both toSafeObservationFacts() and AiuPromptBuilder::productSetContextFingerprint()
     * so the two never drift apart. search_domain is folded into the fingerprint input
     * but is never exposed by any observation output.
     */
    public function getResolvedContextFingerprint(): string
    {
        return $this->resolvedContextFingerprint(
            $this->sortedCopy($this->searchableProductCategories),
            $this->sortedCopy($this->executableSearchDimensions)
        );
    }

    /**
     * @param list<string> $sortedCategories
     * @param list<string> $sortedDimensions
     */
    private function resolvedContextFingerprint(array $sortedCategories, array $sortedDimensions): string
    {
        return $this->canonicalFingerprint([
            'context_version' => self::CONTEXT_VERSION,
            'search_domain' => $this->searchDomain,
            'searchable_product_categories' => $sortedCategories,
            'executable_search_dimensions' => $sortedDimensions,
            'resolution_status' => $this->resolutionStatus,
        ]);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sortedCopy(array $values): array
    {
        $copy = $values;
        sort($copy, SORT_STRING);

        return $copy;
    }

    /**
     * Deterministic canonical-JSON fingerprint: 12-char lowercase SHA-256 prefix.
     * Fails closed (RuntimeException) when the value cannot be serialized.
     *
     * @param mixed $value
     */
    private function canonicalFingerprint($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException(
                'AiuProductSetContext: failed to canonicalize value for observation fingerprinting'
            );
        }

        return substr(hash('sha256', $json), 0, 12);
    }

    /**
     * @param mixed $values
     * @return list<string>
     */
    private static function assertValidStringList($values, string $label): array
    {
        if (!is_array($values) || $values === []) {
            throw new \InvalidArgumentException("AiuProductSetContext: {$label} must be a non-empty list");
        }

        if (array_keys($values) !== range(0, count($values) - 1)) {
            throw new \InvalidArgumentException("AiuProductSetContext: {$label} must be a sequential list");
        }

        $seen = [];
        $out = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException("AiuProductSetContext: {$label} elements must be strings");
            }

            $trimmed = trim($value);
            if ($trimmed === '') {
                throw new \InvalidArgumentException("AiuProductSetContext: {$label} elements must not be blank");
            }

            if (isset($seen[$trimmed])) {
                throw new \InvalidArgumentException("AiuProductSetContext: {$label} must not contain duplicates");
            }
            $seen[$trimmed] = true;
            $out[] = $trimmed;
        }

        return $out;
    }
}
