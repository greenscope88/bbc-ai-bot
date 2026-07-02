<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundingAssemblyContext.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundingContextBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundingSchemaValidator.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'RuntimeType.php';

/**
 * Phase 2-F Step 2-F-1 — Grounding Layer public assembly runtime.
 *
 * SSOT: docs/BATS_AI_GROUNDING_LAYER.md §11.
 */
final class GroundingRuntime
{
    private GroundingContextBuilder $contextBuilder;

    private GroundingSchemaValidator $schemaValidator;

    public function __construct(
        ?GroundingContextBuilder $contextBuilder = null,
        ?GroundingSchemaValidator $schemaValidator = null
    ) {
        $this->contextBuilder = $contextBuilder ?? new GroundingContextBuilder();
        $this->schemaValidator = $schemaValidator ?? new GroundingSchemaValidator();
    }

    public function assemble(GroundingAssemblyContext $context): GroundedInput
    {
        $context->validateRequired();

        $base = $this->buildBaseInput($context);
        $conversationContext = $this->contextBuilder->build($context);
        $tenant = $this->mapTenantContext($context);
        $tone = $this->mapTone($context);
        $metadata = $this->mapMetadata($context);
        $state = $this->mapStateSnapshot($context);
        $replyPolicy = $this->mapReplyPolicy($context, $base, $conversationContext);

        $payload = $base->toArray();
        $payload['tenant'] = $tenant;
        $payload['tone'] = $tone;
        $payload['metadata'] = $metadata;
        $payload['conversation_context'] = $conversationContext;
        $payload['conversation_owner'] = $state['conversation_owner'];
        $payload['conversation_status'] = $state['conversation_status'];
        if ($state['resume_context'] !== null) {
            $payload['resume_context'] = $state['resume_context'];
        }
        $payload['reply_policy'] = $replyPolicy;
        $payload['runtime_type'] = $context->getRuntimeType();
        $payload['source_type'] = $context->getSourceType() !== ''
            ? $context->getSourceType()
            : GroundedInput::mapRuntimeTypeToSourceType($context->getRuntimeType());

        $assembled = GroundedInput::fromArray($payload);
        $this->schemaValidator->validate($assembled);

        return $assembled;
    }

