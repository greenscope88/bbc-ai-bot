<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'LayoutStrategyInterface.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'LayoutDraft.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'RuntimeType.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'LayoutProfile.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR
    . 'recommendation' . DIRECTORY_SEPARATOR . 'TravelConsultantPersonaRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR
    . 'recommendation' . DIRECTORY_SEPARATOR . 'TravelConsultantPersonaFormatter.php';

/**
 * Phase 2-E Step 2-E-2c — Product path layout strategy.
 *
 * Delegates NLG to existing TravelConsultantPersonaRuntime; does not pass through
 * rawRuntimeResult.reply_text as final output. Optional TourFallbackFormatter when
 * tour_context is present in rawRuntimeResult.
 */
final class ProductLayoutStrategy implements LayoutStrategyInterface
{
    private TravelConsultantPersonaRuntime $personaRuntime;

    public function __construct(?TravelConsultantPersonaRuntime $personaRuntime = null)
    {
        $this->personaRuntime = $personaRuntime ?? new TravelConsultantPersonaRuntime(
            TravelConsultantPersonaFormatter::createWithFixedIndex(0)
        );
    }

    public function supports(GroundedInput $input): bool
    {
        return $input->getRuntimeType() === RuntimeType::PRODUCT_SEARCH;
    }

    public function compose(GroundedInput $input): LayoutDraft
    {
        $raw = $input->getRawRuntimeResult();
        $tourContext = trim((string) ($raw['tour_context'] ?? ''));
        $replyText = $tourContext !== ''
            ? $this->composeFromTourContext($tourContext)
            : $this->composeFromPersonaRuntime($input);

        $summary = $this->normalizeRecommendationSummary($input);
        $resultCount = max(0, (int) ($summary['result_count'] ?? 0));
        $grounded = $input->isRuntimeGrounded() && $resultCount > 0;
        $usedFactsCount = $grounded ? $this->countUsedFacts($input, $summary) : 0;

        $replyType = $resultCount <= 0 || !$grounded
            ? ReplyType::NO_RESULTS
            : ReplyType::NORMAL;

        return new LayoutDraft(
            $replyText,
            $grounded,
            $usedFactsCount,
            $replyType,
            LayoutProfile::PRODUCT_RICH,
            $this->collectReferencedFactIds($input)
        );
    }

    private function composeFromTourContext(string $tourContext): string
    {
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tour_fallback_formatter.php';

        return TourFallbackFormatter::formatFromTourContext(
            $tourContext,
            TravelConsultantPersonaFormatter::createWithFixedIndex(0)
        );
    }

    private function composeFromPersonaRuntime(GroundedInput $input): string
    {
        $summary = $this->normalizeRecommendationSummary($input);
        $resultCount = max(0, (int) ($summary['result_count'] ?? 0));
        $mode = $input->getReplyPolicyMode();

        if ($resultCount <= 0 || $mode === 'no_results') {
            return $this->personaRuntime->composeNoResultsMessage();
        }

        return $this->personaRuntime->composeProductRecommendation($summary);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeRecommendationSummary(GroundedInput $input): array
    {
        $summary = $input->getRecommendationSummary();
        $productList = $input->getProductList();
        $maxProducts = max(1, (int) ($input->getReplyPolicy()['max_products'] ?? 3));

        $topProducts = [];
        if (isset($summary['top_products']) && is_array($summary['top_products']) && $summary['top_products'] !== []) {
            $topProducts = $summary['top_products'];
        } else {
            $topProducts = $productList;
        }

        $topProducts = array_values(array_filter($topProducts, 'is_array'));
        $topProducts = array_slice($topProducts, 0, $maxProducts);

        $resultCount = isset($summary['result_count'])
            ? max(0, (int) $summary['result_count'])
            : count($topProducts);

        $normalized = $summary;
        $normalized['result_count'] = $resultCount;
        $normalized['top_products'] = $topProducts;

        if (!isset($normalized['primary_url']) || trim((string) $normalized['primary_url']) === '') {
            foreach ($topProducts as $product) {
                $url = trim((string) ($product['primary_url'] ?? ''));
                if ($url !== '') {
                    $normalized['primary_url'] = $url;
                    break;
                }
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function countUsedFacts(GroundedInput $input, array $summary): int
    {
        $topProducts = isset($summary['top_products']) && is_array($summary['top_products'])
            ? $summary['top_products']
            : [];
        $fromSummary = count(array_filter($topProducts, static function ($row): bool {
            return is_array($row) && trim((string) ($row['title'] ?? '')) !== '';
        }));

        if ($fromSummary > 0) {
            return $fromSummary;
        }

        return $input->getFactCount();
    }

    /**
     * @return list<string>
     */
    private function collectReferencedFactIds(GroundedInput $input): array
    {
        $ids = [];
        foreach ($input->getGroundedFacts() as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $factId = $fact['fact_id'] ?? null;
            if ($factId !== null && $factId !== '') {
                $ids[] = (string) $factId;
            }
        }

        return $ids;
    }
}
