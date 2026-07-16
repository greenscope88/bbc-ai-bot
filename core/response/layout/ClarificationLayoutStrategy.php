<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'LayoutStrategyInterface.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'LayoutDraft.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'RuntimeType.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'LayoutProfile.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'clarification' . DIRECTORY_SEPARATOR . 'ClarificationContract.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'clarification' . DIRECTORY_SEPARATOR . 'ClarificationContractFactory.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'clarification' . DIRECTORY_SEPARATOR . 'ClarificationGeminiPromptBuilder.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'clarification' . DIRECTORY_SEPARATOR . 'ClarificationGeminiGenerator.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'clarification' . DIRECTORY_SEPARATOR . 'ClarificationOutputValidator.php';

/**
 * Generative clarification layout strategy (foundation).
 *
 * Implemented but intentionally NOT registered in LayoutStrategySelector.
 * Production routing must not switch to this path in C-1.
 */
final class ClarificationLayoutStrategy implements LayoutStrategyInterface
{
    private ClarificationGeminiGenerator $generator;

    private ClarificationOutputValidator $validator;

    private ClarificationContractFactory $contractFactory;

    public function __construct(
        ?ClarificationGeminiGenerator $generator = null,
        ?ClarificationOutputValidator $validator = null,
        ?ClarificationContractFactory $contractFactory = null
    ) {
        $this->generator = $generator ?? new ClarificationGeminiGenerator();
        $this->validator = $validator ?? new ClarificationOutputValidator();
        $this->contractFactory = $contractFactory ?? new ClarificationContractFactory();
    }

    public function supports(GroundedInput $input): bool
    {
        return $input->getRuntimeType() === RuntimeType::CLARIFICATION
            && $input->getReplyPolicyMode() === 'clarification';
    }

    public function compose(GroundedInput $input): LayoutDraft
    {
        if (!$this->supports($input)) {
            return $this->failClosedDraft('clarification_input_invalid');
        }

        try {
            $contract = $this->resolveContract($input);
        } catch (\Throwable $e) {
            return $this->failClosedDraft('clarification_input_invalid');
        }

        $generated = $this->generator->generate($contract);
        if (($generated['ok'] ?? false) !== true || !is_array($generated['output'] ?? null)) {
            return $this->failClosedDraft(
                isset($generated['failure_reason'])
                    ? (string) $generated['failure_reason']
                    : 'clarification_gemini_failed'
            );
        }

        $validation = $this->validator->validate($generated['output'], $contract);
        if (($validation['ok'] ?? false) !== true) {
            return $this->failClosedDraft(
                isset($validation['failure_reason'])
                    ? (string) $validation['failure_reason']
                    : 'clarification_validation_failed'
            );
        }

        $replyText = trim((string) $generated['output']['reply_text']);
        $usedFactsCount = (int) $validation['used_facts_count'];
        $referenced = is_array($validation['referenced_fact_ids'] ?? null)
            ? array_values($validation['referenced_fact_ids'])
            : [];

        return new LayoutDraft(
            $replyText,
            $usedFactsCount > 0,
            $usedFactsCount,
            ReplyType::CLARIFICATION,
            LayoutProfile::MINIMAL,
            $referenced
        );
    }

    private function resolveContract(GroundedInput $input): ClarificationContract
    {
        $raw = $input->getRawRuntimeResult();
        if (isset($raw['clarification_contract']) && is_array($raw['clarification_contract'])) {
            $payload = $raw['clarification_contract'];
            if (!isset($payload['known_entities']) || !is_array($payload['known_entities'])) {
                $payload['known_entities'] = [];
            }
            $payload['tenant'] = $input->getTenant();
            $payload['tone'] = $input->getTone();
            $meta = $input->getMetadata();
            if (!isset($payload['trace_id'])) {
                $payload['trace_id'] = (string) ($meta['trace_id'] ?? '');
            }
            if (!isset($payload['conversation_id'])) {
                $payload['conversation_id'] = (string) ($meta['conversation_id'] ?? '');
            }

            return $this->contractFactory->create($payload);
        }

        throw new \InvalidArgumentException('clarification_contract_missing');
    }

    private function failClosedDraft(string $failureReason): LayoutDraft
    {
        unset($failureReason);

        return new LayoutDraft(
            ClarificationContract::TECHNICAL_FAIL_CLOSED_TEXT,
            false,
            0,
            ReplyType::CLARIFICATION,
            LayoutProfile::MINIMAL,
            []
        );
    }
}
