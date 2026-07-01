<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'LayoutStrategyInterface.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'LayoutDraft.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'RuntimeType.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'LayoutProfile.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'knowledge' . DIRECTORY_SEPARATOR . 'KnowledgeResponseComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'knowledge' . DIRECTORY_SEPARATOR . 'KnowledgeQueryFieldResolver.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'knowledge' . DIRECTORY_SEPARATOR . 'TenantPrivateKnowledgeRuntime.php';

/**
 * Phase 2-E Step 2-E-2b — Knowledge path layout strategy.
 *
 * Delegates NLG to existing KnowledgeResponseComposer; does not pass through
 * rawRuntimeResult.reply_text as final output.
 */
final class KnowledgeLayoutStrategy implements LayoutStrategyInterface
{
    /** @var list<string> */
    private const PROFILE_FIELDS = [
        KnowledgeQueryFieldResolver::FIELD_PHONE,
        KnowledgeQueryFieldResolver::FIELD_ADDRESS,
        KnowledgeQueryFieldResolver::FIELD_BUSINESS_HOURS,
        KnowledgeQueryFieldResolver::FIELD_SUMMARY,
        KnowledgeQueryFieldResolver::FIELD_COMPANY_NAME,
        KnowledgeQueryFieldResolver::FIELD_LINE_OFFICIAL,
        KnowledgeQueryFieldResolver::FIELD_EMAIL,
        KnowledgeQueryFieldResolver::FIELD_WEBSITE,
    ];

    private KnowledgeResponseComposer $composer;

    public function __construct(?KnowledgeResponseComposer $composer = null)
    {
        $this->composer = $composer ?? new KnowledgeResponseComposer(static function (string $poolKey, int $count): int {
            return 0;
        });
    }

    public function supports(GroundedInput $input): bool
    {
        $runtimeType = $input->getRuntimeType();

        return $runtimeType === RuntimeType::KNOWLEDGE_PRIVATE
            || $runtimeType === RuntimeType::KNOWLEDGE_SHARED;
    }

    public function compose(GroundedInput $input): LayoutDraft
    {
        $raw = $input->getRawRuntimeResult();
        $layout = is_array($raw['knowledge_layout'] ?? null) ? $raw['knowledge_layout'] : [];
        $queryType = trim((string) ($input->getKnowledgeType() ?? ($raw['query_type'] ?? '')));
        $runtimeType = $input->getRuntimeType();
        $grounded = $input->isRuntimeGrounded();
        $companyName = trim((string) ($input->getTenant()['company_name'] ?? ''));
        $finalRoute = trim((string) ($raw['final_route'] ?? ''));

        $replyText = $this->composeReplyText(
            $input,
            $layout,
            $queryType,
            $runtimeType,
            $grounded,
            $companyName,
            $finalRoute
        );

        $usedFactsCount = $input->getFactCount();
        if ($grounded && $usedFactsCount === 0) {
            $usedFactsCount = 1;
        }
        if (!$grounded) {
            $usedFactsCount = 0;
        }

        $replyType = ReplyType::NORMAL;
        if (!$grounded) {
            if (strpos($finalRoute, 'missing_profile') !== false || strpos($finalRoute, 'empty_field') !== false) {
                $replyType = ReplyType::NO_RESULTS;
            }
        }

        return new LayoutDraft(
            $replyText,
            $grounded,
            $usedFactsCount,
            $replyType,
            LayoutProfile::KNOWLEDGE_STANDARD,
            $this->collectReferencedFactIds($input)
        );
    }

