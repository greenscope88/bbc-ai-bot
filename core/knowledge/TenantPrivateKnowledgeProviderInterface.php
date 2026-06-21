<?php
declare(strict_types=1);

/**
 * Tenant private knowledge read provider (Phase 9-C-2B-2A).
 */
interface TenantPrivateKnowledgeProviderInterface
{
    public function getTenantSno(): string;

    /**
     * @return array<string, mixed>
     */
    public function fetchCompanyProfileDocument(): array;

    /**
     * @return array<string, mixed>
     */
    public function fetchServiceQaDocument(): array;
}
