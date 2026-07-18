<?php
declare(strict_types=1);

final class PublishedProductSet
{
    /** @var list<array<string, mixed>> */
    private array $products;

    /**
     * @param list<array<string, mixed>> $products
     */
    public function __construct(array $products)
    {
        $normalized = [];
        foreach ($products as $product) {
            if (is_array($product)) {
                $normalized[] = $product;
            }
        }
        $this->products = $normalized;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getProducts(): array
    {
        return $this->products;
    }

    public function getCount(): int
    {
        return count($this->products);
    }
}
