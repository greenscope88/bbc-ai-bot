<?php
declare(strict_types=1);

/**
 * B0-LINE-01D-3: immutable canonical keyword token projection result.
 */
final class AiuSearchKeywordProjectionResult
{
    /** @var list<string> */
    private array $tokens;

    private string $keyword;

    private int $duplicateRemovedCount;

    private int $projectionLength;

    /**
     * @param list<string> $tokens
     */
    public function __construct(
        array $tokens,
        string $keyword,
        int $duplicateRemovedCount,
        int $projectionLength
    ) {
        $this->tokens = array_values($tokens);
        $this->keyword = $keyword;
        $this->duplicateRemovedCount = $duplicateRemovedCount;
        $this->projectionLength = $projectionLength;
    }

    /** @return list<string> */
    public function getTokens(): array
    {
        return $this->tokens;
    }

    public function getKeyword(): string
    {
        return $this->keyword;
    }

    public function getDuplicateRemovedCount(): int
    {
        return $this->duplicateRemovedCount;
    }

    public function getProjectionLength(): int
    {
        return $this->projectionLength;
    }
}
