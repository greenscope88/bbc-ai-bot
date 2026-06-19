<?php
declare(strict_types=1);

/**
 * Gemini context document contract (Phase 9-B-23).
 *
 * Structured context for future Gemini Client. Must not contain prompts or API credentials.
 */
final class GeminiContextDocument
{
    public const SCHEMA_VERSION = 1;

    public const SCHEMA_VERSION_V2 = 2;

    private string $customerQuery;

    /** @var list<array<string, mixed>> */
    private array $searchResults;

    private string $tenantName;

    /** @var array<string, mixed> */
    private array $voiceProfile;

    /** @var list<string> */
    private array $tenantServiceScope;

    /** @var array<string, mixed> */
    private array $guardPolicy;

    /** @var array<string, mixed> */
    private array $fallbackPolicy;

    private int $schemaVersion;

    /** @var array<string, mixed>|null */
    private ?array $batsSearchIntent;

    /** @var array<string, mixed>|null */
    private ?array $recommendationSummary;

    /**
     * @param list<array<string, mixed>> $searchResults
     * @param array<string, mixed> $voiceProfile
     * @param list<string> $tenantServiceScope
     * @param array<string, mixed> $guardPolicy
     * @param array<string, mixed> $fallbackPolicy
     * @param array<string, mixed>|null $batsSearchIntent
     * @param array<string, mixed>|null $recommendationSummary
     */
    private function __construct(
        string $customerQuery,
        array $searchResults,
        string $tenantName,
        array $voiceProfile,
        array $tenantServiceScope,
        array $guardPolicy,
        array $fallbackPolicy,
        int $schemaVersion,
        ?array $batsSearchIntent = null,
        ?array $recommendationSummary = null
    ) {
        $this->customerQuery = $customerQuery;
        $this->searchResults = $searchResults;
        $this->tenantName = $tenantName;
        $this->voiceProfile = $voiceProfile;
        $this->tenantServiceScope = $tenantServiceScope;
        $this->guardPolicy = $guardPolicy;
        $this->fallbackPolicy = $fallbackPolicy;
        $this->schemaVersion = $schemaVersion;
        $this->batsSearchIntent = $batsSearchIntent;
        $this->recommendationSummary = $recommendationSummary;
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function fromArray(array $document): self
    {
        $normalized = self::normalizeDocument($document);

        return new self(
            (string) $normalized['customer_query'],
            $normalized['search_results'],
            (string) $normalized['tenant_name'],
            $normalized['voice_profile'],
            $normalized['tenant_service_scope'],
            $normalized['guard_policy'],
            $normalized['fallback_policy'],
            (int) $normalized['schema_version'],
            $normalized['bats_search_intent'],
            $normalized['recommendation_summary']
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $document = [
            'schema_version' => $this->schemaVersion,
            'customer_query' => $this->customerQuery,
            'search_results' => $this->searchResults,
            'tenant_name' => $this->tenantName,
            'voice_profile' => $this->voiceProfile,
            'tenant_service_scope' => $this->tenantServiceScope,
            'guard_policy' => $this->guardPolicy,
            'fallback_policy' => $this->fallbackPolicy,
        ];

        if ($this->schemaVersion >= self::SCHEMA_VERSION_V2 && $this->batsSearchIntent !== null) {
            $document['bats_search_intent'] = $this->batsSearchIntent;
        }

        if ($this->schemaVersion >= self::SCHEMA_VERSION_V2 && $this->recommendationSummary !== null) {
            $document['recommendation_summary'] = $this->recommendationSummary;
        }

        return $document;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBatsSearchIntent(): ?array
    {
        return $this->batsSearchIntent;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSearchResults(): array
    {
        return $this->searchResults;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRecommendationSummary(): ?array
    {
        return $this->recommendationSummary;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function normalizeDocument(array $document): array
    {
        $out = [];

        $out['schema_version'] = isset($document['schema_version'])
            ? (int) $document['schema_version']
            : self::SCHEMA_VERSION;
        $out['customer_query'] = isset($document['customer_query'])
            ? trim((string) $document['customer_query'])
            : '';
        $out['tenant_name'] = isset($document['tenant_name'])
            ? trim((string) $document['tenant_name'])
            : '';

        $out['search_results'] = [];
        if (isset($document['search_results']) && is_array($document['search_results'])) {
            foreach ($document['search_results'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $out['search_results'][] = self::normalizeSearchResult($item);
            }
        }

        $out['voice_profile'] = isset($document['voice_profile']) && is_array($document['voice_profile'])
            ? $document['voice_profile']
            : [];

        $out['tenant_service_scope'] = [];
        if (isset($document['tenant_service_scope']) && is_array($document['tenant_service_scope'])) {
            foreach ($document['tenant_service_scope'] as $scope) {
                if (!is_string($scope)) {
                    continue;
                }
                $trimmed = trim($scope);
                if ($trimmed !== '') {
                    $out['tenant_service_scope'][] = $trimmed;
                }
            }
        }

        $out['guard_policy'] = isset($document['guard_policy']) && is_array($document['guard_policy'])
            ? $document['guard_policy']
            : [];

        $out['fallback_policy'] = isset($document['fallback_policy']) && is_array($document['fallback_policy'])
            ? $document['fallback_policy']
            : [];

        $out['bats_search_intent'] = null;
        if ($out['schema_version'] >= self::SCHEMA_VERSION_V2
            && isset($document['bats_search_intent'])
            && is_array($document['bats_search_intent'])
        ) {
            $out['bats_search_intent'] = self::normalizeBatsSearchIntent($document['bats_search_intent']);
        }

        $out['recommendation_summary'] = null;
        if ($out['schema_version'] >= self::SCHEMA_VERSION_V2
            && isset($document['recommendation_summary'])
            && is_array($document['recommendation_summary'])
        ) {
            $out['recommendation_summary'] = self::normalizeRecommendationSummary($document['recommendation_summary']);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    public static function normalizeRecommendationSummary(array $summary): array
    {
        $topProducts = [];
        if (isset($summary['top_products']) && is_array($summary['top_products'])) {
            foreach ($summary['top_products'] as $product) {
                if (!is_array($product)) {
                    continue;
                }
                $title = isset($product['title']) ? trim((string) $product['title']) : '';
                if ($title === '') {
                    continue;
                }
                $topProducts[] = [
                    'title' => $title,
                    'summary' => isset($product['summary']) && is_string($product['summary'])
                        ? (trim($product['summary']) !== '' ? trim($product['summary']) : null)
                        : null,
                    'primary_url' => isset($product['primary_url']) ? trim((string) $product['primary_url']) : '',
                    'display_emoji' => isset($product['display_emoji']) ? trim((string) $product['display_emoji']) : '✈️',
                ];
            }
        }

        $preferenceHints = [];
        if (isset($summary['preference_hints']) && is_array($summary['preference_hints'])) {
            foreach ($summary['preference_hints'] as $hint) {
                if (!is_string($hint)) {
                    continue;
                }
                $trimmed = trim($hint);
                if ($trimmed !== '') {
                    $preferenceHints[] = $trimmed;
                }
            }
        }

        return [
            'result_count' => isset($summary['result_count']) ? max(0, (int) $summary['result_count']) : 0,
            'top_products' => $topProducts,
            'primary_url' => isset($summary['primary_url']) ? trim((string) $summary['primary_url']) : '',
            'recommendation_reason' => isset($summary['recommendation_reason'])
                ? trim((string) $summary['recommendation_reason'])
                : '',
            'preference_hints' => $preferenceHints,
        ];
    }

    /**
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    public static function normalizeBatsSearchIntent(array $intent): array
    {
        return [
            'intent' => isset($intent['intent']) ? trim((string) $intent['intent']) : 'tour_search',
            'destination' => array_key_exists('destination', $intent)
                ? (is_string($intent['destination']) || $intent['destination'] === null
                    ? ($intent['destination'] === null ? null : trim((string) $intent['destination']))
                    : null)
                : null,
            'destination_alias' => self::normalizeStringList($intent['destination_alias'] ?? []),
            'multi_destination' => self::normalizeStringList($intent['multi_destination'] ?? []),
            'departure_city' => array_key_exists('departure_city', $intent)
                ? (is_string($intent['departure_city']) || $intent['departure_city'] === null
                    ? ($intent['departure_city'] === null ? null : trim((string) $intent['departure_city']))
                    : null)
                : null,
            'date_from' => array_key_exists('date_from', $intent)
                ? (is_string($intent['date_from']) || $intent['date_from'] === null
                    ? ($intent['date_from'] === null ? null : trim((string) $intent['date_from']))
                    : null)
                : null,
            'date_to' => array_key_exists('date_to', $intent)
                ? (is_string($intent['date_to']) || $intent['date_to'] === null
                    ? ($intent['date_to'] === null ? null : trim((string) $intent['date_to']))
                    : null)
                : null,
            'travel_type' => self::normalizeStringList($intent['travel_type'] ?? []),
            'budget_min' => array_key_exists('budget_min', $intent) && $intent['budget_min'] !== null && $intent['budget_min'] !== ''
                ? (int) $intent['budget_min']
                : null,
            'budget_max' => array_key_exists('budget_max', $intent) && $intent['budget_max'] !== null && $intent['budget_max'] !== ''
                ? (int) $intent['budget_max']
                : null,
            'people_count' => array_key_exists('people_count', $intent) && $intent['people_count'] !== null && $intent['people_count'] !== ''
                ? (int) $intent['people_count']
                : null,
            'landmark' => array_key_exists('landmark', $intent)
                ? (is_string($intent['landmark']) || $intent['landmark'] === null
                    ? ($intent['landmark'] === null ? null : trim((string) $intent['landmark']))
                    : null)
                : null,
            'must_have' => self::normalizeStringList($intent['must_have'] ?? []),
            'avoid' => self::normalizeStringList($intent['avoid'] ?? []),
            'clarification_required' => array_key_exists('clarification_required', $intent)
                ? (bool) $intent['clarification_required']
                : false,
            'clarification_reason' => array_key_exists('clarification_reason', $intent)
                ? (is_string($intent['clarification_reason']) || $intent['clarification_reason'] === null
                    ? ($intent['clarification_reason'] === null ? null : trim((string) $intent['clarification_reason']))
                    : null)
                : null,
            'confidence' => isset($intent['confidence']) ? round((float) $intent['confidence'], 2) : 0.0,
            'free_text' => isset($intent['free_text']) ? trim((string) $intent['free_text']) : '',
        ];
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function normalizeStringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $trimmed = trim((string) $item);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function normalizeSearchResult(array $item): array
    {
        $normalized = [
            'title' => isset($item['title']) ? trim((string) $item['title']) : '',
            'summary' => null,
            'primary_url' => isset($item['primary_url']) ? trim((string) $item['primary_url']) : '',
            'metadata' => [],
        ];

        if (isset($item['summary']) && is_string($item['summary'])) {
            $summary = trim($item['summary']);
            $normalized['summary'] = $summary !== '' ? $summary : null;
        }

        if (isset($item['url']) && $normalized['primary_url'] === '') {
            $normalized['primary_url'] = trim((string) $item['url']);
        }

        if (isset($item['metadata']) && is_array($item['metadata'])) {
            $normalized['metadata'] = $item['metadata'];
        }

        return $normalized;
    }
}
