<?php
declare(strict_types=1);

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/tenant_resolver.php';
require_once __DIR__ . '/intent_router.php';
require_once __DIR__ . '/tour_service.php';
require_once __DIR__ . '/ai_prompt_builder.php';
require_once __DIR__ . '/line_service.php';
require_once __DIR__ . '/usage_tracker.php';
require_once __DIR__ . '/gemini_service.php';
require_once __DIR__ . '/tour_fallback_formatter.php';
require_once __DIR__ . '/tour_line_reply_composer.php';
require_once __DIR__ . '/tour_prompt_context_service.php';
require_once __DIR__ . '/tour_prompt_feature_gate.php';
require_once __DIR__ . '/tenant/LineCredentialResolver.php';
require_once __DIR__ . '/product_source/integration/BatsFeatureGate.php';
require_once __DIR__ . '/product_source/integration/BatsWebhookOrchestrator.php';
require_once __DIR__ . '/search/Phase9C1FeatureGate.php';
require_once __DIR__ . '/search/AcknowledgementReplyComposer.php';
require_once __DIR__ . '/search/ConversationStatusResolver.php';
require_once __DIR__ . '/search/FinalReplyGate.php';
require_once __DIR__ . '/date_clarification_line_formatter.php';
require_once __DIR__ . '/product_source/channel_publish_plan/ChannelPublishPlan.php';
require_once __DIR__ . '/product_source/channel_publish_plan/ChannelPublishPlanValidator.php';
require_once __DIR__ . '/product_source/renderer/gemini/GeminiRenderer.php';
require_once __DIR__ . '/product_source/integration/GeminiClient.php';
require_once __DIR__ . '/product_source/recommendation/ProductRecommendationBuilder.php';
require_once __DIR__ . '/search/KnowledgeIntentDetector.php';
require_once __DIR__ . '/search/ConversationQueryMerger.php';
require_once __DIR__ . '/knowledge/HumanServiceResponseComposer.php';
require_once __DIR__ . '/search/BatsSearchIntentBuilder.php';
require_once __DIR__ . '/knowledge/TenantPrivateKnowledgeRuntime.php';
require_once __DIR__ . '/knowledge/TenantPrivateKnowledgeProviderInterface.php';
require_once __DIR__ . '/response/GroundedResponseComposer.php';
require_once __DIR__ . '/conversation/ConversationRuntimeShadowProbe.php';
require_once __DIR__ . '/conversation/ConversationReplyGateCompareProbe.php';
require_once __DIR__ . '/conversation/ConversationEventIngestProbe.php';
require_once __DIR__ . '/conversation/event/ConversationIdentityBuilder.php';
require_once __DIR__ . '/intent/AiIntentUnderstandingShadowProbe.php';
require_once __DIR__ . '/intent/AiIntentUnderstandingRuntimeSelector.php';
require_once __DIR__ . '/intent/AiuProductIntentTranslator.php';
require_once __DIR__ . '/intent/DispatchPlan.php';
require_once __DIR__ . '/grounding/GroundingShadowProbe.php';
require_once __DIR__ . '/grounding/GroundingOrchestratorContextFactory.php';
require_once __DIR__ . '/grounding/GroundingPipelineRuntime.php';
require_once __DIR__ . '/response/RuntimeType.php';
require_once __DIR__ . '/response/GroundedInput.php';

class SaaSRouter
{
    public static function handle(string $rawBody, string $signature, array $config): array
    {
        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            Logger::log('saas_router.log', 'invalid_json', ['raw_body' => $rawBody]);
            return ['ok' => true, 'message' => 'ignored'];
        }

