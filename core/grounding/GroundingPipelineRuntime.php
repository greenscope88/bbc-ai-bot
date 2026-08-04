<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundingOrchestratorContextFactory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundingRuntime.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundingAssemblyException.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'GroundedResponseComposer.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'GroundedOutput.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'RuntimeType.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'LayoutProfile.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'facts'
    . DIRECTORY_SEPARATOR . 'GroundedListingLinkFactBuilder.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'facts'
    . DIRECTORY_SEPARATOR . 'GroundedListingLinkFactsValidator.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'clarification'
    . DIRECTORY_SEPARATOR . 'ClarificationContract.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'clarification'
    . DIRECTORY_SEPARATOR . 'ClarificationContractFactory.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'published'
    . DIRECTORY_SEPARATOR . 'PublishedProductSetSelector.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ProductLineageObservation.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'published'
    . DIRECTORY_SEPARATOR . 'PublicationIntegrityValidator.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer'
    . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'BbcshopsFlexCarouselRenderer.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer'
    . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'LineFlexCarouselPayloadValidator.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer'
    . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'LineMessagePayload.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer'
    . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'LineMessagePayloadValidator.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer'
    . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'OtherSourceListingLinksMessageBuilder.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer'
    . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'OtherSourceListingLinksIntegrityValidator.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'validation'
    . DIRECTORY_SEPARATOR . 'GroundedOutputDegrader.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'DestinationExecutionGateReplyComposer.php';

/**
 * Phase 2-F Step 2-F-2b — authoritative Grounding pipeline with legacy fallback.
 *
 * SSOT: docs/BATS_AI_GROUNDING_LAYER.md §11 / §25.
 *
 * B0-LINE-02C-2: composeClarificationReply is the sole Pipeline entry for
 * generative clarification (no Search/Host B; never parses utterance).
 */
final class GroundingPipelineRuntime
{
    public const FLAG_ENABLED = 'grounding_layer_authoritative_enabled';

    public const FLAG_TENANTS = 'grounding_layer_authoritative_tenant_snos';

    public const PIPELINE_LEGACY = 'legacy';

    public const PIPELINE_AUTHORITATIVE = 'grounding_authoritative';

    public const PIPELINE_LEGACY_FALLBACK = 'legacy_fallback';

    public const PIPELINE_CLARIFICATION = 'clarification';

    public const ROUTE_GENERATIVE_CLARIFICATION = 'phase_9c1_generative_clarification';

    public const ROUTE_CLARIFICATION_FAIL_CLOSED = 'phase_9c1_clarification_fail_closed';

    public const ROUTE_DESTINATION_EXECUTION_GATE = 'phase_9c1_destination_execution_gate';

    public const FEATURE_KEY = 'grounding_authoritative';

