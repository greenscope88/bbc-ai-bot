<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AsscGoldCorpusLoader.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AsscGoldExpectedRepository.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'conversation'
    . DIRECTORY_SEPARATOR . 'ConversationRuntimeFacade.php';

/**
 * ASSC Semantic Validation Runner — offline only; never throw.
 *
 * Feeds Customer Utterance (verbatim) into AiIntentUnderstandingRuntime::understand().
 */
final class AsscSemanticValidationRunner
{
    public const MODE_LEGACY = 'legacy';
    public const SUBSET_SMOKE = 'smoke';
    public const SUBSET_ALL = 'all';

    /** @var AsscGoldCorpusLoader */
    private $corpusLoader;

    /** @var AsscGoldExpectedRepository */
    private $expectedRepository;

    /** @var AiIntentUnderstandingRuntimeInterface */
    private $runtime;

    /** @var string */
    private $tenantSno;

    public function __construct(
        ?AsscGoldCorpusLoader $corpusLoader = null,
        ?AsscGoldExpectedRepository $expectedRepository = null,
        ?AiIntentUnderstandingRuntime $runtime = null,
        string $tenantSno = '5f99b8d665e8444d'
    ) {
        $this->corpusLoader = $corpusLoader ?? new AsscGoldCorpusLoader();
        $this->expectedRepository = $expectedRepository ?? new AsscGoldExpectedRepository();
        $this->runtime = $runtime ?? AiIntentUnderstandingRuntime::createForTesting(
            null,
            null,
            AiIntentContextLoader::createForTesting(ConversationRuntimeFacade::createForTesting())
        );
        $this->tenantSno = $tenantSno;
    }

    /**
     * @return array{
     *   mode: string,
     *   subset: string,
     *   corpus_version: string,
     *   expected_version: string,
     *   executed_at: string,
     *   cases: list<array<string, mixed>>
     * }
     */
    public function run(string $mode = self::MODE_LEGACY, string $subset = self::SUBSET_SMOKE): array
    {
        $corpus = $this->corpusLoader->load();
        $casesToRun = $this->filterCases($corpus, $subset);
        $results = [];

        foreach ($casesToRun as $index => $case) {
            $results[] = $this->runOneCase($case, $mode, $index);
        }

        return [
            'mode' => $mode,
            'subset' => $subset,
            'corpus_version' => AsscGoldCorpusLoader::CORPUS_VERSION,
            'expected_version' => $this->expectedRepository->getVersion(),
            'executed_at' => (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei')))->format('c'),
            'cases' => $results,
        ];
    }

    /**
     * @param array{assc_id: string, customer_utterance: string, primary_scenario: string, sequence: int} $case
     * @return array<string, mixed>
     */
    private function runOneCase(array $case, string $mode, int $index): array
    {
        $asscId = $case['assc_id'];
        $utterance = $case['customer_utterance'];
        $base = [
            'assc_id' => $asscId,
            'primary_scenario' => $case['primary_scenario'],
            'customer_utterance_hash' => $this->messageHash($utterance),
            'mode' => $mode,
            'expected' => $this->expectedRepository->getByAsscId($asscId),
        ];

        if ($base['expected'] === null) {
            return array_merge($base, [
                'status' => 'SKIP',
                'reason' => 'no_expected_annotation',
                'actual' => null,
                'error' => null,
            ]);
        }

        if ($mode !== self::MODE_LEGACY) {
            return array_merge($base, [
                'status' => 'SKIP',
                'reason' => 'unsupported_mode:' . $mode,
                'actual' => null,
                'error' => null,
            ]);
        }

        try {
            $conversationId = $this->tenantSno . ':assc:' . $asscId . ':' . $index;
            $result = $this->runtime->understand($utterance, [
                'conversation_id' => $conversationId,
                'tenant_sno' => $this->tenantSno,
                'now' => new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei')),
            ]);

            return array_merge($base, [
                'status' => 'RAN',
                'reason' => 'evaluated',
                'actual' => $result->toArray(),
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            return array_merge($base, [
                'status' => 'ERROR',
                'reason' => 'runtime_exception',
                'actual' => null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param list<array{assc_id: string, customer_utterance: string, primary_scenario: string, sequence: int}> $corpus
     * @return list<array{assc_id: string, customer_utterance: string, primary_scenario: string, sequence: int}>
     */
    private function filterCases(array $corpus, string $subset): array
    {
        if ($subset === self::SUBSET_ALL) {
            return $corpus;
        }

        if ($subset === self::SUBSET_SMOKE) {
            $smokeIds = array_flip($this->expectedRepository->getSmokeAsscIds());
            $filtered = [];
            foreach ($corpus as $case) {
                if (isset($smokeIds[$case['assc_id']])) {
                    $filtered[] = $case;
                }
            }

            return $filtered;
        }

        throw new \InvalidArgumentException('unsupported subset: ' . $subset);
    }

    private function messageHash(string $message): string
    {
        if ($message === '') {
            return '';
        }

        return substr(hash('sha256', $message), 0, 16);
    }
}
