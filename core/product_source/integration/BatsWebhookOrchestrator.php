<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BatsFeatureGate.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'gemini' . DIRECTORY_SEPARATOR . 'GeminiRenderer.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'LineRenderer.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GeminiClient.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sender' . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'LineSender.php';

/**
 * BATS webhook orchestrator skeleton (Phase 9-B-26B-1).
 *
 * Future single entry for Webhook → BATS pipeline. No search, Gemini, or LINE I/O.
 */
final class BatsWebhookOrchestrator
{
    private const SNAPSHOT_VERSION = 1;

    private BatsFeatureGate $featureGate;
    private GeminiRenderer $geminiRenderer;
    private GeminiClient $geminiClient;
    private LineRenderer $lineRenderer;
    private LineSender $lineSender;

    public function __construct(
        ?BatsFeatureGate $featureGate = null,
        ?GeminiRenderer $geminiRenderer = null,
        ?GeminiClient $geminiClient = null,
        ?LineRenderer $lineRenderer = null,
        ?LineSender $lineSender = null
    )
    {
        $this->featureGate = $featureGate ?? new BatsFeatureGate();
        $this->geminiRenderer = $geminiRenderer ?? new GeminiRenderer();
        $this->geminiClient = $geminiClient ?? new GeminiClient();
        $this->lineRenderer = $lineRenderer ?? new LineRenderer();
        $this->lineSender = $lineSender ?? new LineSender();
    }

