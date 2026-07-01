<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'LayoutStrategyInterface.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'RuntimeType.php';

/**
 * Phase 2-E Step 2-E-2b — selects layout strategy by runtime_type.
 */
final class LayoutStrategySelector
{
    /** @var list<LayoutStrategyInterface> */
    private array $strategies;

    /**
     * @param list<LayoutStrategyInterface> $strategies
     */
    public function __construct(array $strategies = [])
    {
        $this->strategies = $strategies;
    }

    public static function createDefault(): self
    {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'KnowledgeLayoutStrategy.php';
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductLayoutStrategy.php';

        return new self([
            new KnowledgeLayoutStrategy(),
            new ProductLayoutStrategy(),
        ]);
    }

    public function resolve(GroundedInput $input): ?LayoutStrategyInterface
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($input)) {
                return $strategy;
            }
        }

        return null;
    }
}
