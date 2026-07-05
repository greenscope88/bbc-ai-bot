<?php
declare(strict_types=1);

/**
 * ASSC Gold Corpus Loader — offline validation only.
 *
 * Reads docs/ASSC_GOLD_BENCHMARK_v1.md; preserves Customer Utterance verbatim.
 * SSOT corpus is not modified by this loader.
 */
final class AsscGoldCorpusLoader
{
    public const CORPUS_VERSION = 'v1.0';
    public const EXPECTED_MIN_ID = 1;
    public const EXPECTED_MAX_ID = 100;

    /** @var string */
    private $corpusPath;

    public function __construct(?string $corpusPath = null)
    {
        $root = dirname(__DIR__, 2);
        $this->corpusPath = $corpusPath ?? $root . DIRECTORY_SEPARATOR . 'docs'
            . DIRECTORY_SEPARATOR . 'ASSC_GOLD_BENCHMARK_v1.md';
    }

    /**
     * @return list<array{
     *   assc_id: string,
     *   customer_utterance: string,
     *   primary_scenario: string,
     *   sequence: int
     * }>
     */
    public function load(): array
    {
        if (!is_file($this->corpusPath)) {
            throw new \RuntimeException('ASSC corpus not found: ' . $this->corpusPath);
        }

        $lines = file($this->corpusPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException('ASSC corpus unreadable: ' . $this->corpusPath);
        }

        $cases = $this->parseTableRows($lines);
        $this->validateCorpus($cases);

        return $cases;
    }

    public function getCorpusPath(): string
    {
        return $this->corpusPath;
    }

    /**
     * @param list<string> $lines
     * @return list<array{assc_id: string, customer_utterance: string, primary_scenario: string, sequence: int}>
     */
    private function parseTableRows(array $lines): array
    {
        $cases = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '| ASSC-') === false) {
                continue;
            }
            if (strpos($line, '|----------') !== false) {
                continue;
            }

            $parts = array_map('trim', explode('|', trim($line, '|')));
            if (count($parts) < 3) {
                continue;
            }

            $asscId = $parts[0];
            if (!preg_match('/^ASSC-\d{3}$/', $asscId)) {
                continue;
            }

            $primaryScenario = (string) $parts[count($parts) - 1];
            $utteranceParts = array_slice($parts, 1, -1);
            $customerUtterance = implode(' | ', $utteranceParts);

            $cases[] = [
                'assc_id' => $asscId,
                'customer_utterance' => $customerUtterance,
                'primary_scenario' => $primaryScenario,
                'sequence' => (int) substr($asscId, 5),
            ];
        }

        usort($cases, static function (array $a, array $b): int {
            return $a['sequence'] <=> $b['sequence'];
        });

        return $cases;
    }

    /**
     * @param list<array{assc_id: string, customer_utterance: string, primary_scenario: string, sequence: int}> $cases
     */
    private function validateCorpus(array $cases): void
    {
        if (count($cases) !== self::EXPECTED_MAX_ID) {
            throw new \RuntimeException(
                'ASSC corpus case count mismatch: expected '
                . self::EXPECTED_MAX_ID . ', got ' . count($cases)
            );
        }

        for ($i = self::EXPECTED_MIN_ID; $i <= self::EXPECTED_MAX_ID; ++$i) {
            $expectedId = sprintf('ASSC-%03d', $i);
            $index = $i - 1;
            if (!isset($cases[$index]) || $cases[$index]['assc_id'] !== $expectedId) {
                throw new \RuntimeException(
                    'ASSC corpus sequence gap or reorder at ' . $expectedId
                    . ' (found ' . ($cases[$index]['assc_id'] ?? 'missing') . ')'
                );
            }
            if ($cases[$index]['customer_utterance'] === '') {
                throw new \RuntimeException('ASSC corpus empty utterance at ' . $expectedId);
            }
            if ($cases[$index]['primary_scenario'] === '') {
                throw new \RuntimeException('ASSC corpus empty scenario at ' . $expectedId);
            }
        }
    }
}
