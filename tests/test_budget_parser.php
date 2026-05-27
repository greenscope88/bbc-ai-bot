<?php
declare(strict_types=1);

/**
 * Phase 2-C.21: BudgetParser Arabic / 萬 budget rules.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BudgetParser.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$parser = new BudgetParser();

function assertMax(BudgetParser $parser, string $message, int $expectedMax): void
{
    $result = $parser->parse($message);
    test_assert($result->getBudgetMax() === $expectedMax, "{$message} => budget_max={$expectedMax}");
    $flags = $result->getParserFlags();
    test_assert(!empty($flags['budget_parsed']), "{$message} => budget_parsed flag");
}

assertMax($parser, '六月底東京三萬以下', 30000);
assertMax($parser, '6月底東京30000以下', 30000);
assertMax($parser, '東京30000以內', 30000);
assertMax($parser, '東京30000內', 30000);
assertMax($parser, '3萬以下', 30000);

$noBudget = $parser->parse('六月底東京2026/06出發5日6人團');
test_assert($noBudget->getBudgetMax() === null, 'date/pax/day text does not set budget_max');
test_assert(empty($noBudget->getParserFlags()['budget_parsed']), 'date/pax/day text does not set budget_parsed');

if ($failures === 0) {
    echo "OK: BudgetParser tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
