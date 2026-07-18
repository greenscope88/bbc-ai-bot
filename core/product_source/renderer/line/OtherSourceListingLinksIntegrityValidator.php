<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'OtherSourceListingLinksMessageRenderResult.php';

final class OtherSourceListingLinksIntegrityValidator
{
    /**
     * @param list<array<string, mixed>> $linkFacts
     */
    public function validate(OtherSourceListingLinksMessageRenderResult $result, array $linkFacts): void
    {
        $message = $result->getWireMessage();
        $text = trim((string) ($message['text'] ?? ''));
        if (($message['type'] ?? '') !== 'text' || $text === '') {
            throw new \RuntimeException('other_source_wire_message_invalid');
        }

        $expectedIds = [];
        $expectedUrls = [];
        foreach ($linkFacts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $sourceRef = trim((string) ($fact['source_ref'] ?? ''));
            if (!in_array($sourceRef, ['grp:listing', 'bbctravel:listing', 'tourcenter:listing'], true)) {
                continue;
            }
            $id = trim((string) ($fact['fact_id'] ?? ''));
            $url = trim((string) ($fact['value'] ?? ''));
            if ($id !== '') {
                $expectedIds[] = $id;
            }
            if ($url !== '') {
                $expectedUrls[] = $url;
            }
            if ($id !== '' && strpos($text, $id) !== false) {
                throw new \RuntimeException('other_source_fact_id_visible');
            }
        }

        $actualIds = $result->getLinkFactIds();
        sort($expectedIds);
        sort($actualIds);
        if ($expectedIds !== $actualIds) {
            throw new \RuntimeException('other_source_referenced_ids_mismatch');
        }

        foreach ($expectedUrls as $url) {
            if (strpos($text, $url) === false) {
                throw new \RuntimeException('other_source_url_missing');
            }
        }
    }
}
