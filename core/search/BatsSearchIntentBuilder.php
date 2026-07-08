<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AreaParser.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ClarificationPolicy.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'HybridSearchConditionBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchCondition.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TravelIntentLexicon.php';

/**
 * Phase 9-C-1 semantic parser façade — wraps HybridSearchConditionBuilder (RD-004).
 */
final class BatsSearchIntentBuilder
{
    /** @var HybridSearchConditionBuilder */
    private $hybridBuilder;

    /** @var ClarificationPolicy */
    private $clarificationPolicy;

    /** @var list<string>|null */
    private $destinationPhrases;

    public function __construct(
        ?HybridSearchConditionBuilder $hybridBuilder = null,
        ?ClarificationPolicy $clarificationPolicy = null
    ) {
        $this->hybridBuilder = $hybridBuilder ?? new HybridSearchConditionBuilder();
        $this->clarificationPolicy = $clarificationPolicy ?? new ClarificationPolicy();
    }

    /**
     * @param array<string, mixed> $context optional: reference_date (DateTimeImmutable)
     */
    public function parse(string $message, array $context = []): BatsSearchIntent
    {
        $message = trim($message);
        $hybridContext = $context;
        $hybridContext['merge_legacy_keyword'] = $hybridContext['merge_legacy_keyword'] ?? true;

        $condition = $this->hybridBuilder->parse($message, $hybridContext);
        $intent = $this->fromSearchCondition($message, $condition);
        $intent = $this->applyMultiDestination($message, $intent);
        $intent = $this->applyPeopleCount($message, $intent);

        return $this->clarificationPolicy->apply($intent, $condition);
    }

    private function fromSearchCondition(string $message, SearchCondition $condition): BatsSearchIntent
    {
        $destination = $condition->getDestination();
        if ($destination === null || $destination === '') {
            $destination = $condition->getKeyword();
        }
        if ($destination === null || $destination === '') {
            $destination = $condition->getArea();
        }

        $travelType = array_values(array_unique(array_merge(
            $condition->getTravelStyle(),
            $condition->getSpecialTags()
        )));

        return new BatsSearchIntent(
            $message,
            BatsSearchIntent::INTENT_TOUR_SEARCH,
            $destination,
            [],
            [],
            $condition->getDepartureCity(),
            $condition->getDateFrom(),
            $condition->getDateTo(),
            $travelType,
            $condition->getBudgetMin(),
            $condition->getBudgetMax(),
            $condition->getPeopleCount(),
            $condition->getPeopleLabel(),
            $condition->getDuration(),
            $condition->getProductType(),
            null,
            $condition->getMustHave(),
            [],
            false,
            null,
            $condition->getConfidence()
        );
    }

    private function applyMultiDestination(string $message, BatsSearchIntent $intent): BatsSearchIntent
    {
        $ordered = $this->extractOrderedDestinations($message);
        if (count($ordered) < 2) {
            return $intent;
        }

        $primary = $ordered[0];
        $remaining = array_values(array_slice($ordered, 1));

        return $intent->with([
            'destination' => $primary,
            'multi_destination' => $remaining,
        ]);
    }

    private function applyPeopleCount(string $message, BatsSearchIntent $intent): BatsSearchIntent
    {
        if (preg_match('/(\d+)\s*(?:人|位|名)/u', $message, $m) === 1) {
            return $intent->with(['people_count' => (int) $m[1]]);
        }

        return $intent;
    }

    /**
     * @return list<string> destination phrases in message order (non-overlapping)
     */
    private function extractOrderedDestinations(string $message): array
    {
        $text = trim($message);
        if ($text === '') {
            return [];
        }

        $candidates = [];
        foreach ($this->destinationPhrases() as $phrase) {
            $offset = 0;
            $phraseLen = mb_strlen($phrase, 'UTF-8');
            while ($offset < mb_strlen($text, 'UTF-8')) {
                $pos = mb_strpos($text, $phrase, $offset, 'UTF-8');
                if ($pos === false) {
                    break;
                }
                $candidates[] = [
                    'start' => $pos,
                    'end' => $pos + $phraseLen,
                    'phrase' => $phrase,
                    'len' => $phraseLen,
                ];
                $offset = $pos + 1;
            }
        }

        if ($candidates === []) {
            return [];
        }

        usort($candidates, static function (array $a, array $b): int {
            if ($a['start'] !== $b['start']) {
                return $a['start'] <=> $b['start'];
            }

            return $b['len'] <=> $a['len'];
        });

        $picked = [];
        $lastEnd = -1;
        foreach ($candidates as $candidate) {
            if ($candidate['start'] < $lastEnd) {
                continue;
            }
            $picked[] = $candidate['phrase'];
            $lastEnd = $candidate['end'];
        }

        return $picked;
    }

    /**
     * @return list<string>
     */
    private function destinationPhrases(): array
    {
        if ($this->destinationPhrases !== null) {
            return $this->destinationPhrases;
        }

        $phrases = array_merge(
            array_keys(AreaParser::regionCatalog()),
            TravelIntentLexicon::DESTINATIONS
        );
        $phrases = array_values(array_unique($phrases));

        usort($phrases, static function (string $a, string $b): int {
            $lenA = mb_strlen($a, 'UTF-8');
            $lenB = mb_strlen($b, 'UTF-8');
            if ($lenA !== $lenB) {
                return $lenB <=> $lenA;
            }

            return strcmp($a, $b);
        });

        $this->destinationPhrases = $phrases;

        return $this->destinationPhrases;
    }
}
