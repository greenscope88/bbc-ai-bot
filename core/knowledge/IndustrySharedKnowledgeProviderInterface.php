<?php
declare(strict_types=1);

/**
 * Read provider for industry shared knowledge documents (Phase 9-C-2D).
 */
interface IndustrySharedKnowledgeProviderInterface
{
    public function getIndustryCode(): string;

    /**
     * @return array<string, mixed>
     */
    public function fetchFaqDocument(): array;
}