    /**
     * @param array<string, mixed> $input tenant_sno, customer_message, channel?, trace_id?
     * @return array<string, mixed>
     */
    public function handle(array $input): array
    {
        $tenantSno = isset($input['tenant_sno']) ? trim((string) $input['tenant_sno']) : '';
        $customerMessage = isset($input['customer_message']) ? trim((string) $input['customer_message']) : '';
        $channel = isset($input['channel']) ? trim((string) $input['channel']) : '';
        $traceId = isset($input['trace_id']) ? trim((string) $input['trace_id']) : '';
        $sourceResults = isset($input['source_results']) && is_array($input['source_results'])
            ? $input['source_results']
            : [];

        if ($traceId === '') {
            $traceId = $this->generateTraceId();
        }

        if ($tenantSno === '') {
            return $this->buildResult([
                'status' => 'rejected',
                'trace_id' => $traceId,
                'tenant_sno' => '',
                'channel' => $channel,
                'message' => 'tenant_sno is required',
                'bats_mode' => BatsFeatureGate::MODE_DISABLED,
                'decision_snapshot' => $this->createDecisionSnapshot(
                    $traceId,
                    '',
                    $channel,
                    $customerMessage,
                    'rejected',
                    BatsFeatureGate::MODE_DISABLED,
                    $sourceResults
                ),
            ]);
        }

        if (!$this->isValidTenantSno($tenantSno)) {
            return $this->buildResult([
                'status' => 'rejected',
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
                'channel' => $channel,
                'message' => 'invalid tenant_sno',
                'bats_mode' => BatsFeatureGate::MODE_DISABLED,
                'decision_snapshot' => $this->createDecisionSnapshot(
                    $traceId,
                    $tenantSno,
                    $channel,
                    $customerMessage,
                    'rejected',
                    BatsFeatureGate::MODE_DISABLED,
                    $sourceResults
                ),
            ]);
        }

        if ($customerMessage === '') {
            return $this->buildResult([
                'status' => 'rejected',
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
                'channel' => $channel,
                'message' => 'customer_message is required',
                'bats_mode' => BatsFeatureGate::MODE_DISABLED,
                'decision_snapshot' => $this->createDecisionSnapshot(
                    $traceId,
                    $tenantSno,
                    $channel,
                    $customerMessage,
                    'rejected',
                    BatsFeatureGate::MODE_DISABLED,
                    $sourceResults
                ),
            ]);
        }

        $mode = $this->featureGate->resolveMode([
            'tenant_sno' => $tenantSno,
            'channel' => $channel,
        ]);

        if ($mode === BatsFeatureGate::MODE_DISABLED) {
            return $this->buildResult([
                'status' => 'disabled',
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
                'channel' => $channel,
                'message' => 'BATS is disabled for this tenant',
                'bats_mode' => $mode,
                'decision_snapshot' => $this->createDecisionSnapshot(
                    $traceId,
                    $tenantSno,
                    $channel,
                    $customerMessage,
                    'disabled',
                    $mode,
                    $sourceResults
                ),
            ]);
        }

        if ($mode === BatsFeatureGate::MODE_DRY_RUN) {
            return $this->buildResult([
                'status' => 'dry_run',
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
                'channel' => $channel,
                'message' => 'BATS orchestrator dry-run skeleton accepted',
                'bats_mode' => $mode,
                'decision_snapshot' => $this->createDecisionSnapshot(
                    $traceId,
                    $tenantSno,
                    $channel,
                    $customerMessage,
                    'dry_run',
                    $mode,
                    $sourceResults
                ),
            ]);
        }

        return $this->buildResult([
            'status' => 'accepted',
            'trace_id' => $traceId,
            'tenant_sno' => $tenantSno,
            'channel' => $channel,
            'message' => 'BATS orchestrator skeleton accepted',
            'bats_mode' => $mode,
            'decision_snapshot' => $this->createDecisionSnapshot(
                $traceId,
                $tenantSno,
                $channel,
                $customerMessage,
                'accepted',
                $mode,
                $sourceResults
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function normalizeResult(array $result): array
    {
        $status = isset($result['status']) ? trim((string) $result['status']) : '';
        $traceId = isset($result['trace_id']) ? trim((string) $result['trace_id']) : '';
        $tenantSno = isset($result['tenant_sno']) ? trim((string) $result['tenant_sno']) : '';
        $channel = isset($result['channel']) ? trim((string) $result['channel']) : '';
        $batsMode = isset($result['bats_mode'])
            ? strtolower(trim((string) $result['bats_mode']))
            : BatsFeatureGate::MODE_DISABLED;
        $message = isset($result['message']) ? trim((string) $result['message']) : '';

        $decisionSnapshot = null;
        if (isset($result['decision_snapshot']) && is_array($result['decision_snapshot'])) {
            $decisionSnapshot = $this->normalizeDecisionSnapshot($result['decision_snapshot']);
        }

        return [
            'status' => $status,
            'trace_id' => $traceId,
            'tenant_sno' => $tenantSno,
            'channel' => $channel,
            'message' => $message,
            'bats_mode' => $batsMode,
            'decision_snapshot' => $decisionSnapshot,
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function buildResult(array $result): array
    {
        return $this->normalizeResult($result);
    }

    private function generateTraceId(): string
    {
        return 'bats-' . bin2hex(random_bytes(8));
    }

    private function isValidTenantSno(string $tenantSno): bool
    {
        return preg_match('/^[a-f0-9]{16}$/i', $tenantSno) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function createDecisionSnapshot(
        string $traceId,
        string $tenantSno,
        string $channel,
        string $rawQuery,
        string $status,
        string $batsMode,
        array $sourceResults = []
    ): array {
        $isDryRun = $status === 'dry_run' || $batsMode === BatsFeatureGate::MODE_DRY_RUN;
        $candidateSummary = $this->buildCandidateSourceSummary($sourceResults);
        $hasCandidates = $candidateSummary['count'] > 0;

        if ($isDryRun && $hasCandidates) {
            $reasonCode = 'DRY_RUN_SNAPSHOT_WITH_CANDIDATES';
        } elseif ($isDryRun) {
            $reasonCode = 'DRY_RUN_SNAPSHOT_NO_CANDIDATES';
        } else {
            $reasonCode = 'SNAPSHOT_PLACEHOLDER_ONLY';
        }

        $geminiContext = [
            'available' => false,
            'schema_version' => null,
            'renderer_version' => null,
            'prompt_present' => false,
        ];
        $geminiReply = [
            'available' => false,
            'reply_type' => null,
            'used_fallback' => null,
            'voice_profile_used' => null,
        ];
        $lineRender = [
            'available' => false,
            'message_count' => 0,
            'render_mode' => null,
        ];
        $lineSender = [
            'available' => false,
            'dry_run' => false,
            'sender_type' => null,
            'payload_size' => null,
        ];

        if ($isDryRun && $hasCandidates) {
            $geminiResult = $this->buildGeminiDryRunSummary($traceId, $tenantSno, $candidateSummary);
            $geminiContext = $geminiResult['gemini_context'];
            $geminiReply = $geminiResult['gemini_reply'];
            $lineRender = $geminiResult['line_render'];
            $lineSender = $geminiResult['line_sender'];
        }

        return [
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'trace_id' => $traceId,
            'tenant_sno' => $tenantSno,
            'channel' => $channel,
            'dry_run' => $isDryRun,
            'orchestrator_status' => $status,
            'bats_mode' => $batsMode,
            'intent' => [
                'value' => null,
                'source' => 'not_available_yet',
                'confidence' => null,
            ],
            'query' => [
                'raw' => $rawQuery,
                'normalized' => null,
                'normalizer_version' => null,
            ],
            'search_condition' => [
                'available' => false,
                'value' => null,
            ],
            'candidate_sources' => [
                'available' => $hasCandidates,
                'count' => $hasCandidates ? $candidateSummary['count'] : 0,
                'items' => $candidateSummary['items'],
            ],
            'result_summary' => [
                'result_count' => $candidateSummary['result_count'],
                'source_count' => $candidateSummary['count'],
            ],
            'publisher_strategy' => [
                'available' => false,
                'channel' => 'line',
                'strategy_name' => null,
            ],
            'channel_publish_plan' => [
                'available' => false,
                'payload_schema_version' => null,
                'item_count' => null,
                'fallback_present' => null,
            ],
            'gemini_context' => $geminiContext,
            'gemini_reply' => $geminiReply,
            'line_render' => $lineRender,
            'line_sender' => $lineSender,
            'reason_code' => $reasonCode,
            'generated_at_unix' => time(),
        ];
    }

    /**
     * Build LINE text reply for controlled real/preview paths (mock Gemini + LineRenderer).
     *
     * @param array<string, mixed> $input tenant_sno, customer_message, trace_id?, source_results?
     */
    public function resolveControlledLineReplyText(array $input): ?string
    {
        $tenantSno = isset($input['tenant_sno']) ? trim((string) $input['tenant_sno']) : '';
        $traceId = isset($input['trace_id']) ? trim((string) $input['trace_id']) : '';
        $sourceResults = isset($input['source_results']) && is_array($input['source_results'])
            ? $input['source_results']
            : [];

        if ($tenantSno === '' || $sourceResults === []) {
            return null;
        }

        $candidateSummary = $this->buildCandidateSourceSummary($sourceResults);
        if ($candidateSummary['count'] <= 0) {
            return null;
        }

        try {
            $effectiveTraceId = $traceId !== '' ? $traceId : $this->generateTraceId();
            $promptPayload = $this->geminiRenderer->renderPrompts(
                $candidateSummary['items'],
                [
                    'source_count' => $candidateSummary['count'],
                    'result_count' => $candidateSummary['result_count'],
                ],
                [
                    'trace_id' => $effectiveTraceId,
                    'tenant_sno' => $tenantSno,
                ]
            );
            $response = $this->geminiClient->generateResponseFromPrompt($promptPayload, []);
            $linePayload = $this->lineRenderer->renderFromGeminiResponse($response, [
                'trace_id' => $effectiveTraceId,
                'tenant_sno' => $tenantSno,
            ]);
            $messages = $linePayload->getMessages();
            if ($messages === []) {
                return null;
            }

            $text = isset($messages[0]['text']) ? trim((string) $messages[0]['text']) : '';
            return $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array{count: int, result_count: int, items: list<array<string, mixed>>} $candidateSummary
     * @return array{
     *   gemini_context: array<string, mixed>,
     *   gemini_reply: array<string, mixed>,
     *   line_render: array<string, mixed>,
     *   line_sender: array<string, mixed>
     * }
     */
    private function buildGeminiDryRunSummary(string $traceId, string $tenantSno, array $candidateSummary): array
    {
        $default = [
            'gemini_context' => [
                'available' => false,
                'schema_version' => null,
                'renderer_version' => null,
                'prompt_present' => false,
            ],
            'gemini_reply' => [
                'available' => false,
                'reply_type' => null,
                'used_fallback' => null,
                'voice_profile_used' => null,
            ],
            'line_render' => [
                'available' => false,
                'message_count' => 0,
                'render_mode' => null,
            ],
            'line_sender' => [
                'available' => false,
                'dry_run' => false,
                'sender_type' => null,
                'payload_size' => null,
            ],
        ];

        try {
            $promptPayload = $this->geminiRenderer->renderPrompts(
                $candidateSummary['items'],
                [
                    'source_count' => $candidateSummary['count'],
                    'result_count' => $candidateSummary['result_count'],
                ],
                [
                    'trace_id' => $traceId,
                    'tenant_sno' => $tenantSno,
                ]
            );

            $response = $this->geminiClient->generateResponseFromPrompt($promptPayload, []);
            $responseArray = $response->toArray();
            $linePayload = $this->lineRenderer->renderFromGeminiResponse($response, [
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
            ]);
            $lineDryRun = $this->lineSender->prepareDryRun($linePayload, [
                'trace_id' => $traceId,
            ]);
            $renderMetadata = isset($promptPayload['render_metadata']) && is_array($promptPayload['render_metadata'])
                ? $promptPayload['render_metadata']
                : [];

            return [
                'gemini_context' => [
                    'available' => true,
                    'schema_version' => 1,
                    'renderer_version' => isset($renderMetadata['renderer_version'])
                        ? trim((string) $renderMetadata['renderer_version'])
                        : null,
                    'prompt_present' => isset($promptPayload['system_prompt'], $promptPayload['user_prompt']),
                ],
                'gemini_reply' => [
                    'available' => true,
                    'reply_type' => isset($responseArray['reply_type']) ? (string) $responseArray['reply_type'] : null,
                    'used_fallback' => isset($responseArray['used_fallback']) ? (bool) $responseArray['used_fallback'] : null,
                    'voice_profile_used' => isset($responseArray['voice_profile_used'])
                        ? (string) $responseArray['voice_profile_used']
                        : null,
                ],
                'line_render' => [
                    'available' => true,
                    'message_count' => count($linePayload->getMessages()),
                    'render_mode' => 'dry_run',
                ],
                'line_sender' => [
                    'available' => true,
                    'dry_run' => true,
                    'sender_type' => isset($lineDryRun['sender_type']) ? (string) $lineDryRun['sender_type'] : 'line_dry_run',
                    'payload_size' => isset($lineDryRun['payload_size']) ? (int) $lineDryRun['payload_size'] : 0,
                ],
            ];
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * @param array<int, mixed> $sourceResults
     * @return array{count: int, result_count: int, items: list<array<string, mixed>>}
     */
    private function buildCandidateSourceSummary(array $sourceResults): array
    {
        $items = [];
        $resultCount = 0;

        foreach ($sourceResults as $source) {
            if (!is_array($source)) {
                continue;
            }

            $sourcePlatform = isset($source['source_platform']) ? trim((string) $source['source_platform']) : '';
            $tenantInstance = isset($source['tenant_instance']) ? trim((string) $source['tenant_instance']) : '';
            $productCategory = isset($source['product_category']) ? trim((string) $source['product_category']) : '';
            $sourceResultCount = isset($source['result_count']) ? max(0, (int) $source['result_count']) : 0;

            $items[] = [
                'source_platform' => $sourcePlatform,
                'tenant_instance' => $tenantInstance,
                'product_category' => $productCategory,
                'result_count' => $sourceResultCount,
            ];
            $resultCount += $sourceResultCount;
        }

        return [
            'count' => count($items),
            'result_count' => $resultCount,
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function normalizeDecisionSnapshot(array $snapshot): array
    {
        return [
            'snapshot_version' => isset($snapshot['snapshot_version']) ? (int) $snapshot['snapshot_version'] : self::SNAPSHOT_VERSION,
            'trace_id' => isset($snapshot['trace_id']) ? trim((string) $snapshot['trace_id']) : '',
            'tenant_sno' => isset($snapshot['tenant_sno']) ? trim((string) $snapshot['tenant_sno']) : '',
            'channel' => isset($snapshot['channel']) ? trim((string) $snapshot['channel']) : '',
            'dry_run' => isset($snapshot['dry_run']) ? (bool) $snapshot['dry_run'] : false,
            'orchestrator_status' => isset($snapshot['orchestrator_status']) ? trim((string) $snapshot['orchestrator_status']) : '',
            'bats_mode' => isset($snapshot['bats_mode']) ? trim((string) $snapshot['bats_mode']) : BatsFeatureGate::MODE_DISABLED,
            'intent' => isset($snapshot['intent']) && is_array($snapshot['intent']) ? $snapshot['intent'] : [
                'value' => null,
                'source' => 'not_available_yet',
                'confidence' => null,
            ],
            'query' => isset($snapshot['query']) && is_array($snapshot['query']) ? $snapshot['query'] : [
                'raw' => '',
                'normalized' => null,
                'normalizer_version' => null,
            ],
            'search_condition' => isset($snapshot['search_condition']) && is_array($snapshot['search_condition']) ? $snapshot['search_condition'] : [
                'available' => false,
                'value' => null,
            ],
            'candidate_sources' => isset($snapshot['candidate_sources']) && is_array($snapshot['candidate_sources']) ? $snapshot['candidate_sources'] : [
                'available' => false,
                'count' => 0,
                'items' => [],
            ],
            'result_summary' => isset($snapshot['result_summary']) && is_array($snapshot['result_summary']) ? $snapshot['result_summary'] : [
                'result_count' => 0,
                'source_count' => 0,
            ],
            'publisher_strategy' => isset($snapshot['publisher_strategy']) && is_array($snapshot['publisher_strategy']) ? $snapshot['publisher_strategy'] : [
                'available' => false,
                'channel' => 'line',
                'strategy_name' => null,
            ],
            'channel_publish_plan' => isset($snapshot['channel_publish_plan']) && is_array($snapshot['channel_publish_plan']) ? $snapshot['channel_publish_plan'] : [
                'available' => false,
                'payload_schema_version' => null,
                'item_count' => null,
                'fallback_present' => null,
            ],
            'gemini_context' => isset($snapshot['gemini_context']) && is_array($snapshot['gemini_context']) ? $snapshot['gemini_context'] : [
                'available' => false,
                'schema_version' => null,
                'renderer_version' => null,
                'prompt_present' => false,
            ],
            'gemini_reply' => isset($snapshot['gemini_reply']) && is_array($snapshot['gemini_reply']) ? $snapshot['gemini_reply'] : [
                'available' => false,
                'reply_type' => null,
                'used_fallback' => null,
                'voice_profile_used' => null,
            ],
            'line_render' => isset($snapshot['line_render']) && is_array($snapshot['line_render']) ? $snapshot['line_render'] : [
                'available' => false,
                'message_count' => 0,
                'render_mode' => null,
            ],
            'line_sender' => isset($snapshot['line_sender']) && is_array($snapshot['line_sender']) ? $snapshot['line_sender'] : [
                'available' => false,
                'dry_run' => false,
                'sender_type' => null,
                'payload_size' => null,
            ],
            'reason_code' => isset($snapshot['reason_code']) ? trim((string) $snapshot['reason_code']) : '',
            'generated_at_unix' => isset($snapshot['generated_at_unix']) ? (int) $snapshot['generated_at_unix'] : time(),
        ];
    }
}
