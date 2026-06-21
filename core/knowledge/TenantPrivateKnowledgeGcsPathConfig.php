<?php
declare(strict_types=1);

/**
 * GCS object paths for tenant private knowledge (Phase 9-C-2B-2).
 */
final class TenantPrivateKnowledgeGcsPathConfig
{
    public const COMPANY_PROFILE_FILE = 'company_profile.json';
    public const SERVICE_QA_FILE = 'service_qa.json';
    public const SPECIAL_PRICES_FILE = 'special_prices.json';
    public const SERVICE_ITEMS_FILE = 'service_items.json';
    public const EXTERNAL_PRODUCT_LINKS_FILE = 'external_product_links.json';

    public static function resolveObjectPath(string $tenantSno, string $filename): string
    {
        $tenantSno = trim($tenantSno);
        $filename = trim($filename);
        if ($tenantSno === '' || $filename === '') {
            throw new InvalidArgumentException('tenant_sno and filename are required.');
        }

        return 'tenants/' . $tenantSno . '/knowledge/' . $filename;
    }
}
