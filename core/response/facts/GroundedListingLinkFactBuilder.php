<?php
declare(strict_types=1);

final class GroundedListingLinkFactBuilder
{
    /** @var array<string, string> */
    private const BBCSHOPS_IDS = [
        'search' => 'link:bbcshops:search',
        'listing' => 'link:bbcshops:listing',
    ];

    /** @var array<string, string> */
    private const OTHER_IDS = [
        'grp' => 'link:grp:listing',
        'bbctravel' => 'link:bbctravel:listing',
        'tourcenter' => 'link:tourcenter:listing',
    ];

    /**
     * @param array{url: string, role: string}|null $bbcshopsLink
     * @param list<array{platform: string, url: string}> $otherSourceLinks
     * @return list<array<string, mixed>>
     */
    public function build(?array $bbcshopsLink, array $otherSourceLinks): array
    {
        $facts = [];
        $seen = [];

        if ($bbcshopsLink !== null) {
            $role = trim((string) ($bbcshopsLink['role'] ?? ''));
            if (!isset(self::BBCSHOPS_IDS[$role])) {
                throw new \RuntimeException('bbcshops_link_role_invalid');
            }
            $this->appendFact($facts, $seen, self::BBCSHOPS_IDS[$role], (string) $bbcshopsLink['url'], 'bbcshops:' . $role);
        }

        foreach ($otherSourceLinks as $link) {
            if (!is_array($link)) {
                continue;
            }
            $platform = trim((string) ($link['platform'] ?? ''));
            if (!isset(self::OTHER_IDS[$platform])) {
                throw new \RuntimeException('other_source_link_platform_invalid');
            }
            $this->appendFact($facts, $seen, self::OTHER_IDS[$platform], (string) ($link['url'] ?? ''), $platform . ':listing');
        }

        return $facts;
    }

    /**
     * @param list<array<string, mixed>> $facts
     * @param array<string, true> $seen
     */
    private function appendFact(array &$facts, array &$seen, string $factId, string $url, string $sourceRef): void
    {
        if (isset($seen[$factId])) {
            throw new \RuntimeException('listing_link_fact_duplicate');
        }
        $url = trim($url);
        if ($url === '') {
            throw new \RuntimeException('listing_link_fact_url_missing');
        }
        $facts[] = [
            'fact_id' => $factId,
            'fact_type' => 'link',
            'value' => $url,
            'source_ref' => $sourceRef,
        ];
        $seen[$factId] = true;
    }
}
