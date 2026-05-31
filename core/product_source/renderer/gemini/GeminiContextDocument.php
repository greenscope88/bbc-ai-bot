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

    /**
     * @param list<array<string, mixed>> $searchResults
     * @param array<string, mixed> $voiceProfile
     * @param list<string> $tenantServiceScope
     * @param array<string, mixed> $guardPolicy
     * @param array<string, mixed> $fallbackPolicy
     */
    private function __construct(
        string $customerQuery,
        array $searchResults,
        string $tenantName,
        array $voiceProfile,
        array $tenantServiceScope,
        array $guardPolicy,
        array $fallbackPolicy,
        int $schemaVersion
    ) {
        $this->customerQuery = $customerQuery;
        $this->searchResults = $searchResults;
        $this->tenantName = $tenantName;
        $this->voiceProfile = $voiceProfile;
        $this->tenantServiceScope = $tenantServiceScope;
        $this->guardPolicy = $guardPolicy;
        $this->fallbackPolicy = $fallbackPolicy;
        $this->schemaVersion = $schemaVersion;
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
            (int) $normalized['schema_version']
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'customer_query' => $this->customerQuery,
            'search_results' => $this->searchResults,
            'tenant_name' => $this->tenantName,
            'voice_profile' => $this->voiceProfile,
            'tenant_service_scope' => $this->tenantServiceScope,
            'guard_policy' => $this->guardPolicy,
            'fallback_policy' => $this->fallbackPolicy,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSearchResults(): array
    {
        return $this->searchResults;
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

        return $out;
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
