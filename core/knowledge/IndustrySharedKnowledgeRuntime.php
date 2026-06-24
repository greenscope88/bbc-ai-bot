<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GcsIndustrySharedKnowledgeProvider.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'IndustrySharedKnowledgeProviderInterface.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'KnowledgeResponseComposer.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SharedKnowledgeMatcher.php';

/**
 * Phase 9-C-2D — Industry shared FAQ runtime (L2).
 */
final class IndustrySharedKnowledgeRuntime
{
    /** @var callable(string): IndustrySharedKnowledgeProviderInterface */
    private $providerFactory;

    private SharedKnowledgeMatcher $matcher;

    private KnowledgeResponseComposer $composer;

    /**
     * @param callable(string): IndustrySharedKnowledgeProviderInterface|null $providerFactory
     */
    public function __construct(
        ?SharedKnowledgeMatcher $matcher = null,
        ?KnowledgeResponseComposer $composer = null,
        ?callable $providerFactory = null
    ) {
        $this->matcher = $matcher ?? new SharedKnowledgeMatcher();
        $this->composer = $composer ?? new KnowledgeResponseComposer(static function (string $poolKey, int $count): int {
            return 0;
        });
        $this->providerFactory = $providerFactory ?? static function (string $industryCode): IndustrySharedKnowledgeProviderInterface {
            return new GcsIndustrySharedKnowledgeProvider($industryCode);
        };
    }

    /**
     * @return array{
     *   reply_text: string,
     *   grounded: bool,
     *   final_route: string,
     *   fallback_layer: string,
     *   shared_item_id?: string|null
     * }|null
     */
    public function handle(string $message, string $industryCode): ?array
    {
        $industryCode = trim($industryCode);
        if ($industryCode === '') {
            return null;
        }

        $provider = ($this->providerFactory)($industryCode);
        $document = $provider->fetchFaqDocument();
        $items = $document['items'] ?? null;
        if (!is_array($items) || $items === []) {
            return null;
        }

        $match = $this->matcher->match($message, $items);
        if ($match === null) {
            return null;
        }

        $result = [
            'reply_text' => $this->composer->composeSharedFaqReply(
                (string) ($match['grounded_fact'] ?? ''),
                isset($match['category']) ? (string) $match['category'] : null
            ),
            'grounded' => true,
            'final_route' => 'phase_9c2d_industry_shared_runtime',
            'fallback_layer' => 'industry_shared',
        ];

        $itemId = trim((string) ($match['item_id'] ?? ''));
        if ($itemId !== '') {
            $result['shared_item_id'] = $itemId;
        }

        return $result;
    }
}