    private function buildBaseInput(GroundingAssemblyContext $context): GroundedInput
    {
        $runtimeResult = $context->getRuntimeResult();
        $tenant = $context->getTenant();
        $dispatch = $context->getDispatchResult();
        $replyPurpose = isset($dispatch['reply_purpose']) && trim((string) $dispatch['reply_purpose']) !== ''
            ? trim((string) $dispatch['reply_purpose'])
            : ($context->getRuntimeType() === RuntimeType::PRODUCT_SEARCH
                ? GroundedInput::PURPOSE_PRODUCT_REPLY
                : GroundedInput::PURPOSE_KNOWLEDGE_REPLY);

        if ($context->getRuntimeType() === RuntimeType::PRODUCT_SEARCH) {
            return GroundedInput::fromProductRuntimeResult($runtimeResult, $tenant, $replyPurpose);
        }

        return GroundedInput::fromKnowledgeRuntimeResult($runtimeResult, $tenant, $replyPurpose);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTenantContext(GroundingAssemblyContext $context): array
    {
        $tenant = $context->getTenant();
        if ($tenant !== []) {
            return $tenant;
        }

        return [
            'tenant_sno' => $context->getTenantSno(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTone(GroundingAssemblyContext $context): array
    {
        $tone = $context->getTone();
        if ($tone !== []) {
            return $tone;
        }

        return [
            'persona' => '',
            'allow_emoji' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapMetadata(GroundingAssemblyContext $context): array
    {
        return [
            'trace_id' => $context->getTraceId(),
            'conversation_id' => $context->getConversationId(),
            'customer_query' => $context->getCustomerQuery(),
        ];
    }

    /**
     * @return array{conversation_owner: string, conversation_status: string, resume_context: array<string, mixed>|null}
     */
    private function mapStateSnapshot(GroundingAssemblyContext $context): array
    {
        $state = $context->getStateSnapshot();
        $owner = isset($state['conversation_owner']) && trim((string) $state['conversation_owner']) !== ''
            ? strtoupper(trim((string) $state['conversation_owner']))
            : GroundedInput::CONVERSATION_OWNER_AI;
        $status = isset($state['conversation_status']) && trim((string) $state['conversation_status']) !== ''
            ? trim((string) $state['conversation_status'])
            : GroundedInput::CONVERSATION_STATUS_ACTIVE;

        $resume = null;
        if (isset($state['resume_context']) && is_array($state['resume_context']) && $state['resume_context'] !== []) {
            $resume = $state['resume_context'];
        }

        return [
            'conversation_owner' => $owner,
            'conversation_status' => $status,
            'resume_context' => $resume,
        ];
    }

    /**
     * @param array<string, mixed> $conversationContext
     *
     * @return array<string, mixed>
     */
    private function mapReplyPolicy(
        GroundingAssemblyContext $context,
        GroundedInput $base,
        array $conversationContext
    ): array {
        $dispatch = $context->getDispatchResult();
        $policy = $base->getReplyPolicy();

        if ($policy === [] || !isset($policy['mode']) || trim((string) $policy['mode']) === '') {
            $policy = $this->defaultReplyPolicy($context, $base);
        }

        if (!isset($policy['grounded_only'])) {
            $policy['grounded_only'] = true;
        }

        if (isset($dispatch['reply_policy']) && is_array($dispatch['reply_policy'])) {
            foreach ($dispatch['reply_policy'] as $key => $value) {
                $policy[$key] = $value;
            }
        }

        if (isset($dispatch['clarification_required']) && (bool) $dispatch['clarification_required'] === true) {
            $policy['mode'] = 'clarification';
        }

        $hint = $this->mapNextBestActionHint($context, $conversationContext, $policy, $base);
        if ($hint !== null) {
            $policy['next_best_action_hint'] = $hint;
        }

        return $policy;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultReplyPolicy(GroundingAssemblyContext $context, GroundedInput $base): array
    {
        $runtimeType = $context->getRuntimeType();

        if ($runtimeType === RuntimeType::HUMAN_SERVICE) {
            return ['mode' => 'human_handoff', 'grounded_only' => true];
        }
        if ($runtimeType === RuntimeType::CLARIFICATION) {
            return ['mode' => 'clarification', 'grounded_only' => true];
        }
        if ($runtimeType === RuntimeType::WAITING_ACK) {
            return ['mode' => 'acknowledge', 'grounded_only' => true];
        }
        if ($runtimeType === RuntimeType::PRODUCT_SEARCH) {
            $mode = $base->getReplyPolicyMode() !== '' ? $base->getReplyPolicyMode() : 'no_results';

            return ['mode' => $mode, 'grounded_only' => true, 'max_products' => 3];
        }

        return ['mode' => 'recommend', 'grounded_only' => true];
    }

    /**
     * @param array<string, mixed> $conversationContext
     * @param array<string, mixed> $replyPolicy
     */
    private function mapNextBestActionHint(
        GroundingAssemblyContext $context,
        array $conversationContext,
        array $replyPolicy,
        GroundedInput $base
    ): ?string {
        $state = $context->getStateSnapshot();
        $owner = isset($state['conversation_owner'])
            ? strtoupper(trim((string) $state['conversation_owner']))
            : GroundedInput::CONVERSATION_OWNER_AI;

        if (
            $owner === GroundedInput::CONVERSATION_OWNER_HUMAN
            || $context->getRuntimeType() === RuntimeType::HUMAN_SERVICE
        ) {
            return 'contact_human_service';
        }

        if (isset($replyPolicy['next_best_action_hint'])) {
            $existing = trim((string) $replyPolicy['next_best_action_hint']);
            if ($existing !== '') {
                return $existing;
            }
        }

        $dispatch = $context->getDispatchResult();
        if (isset($dispatch['next_best_action_hint'])) {
            $dispatchHint = trim((string) $dispatch['next_best_action_hint']);
            if ($dispatchHint !== '') {
                return $dispatchHint;
            }
        }

        if ($this->hasGroundedUrl($base)) {
            return $context->getRuntimeType() === RuntimeType::PRODUCT_SEARCH
                ? 'view_tour'
                : 'view_product';
        }

        $destination = isset($conversationContext['destination'])
            ? trim((string) $conversationContext['destination'])
            : '';
        $travelDates = isset($conversationContext['travel_dates'])
            ? trim((string) $conversationContext['travel_dates'])
            : '';
        $partySize = isset($conversationContext['party_size'])
            ? trim((string) $conversationContext['party_size'])
            : '';

        if ($destination !== '' && $travelDates === '') {
            return 'ask_travel_dates';
        }
        if ($travelDates !== '' && $partySize === '') {
            return 'ask_party_size';
        }

        return null;
    }

    private function hasGroundedUrl(GroundedInput $base): bool
    {
        foreach ($base->getExternalLinks() as $link) {
            if (!is_array($link)) {
                continue;
            }
            if (trim((string) ($link['url'] ?? '')) !== '') {
                return true;
            }
        }

        foreach ($base->getProductList() as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string) ($row['primary_url'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}
