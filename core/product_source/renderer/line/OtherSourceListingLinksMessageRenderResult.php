<?php
declare(strict_types=1);

final class OtherSourceListingLinksMessageRenderResult
{
    /** @var array<string, mixed> */
    private array $wireMessage;

    /** @var list<string> */
    private array $linkFactIds;

    /**
     * @param array<string, mixed> $wireMessage
     * @param list<string> $linkFactIds
     */
    public function __construct(array $wireMessage, array $linkFactIds)
    {
        $this->wireMessage = $wireMessage;
        $this->linkFactIds = array_values(array_map('strval', $linkFactIds));
    }

    /**
     * @return array<string, mixed>
     */
    public function getWireMessage(): array
    {
        return $this->wireMessage;
    }

    /**
     * @return list<string>
     */
    public function getLinkFactIds(): array
    {
        return $this->linkFactIds;
    }
}
