<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'HumanServiceResponseComposer.php';

/**
 * Phase 9-C-2B-3 — Knowledge priority waterfall after tenant private miss.
 *
 * L2 Industry Shared and L3 Global Shared are stubs in this phase.
 */
final class KnowledgeFallbackResolver
{
    private HumanServiceResponseComposer $humanServiceComposer;

    public function __construct(?HumanServiceResponseComposer $humanServiceComposer = null)
    {
        $this->humanServiceComposer = $humanServiceComposer ?? new HumanServiceResponseComposer(static function (string $poolKey, int $count): int {
            return 0;
        });
    }

    /**
     * @return array{reply_text: string, grounded: bool, final_route: string, fallback_layer: string}
     */
    public function resolve(string $message, ?string $tenantKey = null, ?string $companyName = null): array
    {
        unset($message);

        // L2 Industry Shared — stub (9-C-2C+ / Shared sync later)
        // L3 Global Shared — stub

        return [
            'reply_text' => $this->humanServiceComposer->compose($tenantKey, $companyName),
            'grounded' => false,
            'final_route' => 'phase_9c2b3_knowledge_human_service',
            'fallback_layer' => 'human_service',
        ];
    }
}
