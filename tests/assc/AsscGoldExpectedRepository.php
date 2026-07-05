<?php
declare(strict_types=1);

/**
 * ASSC Gold Expected Semantic Output repository — offline validation metadata.
 *
 * Independent from ASSC_GOLD_BENCHMARK_v1.md corpus (Customer Utterance unchanged).
 */
final class AsscGoldExpectedRepository
{
    /** @var string */
    private $fixturePath;

    /** @var array<string, array<string, mixed>>|null */
    private $byId = null;

    public function __construct(?string $fixturePath = null)
    {
        $root = dirname(__DIR__, 2);
        $this->fixturePath = $fixturePath ?? $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'assc'
            . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'assc_gold_expected_v1.json';
    }

    /**
     * @return list<string>
     */
    public function getSmokeAsscIds(): array
    {
        $ids = [];
        foreach ($this->loadCases() as $case) {
            $ids[] = (string) $case['assc_id'];
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getByAsscId(string $asscId): ?array
    {
        $this->ensureLoaded();

        return $this->byId[$asscId] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadCases(): array
    {
        if (!is_file($this->fixturePath)) {
            throw new \RuntimeException('ASSC expected fixture not found: ' . $this->fixturePath);
        }

        $raw = json_decode((string) file_get_contents($this->fixturePath), true);
        if (!is_array($raw) || !isset($raw['cases']) || !is_array($raw['cases'])) {
            throw new \RuntimeException('ASSC expected fixture invalid: ' . $this->fixturePath);
        }

        $cases = [];
        foreach ($raw['cases'] as $case) {
            if (!is_array($case)) {
                continue;
            }
            $this->validateCase($case);
            $cases[] = $case;
        }

        return $cases;
    }

    public function getFixturePath(): string
    {
        return $this->fixturePath;
    }

    public function getVersion(): string
    {
        if (!is_file($this->fixturePath)) {
            return '';
        }

        $raw = json_decode((string) file_get_contents($this->fixturePath), true);

        return is_array($raw) ? (string) ($raw['version'] ?? '') : '';
    }

    private function ensureLoaded(): void
    {
        if ($this->byId !== null) {
            return;
        }

        $this->byId = [];
        foreach ($this->loadCases() as $case) {
            $this->byId[(string) $case['assc_id']] = $case;
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    private function validateCase(array $case): void
    {
        $required = [
            'assc_id',
            'expected_intent',
            'expected_dispatch_plan',
            'expected_clarification',
            'required_capabilities',
        ];
        foreach ($required as $key) {
            if (!array_key_exists($key, $case)) {
                throw new \RuntimeException('ASSC expected case missing field: ' . $key);
            }
        }
        if (!is_array($case['required_capabilities'])) {
            throw new \RuntimeException('ASSC expected required_capabilities must be array');
        }
    }
}
