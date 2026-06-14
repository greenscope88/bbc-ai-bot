<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsJsonWriter.php';

/**
 * Builds tenant_private_knowledge JSON documents keyed by output filename.
 */
final class BdsKnowledgeDocumentBuilder
{
  /**
   * @param array<string, mixed> $normalized
   * @return array<string, array<string, mixed>>
   */
  public static function fromNormalized(string $tenantSno, array $normalized, ?string $sourceSheetId = null): array
  {
    $publishedAt = gmdate('Y-m-d\TH:i:s\Z');
    $documents = [];

    $profile = isset($normalized['company_profile']) && is_array($normalized['company_profile'])
      ? $normalized['company_profile']
      : [];
    $documents['company_profile.json'] = self::withOptionalSheetId([
      'schema_version' => BdsJsonWriter::SCHEMA_VERSION,
      'data_category' => BdsJsonWriter::DATA_CATEGORY,
      'tenant_sno' => $tenantSno,
      'source_tab' => 'company_profile',
      'published_at' => $publishedAt,
      'profile' => $profile,
    ], $sourceSheetId);

    $itemTabs = [
      'qa' => 'service_qa.json',
      'external_product_links' => 'external_product_links.json',
      'service_items' => 'service_items.json',
      'special_prices' => 'special_prices.json',
    ];

    foreach ($itemTabs as $tab => $filename) {
      $items = isset($normalized[$tab]) && is_array($normalized[$tab]) ? $normalized[$tab] : [];
      $documents[$filename] = self::withOptionalSheetId([
        'schema_version' => BdsJsonWriter::SCHEMA_VERSION,
        'data_category' => BdsJsonWriter::DATA_CATEGORY,
        'tenant_sno' => $tenantSno,
        'source_tab' => $tab,
        'published_at' => $publishedAt,
        'items' => $items,
      ], $sourceSheetId);
    }

    return $documents;
  }

  /**
   * @param array<string, mixed> $document
   * @return array<string, mixed>
   */
  private static function withOptionalSheetId(array $document, ?string $sourceSheetId): array
  {
    if ($sourceSheetId !== null && $sourceSheetId !== '') {
      $document['source_sheet_id'] = $sourceSheetId;
    }

    return $document;
  }

  /**
   * Applies formal sync metadata before GCS promote (upload mode or sheet mode).
   *
   * @param array<string, array<string, mixed>> $knowledgeJson
   * @param array<string, string>|null $uploadSource source_type, upload_session_id, stored_filename
   * @return array<string, array<string, mixed>>
   */
  public static function applyFormalSyncMetadata(
    array $knowledgeJson,
    string $syncId,
    string $publishedAt,
    ?array $uploadSource = null
  ): array {
    $enriched = [];

    foreach ($knowledgeJson as $filename => $document) {
      if (!is_array($document)) {
        continue;
      }

      $document['sync_id'] = $syncId;
      $document['published_at'] = $publishedAt;

      if ($uploadSource !== null) {
        if (!empty($uploadSource['source_type'])) {
          $document['source_type'] = (string) $uploadSource['source_type'];
        }
        if (!empty($uploadSource['upload_session_id'])) {
          $document['upload_session_id'] = (string) $uploadSource['upload_session_id'];
        }
        if (!empty($uploadSource['stored_filename'])) {
          $document['stored_filename'] = (string) $uploadSource['stored_filename'];
        }
      }

      $enriched[$filename] = $document;
    }

    return $enriched;
  }
}
