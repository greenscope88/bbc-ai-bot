<?php
declare(strict_types=1);

/**
 * BATS feature flag config (Phase 9-B-26B-4B).
 *
 * defaults.mode = disabled keeps all tenants on legacy SaaSRouter flow.
 * Pilot: set a single tenant_sno to dry_run before LINE OA dry-run (Phase 9-B-26C).
 */
return [
    'defaults' => [
        'mode' => 'disabled',
    ],
    // Phase 9-B-26C-6 controlled reply preview gate (safe default OFF).
    'controlled_reply_enabled' => false,
    'controlled_reply_tenants' => [
        '5f99b8d665e8444d',
    ],
    'controlled_reply_keyword_prefix' => 'BATS皜祈岫',
    // Phase 9-B-26C-7 controlled real LINE reply test gate (safe default OFF).
    'controlled_real_reply_enabled' => false,
    'controlled_real_reply_tenant_sno' => '5f99b8d665e8444d',
    'controlled_real_reply_tenant_key' => 'travel_b',
    'controlled_real_reply_keyword_prefix' => 'BATS皜祈岫',
    // Phase 2-B Step 4-B Conversation Runtime shadow probe (safe default OFF).
    // Shadow-only: observes ConversationRuntimeFacade decisions and logs parity
    // against the legacy FinalReplyGate. Never changes reply behavior.
    'conversation_runtime_shadow_enabled' => false,
    'conversation_runtime_shadow_tenant_snos' => [
        '5f99b8d665e8444d', // travel_b pilot
    ],
    // Phase 2-B Step 4-C-1 Dual Gate Compare (safe default OFF).
    // Compare-only: evaluates ConversationReplyGate (Owner-based) alongside the
    // legacy FinalReplyGate and logs parity. Legacy FinalReplyGate remains the
    // sole authority; this never changes reply behavior.
    'conversation_reply_gate_compare_enabled' => false,
    'conversation_reply_gate_compare_tenant_snos' => [
        '5f99b8d665e8444d', // travel_b pilot
    ],
    // Phase 2-B Step 2-B-2 Event Source Integration (safe default OFF).
    // Parse: LINE webhook event -> Canonical Descriptor (log only).
    // Dispatch: Canonical Event -> ConversationRuntimeFacade (writes the runtime's
    // own conversation_state / conversation_memory; isolated from legacy state).
    // Dispatch only takes effect when parse is also enabled for the tenant.
    'conversation_event_parse_enabled' => false,
    'conversation_event_parse_tenant_snos' => [
        '5f99b8d665e8444d', // travel_b pilot
    ],
    'conversation_event_dispatch_enabled' => false,
    'conversation_event_dispatch_tenant_snos' => [
        '5f99b8d665e8444d', // travel_b pilot
    ],
    // Phase 2-C Step 2-C-2 Human Service Runtime event ingest (safe default OFF).
    // Parse: backend/CRM human agent event -> human_agent_message descriptor (log only).
    // Dispatch: human_agent_message -> ConversationRuntimeFacade::handleHumanAgentMessage()
    // (Human Takeover, CA-005; Owner transfer performed by ConversationStateRuntime).
    // Independent of the customer event flags; dispatch only takes effect when human
    // parse is also enabled for the tenant.
    'conversation_human_event_parse_enabled' => false,
    'conversation_human_event_parse_tenant_snos' => [
        '5f99b8d665e8444d', // travel_b pilot
    ],
    'conversation_human_event_dispatch_enabled' => false,
    'conversation_human_event_dispatch_tenant_snos' => [
        '5f99b8d665e8444d', // travel_b pilot
    ],
    // Phase 2-D Step 2-D-3-1 AI Intent Understanding Shadow Probe.
    // Shadow-only: runs AiIntentUnderstandingRuntime in parallel with the authoritative
    // pilot path and logs intent parity. Never changes reply text, route, transport,
    //
    // Phase 2-D Step 2-D-3-2B Live Shadow Validation (travel_b): master switch ON,
    // but strictly tenant-scoped via the allowlist below ??only travel_b runs live
    // shadow; every non-allowlisted tenant remains effectively OFF (default-deny).
    // Reversible in one line (set back to false). Production reply flow unaffected.
    'intent_understanding_shadow_enabled' => true,
    'intent_understanding_shadow_tenant_snos' => [
        '5f99b8d665e8444d', // travel_b pilot (Live Shadow Validation)
    ],
    // Phase 2-D Step 2-D-3-3 Authoritative Runtime Selection (safe default OFF).
    // When enabled for allowlisted tenant, AIU dispatch_plan drives routing via
    // legacy-compatible intent_type mapping. Legacy detect always runs for Shadow parity.
    // Shadow probe continues independently. Reversible in one line (set back to false).
    // Production reply flow remains Legacy until this flag is explicitly turned ON.
    // Production AIU membership is Registry status+features.aiu_authoritative (not this list).
    'intent_understanding_authoritative_enabled' => true,
    'intent_understanding_authoritative_tenant_snos' => [],
    // Phase 2-F Step 2-F-2a Grounding Layer shadow probe (safe default OFF).
    // Shadow-only: assembles GroundedInput in parallel and logs structure / reply
    // parity against the legacy compose path. Never changes outbound reply.
    'grounding_layer_shadow_enabled' => false,
    'grounding_layer_shadow_tenant_snos' => [],
    // Phase 2-F Step 2-F-2b Authoritative Grounding path (safe default OFF).
    // When enabled for allowlisted tenant (travel_b pilot), Knowledge / Product exits
    // use GroundingRuntime::assemble() -> compose(). Legacy compose remains fallback.
    // Production Grounding membership is Registry status+features.grounding_authoritative (not this list).
    'grounding_layer_authoritative_enabled' => true,
    'grounding_layer_authoritative_tenant_snos' => [],
    // Phase 2-E Step 2-E-2a Composer Runtime foundation (safe default OFF).
    // When OFF: GroundedResponseComposer uses legacy pass-through only (2-E-1).
    // When ON: ComposerRuntime pipeline (Human Takeover guard + pass-through fallback).
    // Generative NLG / Prioritization / Validator are future 2-E-2 sub-phases.
    'grounded_composer_generative_enabled' => false,
    'tenants' => [
        // Pilot tenant (dry_run only ??no LINE / Gemini from BATS hook in 4B)
        '5f99b8d665e8444d' => [
            'mode' => 'dry_run',
        ],
    ],
    'channels' => [],
];
