<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-2e — read-only view of conversation_context snapshot.
 */
final class ConversationContextView
{
    /** @var list<string> */
    private array $outstandingIssues;

    /** @var list<string> */
    private array $recentlyRecommendedProducts;

    private ?string $currentRequirement;

    private ?string $conversationStage;

    private ?string $destination;

    private ?string $travelDates;

    private ?string $partySize;

    private ?string $budget;

    private ?string $aiSummary;

    /**
     * @param list<string> $outstandingIssues
     * @param list<string> $recentlyRecommendedProducts
     */
    public function __construct(
        ?string $currentRequirement = null,
        ?string $conversationStage = null,
        array $outstandingIssues = [],
        array $recentlyRecommendedProducts = [],
        ?string $destination = null,
        ?string $travelDates = null,
        ?string $partySize = null,
        ?string $budget = null,
        ?string $aiSummary = null
    ) {
        $this->currentRequirement = $currentRequirement;
        $this->conversationStage = $conversationStage;
        $this->outstandingIssues = $outstandingIssues;
        $this->recentlyRecommendedProducts = $recentlyRecommendedProducts;
        $this->destination = $destination;
        $this->travelDates = $travelDates;
        $this->partySize = $partySize;
        $this->budget = $budget;
        $this->aiSummary = $aiSummary;
    }

    public function isEmpty(): bool
    {
        return $this->currentRequirement === null
            && $this->conversationStage === null
            && $this->outstandingIssues === []
            && $this->recentlyRecommendedProducts === []
            && $this->destination === null
            && $this->travelDates === null
            && $this->partySize === null
            && $this->budget === null
            && $this->aiSummary === null;
    }

    public function hasCurrentRequirement(): bool
    {
        return $this->currentRequirement !== null && $this->currentRequirement !== '';
    }

    public function getCurrentRequirement(): ?string
    {
        return $this->currentRequirement;
    }

    public function getConversationStage(): ?string
    {
        return $this->conversationStage;
    }

    /**
     * @return list<string>
     */
    public function getOutstandingIssues(): array
    {
        return $this->outstandingIssues;
    }

    /**
     * @return list<string>
     */
    public function getRecentlyRecommendedProducts(): array
    {
        return $this->recentlyRecommendedProducts;
    }

    public function getDestination(): ?string
    {
        return $this->destination;
    }

    public function hasTravelDates(): bool
    {
        return $this->travelDates !== null && $this->travelDates !== '';
    }

    public function getTravelDates(): ?string
    {
        return $this->travelDates;
    }

    public function hasPartySize(): bool
    {
        return $this->partySize !== null && $this->partySize !== '';
    }

    public function getPartySize(): ?string
    {
        return $this->partySize;
    }

    public function getBudget(): ?string
    {
        return $this->budget;
    }

    public function getAiSummary(): ?string
    {
        return $this->aiSummary;
    }

    /**
     * @return list<string>
     */
    public function getEchoFragments(): array
    {
        $fragments = [];
        foreach (
            [
                $this->currentRequirement,
                $this->destination,
                $this->travelDates,
                $this->partySize,
                $this->budget,
                $this->aiSummary,
            ] as $value
        ) {
            if ($value !== null && $value !== '') {
                $fragments[] = $value;
            }
        }

        foreach ($this->outstandingIssues as $issue) {
            if ($issue !== '') {
                $fragments[] = $issue;
            }
        }

        return array_values(array_unique($fragments));
    }
}
