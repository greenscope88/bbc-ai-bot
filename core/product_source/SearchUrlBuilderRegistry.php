<?php
declare(strict_types=1);

/**
 * Config-driven registry for SearchUrlBuilder (Phase 9-B-11).
 *
 * Platforms, tenant instances, and URL templates are injected — not hardcoded in builder core.
 */
final class SearchUrlBuilderRegistry
{
    /** @var array<string, array<string, mixed>> */
    private $platformsById = [];

    /** @var array<string, array<string, mixed>> */
    private $instancesByTenantKey = [];

    /** @var array<string, array<string, mixed>> */
    private $urlTemplatesById = [];

    /**
     * @param array<string, array<string, mixed>> $platformsById
     * @param array<string, array<string, mixed>> $instancesByTenantKey
     * @param array<string, array<string, mixed>> $urlTemplatesById
     */
    public function __construct(
        array $platformsById = [],
        array $instancesByTenantKey = [],
        array $urlTemplatesById = []
    ) {
        $this->platformsById = $platformsById;
        $this->instancesByTenantKey = $instancesByTenantKey;
        $this->urlTemplatesById = $urlTemplatesById;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPlatform(string $platformId): ?array
    {
        $key = trim($platformId);

        return $key !== '' && isset($this->platformsById[$key]) ? $this->platformsById[$key] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getInstanceByTenantKey(string $tenantInstanceKey): ?array
    {
        $key = trim($tenantInstanceKey);

        return $key !== '' && isset($this->instancesByTenantKey[$key]) ? $this->instancesByTenantKey[$key] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUrlTemplate(string $templateId): ?array
    {
        $key = trim($templateId);

        return $key !== '' && isset($this->urlTemplatesById[$key]) ? $this->urlTemplatesById[$key] : null;
    }
}
