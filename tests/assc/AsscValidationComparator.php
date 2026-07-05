<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'DispatchPlan.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'ExecutionHint.php';

/**
 * Compares AIU semantic output against ASSC Expected annotations.
 *
 * Validation layers: Contract → Intent → Dispatch → Capability.
 */
final class AsscValidationComparator
{
    public const STATUS_PASS = 'PASS';
    public const STATUS_FAIL = 'FAIL';
    public const STATUS_SKIP = 'SKIP';
    public const STATUS_ERROR = 'ERROR';

    /**
     * @param array<string, mixed> $runCase Runner output for one case
     * @return array<string, mixed>
     */
    public function compare(array $runCase): array
    {
        $status = (string) ($runCase['status'] ?? '');
        if ($status === 'SKIP') {
            return $this->result(self::STATUS_SKIP, (string) ($runCase['reason'] ?? 'skipped'), []);
        }
        if ($status === 'ERROR') {
            return $this->result(self::STATUS_ERROR, (string) ($runCase['error'] ?? 'error'), []);
        }
        if ($status !== 'RAN') {
            return $this->result(self::STATUS_ERROR, 'unexpected_runner_status:' . $status, []);
        }

        /** @var array<string, mixed>|null $expected */
        $expected = $runCase['expected'] ?? null;
        /** @var array<string, mixed>|null $actual */
        $actual = $runCase['actual'] ?? null;
        if (!is_array($expected) || !is_array($actual)) {
            return $this->result(self::STATUS_ERROR, 'missing_expected_or_actual', []);
        }

        $checks = [];
        $contractOk = $this->checkContract($actual, $checks);
        $intentOk = $this->checkIntent($expected, $actual, $checks);
        $dispatchOk = $this->checkDispatch($expected, $actual, $checks);
        $capabilityOk = $this->checkCapabilities($expected, $actual, $checks);

        $allOk = $contractOk && $intentOk && $dispatchOk && $capabilityOk;

        return $this->result(
            $allOk ? self::STATUS_PASS : self::STATUS_FAIL,
            $allOk ? 'all_checks_passed' : 'semantic_mismatch',
            $checks
        );
    }

    /**
     * @param array<string, mixed> $actual
     * @param list<array<string, mixed>> $checks
     */
    private function checkContract(array $actual, array &$checks): bool
    {
        $requiredKeys = [
            'intent',
            'entity',
            'context_snapshot',
            'owner_snapshot',
            'conversation_stage',
            'resume_context',
            'clarification',
            'dispatch_plan',
            'execution_hint',
        ];
        $missing = [];
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $actual)) {
                $missing[] = $key;
            }
        }

        $intentValid = AiIntentCategory::isValid((string) ($actual['intent'] ?? ''));
        $dispatchValid = DispatchPlan::isValid((string) ($actual['dispatch_plan'] ?? ''));
        $hint = array_key_exists('execution_hint', $actual) ? $actual['execution_hint'] : null;
        $hintValid = ExecutionHint::isValid($hint === null ? null : (string) $hint);
        $clar = $actual['clarification'] ?? null;
        $clarValid = is_array($clar)
            && array_key_exists('required', $clar)
            && array_key_exists('reason', $clar);

        $ok = $missing === [] && $intentValid && $dispatchValid && $hintValid && $clarValid;
        $checks[] = [
            'layer' => 'contract',
            'pass' => $ok,
            'missing_keys' => $missing,
            'intent_valid' => $intentValid,
            'dispatch_valid' => $dispatchValid,
            'hint_valid' => $hintValid,
            'clarification_valid' => $clarValid,
        ];

        return $ok;
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     * @param list<array<string, mixed>> $checks
     */
    private function checkIntent(array $expected, array $actual, array &$checks): bool
    {
        $expectedIntent = (string) ($expected['expected_intent'] ?? '');
        $actualIntent = (string) ($actual['intent'] ?? '');
        $ok = $expectedIntent === $actualIntent;
        $checks[] = [
            'layer' => 'intent',
            'pass' => $ok,
            'expected' => $expectedIntent,
            'actual' => $actualIntent,
        ];

        return $ok;
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     * @param list<array<string, mixed>> $checks
     */
    private function checkDispatch(array $expected, array $actual, array &$checks): bool
    {
        $expectedPlan = (string) ($expected['expected_dispatch_plan'] ?? '');
        $actualPlan = (string) ($actual['dispatch_plan'] ?? '');
        $ok = $expectedPlan === $actualPlan;
        $checks[] = [
            'layer' => 'dispatch',
            'pass' => $ok,
            'expected' => $expectedPlan,
            'actual' => $actualPlan,
        ];

        return $ok;
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     * @param list<array<string, mixed>> $checks
     */
    private function checkCapabilities(array $expected, array $actual, array &$checks): bool
    {
        $capabilities = $expected['required_capabilities'] ?? [];
        if (!is_array($capabilities)) {
            $capabilities = [];
        }

        $clarExpected = (bool) ($expected['expected_clarification'] ?? false);
        $clarActual = (bool) (($actual['clarification']['required'] ?? false));
        $clarOk = $clarExpected === $clarActual;

        $entity = $actual['entity'] ?? [];
        $entityOk = true;
        $dispatchPlan = (string) ($actual['dispatch_plan'] ?? '');

        $capResults = [];
        $allOk = $clarOk;

        foreach ($capabilities as $cap) {
            $cap = (string) $cap;
            switch ($cap) {
                case 'clarification':
                    $capResults[$cap] = $clarOk;
                    break;
                case 'entity_extraction':
                    $capResults[$cap] = is_array($entity) && $entity !== [];
                    break;
                case 'knowledge_resolve':
                    $capResults[$cap] = $dispatchPlan === DispatchPlan::KNOWLEDGE;
                    break;
                default:
                    $capResults[$cap] = true;
                    break;
            }
            if ($capResults[$cap] === false) {
                $allOk = false;
            }
        }

        if ($capabilities === []) {
            $allOk = $clarOk;
        }

        $checks[] = [
            'layer' => 'capability',
            'pass' => $allOk,
            'expected_clarification' => $clarExpected,
            'actual_clarification' => $clarActual,
            'capabilities' => $capResults,
        ];

        return $allOk;
    }

    /**
     * @param list<array<string, mixed>> $checks
     * @return array<string, mixed>
     */
    private function result(string $status, string $reason, array $checks): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'checks' => $checks,
        ];
    }
}