    /**
     * Global kill switch remains FLAG_ENABLED.
     * Production membership: Registry status in {staging,enabled} AND features.grounding_authoritative.
     * FLAG_TENANTS is not a Production Activation Authority.
     *
     * @param array<string, mixed> $config
     * @param mixed $registry TenantRegistryInterface|null
     */
    public static function isAuthoritativeEnabled(array $config, string $tenantSno, $registry = null): bool
    {
        $enabled = isset($config[self::FLAG_ENABLED]) && (bool) $config[self::FLAG_ENABLED];
        if (!$enabled) {
            return false;
        }

        $tenantSno = trim($tenantSno);
        if ($tenantSno === '') {
            return false;
        }

        if (!is_object($registry) || !method_exists($registry, 'resolveBySno')) {
            if (isset($config['tenant_registry']) && is_object($config['tenant_registry']) && method_exists($config['tenant_registry'], 'resolveBySno')) {
                $registry = $config['tenant_registry'];
            } elseif (isset($config['tenant_registry_path']) && is_string($config['tenant_registry_path']) && $config['tenant_registry_path'] !== '') {
                require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . 'ConfigTenantRegistry.php';
                $registry = new \ConfigTenantRegistry($config['tenant_registry_path']);
            } else {
                require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . 'ConfigTenantRegistry.php';
                $registry = new \ConfigTenantRegistry();
            }
        }

        $tenant = $registry->resolveBySno($tenantSno);
        if ($tenant === null) {
            return false;
        }

        $status = $tenant->getStatus();
        if ($status !== 'staging' && $status !== 'enabled') {
            return false;
        }

        return $tenant->isFeatureEnabled(self::FEATURE_KEY);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{output: GroundedOutput, pipeline: string, fallback_reason?: string}
     */
    public static function composeKnowledgeReply(array $params): array
    {
        $config = isset($params['config']) && is_array($params['config'])
            ? $params['config']
            : self::loadDefaultConfig();
        $tenantSno = trim((string) ($params['tenant_sno'] ?? ''));
        $composer = $params['composer'] ?? new GroundedResponseComposer($config);
        $legacyTenant = is_array($params['legacy_tenant'] ?? null) ? $params['legacy_tenant'] : [];
        $runtimeResult = is_array($params['runtime_result'] ?? null) ? $params['runtime_result'] : [];

        if (!self::isAuthoritativeEnabled($config, $tenantSno)) {
            return [
                'output' => $composer->composeFromKnowledgeResult($runtimeResult, $legacyTenant),
                'pipeline' => self::PIPELINE_LEGACY,
            ];
        }

        try {
            $context = GroundingOrchestratorContextFactory::build($params);
            $groundingRuntime = $params['grounding_runtime'] ?? new GroundingRuntime();
            $groundedInput = $groundingRuntime->assemble($context);
            $output = $composer->compose($groundedInput);

            self::emitSuccess($params, 'knowledge');

            return [
                'output' => $output,
                'pipeline' => self::PIPELINE_AUTHORITATIVE,
            ];
        } catch (\Throwable $e) {
            self::emitFallback($params, 'knowledge', $e);

            return [
                'output' => $composer->composeFromKnowledgeResult($runtimeResult, $legacyTenant),
                'pipeline' => self::PIPELINE_LEGACY_FALLBACK,
                'fallback_reason' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{output: GroundedOutput, pipeline: string, fallback_reason?: string}
     */
    public static function composeProductReply(array $params): array
    {
        $config = isset($params['config']) && is_array($params['config'])
            ? $params['config']
            : self::loadDefaultConfig();
        $tenantSno = trim((string) ($params['tenant_sno'] ?? ''));
        $composer = $params['composer'] ?? new GroundedResponseComposer($config);
        $legacyTenant = is_array($params['legacy_tenant'] ?? null) ? $params['legacy_tenant'] : [];
        $runtimeResult = is_array($params['runtime_result'] ?? null) ? $params['runtime_result'] : [];

        if (!self::isAuthoritativeEnabled($config, $tenantSno)) {
            return [
                'output' => $composer->composeProductReply($runtimeResult, $legacyTenant),
                'pipeline' => self::PIPELINE_LEGACY,
            ];
        }

        try {
            $context = GroundingOrchestratorContextFactory::build($params);
            $groundingRuntime = $params['grounding_runtime'] ?? new GroundingRuntime();
            $groundedInput = $groundingRuntime->assemble($context);
            $output = $composer->compose($groundedInput);

            self::emitSuccess($params, 'product');

            return [
                'output' => $output,
                'pipeline' => self::PIPELINE_AUTHORITATIVE,
            ];
        } catch (\Throwable $e) {
            self::emitFallback($params, 'product', $e);

            return [
                'output' => $composer->composeProductReply($runtimeResult, $legacyTenant),
                'pipeline' => self::PIPELINE_LEGACY_FALLBACK,
                'fallback_reason' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array{output: GroundedOutput, pipeline: string, deliverable: bool, validation_passed: bool, used_facts_count: int, failure_reason: ?string}
     */
    public static function composeBbcshopsFlexMultiSourceReply(array $params): array
    {
        $tone = is_array($params['tone'] ?? null) ? $params['tone'] : ['persona' => 'travel_consultant', 'allow_emoji' => true];
        try {
            $searchResults = is_array($params['search_results'] ?? null)
                ? array_values(array_filter($params['search_results'], 'is_array'))
                : [];
            $storeNo = $params['storeNo'] ?? null;
            $selector = $params['published_product_set_selector'] ?? new PublishedProductSetSelector();
            if (!$selector instanceof PublishedProductSetSelector) {
                throw new \RuntimeException('published_product_set_selector_invalid');
            }
            $publishedSet = $selector->select($searchResults, $storeNo);
            $traceId = trim((string) ($params['trace_id'] ?? ''));
            $lineageTrace = $selector->getLastLineageTrace();
            if (is_array($lineageTrace)) {
                ProductLineageObservation::emitPublishedProductSet($traceId, $lineageTrace);
            }
            if ($searchResults !== [] && $publishedSet->getCount() === 0) {
                throw new \RuntimeException('bbcshops_published_set_empty_despite_eligible');
            }

            $productFacts = self::buildBbcshopsProductFacts($publishedSet->getProducts());
            $productFactIds = array_values(array_map(static function (array $fact): string {
                return (string) $fact['fact_id'];
            }, $productFacts));

            $messages = [];
            $referencedFactIds = [];
            $bbcshopsLink = self::resolveBbcshopsLinkInput($params);
            $otherLinks = self::resolveOtherSourceLinks($params);
            $linkBuilder = new GroundedListingLinkFactBuilder();
            $linkFacts = $linkBuilder->build($bbcshopsLink, $otherLinks);
            (new GroundedListingLinkFactsValidator())->validate($linkFacts);

            $displayLabel = self::resolvePrimarySearchDisplayLabel($params);
            $message1Text = self::buildOpeningText($publishedSet->getCount(), $displayLabel);
            if ($message1Text !== '') {
                $messages[] = ['type' => 'text', 'text' => $message1Text];
            }

            if ($publishedSet->getCount() > 0) {
                $renderResult = (new BbcshopsFlexCarouselRenderer())->render($publishedSet);
                ProductLineageObservation::emitFlexCarousel(
                    $traceId,
                    $publishedSet->getProducts(),
                    $renderResult->getBubbleFactIds()
                );
                (new LineFlexCarouselPayloadValidator())->validate($renderResult->getWireFlexMessage());
                (new PublicationIntegrityValidator())->validate($publishedSet, $productFacts, $renderResult, $productFactIds);
                $messages[] = $renderResult->getWireFlexMessage();
                foreach ($productFactIds as $id) {
                    $referencedFactIds[] = $id;
                }
            }

            $listingLinkText = self::buildMergedListingLinksText($bbcshopsLink, $linkFacts, $displayLabel);
            if ($listingLinkText !== null) {
                $messages[] = ['type' => 'text', 'text' => $listingLinkText['text']];
                foreach ($listingLinkText['fact_ids'] as $id) {
                    $referencedFactIds[] = $id;
                }
            }

            if ($messages === []) {
                $messages[] = ['type' => 'text', 'text' => self::noResultsText()];
            }

            $channelPayload = LineMessagePayload::fromMessages($messages);
            $facts = array_merge($productFacts, $linkFacts);
            $presentable = $publishedSet->getCount()
                + (($bbcshopsLink !== null && trim($bbcshopsLink['url']) !== '') ? 1 : 0)
                + count(self::filterOtherSourceLinkFacts($linkFacts));
            $replyPolicy = [
                'mode' => $presentable > 0 ? 'recommend' : 'no_results',
                'grounded_only' => true,
                'safety_degrader_profile' => GroundedOutputDegrader::PROFILE_BBCSHOPS_FLEX_MULTI_SOURCE,
                'global_presentable_result_count' => $presentable,
            ];
            $input = new GroundedInput(
                GroundedInput::SOURCE_PRODUCT_SEARCH,
                null,
                $facts,
                [
                    'reply_text' => self::firstTextMessage($messages),
                    'grounded' => $referencedFactIds !== [],
                    'recommendation_summary' => ['result_count' => $publishedSet->getCount()],
                    'product_list' => $publishedSet->getProducts(),
                ],
                is_array($params['tenant'] ?? null) ? $params['tenant'] : [],
                GroundedInput::PURPOSE_PRODUCT_REPLY,
                $publishedSet->getProducts(),
                ['result_count' => $publishedSet->getCount()],
                $replyPolicy,
                RuntimeType::PRODUCT_SEARCH,
                $tone
            );
            $composerConfig = isset($params['config']) && is_array($params['config']) ? $params['config'] : null;
            $output = (new GroundedResponseComposer($composerConfig))->composeBbcshopsFlexMultiSourceReply(
                $input,
                $channelPayload,
                $referencedFactIds
            );
            (new LineMessagePayloadValidator())->validateForReplyType(
                ($output->getChannelMessages() ?? $channelPayload)->toArray(),
                $output->getReplyType()
            );

            return [
                'output' => $output,
                'pipeline' => self::PIPELINE_AUTHORITATIVE,
                'deliverable' => $output->getChannelMessages() !== null,
                'validation_passed' => $output->isValidationPassed(),
                'used_facts_count' => $output->getUsedFactsCount(),
                'failure_reason' => null,
            ];
        } catch (\Throwable $e) {
            $output = self::buildTechnicalFailClosedOutput($tone, $e->getMessage());

            return [
                'output' => $output,
                'pipeline' => self::PIPELINE_AUTHORITATIVE,
                'deliverable' => $output->getChannelMessages() !== null,
                'validation_passed' => false,
                'used_facts_count' => 0,
                'failure_reason' => $e->getMessage(),
            ];
        }
    }

    /**
     * Clarification entry — never parses utterance, never calls Search/Host B.
     *
     * @param array<string, mixed> $params
     *
     * @return array{
     *   output: GroundedOutput,
     *   pipeline: string,
     *   route: string,
     *   clarification_reason: string,
     *   missing_entity: string,
     *   asked_entity: string,
     *   host_b_executed: bool,
     *   final_owner: string,
     *   failure_reason: ?string,
     *   validation_passed: bool,
     *   used_facts_count: int
     * }
     */
    public static function composeClarificationReply(array $params): array
    {
        $config = isset($params['config']) && is_array($params['config'])
            ? $params['config']
            : self::loadDefaultConfig();
        /** @var GroundedResponseComposer $composer */
        $composer = $params['composer'] ?? new GroundedResponseComposer($config);
        /** @var ClarificationContractFactory $factory */
        $factory = $params['contract_factory'] ?? new ClarificationContractFactory();

        $reason = trim((string) ($params['clarification_reason'] ?? ''));
        $knownEntities = self::resolveKnownEntities($params);
        $tenant = is_array($params['tenant'] ?? null) ? $params['tenant'] : [];
        if ($tenant === [] && is_array($params['legacy_tenant'] ?? null)) {
            $tenant = $params['legacy_tenant'];
        }
        $tone = is_array($params['tone'] ?? null) ? $params['tone'] : [
            'persona' => 'travel_consultant',
            'allow_emoji' => true,
        ];

        $factoryInput = [
            'clarification_required' => true,
            'clarification_reason' => $reason,
            'known_entities' => $knownEntities,
            'tenant' => $tenant,
            'tone' => $tone,
            'trace_id' => (string) ($params['trace_id'] ?? ''),
            'tenant_sno' => (string) ($params['tenant_sno'] ?? ($tenant['tenant_sno'] ?? $tenant['sno'] ?? '')),
            'conversation_id' => (string) ($params['conversation_id'] ?? ''),
        ];

        try {
            $contract = $factory->create($factoryInput);
        } catch (\Throwable $e) {
            $failureReason = trim($e->getMessage()) !== ''
                ? trim($e->getMessage())
                : 'clarification_input_invalid';
            $output = self::buildTechnicalFailClosedOutput($tone, $failureReason);
            self::emitClarification($params, $output, self::ROUTE_CLARIFICATION_FAIL_CLOSED, $failureReason, '');

            return self::clarificationEnvelope(
                $output,
                self::ROUTE_CLARIFICATION_FAIL_CLOSED,
                $reason,
                '',
                $failureReason
            );
        }

        $output = $composer->composeClarificationReply($contract);
        $isFailClosed = $output->getReplyText() === ClarificationContract::TECHNICAL_FAIL_CLOSED_TEXT
            || $output->isValidationPassed() === false;
        $route = $isFailClosed
            ? self::ROUTE_CLARIFICATION_FAIL_CLOSED
            : self::ROUTE_GENERATIVE_CLARIFICATION;
        $failureReason = $isFailClosed
            ? self::extractFailureReason($output, 'clarification_foundation_fail_closed')
            : null;

        self::emitClarification(
            $params,
            $output,
            $route,
            $failureReason,
            $contract->getMissingEntity()
        );

        return self::clarificationEnvelope(
            $output,
            $route,
            $contract->getClarificationReason(),
            $contract->getMissingEntity(),
            $failureReason
        );
    }

    /**
     * Destination execution gate — no Search / Host B / multi-source links.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function composeDestinationExecutionGateReply(array $params): array
    {
        $decision = trim((string) ($params['execution_gate_decision'] ?? ''));
        $text = DestinationExecutionGateReplyComposer::compose($decision);
        $tone = is_array($params['tone'] ?? null) ? $params['tone'] : [
            'persona' => 'travel_consultant',
            'allow_emoji' => true,
        ];
        $persona = trim((string) ($tone['persona'] ?? 'travel_consultant'));

        $output = new GroundedOutput(
            $text,
            true,
            0,
            GroundedInput::SOURCE_TENANT_PRIVATE,
            false,
            [],
            ReplyType::CLARIFICATION,
            LayoutProfile::MINIMAL,
            true,
            true,
            $persona !== '' ? $persona : 'travel_consultant',
            ['destination_execution_gate:' . $decision],
            [],
            null,
            LineMessagePayload::fromMessages([
                ['type' => 'text', 'text' => $text],
            ])
        );

        return [
            'output' => $output,
            'pipeline' => self::PIPELINE_CLARIFICATION,
            'route' => self::ROUTE_DESTINATION_EXECUTION_GATE,
            'execution_gate_decision' => $decision,
            'host_b_executed' => false,
            'final_owner' => 'destination_execution_gate',
            'failure_reason' => null,
            'validation_passed' => true,
            'used_facts_count' => 0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<array<string, mixed>>
     */
    private static function buildBbcshopsProductFacts(array $products): array
    {
        $facts = [];
        foreach ($products as $product) {
            $factId = trim((string) ($product['fact_id'] ?? ''));
            $title = trim((string) ($product['title'] ?? ''));
            if ($factId === '' || $title === '') {
                throw new \RuntimeException('bbcshops_product_fact_invalid');
            }
            $facts[] = [
                'fact_id' => $factId,
                'fact_type' => 'product_row',
                'value' => $title,
                'source_ref' => 'bbcshops:product',
            ];
        }

        return $facts;
    }

    /**
     * @return array{url: string, role: string}|null
     */
    private static function resolveBbcshopsLinkInput(array $params): ?array
    {
        $url = isset($params['search_url']) ? trim((string) $params['search_url']) : '';
        $role = isset($params['search_url_role']) ? trim((string) $params['search_url_role']) : '';
        if ($url === '' || !in_array($role, ['search', 'listing'], true)) {
            return null;
        }

        return ['url' => $url, 'role' => $role];
    }

    /**
     * @return list<array{platform: string, url: string}>
     */
    private static function resolveOtherSourceLinks(array $params): array
    {
        $links = is_array($params['multi_source_links'] ?? null) ? $params['multi_source_links'] : [];
        $out = [];
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $platform = trim((string) ($link['platform'] ?? ''));
            $url = trim((string) ($link['search_url'] ?? ($link['url'] ?? '')));
            if ($platform !== '' && $url !== '') {
                $out[] = ['platform' => $platform, 'url' => $url];
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $facts
     * @return list<array<string, mixed>>
     */
    private static function filterOtherSourceLinkFacts(array $facts): array
    {
        $out = [];
        foreach ($facts as $fact) {
            $sourceRef = trim((string) ($fact['source_ref'] ?? ''));
            if (in_array($sourceRef, ['grp:listing', 'bbctravel:listing', 'tourcenter:listing'], true)) {
                $out[] = $fact;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function resolvePrimarySearchDisplayLabel(array $params): ?string
    {
        if (!array_key_exists('primary_search_display_label', $params)) {
            return null;
        }
        $raw = $params['primary_search_display_label'];
        if ($raw === null) {
            return null;
        }
        if (!is_scalar($raw)) {
            return null;
        }
        $label = trim((string) $raw);

        return $label !== '' ? $label : null;
    }

    /**
     * @param array{url: string, role: string}|null $bbcshopsLink
     */
    private static function buildOpeningText(int $publishedCount, ?string $displayLabel): string
    {
        if ($publishedCount <= 0) {
            return self::noResultsText();
        }

        $destination = $displayLabel !== null ? trim($displayLabel) : '';
        if ($destination !== '') {
            return "您好，我是您的旅遊客服，已瞭解您的需求。\n以下為{$destination}行程精選圖卡，歡迎參考。";
        }

        return "您好，我是您的旅遊客服，已瞭解您的需求。\n以下為行程精選圖卡，歡迎參考。";
    }

    /**
     * Message Object 3: Bonusmee listing short URL then other-source 一/二/三館.
     *
     * @param array{url: string, role: string}|null $bbcshopsLink
     * @param list<array<string, mixed>> $linkFacts
     * @return array{text: string, fact_ids: list<string>}|null
     */
    private static function buildMergedListingLinksText(
        ?array $bbcshopsLink,
        array $linkFacts,
        ?string $displayLabel
    ): ?array {
        $parts = [];
        $factIds = [];
        $destination = $displayLabel !== null ? trim($displayLabel) : '';

        if ($bbcshopsLink !== null && trim($bbcshopsLink['url']) !== '') {
            $url = trim($bbcshopsLink['url']);
            if ($destination !== '') {
                $parts[] = "🎁 更多{$destination}精選促銷行程，可點選如下網址：\n{$url}";
            } else {
                $parts[] = "🎁 更多精選促銷行程，可點選如下網址：\n{$url}";
            }
            $factIds[] = $bbcshopsLink['role'] === 'listing'
                ? 'link:bbcshops:listing'
                : 'link:bbcshops:search';
        }

        $otherFacts = self::filterOtherSourceLinkFacts($linkFacts);
        if ($otherFacts !== []) {
            $otherResult = (new OtherSourceListingLinksMessageBuilder())->build($otherFacts, $displayLabel);
            (new OtherSourceListingLinksIntegrityValidator())->validate($otherResult, $otherFacts);
            $otherText = trim((string) ($otherResult->getWireMessage()['text'] ?? ''));
            if ($otherText !== '') {
                $parts[] = $otherText;
            }
            foreach ($otherResult->getLinkFactIds() as $id) {
                $factIds[] = $id;
            }
        }

        if ($parts === []) {
            return null;
        }

        return [
            'text' => implode("\n\n", $parts),
            'fact_ids' => $factIds,
        ];
    }

    private static function noResultsText(): string
    {
        return '目前沒有找到符合條件的商品。';
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private static function firstTextMessage(array $messages): string
    {
        foreach ($messages as $message) {
            if (is_array($message) && ($message['type'] ?? '') === 'text') {
                return trim((string) ($message['text'] ?? ''));
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function resolveKnownEntities(array $params): array
    {
        if (isset($params['known_entities']) && is_array($params['known_entities'])) {
            return $params['known_entities'];
        }

        $intent = $params['bats_search_intent'] ?? null;
        if ($intent instanceof BatsSearchIntent) {
            return self::knownEntitiesFromBatsIntent($intent);
        }

        if (is_array($intent)) {
            return self::knownEntitiesFromIntentArray($intent);
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function knownEntitiesFromBatsIntent(BatsSearchIntent $intent): array
    {
        $known = [];
        $dateFrom = $intent->getDateFrom();
        $dateTo = $intent->getDateTo();
        $departure = $intent->getDepartureCity();
        $destination = $intent->getDestination();

        if ($dateFrom !== null && $dateFrom !== '') {
            $known['date_from'] = $dateFrom;
        }
        if ($dateTo !== null && $dateTo !== '') {
            $known['date_to'] = $dateTo;
        }
        if ($departure !== null && $departure !== '') {
            $known['departure'] = $departure;
        }
        if ($destination !== []) {
            $known['destination'] = $destination;
        }

        return $known;
    }

    /**
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    public static function knownEntitiesFromIntentArray(array $intent): array
    {
        $known = [];
        foreach (['date_from', 'date_to', 'departure', 'destination'] as $key) {
            $srcKey = $key === 'departure' && !array_key_exists('departure', $intent)
                ? 'departure_city'
                : $key;
            if (!array_key_exists($srcKey, $intent)) {
                continue;
            }
            $value = $intent[$srcKey];
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $known[$key] = $value;
        }

        return $known;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function emitSuccess(array $params, string $path): void
    {
        self::emit('grounding_authoritative_compose_success', [
            'trace_id' => (string) ($params['trace_id'] ?? ''),
            'tenant_sno' => (string) ($params['tenant_sno'] ?? ''),
            'conversation_id' => (string) ($params['conversation_id'] ?? ''),
            'path' => $path,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function emitFallback(array $params, string $path, \Throwable $e): void
    {
        self::emit('grounding_authoritative_fallback', [
            'trace_id' => (string) ($params['trace_id'] ?? ''),
            'tenant_sno' => (string) ($params['tenant_sno'] ?? ''),
            'conversation_id' => (string) ($params['conversation_id'] ?? ''),
            'path' => $path,
            'exception_class' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function emitClarification(
        array $params,
        GroundedOutput $output,
        string $route,
        ?string $failureReason,
        string $askedEntity
    ): void {
        self::emit('phase_9c1_clarification_compose', [
            'trace_id' => (string) ($params['trace_id'] ?? ''),
            'tenant_sno' => (string) ($params['tenant_sno'] ?? ''),
            'conversation_id' => (string) ($params['conversation_id'] ?? ''),
            'clarification_reason' => (string) ($params['clarification_reason'] ?? ''),
            'asked_entity' => $askedEntity,
            'reply_type' => $output->getReplyType(),
            'used_facts_count' => $output->getUsedFactsCount(),
            'validation_passed' => $output->isValidationPassed(),
            'failure_reason' => $failureReason,
            'final_owner' => 'grounded_response_composer',
            'host_b_executed' => false,
            'final_route' => $route,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function emit(string $step, array $context): void
    {
        try {
            if (class_exists('Logger')) {
                \Logger::log('saas_router.log', $step, $context);
            }
        } catch (\Throwable $e) {
            // Never throw from logging.
        }
    }

    /**
     * @param array{persona?: string, allow_emoji?: bool} $tone
     */
    private static function buildTechnicalFailClosedOutput(array $tone, string $failureReason): GroundedOutput
    {
        $persona = trim((string) ($tone['persona'] ?? 'travel_consultant'));

        return new GroundedOutput(
            ClarificationContract::TECHNICAL_FAIL_CLOSED_TEXT,
            false,
            0,
            GroundedInput::SOURCE_TENANT_PRIVATE,
            false,
            [],
            ReplyType::NO_RESULTS,
            LayoutProfile::MINIMAL,
            false,
            false,
            $persona !== '' ? $persona : 'travel_consultant',
            ['failure_reason:' . $failureReason],
            [],
            null,
            LineMessagePayload::fromMessages([
                ['type' => 'text', 'text' => ClarificationContract::TECHNICAL_FAIL_CLOSED_TEXT],
            ])
        );
    }

    /**
     * @return array{
     *   output: GroundedOutput,
     *   pipeline: string,
     *   route: string,
     *   clarification_reason: string,
     *   missing_entity: string,
     *   asked_entity: string,
     *   host_b_executed: bool,
     *   final_owner: string,
     *   failure_reason: ?string,
     *   validation_passed: bool,
     *   used_facts_count: int
     * }
     */
    private static function clarificationEnvelope(
        GroundedOutput $output,
        string $route,
        string $reason,
        string $missingEntity,
        ?string $failureReason
    ): array {
        return [
            'output' => $output,
            'pipeline' => self::PIPELINE_CLARIFICATION,
            'route' => $route,
            'clarification_reason' => $reason,
            'missing_entity' => $missingEntity,
            'asked_entity' => $missingEntity,
            'host_b_executed' => false,
            'final_owner' => 'grounded_response_composer',
            'failure_reason' => $failureReason,
            'validation_passed' => $output->isValidationPassed(),
            'used_facts_count' => $output->getUsedFactsCount(),
        ];
    }

    private static function extractFailureReason(GroundedOutput $output, string $default): string
    {
        foreach ($output->getValidationNotes() as $note) {
            if (strpos((string) $note, 'failure_reason:') === 0) {
                return substr((string) $note, strlen('failure_reason:'));
            }
        }

        return $default;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadDefaultConfig(): array
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bats_feature.php';
        if (!is_file($path)) {
            return [];
        }

        $loaded = require $path;

        return is_array($loaded) ? $loaded : [];
    }
}
