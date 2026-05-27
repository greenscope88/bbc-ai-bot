<?php
declare(strict_types=1);

/**
 * Immutable tenant resolution result (Phase 2A). No runtime business logic.
 */
final class ResolvedTenant
{
    /** @var string */
    private $tenantKey;

    /** @var string */
    private $lineChannelId;

    /** @var string */
    private $sno;

    /** @var int */
    private $depId;

    /** @var int */
    private $storeNo;

    /** @var int */
    private $storeUid;

    /** @var int */
    private $providerIdNo;

    /** @var string enabled|staging|disabled */
    private $status;

    /** @var array<string, bool> */
    private $features;

    /** @var array<string, mixed> */
    private $profile;

    /** @var array<string, mixed> */
    private $geminiPolicy;

    /** @var array<string, mixed> */
    private $sourcePolicy;

    /**
     * @param array<string, bool> $features
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $geminiPolicy
     * @param array<string, mixed> $sourcePolicy
     */
    public function __construct(
        string $tenantKey,
        string $lineChannelId,
        string $sno,
        int $depId,
        int $storeNo,
        int $storeUid,
        int $providerIdNo,
        string $status,
        array $features,
        array $profile,
        array $geminiPolicy,
        array $sourcePolicy
    ) {
        $this->tenantKey = $tenantKey;
        $this->lineChannelId = $lineChannelId;
        $this->sno = $sno;
        $this->depId = $depId;
        $this->storeNo = $storeNo;
        $this->storeUid = $storeUid;
        $this->providerIdNo = $providerIdNo;
        $this->status = $status;
        $this->features = $features;
        $this->profile = $profile;
        $this->geminiPolicy = $geminiPolicy;
        $this->sourcePolicy = $sourcePolicy;
    }

    public function getTenantKey(): string
    {
        return $this->tenantKey;
    }

    public function getLineChannelId(): string
    {
        return $this->lineChannelId;
    }

    public function getSno(): string
    {
        return $this->sno;
    }

    public function getDepId(): int
    {
        return $this->depId;
    }

    public function getStoreNo(): int
    {
        return $this->storeNo;
    }

    public function getStoreUid(): int
    {
        return $this->storeUid;
    }

    public function getProviderIdNo(): int
    {
        return $this->providerIdNo;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /** @return array<string, bool> */
    public function getFeatures(): array
    {
        return $this->features;
    }

    /** @return array<string, mixed> */
    public function getProfile(): array
    {
        return $this->profile;
    }

    /** @return array<string, mixed> */
    public function getGeminiPolicy(): array
    {
        return $this->geminiPolicy;
    }

    /** @return array<string, mixed> */
    public function getSourcePolicy(): array
    {
        return $this->sourcePolicy;
    }

    /**
     * True when tenant status is explicitly enabled (not staging/disabled).
     */
    public function isEnabled(): bool
    {
        return $this->status === 'enabled';
    }

    public function isFeatureEnabled(string $feature): bool
    {
        return ($this->features[$feature] ?? false) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tenant_key' => $this->tenantKey,
            'line_channel_id' => $this->lineChannelId,
            'sno' => $this->sno,
            'depID' => $this->depId,
            'storeNo' => $this->storeNo,
            'store_uid' => $this->storeUid,
            'provider_id_no' => $this->providerIdNo,
            'status' => $this->status,
            'features' => $this->features,
            'profile' => $this->profile,
            'gemini_policy' => $this->geminiPolicy,
            'source_policy' => $this->sourcePolicy,
        ];
    }
}
