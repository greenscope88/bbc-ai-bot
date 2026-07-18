<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'PublishedProductSet.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'line'
    . DIRECTORY_SEPARATOR . 'BbcshopsFlexCarouselRenderResult.php';

final class PublicationIntegrityValidator
{
    /**
     * @param list<array<string, mixed>> $productFacts
     * @param list<string> $referencedProductFactIds
     */
    public function validate(
        PublishedProductSet $set,
        array $productFacts,
        BbcshopsFlexCarouselRenderResult $renderResult,
        array $referencedProductFactIds
    ): void {
        $products = $set->getProducts();
        $count = count($products);
        $bubbleFactIds = $renderResult->getBubbleFactIds();

        if ($count !== count($productFacts)) {
            throw new \RuntimeException('publication_product_fact_count_mismatch');
        }
        if ($count !== $renderResult->getBubbleCount()) {
            throw new \RuntimeException('publication_bubble_count_mismatch');
        }
        if ($count !== count($bubbleFactIds)) {
            throw new \RuntimeException('publication_bubble_fact_id_count_mismatch');
        }
        if ($count !== count($referencedProductFactIds)) {
            throw new \RuntimeException('publication_referenced_product_fact_count_mismatch');
        }

        $expected = [];
        foreach ($products as $product) {
            $factId = isset($product['fact_id']) ? trim((string) $product['fact_id']) : '';
            if ($factId === '') {
                throw new \RuntimeException('publication_product_missing_fact_id');
            }
            if (isset($expected[$factId])) {
                throw new \RuntimeException('publication_duplicate_product_fact_id');
            }
            $expected[$factId] = true;
        }

        $this->assertSameFactIdSet(array_keys($expected), $this->factIdsFromFacts($productFacts), 'publication_orphan_product_fact');
        $this->assertSameFactIdSet(array_keys($expected), $bubbleFactIds, 'publication_orphan_bubble');
        $this->assertSameFactIdSet(array_keys($expected), $referencedProductFactIds, 'publication_orphan_reference');
    }

    /**
     * @param list<array<string, mixed>> $facts
     * @return list<string>
     */
    private function factIdsFromFacts(array $facts): array
    {
        $ids = [];
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $id = isset($fact['fact_id']) ? trim((string) $fact['fact_id']) : '';
            if ($id === '' || isset($ids[$id])) {
                if ($id !== '') {
                    throw new \RuntimeException('publication_duplicate_fact_id');
                }
                throw new \RuntimeException('publication_fact_missing_id');
            }
            $ids[$id] = true;
        }

        return array_keys($ids);
    }

    /**
     * @param list<string> $expected
     * @param list<string> $actual
     */
    private function assertSameFactIdSet(array $expected, array $actual, string $error): void
    {
        sort($expected);
        sort($actual);
        if ($expected !== $actual) {
            throw new \RuntimeException($error);
        }
    }
}
