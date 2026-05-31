<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'PublisherStrategyContract.php';

/**
 * Applies a publisher strategy to channel-agnostic PublisherContract documents (Phase 9-B-19).
 *
 * Returns plain array only — not LINE Flex, Gemini prompts, or API payloads.
 */
interface PublisherStrategyInterface
{
    /**
     * @param list<array<string, mixed>> $publisherContracts
     * @return array<string, mixed>
     */
    public function applyStrategy(array $publisherContracts, PublisherStrategyContract $strategy): array;
}
