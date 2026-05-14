<?php
declare(strict_types=1);

/**
 * Phase 4 Stage 5 — mock rate limit policies (isolated).
 * No SQL, no Redis, not wired to production entry.
 */
final class RateLimitPolicyRepository
{
    /** @var list<array<string, mixed>> */
    private array $policies;

    /**
     * @param list<array<string, mixed>>|null $policies
     */
    public function __construct(?array $policies = null)
    {
        $this->policies = $policies ?? $this->defaultPolicies();
    }

    /**
     * Resolve the most specific matching policy row (may be disabled).
     *
     * @return array<string, mixed>|null
     */
    public function resolvePolicy(string $sno, $apiKeyId, string $service): ?array
    {
        $best = null;
        $bestScore = -1;

        foreach ($this->policies as $policy) {
            if (!$this->matches($policy, $sno, $apiKeyId, $service)) {
                continue;
            }
            $score = $this->specificityScore($policy);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $policy;
            }
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function matches(array $policy, string $sno, $apiKeyId, string $service): bool
    {
        $psno = $policy['sno'] ?? null;
        if ($psno !== null && (string) $psno !== $sno) {
            return false;
        }

        $pKey = $policy['api_key_id'] ?? null;
        if ($pKey !== null && (string) $pKey !== (string) $apiKeyId) {
            return false;
        }

        $psvc = $policy['service'] ?? null;
        if ($psvc !== null && (string) $psvc !== $service) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function specificityScore(array $policy): int
    {
        $score = 0;
        if (array_key_exists('sno', $policy) && $policy['sno'] !== null && (string) $policy['sno'] !== '') {
            $score += 4;
        }
        if (array_key_exists('api_key_id', $policy) && $policy['api_key_id'] !== null && (string) $policy['api_key_id'] !== '') {
            $score += 2;
        }
        if (array_key_exists('service', $policy) && $policy['service'] !== null && (string) $policy['service'] !== '') {
            $score += 1;
        }

        return $score;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function defaultPolicies(): array
    {
        return [
            [
                'policy_id' => 'rl_global_active',
                'policy_name' => 'global_default_active',
                'sno' => null,
                'api_key_id' => null,
                'service' => null,
                'limit_per_minute' => 100000,
                'limit_per_hour' => 1000000,
                'limit_per_day' => 10000000,
                'burst_limit' => 100000,
                'policy_status' => 'active',
            ],
            [
                'policy_id' => 'rl_e1_101_tour',
                'policy_name' => 'tenant_key_tour_burst',
                'sno' => 'e1fd133c7e8e45a1',
                'api_key_id' => 101,
                'service' => 'tour.search',
                'limit_per_minute' => 1000,
                'limit_per_hour' => 100000,
                'limit_per_day' => 1000000,
                'burst_limit' => 3,
                'policy_status' => 'active',
            ],
            [
                'policy_id' => 'rl_e1_101_minute',
                'policy_name' => 'tenant_key_minute_tight',
                'sno' => 'e1fd133c7e8e45a1',
                'api_key_id' => 101,
                'service' => 'svc.minute',
                'limit_per_minute' => 2,
                'limit_per_hour' => 100000,
                'limit_per_day' => 1000000,
                'burst_limit' => 10000,
                'policy_status' => 'active',
            ],
            [
                'policy_id' => 'rl_e1_101_disabled',
                'policy_name' => 'tenant_key_disabled_route',
                'sno' => 'e1fd133c7e8e45a1',
                'api_key_id' => 101,
                'service' => 'svc.disabled',
                'limit_per_minute' => 10,
                'limit_per_hour' => 100,
                'limit_per_day' => 1000,
                'burst_limit' => 5,
                'policy_status' => 'disabled',
            ],
            [
                'policy_id' => 'rl_e1_101_ipcounter',
                'policy_name' => 'per_ip_minute_one',
                'sno' => 'e1fd133c7e8e45a1',
                'api_key_id' => 101,
                'service' => 'svc.ipcounter',
                'limit_per_minute' => 1,
                'limit_per_hour' => 100000,
                'limit_per_day' => 1000000,
                'burst_limit' => 10000,
                'policy_status' => 'active',
            ],
            [
                'policy_id' => 'rl_e1_101_svcA',
                'policy_name' => 'service_a_minute_one',
                'sno' => 'e1fd133c7e8e45a1',
                'api_key_id' => 101,
                'service' => 'svc.svcA',
                'limit_per_minute' => 1,
                'limit_per_hour' => 100000,
                'limit_per_day' => 1000000,
                'burst_limit' => 10000,
                'policy_status' => 'active',
            ],
            [
                'policy_id' => 'rl_e1_101_svcB',
                'policy_name' => 'service_b_minute_one',
                'sno' => 'e1fd133c7e8e45a1',
                'api_key_id' => 101,
                'service' => 'svc.svcB',
                'limit_per_minute' => 1,
                'limit_per_hour' => 100000,
                'limit_per_day' => 1000000,
                'burst_limit' => 10000,
                'policy_status' => 'active',
            ],
        ];
    }
}
