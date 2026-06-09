<?php
declare(strict_types=1);

/**
 * BDS Phase 2 — Validator for normalized tenant_private_knowledge arrays.
 *
 * Validates Phase 1 parser output with whole-file fail semantics.
 * No Google API, no GCS, no JSON writer.
 *
 * @see docs/BATS_DATA_CONTRACT.md §1.4, §8
 * @see docs/BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md Phase 2
 */
final class BdsValidator
{
  /** @var list<string> */
  public const REQUIRED_BLOCKS = [
    'company_profile',
    'qa',
    'external_product_links',
    'service_items',
    'special_prices',
  ];

  /** @var list<string> */
  private const KNOWN_CHINESE_FIELD_ALIASES = [
    '問題',
    '答案',
    '公司名稱',
  ];

  /**
   * @param array<string, mixed> $normalized Phase 1 parser output.
   * @return array{
   *   ok: bool,
   *   errors: list<array{code: string, message: string, tab: ?string, row_index: ?int, field: ?string}>,
   *   warnings: list<string>,
   *   validated_at: string
   * }
   */
  public function validate(array $normalized): array
  {
    $errors = [];
    $warnings = [];

    $this->validateStructure($normalized, $errors);
    $this->validateCompanyProfile($normalized, $errors);
    $this->validateQaItems($normalized, $errors);
    $this->validateExternalProductLinks($normalized, $errors);
    $this->validateServiceItems($normalized, $errors);
    $this->validateSpecialPrices($normalized, $errors);

    return [
      'ok' => count($errors) === 0,
      'errors' => $errors,
      'warnings' => $warnings,
      'validated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
  }

  /**
   * @param list<array{code: string, message: string, tab: ?string, row_index: ?int, field: ?string}> $errors
   * @param array<string, mixed> $normalized
   */
  private function validateStructure(array $normalized, array &$errors): void
  {
    foreach (self::REQUIRED_BLOCKS as $block) {
      if (!array_key_exists($block, $normalized)) {
        $this->addError(
          $errors,
          'BDC_TAB_MISSING',
          'Missing required data block: ' . $block,
          $block,
          null,
          null
        );
      }
    }
  }

  /**
   * @param list<array{code: string, message: string, tab: ?string, row_index: ?int, field: ?string}> $errors
   * @param array<string, mixed> $normalized
   */
  private function validateCompanyProfile(array $normalized, array &$errors): void
  {
    if (!isset($normalized['company_profile']) || !is_array($normalized['company_profile'])) {
      return;
    }

    $profile = $normalized['company_profile'];

    foreach (array_keys($profile) as $field) {
      if (!is_string($field)) {
        continue;
      }
      $this->validateEnglishFieldName($errors, 'company_profile', null, $field);
    }

    if (!$this->hasNonEmptyString($profile, 'company_name')) {
      $this->addError(
        $errors,
        'BDC_REQUIRED_FIELD_MISSING',
        'company_profile.company_name is required',
        'company_profile',
        null,
        'company_name'
      );
    }
  }

  /**
   * @param list<array{code: string, message: string, tab: ?string, row_index: ?int, field: ?string}> $errors
   * @param array<string, mixed> $normalized
   */
  private function validateQaItems(array $normalized, array &$errors): void
  {
    if (!isset($normalized['qa']) || !is_array($normalized['qa'])) {
      return;
    }

    $this->validateItemRows(
      $errors,
      'qa',
      $normalized['qa'],
      ['question', 'answer']
    );
  }

  /**
   * @param list<array{code: string, message: string, tab: ?string, row_index: ?int, field: ?string}> $errors
   * @param array<string, mixed> $normalized
   */
  private function validateExternalProductLinks(array $normalized, array &$errors): void
  {
    if (!isset($normalized['external_product_links']) || !is_array($normalized['external_product_links'])) {
      return;
    }

    $this->validateItemRows(
      $errors,
      'external_product_links',
      $normalized['external_product_links'],
      ['name', 'url']
    );
  }

  /**
   * @param list<array{code: string, message: string, tab: ?string, row_index: ?int, field: ?string}> $errors
   * @param array<string, mixed> $normalized
   */
  private function validateServiceItems(array $normalized, array &$errors): void
  {
    if (!isset($normalized['service_items']) || !is_array($normalized['service_items'])) {
      return;
    }

    $this->validateItemRows(
      $errors,
      'service_items',
      $normalized['service_items'],
      ['service_id', 'name']
    );
  }

  /**
   * @param list<array{code: string, message: string, tab: ?string, row_index: ?int, field: ?string}> $errors
   * @param array<string, mixed> $normalized
   */
  private function validateSpecialPrices(array $normalized, array &$errors): void
  {
    if (!isset($normalized['special_prices']) || !is_array($normalized['special_prices'])) {
      return;
    }

    $this->validateItemRows(
      $errors,
      'special_prices',
      $normalized['special_prices'],
      ['item_name', 'price_amount']
    );
  }

  /**
   * @param list<array{code: string, message: string, tab: ?string, row_index: ?int, field: ?string}> $errors
   * @param list<mixed> $items
   * @param list<string> $requiredFields
   */
  private function validateItemRows(array &$errors, string $tab, array $items, array $requiredFields): void
  {
    foreach ($items as $rowIndex => $item) {
      if (!is_array($item)) {
        $this->addError(
          $errors,
          'BDC_INVALID_TYPE',
          $tab . ' item must be an object',
          $tab,
          is_int($rowIndex) ? $rowIndex : null,
          null
        );
        continue;
      }

      foreach (array_keys($item) as $field) {
        if (!is_string($field)) {
          continue;
        }
        $this->validateEnglishFieldName($errors, $tab, is_int($rowIndex) ? $rowIndex : null, $field);
      }

      foreach ($requiredFields as $field) {
        if ($field === 'price_amount') {
          if (!$this->hasValidPriceAmount($item, 'price_amount')) {
            $this->addError(
              $errors,
              'BDC_REQUIRED_FIELD_MISSING',
              $tab . '.' . $field . ' is required and must be a number >= 0',
              $tab,
              is_int($rowIndex) ? $rowIndex : null,
              $field
            );
          }
          continue;
        }

        if (!$this->hasNonEmptyString($item, $field)) {
          $this->addError(
            $errors,
            'BDC_REQUIRED_FIELD_MISSING',
            $tab . '.' . $field . ' is required',
            $tab,
            is_int($rowIndex) ? $rowIndex : null,
            $field
          );
        }
      }
    }
  }

  /**
   * @param list<array{code: string, message: string, tab: ?string, row_index: ?int, field: ?string}> $errors
   */
  private function validateEnglishFieldName(array &$errors, string $tab, ?int $rowIndex, string $field): void
  {
    if ($this->isEnglishFieldName($field)) {
      return;
    }

    $this->addError(
      $errors,
      'BDC_INVALID_FIELD_NAME',
      'Field name must be English snake_case in v1: ' . $field,
      $tab,
      $rowIndex,
      $field
    );
  }

  private function isEnglishFieldName(string $field): bool
  {
    if ($field === '') {
      return false;
    }

    if (in_array($field, self::KNOWN_CHINESE_FIELD_ALIASES, true)) {
      return false;
    }

    if (preg_match('/\p{Han}/u', $field) === 1) {
      return false;
    }

    return preg_match('/^[a-z][a-z0-9_]*$/', $field) === 1;
  }

  /**
   * @param array<string, mixed> $row
   */
  private function hasNonEmptyString(array $row, string $field): bool
  {
    if (!array_key_exists($field, $row)) {
      return false;
    }

    $value = $row[$field];
    if ($value === null) {
      return false;
    }

    if (is_string($value)) {
      return trim($value) !== '';
    }

    if (is_int($value) || is_float($value)) {
      return true;
    }

    return false;
  }

  /**
   * @param array<string, mixed> $row
   */
  private function hasValidPriceAmount(array $row, string $field): bool
  {
    if (!array_key_exists($field, $row)) {
      return false;
    }

    $value = $row[$field];
    if (is_int($value) || is_float($value)) {
      return $value >= 0;
    }

    if (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
      return (float) trim($value) >= 0;
    }

    return false;
  }

  /**
   * @param list<array{code: string, message: string, tab: ?string, row_index: ?int, field: ?string}> $errors
   */
  private function addError(array &$errors, string $code, string $message, ?string $tab, ?int $rowIndex, ?string $field): void
  {
    $errors[] = [
      'code' => $code,
      'message' => $message,
      'tab' => $tab,
      'row_index' => $rowIndex,
      'field' => $field,
    ];
  }
}
