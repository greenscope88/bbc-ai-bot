<?php
declare(strict_types=1);

/**
 * BDS Phase 1 — Local Mock Sheet Parser.
 *
 * Parses in-memory tab arrays that simulate Google Sheet rows into a normalized
 * tenant_private_knowledge structure. No Google API, no GCS, no validation.
 *
 * @see docs/BATS_DATA_CONTRACT.md
 * @see docs/BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md Phase 1
 */
final class BdsMockSheetParser
{
  /** @var list<string> */
  public const REQUIRED_TABS = [
    'company_profile',
    'qa',
    'external_product_links',
    'service_items',
    'special_prices',
  ];

  /**
   * @param string $tenantSno Registry tenant sno (caller-supplied; not hardcoded).
   * @param array<string, list<array<string, mixed>>> $tabs Mock sheet tabs keyed by tab name.
   * @return array{
   *   tenant_sno: string,
   *   company_profile: array<string, mixed>,
   *   qa: list<array<string, mixed>>,
   *   external_product_links: list<array<string, mixed>>,
   *   service_items: list<array<string, mixed>>,
   *   special_prices: list<array<string, mixed>>
   * }
   */
  public function parse(string $tenantSno, array $tabs): array
  {
    return [
      'tenant_sno' => $tenantSno,
      'company_profile' => $this->parseCompanyProfile(isset($tabs['company_profile']) ? $tabs['company_profile'] : []),
      'qa' => $this->parseQaItems(isset($tabs['qa']) ? $tabs['qa'] : []),
      'external_product_links' => $this->parseExternalProductLinkItems(isset($tabs['external_product_links']) ? $tabs['external_product_links'] : []),
      'service_items' => $this->parseServiceItems(isset($tabs['service_items']) ? $tabs['service_items'] : []),
      'special_prices' => $this->parseSpecialPrices(isset($tabs['special_prices']) ? $tabs['special_prices'] : []),
    ];
  }

  /**
   * @param list<array<string, mixed>> $rows
   * @return array<string, mixed>
   */
  private function parseCompanyProfile(array $rows): array
  {
    $profile = [];

    foreach ($rows as $row) {
      if (!$this->isNonEmptyRow($row)) {
        continue;
      }

      if ($this->isKeyValueRow($row)) {
        $key = $this->stringValue($row, 'key');
        if ($key === '' && isset($row['field'])) {
          $key = $this->stringValue($row, 'field');
        }
        $value = isset($row['value']) ? $row['value'] : '';
        if ($key !== '') {
          $profile[$key] = $this->normalizeScalar($value);
        }
        continue;
      }

      foreach ($row as $field => $value) {
        if (!is_string($field) || $field === '') {
          continue;
        }
        $profile[$field] = $this->normalizeScalar($value);
      }
    }

    return $profile;
  }

  /**
   * @param list<array<string, mixed>> $rows
   * @return list<array<string, mixed>>
   */
  private function parseQaItems(array $rows): array
  {
    $items = [];
    $rowNumber = 0;

    foreach ($rows as $row) {
      ++$rowNumber;
      if (!$this->isNonEmptyRow($row)) {
        continue;
      }

      $item = $this->normalizeItemRow($row, ['question', 'answer']);
      if ($item === null) {
        continue;
      }

      if (!isset($item['qa_id']) || $this->stringValue($item, 'qa_id') === '') {
        $item['qa_id'] = 'qa_' . $rowNumber;
      }

      $items[] = $item;
    }

    return $items;
  }

  /**
   * @param list<array<string, mixed>> $rows
   * @return list<array<string, mixed>>
   */
  private function parseExternalProductLinkItems(array $rows): array
  {
    $items = [];
    $rowNumber = 0;

    foreach ($rows as $row) {
      ++$rowNumber;
      if (!$this->isNonEmptyRow($row)) {
        continue;
      }

      $item = $this->normalizeItemRow($row, ['name', 'url']);
      if ($item === null) {
        continue;
      }

      if (!isset($item['link_id']) || $this->stringValue($item, 'link_id') === '') {
        $item['link_id'] = 'link_' . $rowNumber;
      }

      $items[] = $item;
    }

    return $items;
  }

  /**
   * @param list<array<string, mixed>> $rows
   * @return list<array<string, mixed>>
   */
  private function parseServiceItems(array $rows): array
  {
    $items = [];
    $rowNumber = 0;

    foreach ($rows as $row) {
      ++$rowNumber;
      if (!$this->isNonEmptyRow($row)) {
        continue;
      }

      $item = $this->normalizeItemRow($row, ['name']);
      if ($item === null) {
        continue;
      }

      if (!isset($item['service_id']) || $this->stringValue($item, 'service_id') === '') {
        $item['service_id'] = 'svc_' . $rowNumber;
      }

      $items[] = $item;
    }

    return $items;
  }

