<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ChannelRendererInterface.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GeminiContextDocument.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GeminiContextDocumentValidator.php';

/**
 * Gemini channel renderer (Phase 9-B-23).
 *
 * ChannelPublishPlan → GeminiContextDocument. No prompts, HTTP, or Gemini API calls.
 */
final class GeminiRenderer implements ChannelRendererInterface
{
    private const PROMPT_RENDERER_VERSION = 'gemini_prompt_renderer_v1';

    private GeminiContextDocumentValidator $documentValidator;

    public function __construct(?GeminiContextDocumentValidator $documentValidator = null)
    {
        $this->documentValidator = $documentValidator ?? new GeminiContextDocumentValidator();
    }

    /**
     * @param array<string, mixed> $renderContext
     */
    public function render(ChannelPublishPlan $plan, array $renderContext = []): array
    {
        return $this->renderDocument($plan, $renderContext)->toArray();
    }

    /**
     * @param array<string, mixed> $renderContext
     */
    public function renderDocument(ChannelPublishPlan $plan, array $renderContext = []): GeminiContextDocument
    {
        if ($plan->getChannel() !== 'gemini') {
            throw new \InvalidArgumentException(
                'GeminiRenderer requires channel=gemini, got: ' . $plan->getChannel()
            );
        }

        $planDocument = $plan->toArray();
        $planMetadata = isset($planDocument['metadata']) && is_array($planDocument['metadata'])
            ? $planDocument['metadata']
            : [];

        $mergedContext = array_merge($planMetadata, $renderContext);

        $document = [
            'schema_version' => GeminiContextDocument::SCHEMA_VERSION,
            'customer_query' => $this->resolveString($mergedContext, 'customer_query'),
            'search_results' => $this->buildSearchResults($plan),
            'tenant_name' => $this->resolveString($mergedContext, 'tenant_name'),
            'voice_profile' => $this->resolveVoiceProfile($mergedContext),
            'tenant_service_scope' => $this->resolveTenantServiceScope($mergedContext),
            'guard_policy' => $this->resolveGuardPolicy($mergedContext),
            'fallback_policy' => $this->resolveFallbackPolicy($mergedContext, $planDocument['fallback']),
        ];

        return $this->documentValidator->validate($document);
    }