        return self::handleEvent($event, $signature, $rawBody, $config);
    }

    public static function handleEvent(array $event, string $signature, string $rawBody, array $config): array
    {
        $start = microtime(true);
        $traceId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));

        $lineSecret = (string) ($config['line']['channel_secret'] ?? '');
        $lineToken = (string) ($config['line']['channel_access_token'] ?? '');
        $lineReplyUrl = (string) ($config['line']['reply_api_url'] ?? 'https://api.line.me/v2/bot/message/reply');

        // Stage 5-3: Multi LINE credential resolver (tenant-specific secrets live in .env/vault).
        // Security: fail-closed for non-travel_a tenants when credentials are missing; never cross-tenant fallback.
        $channelId = isset($event['destination']) ? trim((string) $event['destination']) : '';
        if ($channelId !== '') {
            $cred = LineCredentialResolver::resolveByChannelId($channelId);
            if (($cred['ok'] ?? false) === true) {
                $lineSecret = (string) ($cred['channel_secret'] ?? '');
                $lineToken = (string) ($cred['channel_access_token'] ?? '');
            } elseif (($cred['registry_hit'] ?? false) === true) {
                $tenantKey = (string) ($cred['tenant_key'] ?? '');
                if ($tenantKey !== 'travel_a') {
                    self::appendWebhookLog('missing_line_credentials', [
                        'trace_id' => $traceId,
                        'tenant_key' => $tenantKey,
                        'channel_id' => $channelId,
                        'errorCode' => (string) ($cred['errorCode'] ?? ''),
                        'missing_keys' => $cred['missing_keys'] ?? [],
                    ]);
                    Logger::log('saas_router.log', 'missing_line_credentials', [
                        'trace_id' => $traceId,
                        'tenant_key' => $tenantKey,
                        'channel_id' => $channelId,
                        'errorCode' => (string) ($cred['errorCode'] ?? ''),
                        'missing_keys' => $cred['missing_keys'] ?? [],
                    ]);
                    return ['ok' => false, 'status' => 403, 'message' => 'missing line credentials'];
                }
                // travel_a: keep legacy behavior unchanged even if per-tenant keys are not configured yet.
            }
        }

        Logger::log('saas_router.log', 'router_start', ['trace_id' => $traceId]);

        if (!LineService::verifySignature($rawBody, $signature, $lineSecret)) {
            self::appendWebhookLog('invalid_signature', [
                'trace_id' => $traceId,
                'signature_present' => $signature !== '',
            ]);
            Logger::log('saas_router.log', 'invalid_signature', ['trace_id' => $traceId]);
            return ['ok' => false, 'status' => 403, 'message' => 'invalid signature'];
        }

        if (!isset($event['events'][0]) || !is_array($event['events'][0])) {
            Logger::log('saas_router.log', 'no_events', ['trace_id' => $traceId]);
            self::appendWebhookLog('no_events', ['trace_id' => $traceId]);
            return ['ok' => true, 'message' => 'no events'];
        }

        $firstEvent = $event['events'][0];
        $replyToken = isset($firstEvent['replyToken']) ? (string) $firstEvent['replyToken'] : '';
        $userMessage = isset($firstEvent['message']['text']) ? trim((string) $firstEvent['message']['text']) : '';

        self::appendWebhookLog('line_event_received', [
            'trace_id' => $traceId,
            'message_text' => $userMessage,
            'reply_token_exists' => $replyToken !== '',
        ]);

        Logger::log('saas_router.log', 'line_event_received', [
            'trace_id' => $traceId,
            'message_text' => $userMessage,
            'reply_token_exists' => $replyToken !== '',
        ]);

        // Phase 9-B-26C-0: read-only BATS hook trace (always fall-through).
        // Safety: never throw, never reply, never call Gemini, never change legacy flow.
        try {
            self::traceBatsHookReadOnly($event, $firstEvent, $userMessage, $replyToken, $traceId);
        } catch (\Throwable $e) {
            Logger::log('saas_router.log', 'bats_hook_trace_error', [
                'trace_id' => $traceId,
                'message' => $e->getMessage(),
            ]);
        }

        if ($replyToken === '' || $userMessage === '') {
            Logger::log('saas_router.log', 'empty_reply_or_message', ['trace_id' => $traceId]);
            return ['ok' => true, 'message' => 'ignored'];
        }

        if ($userMessage === '你好') {
            $helloReply = '你好，我是 BBC AI 客服小編';
            $helloRes = LineService::replyToLine($lineReplyUrl, $lineToken, $replyToken, $helloReply);
            self::appendWebhookLog('line_api_response', [
                'trace_id' => $traceId,
                'response' => $helloRes,
            ]);
            Logger::log('saas_router.log', 'hello_reply', [
                'trace_id' => $traceId,
                'line_reply_status' => $helloRes['status'],
            ]);
            return ['ok' => true, 'message' => 'hello_replied'];
        }

        $intent = IntentRouter::detect($userMessage);

        // Weather must go to Gemini directly (no SQL path)
        if ($intent['intent'] === 'weather_query') {
            $geminiResult = callGemini($userMessage);
            if ($geminiResult['ok']) {
                $replyText = (string) $geminiResult['text'];
            } else {
                $replyText = '天氣查詢暫時無法取得，請稍後再試，或換個方式提問。';
            }

            $lineReplyRes = LineService::replyToLine($lineReplyUrl, $lineToken, $replyToken, $replyText);
            self::appendWebhookLog('line_api_response', [
                'trace_id' => $traceId,
                'response' => $lineReplyRes,
            ]);

            Logger::log('saas_router.log', 'weather_reply', [
                'trace_id' => $traceId,
                'line_reply_status' => $lineReplyRes['status'],
                'ai_ok' => $geminiResult['ok'],
            ]);

            return ['ok' => true, 'message' => 'weather_replied'];
        }

        $tenant = [
            'sno' => '',
            'company_name' => '旅行社客服',
            'ai_tone' => '親切',
            'travel_specialties' => '綜合旅遊',
            'price_catalog_json' => '{}',
            'channel_id' => isset($event['destination']) ? (string) $event['destination'] : '',
        ];
        $serviceData = [];
        $limits = [];

        try {
            $pdo = self::createPdo($config);
            $tenant = TenantResolver::resolve($pdo, $event, $config);

            // Phase 9-B-26C-0: resolve tenant then trace BATS gate/orchestrator read-only.
            try {
                $postResolveChannelId = (string) ($tenant['channel_id'] ?? '');
                if ($postResolveChannelId === '' && isset($event['destination'])) {
                    $postResolveChannelId = (string) $event['destination'];
                }
                self::traceBatsHookAfterTenantReadOnly($tenant, $userMessage, $traceId, $postResolveChannelId);
            } catch (\Throwable $e) {
                Logger::log('saas_router.log', 'bats_hook_trace_error_post_resolve', [
                    'trace_id' => $traceId,
                    'message' => $e->getMessage(),
                ]);
            }

            $intent = IntentRouter::detect($userMessage);

            $batsFeatureConfig = self::loadBatsFeatureConfig();

            // Phase 9-C-1Z: BATS-enabled tenant gate (travel_b first production tenant).
            $phase9C1Decision = Phase9C1FeatureGate::evaluate([
                'sno' => (string) ($tenant['sno'] ?? ''),
                'userMessage' => $userMessage,
            ]);
            Logger::log('saas_router.log', 'phase_9c1_gate_decision', array_merge($phase9C1Decision, [
                'trace_id' => $traceId,
            ]));
            self::appendWebhookLog('phase_9c1_gate_decision', array_merge($phase9C1Decision, [
                'trace_id' => $traceId,
            ]));
            if (($phase9C1Decision['enabled'] ?? false) === true) {
                $lineUserId = '';
                if (isset($firstEvent['source']) && is_array($firstEvent['source'])) {
                    $lineUserId = isset($firstEvent['source']['userId'])
                        ? trim((string) $firstEvent['source']['userId'])
                        : '';
                }
                $phase9C1Result = self::attemptPhase9C1StructuredPilotPath(
                    $tenant,
                    $userMessage,
                    $traceId,
                    $replyToken,
                    $lineReplyUrl,
                    $lineToken,
                    (string) ($tenant['channel_id'] ?? ''),
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    $lineUserId,
                    null,
                    null,
                    $event
                );
                if (is_array($phase9C1Result)) {
                    return $phase9C1Result;
                }
            }

            // Phase 9-B-26C-7: controlled real LINE reply gate (travel_b + sno + BATS測試*).
            $realGateDecision = self::evaluateControlledRealLineReplyGate($tenant, $userMessage, $batsFeatureConfig);
            Logger::log('saas_router.log', 'controlled_real_reply_gate_decision', $realGateDecision);
            self::appendWebhookLog('controlled_real_reply_gate_decision', $realGateDecision);

            if ($realGateDecision['controlled_real_reply_allowed']) {
                $controlledRealResult = self::attemptControlledRealLineReplyPath(
                    $tenant,
                    $userMessage,
                    $traceId,
                    $replyToken,
                    $lineReplyUrl,
                    $lineToken,
                    (string) ($tenant['channel_id'] ?? ''),
                    $batsFeatureConfig
                );
                if (is_array($controlledRealResult)) {
                    return $controlledRealResult;
                }
            } else {
                // Phase 9-B-26C-6: controlled reply preview gate (travel_b + BATS測試*).
                $controlledResult = self::attemptControlledReplyPath(
                    $tenant,
                    $userMessage,
                    $traceId,
                    (string) ($tenant['channel_id'] ?? ''),
                    $batsFeatureConfig,
                    null
                );
                if (is_array($controlledResult)) {
                    return $controlledResult;
                }
            }

            $supportCheck = TourService::isServiceSupported($pdo, (string) $tenant['sno'], (string) $intent['service_name']);
            if (!$supportCheck['supported']) {
                $replyText = '目前我們暫時沒有提供【' . (string) $intent['service_name'] . '】，若您需要，我可以協助您查詢其他目前有提供的服務。';
                $replyRes = LineService::replyToLine($lineReplyUrl, $lineToken, $replyToken, $replyText);
                self::appendWebhookLog('line_api_response', [
                    'trace_id' => $traceId,
                    'response' => $replyRes,
                ]);
                Logger::log('saas_router.log', 'unsupported_service', [
                    'trace_id' => $traceId,
                    'sno' => $tenant['sno'],
                    'service' => $intent['service_name'],
                    'line_reply' => $replyRes,
                ]);
                UsageTracker::track((string) $tenant['sno'], 'unsupported_service', mb_strlen($userMessage, 'UTF-8'), mb_strlen($replyText, 'UTF-8'));
                return ['ok' => true, 'message' => 'unsupported handled'];
            }

            $serviceData = TourService::fetchServiceData($pdo, (string) $tenant['sno'], $intent);
            $limits = self::fetchServiceLimits($pdo, (string) $tenant['sno']);
        } catch (Throwable $e) {
            Logger::log('saas_router.log', 'db_layer_fallback', [
                'trace_id' => $traceId,
                'error' => $e->getMessage(),
            ]);
        }

        $prompt = AiPromptBuilder::build($tenant, $intent, $serviceData, $limits);

        // Stage 1-B-18: governed by TourPromptFeatureGate (default OFF, empty allowlists).
        $channelId = (string) ($tenant['channel_id'] ?? '');
        if ($channelId === '' && isset($event['destination'])) {
            $channelId = (string) $event['destination'];
        }

        $enableTourPromptContext = TourPromptFeatureGate::isEnabled([
            'sno' => (string) ($tenant['sno'] ?? ''),
            'channelId' => $channelId !== '' ? $channelId : null,
        ]);
        $tourContext = (new TourPromptContextService())->buildTourContextForPrompt([
            'userText' => $userMessage,
            'sno' => (string) ($tenant['sno'] ?? ''),
            'channelId' => $channelId !== '' ? $channelId : null,
            'traceId' => $traceId,
            'featureEnabled' => $enableTourPromptContext,
        ]);
        if ($tourContext !== '') {
            $prompt = AiPromptBuilder::appendTourContext($prompt, $tourContext);
        }

        $gateCtx = [
            'sno' => (string) ($tenant['sno'] ?? ''),
            'channelId' => $channelId !== '' ? $channelId : null,
        ];
        $allowFixedFormatter = TourPromptFeatureGate::isFixedFormatterEnabled($gateCtx);

        $composed = TourLineReplyComposer::resolve(
            $prompt,
            $tourContext,
            static fn (): array => callGemini($prompt),
            $allowFixedFormatter
        );
        $replyText = $composed['reply_text'];
        $usedTourFallback = $composed['used_tour_fallback'];
        $usedFixedTourList = $composed['used_fixed_tour_list'];

        $lineReplyRes = LineService::replyToLine($lineReplyUrl, $lineToken, $replyToken, $replyText);
        self::appendWebhookLog('line_api_response', [
            'trace_id' => $traceId,
            'response' => $lineReplyRes,
        ]);

        UsageTracker::track((string) $tenant['sno'], (string) $intent['intent'], mb_strlen($userMessage, 'UTF-8'), mb_strlen($replyText, 'UTF-8'));
        Logger::log('saas_router.log', 'router_complete', [
            'trace_id' => $traceId,
            'sno' => $tenant['sno'],
            'intent' => $intent,
            'line_reply_status' => $lineReplyRes['status'],
            'elapsed_ms' => (int) ((microtime(true) - $start) * 1000),
            'ai_ok' => $composed['ai_ok'],
            'allow_fixed_formatter' => $allowFixedFormatter,
            'used_fixed_tour_list' => $usedFixedTourList,
            'used_tour_fallback' => $usedTourFallback,
        ]);

        return ['ok' => true, 'message' => 'completed'];
    }

    /**
     * Phase 9-B-26C-0: Read-only trace hook (no intercept).
     *
     * Logs minimal event metadata + channel-based feature mode decision.
     * Must not log tokens/secrets and must not throw.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $firstEvent
     */
    private static function traceBatsHookReadOnly(
        array $event,
        array $firstEvent,
        string $userMessage,
        string $replyToken,
        string $traceId
    ): void {
        $destination = isset($event['destination']) ? trim((string) $event['destination']) : '';
        $eventType = isset($firstEvent['type']) ? trim((string) $firstEvent['type']) : '';
        $eventTimestamp = isset($firstEvent['timestamp']) ? (int) $firstEvent['timestamp'] : 0;

        $source = (isset($firstEvent['source']) && is_array($firstEvent['source'])) ? $firstEvent['source'] : [];
        $userId = isset($source['userId']) ? (string) $source['userId'] : '';
        $groupId = isset($source['groupId']) ? (string) $source['groupId'] : '';
        $roomId = isset($source['roomId']) ? (string) $source['roomId'] : '';

        $message = (isset($firstEvent['message']) && is_array($firstEvent['message'])) ? $firstEvent['message'] : [];
        $messageType = isset($message['type']) ? (string) $message['type'] : '';

        $preview = self::messagePreview($userMessage, 80);
        $hash8 = substr(hash('sha256', $userMessage), 0, 8);

        $gate = new BatsFeatureGate(self::loadBatsFeatureConfig());
        $mode = $gate->resolveMode([
            'tenant_sno' => '',
            'channel' => $destination,
        ]);

        $featureEnabled = $gate->isPipelineAllowed($mode);
        $dryRunEnabled = $mode === BatsFeatureGate::MODE_DRY_RUN;

        $payload = [
            'trace_id' => $traceId,
            'timestamp' => $eventTimestamp > 0 ? $eventTimestamp : time(),
            'event_type' => $eventType,
            'reply_token_exists' => $replyToken !== '',
            'user_id' => $userId,
            'group_id' => $groupId,
            'room_id' => $roomId,
            'channel' => $destination,
            'message_type' => $messageType,
            'message_text_preview' => $preview,
            'message_text_hash8' => $hash8,
            'bats_feature_enabled' => $featureEnabled,
            'bats_dry_run_enabled' => $dryRunEnabled,
            'bats_hook_called' => true,
            'bats_hook_decision' => $featureEnabled ? 'gate_enabled_channel' : 'gate_disabled_channel',
            'fallthrough_to_legacy' => true,
        ];

        Logger::log('saas_router.log', 'bats_hook_trace', $payload);
        self::appendWebhookLog('bats_hook_trace', $payload);
    }

    /**
     * Phase 9-B-26C-0: After tenant resolved, trace tenant-based gate and orchestrator status.
     * Always fall-through. Never returns any intercept result.
     *
     * @param array<string, mixed> $tenant
     */
    private static function traceBatsHookAfterTenantReadOnly(
        array $tenant,
        string $userMessage,
        string $traceId,
        string $channelId = ''
    ): void {
        $tenantSno = trim((string) ($tenant['sno'] ?? ''));
        $resolvedChannelId = $channelId !== '' ? $channelId : trim((string) ($tenant['channel_id'] ?? ''));

        $gate = new BatsFeatureGate(self::loadBatsFeatureConfig());
        $mode = $gate->resolveMode([
            'tenant_sno' => $tenantSno,
            'channel' => $resolvedChannelId,
        ]);

        $featureEnabled = $gate->isPipelineAllowed($mode);
        $dryRunEnabled = $mode === BatsFeatureGate::MODE_DRY_RUN;

        $decision = $featureEnabled ? 'gate_enabled_tenant' : 'gate_disabled_tenant';
        $batsStatus = '';
        $snapshotVersion = null;
        $snapshotReasonCode = null;
        $decisionSnapshotPresent = false;
        $candidateSourceCount = null;
        $resultCount = null;

        if ($featureEnabled && $tenantSno !== '') {
            $orch = new BatsWebhookOrchestrator($gate);
            $batsResult = $orch->handle([
                'tenant_sno' => $tenantSno,
                'customer_message' => $userMessage,
                'channel' => $resolvedChannelId,
                'trace_id' => $traceId,
            ]);
            $batsStatus = isset($batsResult['status']) ? trim((string) $batsResult['status']) : '';
            if (isset($batsResult['decision_snapshot']) && is_array($batsResult['decision_snapshot'])) {
                $decisionSnapshotPresent = true;
                $snapshotVersion = isset($batsResult['decision_snapshot']['snapshot_version'])
                    ? (int) $batsResult['decision_snapshot']['snapshot_version']
                    : null;
                $snapshotReasonCode = isset($batsResult['decision_snapshot']['reason_code'])
                    ? (string) $batsResult['decision_snapshot']['reason_code']
                    : null;
                $candidateSourceCount = isset($batsResult['decision_snapshot']['candidate_sources']['count'])
                    ? (int) $batsResult['decision_snapshot']['candidate_sources']['count']
                    : null;
                $resultCount = isset($batsResult['decision_snapshot']['result_summary']['result_count'])
                    ? (int) $batsResult['decision_snapshot']['result_summary']['result_count']
                    : null;
            }
            $decision = 'orchestrator_called';
        } elseif ($featureEnabled && $tenantSno === '') {
            $decision = 'skipped_missing_tenant_sno';
        }

        $payload = [
            'trace_id' => $traceId,
            'timestamp' => time(),
            'tenant_sno' => $tenantSno,
            'channel' => $resolvedChannelId,
            'bats_feature_enabled' => $featureEnabled,
            'bats_dry_run_enabled' => $dryRunEnabled,
            'bats_hook_called' => true,
            'bats_hook_decision' => $decision,
            'bats_mode' => $mode,
            'bats_status' => $batsStatus,
            'decision_snapshot_present' => $decisionSnapshotPresent,
            'snapshot_version' => $snapshotVersion,
            'reason_code' => $snapshotReasonCode,
            'candidate_source_count' => $candidateSourceCount,
            'result_count' => $resultCount,
            'fallthrough_to_legacy' => true,
        ];

        Logger::log('saas_router.log', 'bats_hook_trace_post_resolve', $payload);
        self::appendWebhookLog('bats_hook_trace_post_resolve', $payload);
    }

    private static function messagePreview(string $text, int $maxChars): string
    {
        $t = trim(str_replace(["\r", "\n", "\t"], ' ', $text));
        if ($t === '') {
            return '';
        }

        $len = mb_strlen($t, 'UTF-8');
        if ($len <= $maxChars) {
            return $t;
        }

        return mb_substr($t, 0, $maxChars, 'UTF-8') . '…';
    }

    /**
     * Phase 9-B-26B-4B: BATS hook after TenantResolver, before legacy Tour/Gemini/LINE.
     * Returns router result when BATS intercepts; null = fall through to legacy.
     *
     * @param array<string, mixed> $tenant
     * @return array<string, mixed>|null
     */
    public static function attemptBatsWebhookHook(
        array $tenant,
        string $userMessage,
        string $traceId,
        string $channelId = '',
        ?BatsFeatureGate $featureGate = null,
        ?BatsWebhookOrchestrator $orchestrator = null
    ): ?array {
        $tenantSno = trim((string) ($tenant['sno'] ?? ''));
        if ($channelId === '') {
            $channelId = trim((string) ($tenant['channel_id'] ?? ''));
        }

        $gate = $featureGate ?? new BatsFeatureGate(self::loadBatsFeatureConfig());
        $mode = $gate->resolveMode([
            'tenant_sno' => $tenantSno,
            'channel' => $channelId,
        ]);

        if (!$gate->isPipelineAllowed($mode)) {
            return null;
        }

        if ($tenantSno === '') {
            return null;
        }

        $orch = $orchestrator ?? new BatsWebhookOrchestrator($gate);
        $batsResult = $orch->handle([
            'tenant_sno' => $tenantSno,
            'customer_message' => $userMessage,
            'channel' => $channelId,
            'trace_id' => $traceId,
        ]);

        $status = isset($batsResult['status']) ? trim((string) $batsResult['status']) : '';
        if ($status === 'disabled' || $status === 'rejected') {
            return null;
        }

        $routerMessage = 'bats_dry_run';
        if ($status === 'accepted' || $mode === BatsFeatureGate::MODE_ENABLED) {
            $routerMessage = 'bats_enabled_skeleton';
        }
        if ($status === 'dry_run') {
            $routerMessage = 'bats_dry_run';
        }

        Logger::log('saas_router.log', 'bats_orchestrator_hook', [
            'trace_id' => $traceId,
            'tenant_sno' => $tenantSno,
            'bats_mode' => $mode,
            'bats_status' => $status,
            'router_message' => $routerMessage,
        ]);
        self::appendWebhookLog('bats_orchestrator_hook', [
            'trace_id' => $traceId,
            'tenant_sno' => $tenantSno,
            'bats' => $batsResult,
            'router_message' => $routerMessage,
        ]);

        return [
            'ok' => true,
            'message' => $routerMessage,
            'bats' => $batsResult,
        ];
    }

    /**
     * Phase 9-B-26C-7: evaluate controlled real LINE reply gate (trace / decision snapshot).
     *
     * @param array<string, mixed> $tenant
     * @return array<string, mixed>
     */
    public static function evaluateControlledRealLineReplyGate(
        array $tenant,
        string $userMessage,
        ?array $batsFeatureConfig = null
    ): array {
        $config = is_array($batsFeatureConfig) ? $batsFeatureConfig : self::loadBatsFeatureConfig();
        $enabled = isset($config['controlled_real_reply_enabled']) && (bool) $config['controlled_real_reply_enabled'];
        $tenantSno = trim((string) ($tenant['sno'] ?? ''));
        $tenantKey = self::resolveTenantKeyFromTenant($tenant);
        $expectedSno = isset($config['controlled_real_reply_tenant_sno'])
            ? trim((string) $config['controlled_real_reply_tenant_sno'])
            : '5f99b8d665e8444d';
        $expectedKey = isset($config['controlled_real_reply_tenant_key'])
            ? trim((string) $config['controlled_real_reply_tenant_key'])
            : 'travel_b';
        $prefix = isset($config['controlled_real_reply_keyword_prefix'])
            ? trim((string) $config['controlled_real_reply_keyword_prefix'])
            : 'BATS測試';

        $message = trim($userMessage);
        $prefixCheckPassed = $prefix !== ''
            && $message !== ''
            && mb_substr($message, 0, mb_strlen($prefix)) === $prefix;
        $snoMatch = $expectedSno !== '' && $tenantSno === $expectedSno;
        $keyMatch = $expectedKey === ''
            || $tenantKey === ''
            || $tenantKey === $expectedKey;

        $allowed = false;
        $reason = 'real_reply_disabled';

        if (!$enabled) {
            $reason = 'real_reply_disabled';
        } elseif ($tenantSno === '') {
            $reason = 'missing_tenant_sno';
        } elseif (!$snoMatch) {
            $reason = 'tenant_sno_not_allowlisted';
        } elseif (!$keyMatch) {
            $reason = 'tenant_key_mismatch';
        } elseif (!$prefixCheckPassed) {
            $reason = 'message_prefix_mismatch';
        } else {
            $allowed = true;
            $reason = 'all_conditions_met';
        }

        return [
            'controlled_real_reply_allowed' => $allowed,
            'reason' => $reason,
            'tenant_sno' => $tenantSno,
            'tenant_key' => $tenantKey,
            'expected_tenant_sno' => $expectedSno,
            'expected_tenant_key' => $expectedKey,
            'message_prefix' => $prefix,
            'message_prefix_check_passed' => $prefixCheckPassed,
            'controlled_real_reply_enabled' => $enabled,
            'final_route' => $allowed ? 'bats_real_line_reply' : 'legacy',
        ];
    }

    /**
     * @param array<string, mixed> $tenant
     */
    public static function isControlledRealLineReplyEligible(
        array $tenant,
        string $userMessage,
        ?array $batsFeatureConfig = null
    ): bool {
        $decision = self::evaluateControlledRealLineReplyGate($tenant, $userMessage, $batsFeatureConfig);

        return (bool) ($decision['controlled_real_reply_allowed'] ?? false);
    }

    /**
     * Controlled real LINE reply path for Phase 9-B-26C-7.
     *
     * Sends one text reply via LineService when gate allows. Returns null on ineligible or error (legacy).
     *
     * @param array<string, mixed> $tenant
     * @param callable|null $orchestratorFactory fn(BatsFeatureGate): BatsWebhookOrchestrator
     * @param callable|null $lineReplySender fn(string $url, string $token, string $replyToken, string $text): array
     * @return array<string, mixed>|null
     */
    public static function attemptControlledRealLineReplyPath(
        array $tenant,
        string $userMessage,
        string $traceId,
        string $replyToken,
        string $lineReplyUrl,
        string $lineToken,
        string $channelId = '',
        ?array $batsFeatureConfig = null,
        $orchestratorFactory = null,
        $lineReplySender = null
    ): ?array {
        $config = is_array($batsFeatureConfig) ? $batsFeatureConfig : self::loadBatsFeatureConfig();
        $gateDecision = self::evaluateControlledRealLineReplyGate($tenant, $userMessage, $config);
        if (!($gateDecision['controlled_real_reply_allowed'] ?? false)) {
            return null;
        }

        if ($replyToken === '' || $lineReplyUrl === '' || $lineToken === '') {
            Logger::log('saas_router.log', 'controlled_real_reply_error', [
                'trace_id' => $traceId,
                'reason' => 'missing_line_reply_credentials',
                'fallthrough_to_legacy' => true,
            ]);
            self::appendWebhookLog('controlled_real_reply_error', [
                'trace_id' => $traceId,
                'reason' => 'missing_line_reply_credentials',
                'fallthrough_to_legacy' => true,
            ]);

            return null;
        }

        try {
            $tenantSno = trim((string) ($tenant['sno'] ?? ''));
            if ($channelId === '') {
                $channelId = trim((string) ($tenant['channel_id'] ?? ''));
            }

            $gate = new BatsFeatureGate($config);
            if (is_callable($orchestratorFactory)) {
                $orch = $orchestratorFactory($gate);
                if (!$orch instanceof BatsWebhookOrchestrator) {
                    throw new \RuntimeException('controlled real orchestrator factory must return BatsWebhookOrchestrator');
                }
            } else {
                $orch = new BatsWebhookOrchestrator($gate);
            }

            $sourceResults = self::controlledSourceResultsFixture($tenantSno);
            $batsResult = $orch->handle([
                'tenant_sno' => $tenantSno,
                'customer_message' => $userMessage,
                'channel' => $channelId,
                'trace_id' => $traceId,
                'source_results' => $sourceResults,
            ]);

            $replyText = $orch->resolveControlledLineReplyText([
                'tenant_sno' => $tenantSno,
                'customer_message' => $userMessage,
                'trace_id' => $traceId,
                'source_results' => $sourceResults,
            ]);
            if ($replyText === null || $replyText === '') {
                $replyText = 'BATS controlled test：目前無法產生回覆，請稍後再試。';
            }

            if (is_callable($lineReplySender)) {
                $replyRes = $lineReplySender($lineReplyUrl, $lineToken, $replyToken, $replyText);
            } else {
                $replyRes = LineService::replyToLine($lineReplyUrl, $lineToken, $replyToken, $replyText);
            }

            $payload = array_merge($gateDecision, [
                'trace_id' => $traceId,
                'reply_text_length' => mb_strlen($replyText),
                'line_reply' => $replyRes,
                'bats_status' => isset($batsResult['status']) ? (string) $batsResult['status'] : '',
                'final_route' => 'bats_real_line_reply',
            ]);
            Logger::log('saas_router.log', 'controlled_real_line_reply', $payload);
            self::appendWebhookLog('controlled_real_line_reply', $payload);

            return [
                'ok' => true,
                'message' => 'bats_controlled_real_line_reply',
                'controlled_real_reply' => $payload,
                'bats' => $batsResult,
            ];
        } catch (\Throwable $e) {
            Logger::log('saas_router.log', 'controlled_real_reply_error', [
                'trace_id' => $traceId,
                'tenant_sno' => (string) ($tenant['sno'] ?? ''),
                'message' => $e->getMessage(),
                'fallthrough_to_legacy' => true,
            ]);
            self::appendWebhookLog('controlled_real_reply_error', [
                'trace_id' => $traceId,
                'tenant_sno' => (string) ($tenant['sno'] ?? ''),
                'message' => $e->getMessage(),
                'fallthrough_to_legacy' => true,
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private static function resolveTenantKeyFromTenant(array $tenant): string
    {
        if (isset($tenant['tenant_key'])) {
            return trim((string) $tenant['tenant_key']);
        }
        if (isset($tenant['tenant'])) {
            return trim((string) $tenant['tenant']);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $tenant
     */
    public static function isControlledReplyEligible(
        array $tenant,
        string $userMessage,
        ?array $batsFeatureConfig = null
    ): bool {
        $config = is_array($batsFeatureConfig) ? $batsFeatureConfig : self::loadBatsFeatureConfig();
        $enabled = isset($config['controlled_reply_enabled']) && (bool) $config['controlled_reply_enabled'];
        if (!$enabled) {
            return false;
        }

        $tenantSno = trim((string) ($tenant['sno'] ?? ''));
        if ($tenantSno === '') {
            return false;
        }

        $allowedTenants = isset($config['controlled_reply_tenants']) && is_array($config['controlled_reply_tenants'])
            ? $config['controlled_reply_tenants']
            : [];
        $allowedTenants = array_values(array_filter(array_map('strval', $allowedTenants)));
        if (!in_array($tenantSno, $allowedTenants, true)) {
            return false;
        }

        $prefix = isset($config['controlled_reply_keyword_prefix'])
            ? trim((string) $config['controlled_reply_keyword_prefix'])
            : 'BATS測試';
        if ($prefix === '') {
            return false;
        }

        $message = trim($userMessage);
        if ($message === '') {
            return false;
        }

        return mb_substr($message, 0, mb_strlen($prefix)) === $prefix;
    }

    /**
     * Controlled reply path for Phase 9-B-26C-6.
     *
     * Preview-only in this phase: logs result and returns handled message without LINE send.
     * Returns null when gate not eligible or when any error occurs (fallback to legacy).
     *
     * @param array<string, mixed> $tenant
     * @param callable|null $orchestratorFactory fn(BatsFeatureGate): BatsWebhookOrchestrator
     * @return array<string, mixed>|null
     */
    public static function attemptControlledReplyPath(
        array $tenant,
        string $userMessage,
        string $traceId,
        string $channelId = '',
        ?array $batsFeatureConfig = null,
        $orchestratorFactory = null
    ): ?array {
        $config = is_array($batsFeatureConfig) ? $batsFeatureConfig : self::loadBatsFeatureConfig();
        if (!self::isControlledReplyEligible($tenant, $userMessage, $config)) {
            return null;
        }

        try {
            $tenantSno = trim((string) ($tenant['sno'] ?? ''));
            if ($channelId === '') {
                $channelId = trim((string) ($tenant['channel_id'] ?? ''));
            }

            $gate = new BatsFeatureGate($config);
            if (is_callable($orchestratorFactory)) {
                $orch = $orchestratorFactory($gate);
                if (!$orch instanceof BatsWebhookOrchestrator) {
                    throw new \RuntimeException('controlled orchestrator factory must return BatsWebhookOrchestrator');
                }
            } else {
                $orch = new BatsWebhookOrchestrator($gate);
            }

            $batsResult = $orch->handle([
                'tenant_sno' => $tenantSno,
                'customer_message' => $userMessage,
                'channel' => $channelId,
                'trace_id' => $traceId,
                'source_results' => self::controlledSourceResultsFixture($tenantSno),
            ]);

            $snapshot = isset($batsResult['decision_snapshot']) && is_array($batsResult['decision_snapshot'])
                ? $batsResult['decision_snapshot']
                : [];
            $lineRenderAvailable = (bool) ($snapshot['line_render']['available'] ?? false);
            $lineSenderAvailable = (bool) ($snapshot['line_sender']['available'] ?? false);
            $lineMessageCount = (int) ($snapshot['line_render']['message_count'] ?? 0);
            $linePayloadSize = isset($snapshot['line_sender']['payload_size'])
                ? (int) $snapshot['line_sender']['payload_size']
                : 0;

            $previewPayload = [
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
                'controlled_reply' => [
                    'eligible' => true,
                    'mode' => 'preview_only',
                    'keyword_prefix' => (string) ($config['controlled_reply_keyword_prefix'] ?? 'BATS測試'),
                    'line_render_available' => $lineRenderAvailable,
                    'line_sender_available' => $lineSenderAvailable,
                    'line_message_count' => $lineMessageCount,
                    'line_payload_size' => $linePayloadSize,
                ],
                'bats_status' => isset($batsResult['status']) ? (string) $batsResult['status'] : '',
                'reason_code' => isset($snapshot['reason_code']) ? (string) $snapshot['reason_code'] : '',
            ];
            Logger::log('saas_router.log', 'controlled_reply_preview', $previewPayload);
            self::appendWebhookLog('controlled_reply_preview', $previewPayload);

            return [
                'ok' => true,
                'message' => 'bats_controlled_reply_preview',
                'controlled_reply' => $previewPayload['controlled_reply'],
                'bats' => $batsResult,
            ];
        } catch (\Throwable $e) {
            Logger::log('saas_router.log', 'controlled_reply_error', [
                'trace_id' => $traceId,
                'tenant_sno' => (string) ($tenant['sno'] ?? ''),
                'message' => $e->getMessage(),
                'fallthrough_to_legacy' => true,
            ]);
            self::appendWebhookLog('controlled_reply_error', [
                'trace_id' => $traceId,
                'tenant_sno' => (string) ($tenant['sno'] ?? ''),
                'message' => $e->getMessage(),
                'fallthrough_to_legacy' => true,
            ]);
            return null;
        }
    }

    /**
     * Controlled-test only source fixture for preview path (no external API calls).
     *
     * @return list<array<string, mixed>>
     */
    private static function controlledSourceResultsFixture(string $tenantSno): array
    {
        if ($tenantSno !== '5f99b8d665e8444d') {
            return [];
        }

        return [
            [
                'source_platform' => 'bbcshops',
                'tenant_instance' => 'travel_b',
                'product_category' => 'group_tour',
                'result_count' => 10,
            ],
            [
                'source_platform' => 'agenttour',
                'tenant_instance' => 'travel_b_agenttour',
                'product_category' => 'group_tour',
                'result_count' => 8,
            ],
            [
                'source_platform' => 'grp',
                'tenant_instance' => 'travel_b_grp',
                'product_category' => 'group_tour',
                'result_count' => 12,
            ],
        ];
    }

    /**
     * Phase 9-C-1Z: structured BATS Runtime path (BATS-enabled tenant gate).
     *
     * @param array<string, mixed> $tenant
     * @param callable|null $lineReplySender fn(string $url, string $token, string $replyToken, string $text): array
     * @param callable|null $linePushSender fn(string $url, string $token, string $userId, string $text): array
     * @return array<string, mixed>|null
     */
    public static function attemptPhase9C1StructuredPilotPath(
        array $tenant,
        string $userMessage,
        string $traceId,
        string $replyToken,
        string $lineReplyUrl,
        string $lineToken,
        string $channelId = '',
        ?TourPromptContextService $tourContextService = null,
        ?TourSearchApiClient $searchClient = null,
        ?\DateTimeImmutable $referenceDate = null,
        $lineReplySender = null,
        ?GeminiRenderer $geminiRenderer = null,
        ?GeminiClient $geminiClient = null,
        ?string $conversationId = null,
        ?ConversationStatusResolver $conversationStatusResolver = null,
        $ackReplySender = null,
        ?TenantPrivateKnowledgeProviderInterface $knowledgeProvider = null,
        ?KnowledgeIntentDetector $knowledgeIntentDetector = null,
        string $userId = '',
        $linePushSender = null,
        ?KnowledgeFallbackResolver $knowledgeFallbackResolver = null,
        ?array $rawLineEvent = null,
        ?AiIntentUnderstandingRuntime $aiuRuntime = null
    ): ?array {
        $tenantSno = trim((string) ($tenant['sno'] ?? ''));
        if (!Phase9C1FeatureGate::isEnabled([
            'sno' => $tenantSno,
            'userMessage' => $userMessage,
        ])) {
            return null;
        }

        if ($replyToken === '' || $lineReplyUrl === '' || $lineToken === '') {
            Logger::log('saas_router.log', 'phase_9c1_structured_pilot_error', [
                'trace_id' => $traceId,
                'reason' => 'missing_line_reply_credentials',
                'fallthrough_to_legacy' => true,
            ]);

            return null;
        }

        try {
            if ($channelId === '') {
                $channelId = trim((string) ($tenant['channel_id'] ?? ''));
            }

            $now = $referenceDate ?? new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));
            $conversationKey = $conversationId ?? ($tenantSno . ':' . ($channelId !== '' ? $channelId : 'unknown'));
            $statusResolver = $conversationStatusResolver ?? new ConversationStatusResolver();

            // Phase 2-B Step 2-B-2: per-user Conversation Runtime id (tenantSno:line:userId).
            // The Conversation Runtime (Owner / State / Memory) is keyed per end-user;
            // the legacy $conversationKey (per-OA) is left untouched for the legacy
            // ConversationStatusResolver and existing webhook logs.
            $runtimeUserId = trim($userId);
            $runtimeConversationId = $runtimeUserId !== ''
                ? ConversationIdentityBuilder::forLine($tenantSno, $runtimeUserId)
                : $conversationKey;

            $rawQueryText = Phase9C1FeatureGate::stripPilotQueryPrefix($userMessage);
            $queryText = ConversationQueryMerger::mergeDateClarificationFollowUp(
                $statusResolver,
                $conversationKey,
                $rawQueryText
            );
            $statusResolver->recordCustomerMessage($conversationKey, $rawQueryText, $now);
            $conversationStatus = $statusResolver->resolveStatus($conversationKey, $now);

            if ($conversationStatus === ConversationStatusResolver::STATUS_HUMAN_ACTIVE) {
                self::recordReplyGateCompare(
                    ConversationReplyGateCompareProbe::GATE_POINT_EARLY_BLOCK,
                    $tenantSno,
                    $runtimeConversationId,
                    $conversationStatus,
                    FinalReplyGate::maySendAiReply($conversationStatus),
                    $traceId,
                    $now
                );

                $blockedPayload = [
                    'trace_id' => $traceId,
                    'tenant_sno' => $tenantSno,
                    'conversation_id' => $conversationKey,
                    'conversation_status' => $conversationStatus,
                    'blocked' => true,
                    'block_reason' => 'human_active',
                    'final_route' => 'phase_9c1_structured_pilot_blocked',
                ];
                Logger::log('saas_router.log', 'phase_9c1_structured_pilot_blocked', $blockedPayload);
                self::appendWebhookLog('phase_9c1_structured_pilot_blocked', $blockedPayload);

                return [
                    'ok' => true,
                    'message' => 'phase_9c1_structured_pilot_blocked',
                    'phase_9c1' => $blockedPayload,
                ];
            }

            $intentDetector = $knowledgeIntentDetector ?? new KnowledgeIntentDetector();
            $legacyIntentDetection = $intentDetector->detect($queryText);
            $legacyIntentType = (string) ($legacyIntentDetection['intent_type'] ?? '');

            // Phase 2-D Step 2-D-3-3: Authoritative Runtime Selection (flag default OFF).
            // When enabled for tenant, AIU dispatch_plan drives routing via legacy-compatible
            // intent_type mapping. Legacy detect always runs first for Shadow parity; flag OFF
            // is byte-identical to pre-2-D-3-3 behavior. Never throws; falls back to legacy.
            $intentSelection = AiIntentUnderstandingRuntimeSelector::resolve([
                'tenant_sno' => $tenantSno,
                'conversation_id' => $runtimeConversationId,
                'message' => $queryText,
                'legacy_intent_type' => $legacyIntentType,
                'now' => $now,
                'reference_date' => $now,
            ], $aiuRuntime);
            if (($intentSelection['runtime_source'] ?? '') === AiIntentUnderstandingRuntimeSelector::SOURCE_AIU) {
                Logger::log('saas_router.log', 'intent_understanding_authoritative_selection', [
                    'trace_id' => $traceId,
                    'tenant_sno' => $tenantSno,
                    'conversation_id' => $runtimeConversationId,
                    'legacy_intent_type' => $legacyIntentType,
                    'selected_intent_type' => (string) ($intentSelection['intent_type'] ?? ''),
                    'dispatch_plan' => $intentSelection['dispatch_plan'] ?? null,
                    'execution_hint' => $intentSelection['execution_hint'] ?? null,
                    'human_blocked' => (bool) ($intentSelection['human_blocked'] ?? false),
                ]);
            }

            // Phase 2-B Step 4-B: Conversation Runtime shadow probe (flag default OFF).
            // Shadow-only — observes ConversationRuntimeFacade decision and logs parity
            // vs the legacy FinalReplyGate. Never changes reply text, route, transport,
            // Product/Knowledge Runtime, or LineService behavior; never throws.
            try {
                $shadowLegacyStatus = $statusResolver->resolveStatus($conversationKey, $now);
                ConversationRuntimeShadowProbe::run([
                    'tenant_sno' => $tenantSno,
                    'conversation_id' => $runtimeConversationId,
                    'intent_type' => (string) ($intentSelection['intent_type'] ?? $legacyIntentType),
                    'legacy_conversation_status' => $shadowLegacyStatus,
                    'legacy_allowed' => FinalReplyGate::maySendAiReply($shadowLegacyStatus),
                    'trace_id' => $traceId,
                    'now' => $now,
                ]);
            } catch (\Throwable $shadowProbeError) {
                Logger::log('saas_router.log', 'conversation_runtime_shadow_probe_error', [
                    'trace_id' => $traceId,
                    'message' => $shadowProbeError->getMessage(),
                ]);
            }

            // Phase 2-D Step 2-D-3-1: AI Intent Understanding shadow probe (flag default OFF).
            // Shadow-only — runs AiIntentUnderstandingRuntime in parallel with the legacy
            // KnowledgeIntentDetector and logs intent / dispatch_plan / execution_hint /
            // owner_snapshot parity. Never changes reply text, route, transport, Knowledge /
            // Product / Human Runtime, or LineService behavior; never throws.
            try {
                AiIntentUnderstandingShadowProbe::run([
                    'tenant_sno' => $tenantSno,
                    'conversation_id' => $runtimeConversationId,
                    'message' => $queryText,
                    'legacy_intent_type' => $legacyIntentType,
                    'trace_id' => $traceId,
                    'now' => $now,
                    'reference_date' => $now,
                ]);
            } catch (\Throwable $intentShadowError) {
                Logger::log('saas_router.log', 'intent_understanding_shadow_probe_error', [
                    'trace_id' => $traceId,
                    'message' => $intentShadowError->getMessage(),
                ]);
            }

            if (($intentSelection['human_blocked'] ?? false) === true) {
                self::recordReplyGateCompare(
                    ConversationReplyGateCompareProbe::GATE_POINT_EARLY_BLOCK,
                    $tenantSno,
                    $runtimeConversationId,
                    $conversationStatus,
                    FinalReplyGate::maySendAiReply($conversationStatus),
                    $traceId,
                    $now
                );

                $blockedPayload = [
                    'trace_id' => $traceId,
                    'tenant_sno' => $tenantSno,
                    'conversation_id' => $conversationKey,
                    'conversation_status' => $conversationStatus,
                    'blocked' => true,
                    'block_reason' => 'aiu_human_owner',
                    'runtime_source' => AiIntentUnderstandingRuntimeSelector::SOURCE_AIU,
                    'dispatch_plan' => $intentSelection['dispatch_plan'] ?? DispatchPlan::HUMAN,
                    'final_route' => 'phase_9c1_structured_pilot_blocked',
                ];
                Logger::log('saas_router.log', 'phase_9c1_structured_pilot_blocked', $blockedPayload);
                self::appendWebhookLog('phase_9c1_structured_pilot_blocked', $blockedPayload);

                return [
                    'ok' => true,
                    'message' => 'phase_9c1_structured_pilot_blocked',
                    'phase_9c1' => $blockedPayload,
                ];
            }

            $intentDetection = ['intent_type' => (string) ($intentSelection['intent_type'] ?? $legacyIntentType)];

            // AIU v2 Last Mile: Contract Translation of authoritative Semantic Result
            // for Product Execute Layer. Legacy BatsSearchIntentBuilder is NOT used when
            // runtime_source = aiu and aiu_result is present.
            $authoritativeProductIntent = null;
            if (
                ($intentSelection['runtime_source'] ?? '') === AiIntentUnderstandingRuntimeSelector::SOURCE_AIU
                && ($intentSelection['aiu_result'] ?? null) instanceof AiIntentUnderstandingResult
            ) {
                $authoritativeProductIntent = (new AiuProductIntentTranslator())->translate(
                    $intentSelection['aiu_result']
                );
            }

            // Phase 2-B Step 2-B-2: Event Source ingest (parse + optional dispatch).
            // flags default OFF. Parse-only logs canonical descriptors; dispatch (when
            // also enabled) feeds ConversationRuntimeFacade's own isolated state/memory.
            // Never changes reply text, route, transport, Product/Knowledge Runtime, or
            // LineService behavior; never throws. Out of scope: Human Takeover (Owner
            // stays AI), Gate switch, legacy resolver.
            try {
                if (is_array($rawLineEvent) && $rawLineEvent !== []) {
                    ConversationEventIngestProbe::run([
                        'tenant_sno' => $tenantSno,
                        'channel' => ConversationEventIngestProbe::CHANNEL_LINE,
                        'raw_envelope' => $rawLineEvent,
                        'trace_id' => $traceId,
                        'now' => $now,
                    ]);
                }
            } catch (\Throwable $ingestError) {
                Logger::log('saas_router.log', 'conversation_event_ingest_error', [
                    'trace_id' => $traceId,
                    'message' => $ingestError->getMessage(),
                ]);
            }

            if (($intentDetection['intent_type'] ?? '') === KnowledgeIntentDetector::INTENT_HUMAN_SERVICE_REQUEST) {
                $humanServiceComposer = new HumanServiceResponseComposer();
                $replyText = $humanServiceComposer->compose(
                    trim((string) ($tenant['tenant_key'] ?? '')),
                    trim((string) ($tenant['company_name'] ?? ''))
                );
                $statusResolver->markHumanActive($conversationKey, $now);

                $replyRes = null;
                if (is_callable($lineReplySender)) {
                    $replyRes = $lineReplySender($lineReplyUrl, $lineToken, $replyToken, $replyText);
                } else {
                    $replyRes = LineService::replyToLine($lineReplyUrl, $lineToken, $replyToken, $replyText);
                }

                $humanPayload = [
                    'trace_id' => $traceId,
                    'tenant_sno' => $tenantSno,
                    'conversation_id' => $conversationKey,
                    'conversation_status' => $statusResolver->resolveStatus($conversationKey, $now),
                    'intent_type' => KnowledgeIntentDetector::INTENT_HUMAN_SERVICE_REQUEST,
                    'reply_text_length' => mb_strlen($replyText),
                    'line_reply' => $replyRes,
                    'final_route' => 'phase_9c2b3_knowledge_human_service_request',
                ];
                Logger::log('saas_router.log', 'phase_9c2b3_knowledge_human_service_request', $humanPayload);
                self::appendWebhookLog('phase_9c2b3_knowledge_human_service_request', $humanPayload);

                return [
                    'ok' => true,
                    'message' => 'phase_9c2b3_knowledge_human_service_request',
                    'phase_9c1' => $humanPayload,
                ];
            }

            if (($intentDetection['intent_type'] ?? '') === KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY) {
                $industryCode = trim((string) ($tenant['industry_code'] ?? ''));
                if ($industryCode === '' && trim((string) ($tenant['tenant_key'] ?? '')) === 'travel_b') {
                    $industryCode = 'travel';
                }

                $knowledgeRuntime = $knowledgeProvider !== null
                    ? new TenantPrivateKnowledgeRuntime($knowledgeProvider, null, null, null, null, null, null, null, $knowledgeFallbackResolver)
                    : new TenantPrivateKnowledgeRuntime(null, null, null, $tenantSno, null, null, null, null, $knowledgeFallbackResolver);
                $knowledgeResult = $knowledgeRuntime->handle($queryText, [
                    'tenant_sno' => $tenantSno,
                    'tenant_key' => trim((string) ($tenant['tenant_key'] ?? '')),
                    'company_name' => trim((string) ($tenant['company_name'] ?? '')),
                    'industry_code' => $industryCode,
                ]);

                // Phase 9-C-2C-1 / 2-F-2b: Grounded Response Composer (legacy or authoritative pilot).
                $tenantContext = [
                    'tenant_sno' => $tenantSno,
                    'tenant_key' => trim((string) ($tenant['tenant_key'] ?? '')),
                    'company_name' => trim((string) ($tenant['company_name'] ?? '')),
                    'industry_code' => $industryCode,
                ];
                $knowledgeCompose = GroundingPipelineRuntime::composeKnowledgeReply([
                    'config' => self::loadBatsFeatureConfig(),
                    'trace_id' => $traceId,
                    'tenant_sno' => $tenantSno,
                    'conversation_id' => $runtimeConversationId,
                    'customer_query' => $queryText,
                    'runtime_type' => GroundingOrchestratorContextFactory::resolveKnowledgeRuntimeType($knowledgeResult),
                    'runtime_result' => $knowledgeResult,
                    'dispatch_result' => [
                        'reply_purpose' => GroundedInput::PURPOSE_KNOWLEDGE_REPLY,
                        'intent_type' => (string) ($intentDetection['intent_type'] ?? ''),
                    ],
                    'tenant' => $tenantContext,
                    'legacy_tenant' => $tenantContext,
                ]);
                $groundedOutput = $knowledgeCompose['output'];
                $replyText = $groundedOutput->getText();
                if ($replyText === '' || $groundedOutput->isReplySuppressed()) {
                    $runtimeReply = trim((string) ($knowledgeResult['reply_text'] ?? ''));
                    if ($runtimeReply !== '') {
                        $replyText = $runtimeReply;
                    }
                }

                self::runGroundingShadowProbe([
                    'path' => 'knowledge',
                    'trace_id' => $traceId,
                    'tenant_sno' => $tenantSno,
                    'conversation_id' => $runtimeConversationId,
                    'customer_query' => $queryText,
                    'runtime_type' => GroundingOrchestratorContextFactory::resolveKnowledgeRuntimeType($knowledgeResult),
                    'runtime_result' => $knowledgeResult,
                    'dispatch_result' => [
                        'reply_purpose' => GroundedInput::PURPOSE_KNOWLEDGE_REPLY,
                        'intent_type' => (string) ($intentDetection['intent_type'] ?? ''),
                    ],
                    'tenant' => [
                        'tenant_sno' => $tenantSno,
                        'tenant_key' => trim((string) ($tenant['tenant_key'] ?? '')),
                        'company_name' => trim((string) ($tenant['company_name'] ?? '')),
                        'industry_code' => $industryCode,
                    ],
                    'legacy_reply_text' => $replyText,
                ]);

                $finalGate = FinalReplyGate::evaluate($statusResolver->resolveStatus($conversationKey, $now));
                self::recordReplyGateCompare(
                    ConversationReplyGateCompareProbe::GATE_POINT_KNOWLEDGE_FINAL,
                    $tenantSno,
                    $runtimeConversationId,
                    (string) $finalGate['conversation_status'],
                    (bool) $finalGate['allowed'],
                    $traceId,
                    $now
                );
                if (!$finalGate['allowed']) {
                    $blockedPayload = [
                        'trace_id' => $traceId,
                        'tenant_sno' => $tenantSno,
                        'conversation_id' => $conversationKey,
                        'conversation_status' => $finalGate['conversation_status'],
                        'blocked' => true,
                        'block_reason' => $finalGate['block_reason'],
                        'knowledge_query_type' => $knowledgeResult['query_type'] ?? null,
                        'reply_text_length' => mb_strlen($replyText),
                        'final_route' => 'phase_9c2b_knowledge_runtime_blocked',
                    ];
                    Logger::log('saas_router.log', 'phase_9c2b_knowledge_runtime_blocked', $blockedPayload);
                    self::appendWebhookLog('phase_9c2b_knowledge_runtime_blocked', $blockedPayload);

                    return [
                        'ok' => true,
                        'message' => 'phase_9c2b_knowledge_runtime_blocked',
                        'phase_9c1' => $blockedPayload,
                    ];
                }

                $replyRes = null;
                if (is_callable($lineReplySender)) {
                    $replyRes = $lineReplySender($lineReplyUrl, $lineToken, $replyToken, $replyText);
                } else {
                    $replyRes = LineService::replyToLine($lineReplyUrl, $lineToken, $replyToken, $replyText);
                }

                $payload = [
                    'trace_id' => $traceId,
                    'tenant_sno' => $tenantSno,
                    'conversation_id' => $conversationKey,
                    'conversation_status' => $finalGate['conversation_status'],
                    'intent_type' => KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY,
                    'knowledge_query_type' => $knowledgeResult['query_type'] ?? null,
                    'knowledge_grounded' => (bool) ($knowledgeResult['grounded'] ?? false),
                    'reply_text_length' => mb_strlen($replyText),
                    'line_reply' => $replyRes,
                    'final_route' => (string) ($knowledgeResult['final_route'] ?? 'phase_9c2b_knowledge_runtime'),
                    'grounded_source_type' => $groundedOutput->getSourceType(),
                    'grounded_used_facts_count' => $groundedOutput->getUsedFactsCount(),
                    'grounded_human_service_required' => $groundedOutput->isHumanServiceRequired(),
                ];
                if ($groundedOutput->getSafetyNotes() !== []) {
                    $payload['grounded_safety_notes'] = $groundedOutput->getSafetyNotes();
                }
                if (!empty($knowledgeResult['qa_id'])) {
                    $payload['knowledge_qa_id'] = (string) $knowledgeResult['qa_id'];
                }
                if (!empty($knowledgeResult['price_id'])) {
                    $payload['knowledge_price_id'] = (string) $knowledgeResult['price_id'];
                }
                if (!empty($knowledgeResult['service_id'])) {
                    $payload['knowledge_service_id'] = (string) $knowledgeResult['service_id'];
                }
                if (!empty($knowledgeResult['link_id'])) {
                    $payload['knowledge_link_id'] = (string) $knowledgeResult['link_id'];
                }
                if (!empty($knowledgeResult['fallback_layer'])) {
                    $payload['knowledge_fallback_layer'] = (string) $knowledgeResult['fallback_layer'];
                }
                Logger::log('saas_router.log', 'phase_9c2b_knowledge_runtime_reply', $payload);
                self::appendWebhookLog('phase_9c2b_knowledge_runtime_reply', $payload);

                return [
                    'ok' => true,
                    'message' => (string) ($knowledgeResult['final_route'] ?? 'phase_9c2b_knowledge_runtime'),
                    'phase_9c1' => $payload,
                ];
            }

            $service = $tourContextService ?? new TourPromptContextService();
            $contextParams = [
                'userText' => $queryText,
                'sno' => $tenantSno,
                'channelId' => $channelId !== '' ? $channelId : null,
                'traceId' => $traceId,
                'featureEnabled' => true,
                'referenceDate' => $now,
                'hybridSearchConfig' => [
                    'enabled' => false,
                    'allowed_sno' => [],
                    'allowed_channels' => [],
                    'dry_run_log_enabled' => false,
                ],
            ];
            if ($searchClient instanceof TourSearchApiClient) {
                $contextParams['searchClient'] = $searchClient;
            }
            if ($authoritativeProductIntent instanceof BatsSearchIntent) {
                $contextParams['authoritativeIntent'] = $authoritativeProductIntent;
            }

            $intentType = (string) ($intentDetection['intent_type'] ?? '');
            $waitingReplySent = false;
            $ackText = '';
            $ackReply = null;
            $linePushApiUrl = self::resolveLinePushApiUrl($lineReplyUrl);
            $trimmedUserId = trim($userId);

            if ($intentType === KnowledgeIntentDetector::INTENT_PRODUCT_SEARCH) {
                // AIU v2 Last Mile: preflight clarification gate uses AIU Contract Translation
                // when authoritative; legacy builder only as explicit fallback.
                if ($authoritativeProductIntent instanceof BatsSearchIntent) {
                    $preflightIntent = $authoritativeProductIntent;
                } else {
                    $preflightIntent = (new BatsSearchIntentBuilder())->parse($queryText, [
                        'reference_date' => $now,
                        'merge_legacy_keyword' => true,
                    ]);
                }
                $preflightGate = FinalReplyGate::evaluate($statusResolver->resolveStatus($conversationKey, $now));
                self::recordReplyGateCompare(
                    ConversationReplyGateCompareProbe::GATE_POINT_PRODUCT_PREFLIGHT,
                    $tenantSno,
                    $runtimeConversationId,
                    (string) $preflightGate['conversation_status'],
                    (bool) $preflightGate['allowed'],
                    $traceId,
                    $now
                );
                if (
                    !$preflightIntent->isClarificationRequired()
                    && ($preflightGate['allowed'] ?? false) === true
                    && $trimmedUserId !== ''
                ) {
                    $ackText = AcknowledgementReplyComposer::composeProductWaitingReply();
                    if ($ackText !== '') {
                        if (is_callable($lineReplySender)) {
                            $ackReply = $lineReplySender($lineReplyUrl, $lineToken, $replyToken, $ackText);
                        } else {
                            $ackReply = LineService::replyToLine($lineReplyUrl, $lineToken, $replyToken, $ackText);
                        }
                        $waitingReplySent = true;
                        Logger::log('saas_router.log', 'phase_9c1_waiting_reply_sent', [
                            'trace_id' => $traceId,
                            'conversation_id' => $conversationKey,
                            'intent_type' => $intentType,
                            'ack_text_length' => mb_strlen($ackText),
                            'user_id_present' => true,
                        ]);
                        self::appendWebhookLog('phase_9c1_waiting_reply_sent', [
                            'trace_id' => $traceId,
                            'conversation_id' => $conversationKey,
                            'intent_type' => $intentType,
                            'ack_text_length' => mb_strlen($ackText),
                        ]);
                    }
                }
            }

            $structuredResult = $service->buildTourContextResult($contextParams);
            $geminiContextSchemaVersion = null;
            $replyText = '';
            $groundedReplyType = null;
            $groundedLayoutProfile = null;
            $groundedUsedFactsCount = null;

            if ($structuredResult->isClarificationRequired()) {
                $replyText = DateClarificationLineFormatter::formatFromTourContext(
                    $structuredResult->getLegacyContext()
                );
                if ($replyText === '') {
                    $replyText = '您好 😊 請問您預計什麼時候出發呢？我會依照您的出發時間幫您查詢適合的行程。';
                }
            } else {
                $renderer = $geminiRenderer ?? new GeminiRenderer();
                $client = $geminiClient ?? new GeminiClient();
                $tenantName = trim((string) ($tenant['company_name'] ?? ''));
                if ($tenantName === '') {
                    $tenantName = 'BBC Travel';
                }

                $plan = self::buildGeminiPublishPlanFromSearchResults($structuredResult->getSearchResults());
                $intentArray = $structuredResult->getIntent()->toArray();
                $recommendationBuilder = new ProductRecommendationBuilder();
                $recommendationSummary = $recommendationBuilder->build(
                    $plan->getItems(),
                    $intentArray,
                    $queryText,
                    $structuredResult->getSearchPolicyMeta()
                );
                $geminiContext = $renderer->renderDocument($plan, [
                    'schema_version' => GeminiContextDocument::SCHEMA_VERSION_V2,
                    'customer_query' => $queryText,
                    'tenant_name' => $tenantName,
                    'bats_search_intent' => $intentArray,
                    'recommendation_summary' => $recommendationSummary,
                ]);
                $geminiContextSchemaVersion = $geminiContext->getSchemaVersion();
                $geminiReplyText = $client->generateResponse($geminiContext)->getReplyText();

                // P1 Product Layout Fix: restore the original fixed BBC product
                // layout via TourFallbackFormatter, sourced from the already-built
                // legacy context. The fixed layout is only applied when the search
                // returned results; with zero results the existing reply (the
                // no-results message) is preserved unchanged.
                                $replyText = $geminiReplyText;
                if (
                    count($structuredResult->getSearchResults()) === 0
                    && (
                        ($recommendationSummary['hard_constraints_complete'] ?? false) === true
                        || ($recommendationSummary['no_result_composer'] ?? false) === true
                    )
                ) {
                    $noResultPersona = new TravelConsultantPersonaRuntime();
                    $replyText = $noResultPersona->composeProductRecommendation($recommendationSummary);
                }
                if (count($structuredResult->getSearchResults()) > 0) {
                    $fixedProductLayout = TourFallbackFormatter::formatFromTourContext(
                        $structuredResult->getLegacyContext()
                    );
                    if (trim($fixedProductLayout) !== '') {
                        $replyText = $fixedProductLayout;
                    }
                }

                // Phase 9-C-2C-2: present the Product Runtime reply through the
                // unified GroundedResponseComposer. The recommendation copy is
                // NOT regenerated here — the composer only normalizes grounded
                // presentation metadata and passes the reply text through.
                $productResultCount = isset($recommendationSummary['result_count'])
                    ? (int) $recommendationSummary['result_count']
                    : count($structuredResult->getSearchResults());
                $productProductList = isset($recommendationSummary['top_products']) && is_array($recommendationSummary['top_products'])
                    ? $recommendationSummary['top_products']
                    : [];
                $productRuntimeResult = [
                    'reply_text' => $replyText,
                    'grounded' => $productResultCount > 0,
                    'recommendation_summary' => $recommendationSummary,
                    'product_list' => $productProductList,
                ];
                $tenantArray = is_array($tenant) ? $tenant : [];
                $productCompose = GroundingPipelineRuntime::composeProductReply([
                    'config' => self::loadBatsFeatureConfig(),
                    'trace_id' => $traceId,
                    'tenant_sno' => $tenantSno,
                    'conversation_id' => $runtimeConversationId,
                    'customer_query' => $queryText,
                    'runtime_type' => RuntimeType::PRODUCT_SEARCH,
                    'runtime_result' => $productRuntimeResult,
                    'dispatch_result' => [
                        'reply_purpose' => GroundedInput::PURPOSE_PRODUCT_REPLY,
                        'intent_type' => (string) ($intentDetection['intent_type'] ?? ''),
                    ],
                    'tenant' => $tenantArray,
                    'legacy_tenant' => $tenantArray,
                    'bats_search_intent' => $intentArray,
                ]);
                $groundedOutput = $productCompose['output'];
                $composedReplyText = $groundedOutput->getReplyText();
                if ($composedReplyText !== '') {
                    $replyText = $composedReplyText;
                }
                $groundedReplyType = $groundedOutput->getReplyType();
                $groundedLayoutProfile = $groundedOutput->getLayoutProfile();
                $groundedUsedFactsCount = $groundedOutput->getUsedFactsCount();

                self::runGroundingShadowProbe([
                    'path' => 'product',
                    'trace_id' => $traceId,
                    'tenant_sno' => $tenantSno,
                    'conversation_id' => $runtimeConversationId,
                    'customer_query' => $queryText,
                    'runtime_type' => RuntimeType::PRODUCT_SEARCH,
                    'runtime_result' => [
                        'reply_text' => $replyText,
                        'grounded' => $productResultCount > 0,
                        'recommendation_summary' => $recommendationSummary,
                        'product_list' => $productProductList,
                    ],
                    'dispatch_result' => [
                        'reply_purpose' => GroundedInput::PURPOSE_PRODUCT_REPLY,
                        'intent_type' => (string) ($intentDetection['intent_type'] ?? ''),
                    ],
                    'tenant' => is_array($tenant) ? $tenant : [],
                    'bats_search_intent' => $intentArray,
                    'legacy_reply_text' => $replyText,
                ]);
            }

            if ($replyText === '') {
                $replyText = 'BATS Phase 9-C-1 pilot：目前無法產生回覆，請稍後再試。';
            }

            $finalGate = FinalReplyGate::evaluate($statusResolver->resolveStatus($conversationKey, $now));
            self::recordReplyGateCompare(
                ConversationReplyGateCompareProbe::GATE_POINT_PRODUCT_FINAL,
                $tenantSno,
                $runtimeConversationId,
                (string) $finalGate['conversation_status'],
                (bool) $finalGate['allowed'],
                $traceId,
                $now
            );
            if (!$finalGate['allowed']) {
                $blockedPayload = [
                    'trace_id' => $traceId,
                    'tenant_sno' => $tenantSno,
                    'conversation_id' => $conversationKey,
                    'conversation_status' => $finalGate['conversation_status'],
                    'blocked' => true,
                    'block_reason' => $finalGate['block_reason'],
                    'clarification_required' => $structuredResult->isClarificationRequired(),
                    'ack_text_length' => $ackText !== '' ? mb_strlen($ackText) : 0,
                    'reply_text_length' => mb_strlen($replyText),
                    'final_route' => 'phase_9c1_structured_pilot_final_blocked',
                ];
                Logger::log('saas_router.log', 'phase_9c1_structured_pilot_final_blocked', $blockedPayload);
                self::appendWebhookLog('phase_9c1_structured_pilot_final_blocked', $blockedPayload);

                return [
                    'ok' => true,
                    'message' => 'phase_9c1_structured_pilot_final_blocked',
                    'phase_9c1' => $blockedPayload,
                ];
            }

            $replyRes = null;
            $pushRes = null;
            if ($waitingReplySent && !$structuredResult->isClarificationRequired()) {
                if (is_callable($linePushSender)) {
                    $pushRes = $linePushSender($linePushApiUrl, $lineToken, $trimmedUserId, $replyText);
                } else {
                    $pushRes = LineService::pushToLine($linePushApiUrl, $lineToken, $trimmedUserId, $replyText);
                }
            } else {
                if (is_callable($lineReplySender)) {
                    $replyRes = $lineReplySender($lineReplyUrl, $lineToken, $replyToken, $replyText);
                } else {
                    $replyRes = LineService::replyToLine($lineReplyUrl, $lineToken, $replyToken, $replyText);
                }
            }

            $payload = [
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
                'conversation_id' => $conversationKey,
                'conversation_status' => $finalGate['conversation_status'],
                'intent_type' => $intentType,
                'runtime_source' => (string) ($intentSelection['runtime_source'] ?? AiIntentUnderstandingRuntimeSelector::SOURCE_LEGACY),
                'authoritative_product_path' => $authoritativeProductIntent instanceof BatsSearchIntent,
                'clarification_required' => $structuredResult->isClarificationRequired(),
                'clarification_reason' => $structuredResult->getClarificationReason(),
                'gemini_context_schema_version' => $geminiContextSchemaVersion,
                'search_result_count' => count($structuredResult->getSearchResults()),
                'waiting_reply_sent' => $waitingReplySent,
                'ack_text_length' => $ackText !== '' ? mb_strlen($ackText) : 0,
                'ack_line_reply' => $ackReply,
                'reply_text_length' => mb_strlen($replyText),
                'grounded_reply_type' => $groundedReplyType,
                'grounded_layout_profile' => $groundedLayoutProfile,
                'grounded_used_facts_count' => $groundedUsedFactsCount,
                'line_reply' => $replyRes,
                'line_push' => $pushRes,
                'final_transport' => ($waitingReplySent && !$structuredResult->isClarificationRequired()) ? 'push' : 'reply',
                'final_route' => 'phase_9c1_structured_pilot',
            ];
            Logger::log('saas_router.log', 'phase_9c1_structured_pilot_reply', $payload);
            self::appendWebhookLog('phase_9c1_structured_pilot_reply', $payload);

            return [
                'ok' => true,
                'message' => 'phase_9c1_structured_pilot',
                'phase_9c1' => $payload,
            ];
        } catch (\Throwable $e) {
            Logger::log('saas_router.log', 'phase_9c1_structured_pilot_error', [
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
                'message' => $e->getMessage(),
                'fallthrough_to_legacy' => true,
            ]);
            self::appendWebhookLog('phase_9c1_structured_pilot_error', [
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
                'message' => $e->getMessage(),
                'fallthrough_to_legacy' => true,
            ]);

            return null;
        }
    }

    private static function resolveLinePushApiUrl(string $lineReplyUrl): string
    {
        $lineReplyUrl = trim($lineReplyUrl);
        if ($lineReplyUrl !== '' && preg_match('/\/reply\/?$/i', $lineReplyUrl) === 1) {
            return preg_replace('/\/reply\/?$/i', '/push', $lineReplyUrl) ?? 'https://api.line.me/v2/bot/message/push';
        }

        return 'https://api.line.me/v2/bot/message/push';
    }

    /**
     * Aligns with GeminiTourContextBuilder title field fallbacks (legacy tour list).
     *
     * @param array<string, mixed> $row
     */
    private static function resolvePublishPlanItemTitle(array $row): string
    {
        foreach (['title', 'couponName', 'name', '行程名稱'] as $key) {
            if (!isset($row[$key])) {
                continue;
            }
            $value = trim((string) $row[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param list<array<string, mixed>> $searchResults
     */
    private static function buildGeminiPublishPlanFromSearchResults(array $searchResults): ChannelPublishPlan
    {
        $items = [];
        foreach ($searchResults as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = self::resolvePublishPlanItemTitle($row);
            if ($title === '') {
                continue;
            }
            $primaryUrl = 'https://example.test/tour/pilot';
            if (isset($row['primary_url']) && trim((string) $row['primary_url']) !== '') {
                $primaryUrl = trim((string) $row['primary_url']);
            } elseif (isset($row['search_url']) && trim((string) $row['search_url']) !== '') {
                $primaryUrl = trim((string) $row['search_url']);
            }
            $items[] = [
                'title' => $title,
                'summary' => null,
                'primary_url' => $primaryUrl,
                'metadata' => $row,
            ];
        }

        return (new ChannelPublishPlanValidator())->validate([
            'channel' => 'gemini',
            'strategy_name' => 'phase9c1_structured_pilot_v1',
            'payload_schema_version' => ChannelPublishPlan::PAYLOAD_SCHEMA_VERSION,
            'items' => $items,
            'fallback' => null,
            'metadata' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadBatsFeatureConfig(): array
    {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bats_feature.php';
        if (!is_file($path)) {
            return BatsFeatureGate::defaultConfig();
        }

        $loaded = require $path;

        return is_array($loaded) ? $loaded : BatsFeatureGate::defaultConfig();
    }

    /**
     * Phase 2-F-2a: shadow-only grounding probe (never throw; never changes reply).
     *
     * @param array<string, mixed> $params
     */
    private static function runGroundingShadowProbe(array $params): void
    {
        try {
            GroundingShadowProbe::run($params);
        } catch (\Throwable $e) {
            Logger::log('saas_router.log', 'grounding_layer_shadow_probe_error', [
                'trace_id' => (string) ($params['trace_id'] ?? ''),
                'path' => (string) ($params['path'] ?? ''),
                'message' => $e->getMessage(),
            ]);
        }
    }

    private static function createPdo(array $config): PDO
    {
        $host = (string) ($config['database']['host'] ?? '');
        $db = (string) ($config['database']['name'] ?? '');
        $user = (string) ($config['database']['user'] ?? '');
        $pass = (string) ($config['database']['pass'] ?? '');

        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';
        $dsn = build_sqlsrv_dsn($host, $db);
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private static function fetchServiceLimits(PDO $pdo, string $sno): array
    {
        $stmt = $pdo->prepare('SELECT service_name, is_supported, note, updated_at FROM tenant_service_limits WHERE sno = :sno ORDER BY updated_at DESC');
        $stmt->bindValue(':sno', $sno, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    private static function appendWebhookLog(string $step, array $context): void
    {
        $path = 'C:/bbc-ai-bot/logs/webhook.log';
        $line = '[' . date('Y-m-d H:i:s') . '][' . $step . '] ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        @file_put_contents($path, $line, FILE_APPEND);
    }

    /**
     * Phase 2-B Step 4-C-1: Dual Gate Compare (flag default OFF).
     *
     * Compare-only — records ConversationReplyGate (Owner-based) parity against
     * the legacy FinalReplyGate result passed in by the caller. The legacy gate
     * remains the SOLE authority; this helper never changes any allow/block
     * decision, reply text, route, transport, or Product/Knowledge Runtime, and
     * never throws (any failure is swallowed after logging).
     */
    private static function recordReplyGateCompare(
        string $gatePoint,
        string $tenantSno,
        string $conversationKey,
        string $legacyStatus,
        bool $legacyAllowed,
        string $traceId,
        ?\DateTimeImmutable $now
    ): void {
        try {
            ConversationReplyGateCompareProbe::compare([
                'tenant_sno' => $tenantSno,
                'conversation_id' => $conversationKey,
                'gate_point' => $gatePoint,
                'legacy_status' => $legacyStatus,
                'legacy_allowed' => $legacyAllowed,
                'trace_id' => $traceId,
                'now' => $now,
            ]);
        } catch (\Throwable $compareError) {
            Logger::log('saas_router.log', 'conversation_reply_gate_compare_error', [
                'trace_id' => $traceId,
                'gate_point' => $gatePoint,
                'message' => $compareError->getMessage(),
            ]);
        }
    }
}

function routeAIRequest(array $event): array
{
    $rawBody = isset($event['__meta']['raw_body']) ? (string) $event['__meta']['raw_body'] : '';
    if ($rawBody === '') {
        $rawBody = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($rawBody)) {
            $rawBody = '';
        }
    }

    $signature = isset($event['__meta']['signature']) ? (string) $event['__meta']['signature'] : '';
    return SaaSRouter::handleEvent($event, $signature, $rawBody, app_config());
}