  /**
   * @param list<array<string, mixed>> $rows
   * @return list<array<string, mixed>>
   */
  private function parseSpecialPrices(array $rows): array
  {
    $items = [];
    $rowNumber = 0;

    foreach ($rows as $row) {
      ++$rowNumber;
      if (!$this->isNonEmptyRow($row)) {
        continue;
      }

      $item = $this->normalizeItemRow($row, ['item_name', 'price_amount']);
      if ($item === null) {
        continue;
      }

      if (!isset($item['price_id']) || $this->stringValue($item, 'price_id') === '') {
        $item['price_id'] = 'price_' . $rowNumber;
      }

      if (isset($item['price_amount'])) {
        $item['price_amount'] = $this->normalizeNumber($item['price_amount']);
      }

      $items[] = $item;
    }

    return $items;
  }

  /**
   * @param array<string, mixed> $row
   * @param list<string> $requiredFields
   * @return array<string, mixed>|null
   */
  private function normalizeItemRow(array $row, array $requiredFields): ?array
  {
    $item = [];

    foreach ($row as $field => $value) {
      if (!is_string($field) || $field === '') {
        continue;
      }
      $item[$field] = $this->normalizeFieldValue($field, $value);
    }

    foreach ($requiredFields as $field) {
      if (!isset($item[$field]) || $this->isEmptyValue($item[$field])) {
        return null;
      }
    }

    return $item;
  }

  /**
   * @param array<string, mixed> $row
   */
  private function isKeyValueRow(array $row): bool
  {
    $hasKey = isset($row['key']) || isset($row['field']);
    return $hasKey && array_key_exists('value', $row);
  }

  /**
   * @param array<string, mixed> $row
   */
  private function isNonEmptyRow(array $row): bool
  {
    foreach ($row as $value) {
      if (!$this->isEmptyValue($value)) {
        return true;
      }
    }

    return false;
  }

  /**
   * @param mixed $value
   */
  private function isEmptyValue($value): bool
  {
    if ($value === null) {
      return true;
    }

    if (is_string($value) && trim($value) === '') {
      return true;
    }

    return false;
  }

  /**
   * @param array<string, mixed> $row
   */
  private function stringValue(array $row, string $field): string
  {
    if (!isset($row[$field])) {
      return '';
    }

    return trim((string) $row[$field]);
  }

  /**
   * @param mixed $value
   * @return mixed
   */
  private function normalizeScalar($value)
  {
    if (is_bool($value) || is_int($value) || is_float($value)) {
      return $value;
    }

    if (!is_string($value)) {
      return $value;
    }

    $trimmed = trim($value);
    if ($trimmed === 'true' || $trimmed === '1') {
      return true;
    }
    if ($trimmed === 'false' || $trimmed === '0') {
      return false;
    }

    if (is_numeric($trimmed)) {
      return strpos($trimmed, '.') !== false ? (float) $trimmed : (int) $trimmed;
    }

    return $trimmed;
  }

  /**
   * @param mixed $value
   * @return mixed
   */
  private function normalizeFieldValue(string $field, $value)
  {
    if ($field === 'enabled') {
      return $this->normalizeBoolean($value, true);
    }

    if ($field === 'sort_order') {
      return $this->normalizeInteger($value, 0);
    }

    if ($field === 'price_amount') {
      return $this->normalizeNumber($value);
    }

    return $this->normalizeScalar($value);
  }

  /**
   * @param mixed $value
   */
  private function normalizeBoolean($value, bool $default): bool
  {
    if (is_bool($value)) {
      return $value;
    }

    if (is_int($value) || is_float($value)) {
      return (bool) $value;
    }

    if (!is_string($value)) {
      return $default;
    }

    $normalized = strtolower(trim($value));
    if ($normalized === 'true' || $normalized === '1' || $normalized === 'yes') {
      return true;
    }
    if ($normalized === 'false' || $normalized === '0' || $normalized === 'no') {
      return false;
    }

    return $default;
  }

  /**
   * @param mixed $value
   */
  private function normalizeInteger($value, int $default): int
  {
    if (is_int($value)) {
      return $value;
    }

    if (is_float($value)) {
      return (int) $value;
    }

    if (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
      return (int) trim($value);
    }

    return $default;
  }

  /**
   * @param mixed $value
   */
  private function normalizeNumber($value): float
  {
    if (is_int($value) || is_float($value)) {
      return (float) $value;
    }

    if (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
      return (float) trim($value);
    }

    return 0.0;
  }
}
