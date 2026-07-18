<?php
declare(strict_types=1);

final class GroundedListingLinkFactsValidator
{
    /** @var array<string, string> */
    private const ALLOWED = [
        'link:bbcshops:search' => 'bbcshops:search',
        'link:bbcshops:listing' => 'bbcshops:listing',
        'link:grp:listing' => 'grp:listing',
        'link:bbctravel:listing' => 'bbctravel:listing',
        'link:tourcenter:listing' => 'tourcenter:listing',
    ];

    /**
     * @param list<array<string, mixed>> $facts
     */
    public function validate(array $facts): void
    {
        $seen = [];
        $hasBbcshopsSearch = false;
        $hasBbcshopsListing = false;
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                throw new \RuntimeException('listing_link_fact_not_array');
            }
            $id = trim((string) ($fact['fact_id'] ?? ''));
            if (!isset(self::ALLOWED[$id])) {
                throw new \RuntimeException('listing_link_fact_id_invalid');
            }
            if (isset($seen[$id])) {
                throw new \RuntimeException('listing_link_fact_id_duplicate');
            }
            if (($fact['fact_type'] ?? '') !== 'link') {
                throw new \RuntimeException('listing_link_fact_type_invalid');
            }
            $value = trim((string) ($fact['value'] ?? ''));
            if ($value === '' || preg_match('#^https?://#i', $value) !== 1) {
                throw new \RuntimeException('listing_link_fact_value_invalid');
            }
            if (trim((string) ($fact['source_ref'] ?? '')) !== self::ALLOWED[$id]) {
                throw new \RuntimeException('listing_link_fact_source_ref_invalid');
            }
            if ($id === 'link:bbcshops:search') {
                $hasBbcshopsSearch = true;
            }
            if ($id === 'link:bbcshops:listing') {
                $hasBbcshopsListing = true;
            }
            $seen[$id] = true;
        }
        if ($hasBbcshopsSearch && $hasBbcshopsListing) {
            throw new \RuntimeException('bbcshops_search_listing_mutually_exclusive');
        }
    }
}