    /**
     * @param list<array<string, mixed>> $candidateSources
     * @param array<string, mixed> $resultSummary
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function renderPrompts(array $candidateSources, array $resultSummary, array $metadata = []): array
    {
        $traceId = isset($metadata['trace_id']) ? trim((string) $metadata['trace_id']) : '';
        $tenantSno = isset($metadata['tenant_sno']) ? trim((string) $metadata['tenant_sno']) : '';
        $sourceCount = isset($resultSummary['source_count']) ? max(0, (int) $resultSummary['source_count']) : count($candidateSources);
        $resultCount = isset($resultSummary['result_count']) ? max(0, (int) $resultSummary['result_count']) : 0;

        $systemPrompt = implode("\n", [
            '你是旅行社年輕女孩客服助理，語氣溫暖、熱情、專業，不輕浮。',
            '可適度使用 emoji，但避免過量與幼稚語氣。',
            '不得捏造價格、庫存、成團狀態或不存在之商品資訊。',
            '若資料不足或無法確認，必須明確建議轉由專人客服協助。',
            '回覆必須只根據使用者提供的商品來源摘要。',
        ]);

        $sourceLines = [];
        foreach ($candidateSources as $index => $source) {
            if (!is_array($source)) {
                continue;
            }
            $sourceLines[] = sprintf(
                '%d) platform=%s, tenant_instance=%s, product_category=%s, result_count=%d',
                $index + 1,
                isset($source['source_platform']) ? trim((string) $source['source_platform']) : '',
                isset($source['tenant_instance']) ? trim((string) $source['tenant_instance']) : '',
                isset($source['product_category']) ? trim((string) $source['product_category']) : '',
                isset($source['result_count']) ? max(0, (int) $source['result_count']) : 0
            );
        }

        if ($sourceLines === []) {
            $sourceLines[] = '無候選商品來源摘要。';
        }

        $userPrompt = implode("\n", [
            '請使用以下商品來源摘要，產生旅遊客服回覆草稿。',
            '僅可引用下列摘要資訊，不可加入未提供的商品明細。',
            sprintf('source_count=%d, result_count=%d', $sourceCount, $resultCount),
            'candidate_sources:',
            implode("\n", $sourceLines),
        ]);

        return [
            'system_prompt' => $systemPrompt,
            'user_prompt' => $userPrompt,
            'render_metadata' => [
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
                'source_count' => $sourceCount,
                'result_count' => $resultCount,
                'renderer_version' => self::PROMPT_RENDERER_VERSION,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildSearchResults(ChannelPublishPlan $plan): array
    {
        $results = [];

        foreach ($plan->getItems() as $item) {
            $results[] = [
                'title' => isset($item['title']) ? trim((string) $item['title']) : '',
                'summary' => isset($item['summary']) && is_string($item['summary'])
                    ? (trim($item['summary']) !== '' ? trim($item['summary']) : null)
                    : null,
                'primary_url' => isset($item['primary_url']) ? trim((string) $item['primary_url']) : '',
                'metadata' => isset($item['metadata']) && is_array($item['metadata']) ? $item['metadata'] : [],
            ];
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveString(array $context, string $key): string
    {
        return isset($context[$key]) ? trim((string) $context[$key]) : '';
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function resolveVoiceProfile(array $context): array
    {
        $defaults = $this->defaultVoiceProfile();
        $override = isset($context['voice_profile']) && is_array($context['voice_profile'])
            ? $context['voice_profile']
            : [];

        $merged = array_merge($defaults, $override);

        if (isset($override['tone']) && is_array($override['tone'])) {
            $merged['tone'] = $override['tone'];
        }

        if (isset($override['constraints']) && is_array($override['constraints'])) {
            $merged['constraints'] = $override['constraints'];
        }

        if (isset($override['emoji_policy']) && is_array($override['emoji_policy'])) {
            $merged['emoji_policy'] = array_merge(
                $defaults['emoji_policy'],
                $override['emoji_policy']
            );
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultVoiceProfile(): array
    {
        return [
            'persona' => 'young_female',
            'tone' => ['warm', 'enthusiastic', 'professional', 'friendly'],
            'constraints' => [
                '不輕浮',
                '不誇大',
                '不亂承諾',
            ],
            'emoji_policy' => [
                'allowed_emojis' => ['😊', '✈️', '🌸', '📌', '💡', '🧳'],
                'rules' => [
                    '不可過量',
                    '不可幼稚化',
                    '不可裝可愛過頭',
                    '不可影響專業感',
                    '不可誤導客人',
                    '不可暗示不存在優惠',
                    '不可暗示保證成團',
                ],
                'service_style' => 'natural_warm_professional',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return list<string>
     */
    private function resolveTenantServiceScope(array $context): array
    {
        if (isset($context['tenant_service_scope']) && is_array($context['tenant_service_scope'])) {
            $scope = [];
            foreach ($context['tenant_service_scope'] as $item) {
                if (!is_string($item)) {
                    continue;
                }
                $trimmed = trim($item);
                if ($trimmed !== '') {
                    $scope[] = $trimmed;
                }
            }

            if ($scope !== []) {
                return $scope;
            }
        }

        return ['tour', 'passport', 'visa', 'ticket', 'hotel', 'future_custom_service'];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function resolveGuardPolicy(array $context): array
    {
        $defaults = [
            'grounding_required' => true,
            'allow_hallucination' => false,
            'strict_data_mode' => true,
        ];

        $override = isset($context['guard_policy']) && is_array($context['guard_policy'])
            ? $context['guard_policy']
            : [];

        return array_merge($defaults, $override);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $planFallback
     * @return array<string, mixed>
     */
    private function resolveFallbackPolicy(array $context, ?array $planFallback): array
    {
        $defaults = [
            'mode' => 'human_agent',
            'fallback_message' => '這個問題我先幫您轉由專人客服確認，稍後將有客服人員與您聯絡，謝謝您 😊',
        ];

        $override = isset($context['fallback_policy']) && is_array($context['fallback_policy'])
            ? $context['fallback_policy']
            : [];

        $merged = array_merge($defaults, $override);

        if ($planFallback !== null && isset($planFallback['message'])) {
            $notice = trim((string) $planFallback['message']);
            if ($notice !== '') {
                $merged['overflow_notice'] = $notice;
            }
        }

        return $merged;
    }
}
