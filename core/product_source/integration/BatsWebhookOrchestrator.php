<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BatsFeatureGate.php';

/**
 * BATS webhook orchestrator skeleton (Phase 9-B-26B-1).
 *
 * Future single entry for Webhook → BATS pipeline. No search, Gemini, or LINE I/O.
 */
final class BatsWebhookOrchestrator
{
    private BatsFeatureGate $featureGate;

    public function __construct(?BatsFeatureGate $featureGate = null)
    {
        $this->featureGate = $featureGate ?? new BatsFeatureGate();
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

        if ($traceId === '') {
            $traceId = $this->generateTraceId();
        }

        if ($tenantSno === '') {
            return $this->buildResult([
                'status' => 'rejected',
                'trace_id' => $traceId,
                'tenant_sno' => '',
                'message' => 'tenant_sno is required',
                'bats_mode' => BatsFeatureGate::MODE_DISABLED,
            ]);
        }

        if (!$this->isValidTenantSno($tenantSno)) {
            return $this->buildResult([
                'status' => 'rejected',
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
                'message' => 'invalid tenant_sno',
                'bats_mode' => BatsFeatureGate::MODE_DISABLED,
            ]);
        }

        if ($customerMessage === '') {
            return $this->buildResult([
                'status' => 'rejected',
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
                'message' => 'customer_message is required',
                'bats_mode' => BatsFeatureGate::MODE_DISABLED,
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
                'message' => 'BATS is disabled for this tenant',
                'bats_mode' => $mode,
            ]);
        }

        if ($mode === BatsFeatureGate::MODE_DRY_RUN) {
            return $this->buildResult([
                'status' => 'dry_run',
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
                'message' => 'BATS orchestrator dry-run skeleton accepted',
                'bats_mode' => $mode,
            ]);
        }

        return $this->buildResult([
            'status' => 'accepted',
            'trace_id' => $traceId,
            'tenant_sno' => $tenantSno,
            'message' => 'BATS orchestrator skeleton accepted',
            'bats_mode' => $mode,
        ]);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function normalizeResult(array $result): array
    {
        return [
            'status' => isset($result['status']) ? trim((string) $result['status']) : '',
            'trace_id' => isset($result['trace_id']) ? trim((string) $result['trace_id']) : '',
            'tenant_sno' => isset($result['tenant_sno']) ? trim((string) $result['tenant_sno']) : '',
            'message' => isset($result['message']) ? trim((string) $result['message']) : '',
            'bats_mode' => isset($result['bats_mode'])
                ? strtolower(trim((string) $result['bats_mode']))
                : BatsFeatureGate::MODE_DISABLED,
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
}
