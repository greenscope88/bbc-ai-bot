<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'RuntimeType.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GroundingAssemblyException.php';

/**
 * Phase 2-F Step 2-F-1 — GroundedInput schema validation (input contract only).
 *
 * SSOT: docs/BATS_AI_GROUNDING_LAYER.md §16.
 */
final class GroundingSchemaValidator
{
    /**
     * @throws GroundingAssemblyException
     */
    public function validate(GroundedInput $input): void
    {
        if ($input->getSchemaVersion() !== GroundedInput::SCHEMA_VERSION) {
            throw new GroundingAssemblyException('invalid schema_version');
        }

        if (!RuntimeType::isValid($input->getRuntimeType())) {
            throw new GroundingAssemblyException('invalid runtime_type on assembled input');
        }

        $metadata = $input->getMetadata();
        if (!isset($metadata['customer_query']) || trim((string) $metadata['customer_query']) === '') {
            throw new GroundingAssemblyException('missing metadata.customer_query');
        }

        $replyPolicy = $input->getReplyPolicy();
        if (!isset($replyPolicy['grounded_only']) || $replyPolicy['grounded_only'] !== true) {
            throw new GroundingAssemblyException('reply_policy.grounded_only must be true');
        }

        $mode = isset($replyPolicy['mode']) ? trim((string) $replyPolicy['mode']) : '';
        if ($mode === '') {
            throw new GroundingAssemblyException('missing reply_policy.mode');
        }

        $owner = $input->getConversationOwner();
        if (
            $owner !== GroundedInput::CONVERSATION_OWNER_AI
            && $owner !== GroundedInput::CONVERSATION_OWNER_HUMAN
        ) {
            throw new GroundingAssemblyException('invalid conversation_owner');
        }

        $serialized = $input->toArray();
        if (isset($serialized['dispatch_plan'])) {
            throw new GroundingAssemblyException('forbidden dispatch_plan on grounded input');
        }
        if (isset($serialized['execution_hint'])) {
            throw new GroundingAssemblyException('forbidden execution_hint on grounded input');
        }
    }
}
