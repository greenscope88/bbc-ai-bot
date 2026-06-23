<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'HumanServiceResponseComposer.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'IndustrySharedKnowledgeRuntime.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsSourceRegistryLoader.php';

/**
 * Phase 9-C-2B-3 / 9-C-2D — Knowledge priority waterfall after tenant private miss.
 *
 * L2 Industry Shared → L3 Global Shared stub → L4 Human Service.
 */
final class KnowledgeFallbackResolver
{
    private HumanServiceResponseComposer $humanServiceComposer;

    private IndustrySharedKnowledgeRuntime $sharedRuntime;

    public function __construct(
        ?HumanServiceResponseComposer $humanServiceComposer = null,
        ?IndustrySharedKnowledgeRuntime $sharedRuntime = null
    ) {
        $this->humanServiceComposer = $humanServiceComposer ?? new HumanServiceResponseComposer(static function (string $poolKey, int $count): int {
            return 0;
        });
        $this->sharedRuntime = $sharedRuntime ?? new IndustrySharedKnowledgeRuntime();
    }

    /**
     * @return array{
     *   reply_text: string,
     *   grounded: bool,
     *   final_route: string,
     *   fallback_layer: string,
     *   shared_item_id?: string|null
     * }
     */
    public function resolve(
        string $message,
        ?string $tenantKey = null,
        ?string $companyName = null,
        ?string $industryCode = null
    ): array {
        $industryCode = trim((string) $industryCode);
        if ($industryCode !== '') {
            $sharedResult = $this->sharedRuntime->handle($message, $industryCode);
            if ($sharedResult !== null) {
                return $sharedResult;
            }
        }

        // L3 Global Shared — stub (future phase)

        return [
            'reply_text' => $this->humanServiceComposer->compose($tenantKey, $companyName),
            'grounded' => false,
            'final_route' => 'phase_9c2b3_knowledge_human_service',
            'fallback_layer' => 'human_service',
        ];
    }

    /**
     * @deprecated Use industry_code from Tenant Resolve context; not used by main flow.
     */
    private function resolveIndustryCode(?string $tenantKey): string
    {
        $tenantKey = trim((string) $tenantKey);
        if ($tenantKey === '') {
            return '';
        }

        $entry = BdsSourceRegistryLoader::loadByTenantKey($tenantKey);
        if ($entry === null) {
            return '';
        }

        return trim((string) ($entry['industry_code'] ?? ''));
    }
}