    /**
     * @param array<string, mixed> $layout
     */
    private function composeReplyText(
        GroundedInput $input,
        array $layout,
        string $queryType,
        string $runtimeType,
        bool $grounded,
        string $companyName,
        string $finalRoute
    ): string {
        if ($runtimeType === RuntimeType::KNOWLEDGE_SHARED) {
            $fact = trim((string) ($layout['grounded_fact'] ?? $this->extractCoreBlock($input->getRuntimeReplyText())));
            $category = isset($layout['category']) ? (string) $layout['category'] : $this->extractCategoryFromLabel(
                $this->extractLabelBlock($input->getRuntimeReplyText())
            );

            return $this->composer->composeSharedFaqReply($fact, $category !== '' ? $category : null);
        }

        if (strpos($finalRoute, 'missing_profile') !== false) {
            return $this->composer->composeMissingProfileReply();
        }

        if (strpos($finalRoute, 'empty_field') !== false) {
            return $this->composer->composeEmptyFieldReply();
        }

        if ($queryType === TenantPrivateKnowledgeRuntime::QUERY_TYPE_SERVICE_QA) {
            $fact = trim((string) ($layout['grounded_fact'] ?? $this->extractCoreBlock($input->getRuntimeReplyText())));
            $category = isset($layout['category']) ? (string) $layout['category'] : $this->extractCategoryFromLabel(
                $this->extractLabelBlock($input->getRuntimeReplyText())
            );

            return $this->composer->composeServiceQaReply($fact, $category !== '' ? $category : null);
        }

        if ($queryType === TenantPrivateKnowledgeRuntime::QUERY_TYPE_SPECIAL_PRICES) {
            $itemName = trim((string) ($layout['item_name'] ?? ''));
            $priceAmount = $layout['price_amount'] ?? null;
            if ($itemName === '' || $priceAmount === null) {
                [$itemName, $priceAmount] = $this->parseSpecialPriceCore(
                    (string) ($layout['grounded_fact'] ?? $this->extractCoreBlock($input->getRuntimeReplyText()))
                );
            }

            return $this->composer->composeSpecialPriceReply($itemName, $priceAmount);
        }

        if ($queryType === TenantPrivateKnowledgeRuntime::QUERY_TYPE_SERVICE_ITEMS) {
            $serviceNames = $layout['service_names'] ?? $this->parseBulletNames(
                (string) ($layout['grounded_fact'] ?? $this->extractCoreBlock($input->getRuntimeReplyText()))
            );
            $isList = (bool) ($layout['is_list'] ?? count($serviceNames) > 1);

            return $this->composer->composeServiceItemsReply(
                is_array($serviceNames) ? $serviceNames : [],
                $isList
            );
        }

        if ($queryType === TenantPrivateKnowledgeRuntime::QUERY_TYPE_EXTERNAL_PRODUCT_LINKS) {
            $links = $layout['links'] ?? $this->parseExternalLinks(
                (string) ($layout['grounded_fact'] ?? $this->extractCoreBlock($input->getRuntimeReplyText()))
            );
            $isList = (bool) ($layout['is_list'] ?? count($links) > 1);

            return $this->composer->composeExternalProductLinksReply(
                is_array($links) ? $links : [],
                $isList
            );
        }

        if ($this->isProfileField($queryType)) {
            $value = trim((string) ($layout['value'] ?? $this->extractCoreBlock($input->getRuntimeReplyText())));

            return $this->composer->composeCompanyProfileReply($queryType, $companyName, $value);
        }

        if (!$grounded) {
            return $this->composer->composeUnresolvableReply();
        }

        return $this->composer->composeUnresolvableReply();
    }

    private function isProfileField(string $queryType): bool
    {
        return in_array($queryType, self::PROFILE_FIELDS, true);
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

    private function extractCoreBlock(string $replyText): string
    {
        $parts = $this->splitReplyBlocks($replyText);
        if (count($parts) >= 3) {
            return trim($parts[2]);
        }

        return trim($replyText);
    }

    private function extractLabelBlock(string $replyText): string
    {
        $parts = $this->splitReplyBlocks($replyText);
        if (count($parts) >= 2) {
            return trim($parts[1]);
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function splitReplyBlocks(string $replyText): array
    {
        $replyText = trim($replyText);
        if ($replyText === '') {
            return [];
        }

        $parts = preg_split('/\n\n+/', $replyText);

        return is_array($parts) ? array_values(array_map('trim', $parts)) : [];
    }

    private function extractCategoryFromLabel(string $label): string
    {
        if (preg_match('/關於(.+?)，為您整理如下：/u', $label, $matches) === 1) {
            return trim($matches[1]);
        }

        return '';
    }

    /**
     * @return array{0: string, 1: int|float|string|null}
     */
    private function parseSpecialPriceCore(string $core): array
    {
        $core = trim($core);
        if ($core === '') {
            return ['', null];
        }

        $segments = preg_split('/[：:]/u', $core, 2);
        if (!is_array($segments) || count($segments) < 2) {
            return ['', null];
        }

        $itemName = trim($segments[0]);
        $priceText = trim(str_replace('元', '', $segments[1]));

        return [$itemName, $priceText !== '' && is_numeric($priceText) ? $priceText : null];
    }

    /**
     * @return list<string>
     */
    private function parseBulletNames(string $core): array
    {
        $names = [];
        foreach (preg_split('/\R/u', $core) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $line = preg_replace('/^•\s*/u', '', $line) ?? $line;
            if ($line !== '') {
                $names[] = $line;
            }
        }

        return $names;
    }

    /**
     * @return list<array{name: string, url: string}>
     */
    private function parseExternalLinks(string $core): array
    {
        $links = [];
        foreach (preg_split('/\R/u', $core) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $line = preg_replace('/^•\s*/u', '', $line) ?? $line;
            $segments = preg_split('/[：:]/u', $line, 2);
            if (!is_array($segments) || count($segments) < 2) {
                continue;
            }
            $name = trim($segments[0]);
            $url = trim($segments[1]);
            if ($name !== '' && $url !== '') {
                $links[] = ['name' => $name, 'url' => $url];
            }
        }

        return $links;
    }
}
