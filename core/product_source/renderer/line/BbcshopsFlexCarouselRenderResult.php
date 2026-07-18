<?php
declare(strict_types=1);

final class BbcshopsFlexCarouselRenderResult
{
    /** @var array<string, mixed> */
    private array $wireFlexMessage;

    /** @var list<string> */
    private array $bubbleFactIds;

    /**
     * @param array<string, mixed> $wireFlexMessage
     * @param list<string> $bubbleFactIds
     */
    public function __construct(array $wireFlexMessage, array $bubbleFactIds)
    {
        $this->wireFlexMessage = $wireFlexMessage;
        $this->bubbleFactIds = array_values(array_map('strval', $bubbleFactIds));
    }

    /**
     * @return array<string, mixed>
     */
    public function getWireFlexMessage(): array
    {
        return $this->wireFlexMessage;
    }

    /**
     * @return list<string>
     */
    public function getBubbleFactIds(): array
    {
        return $this->bubbleFactIds;
    }

    public function getBubbleCount(): int
    {
        $contents = isset($this->wireFlexMessage['contents']) && is_array($this->wireFlexMessage['contents'])
            ? $this->wireFlexMessage['contents']
            : [];
        $bubbles = isset($contents['contents']) && is_array($contents['contents']) ? $contents['contents'] : [];

        return count($bubbles);
    }
}
