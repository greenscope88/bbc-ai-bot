<?php
declare(strict_types=1);

/**
 * BDS Phase 2 — BdsValidator minimal tests.
 *
 * @see docs/BATS_DATA_SYNC_TEST_PLAN.md T-02, T-03, T-04
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsMockSheetParser.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsValidator.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
  global $failures;
  if (!$cond) {
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
  }
}

function build_valid_normalized(): array
{
  $parser = new BdsMockSheetParser();

  return $parser->parse('validator_tenant_001', [
    'company_profile' => [
      ['company_name' => 'Validator Travel Co', 'phone' => '02-9999-8888'],
    ],
    'qa' => [
      ['question' => 'How to contact us?', 'answer' => 'Call our hotline.'],
    ],
    'external_product_links' => [
      ['name' => 'Partner Portal', 'url' => 'https://example.com/partner'],
    ],
    'service_items' => [
      ['service_id' => 'svc_passport', 'name' => 'Passport Service'],
    ],
    'special_prices' => [
      ['item_name' => 'Passport Service', 'price_amount' => 1500],
    ],
  ]);
}

function has_error_code(array $result, string $code, ?string $tab = null, ?string $field = null): bool
{
  if (!isset($result['errors']) || !is_array($result['errors'])) {
    return false;
  }

  foreach ($result['errors'] as $error) {
    if (!is_array($error) || !isset($error['code']) || $error['code'] !== $code) {
      continue;
    }
    if ($tab !== null && (!isset($error['tab']) || $error['tab'] !== $tab)) {
      continue;
    }
    if ($field !== null && (!isset($error['field']) || $error['field'] !== $field)) {
      continue;
    }
    return true;
  }

  return false;
}

$validator = new BdsValidator();

$valid = $validator->validate(build_valid_normalized());
test_assert($valid['ok'] === true, 'valid 5 tabs pass');
test_assert(isset($valid['validated_at']) && is_string($valid['validated_at']) && $valid['validated_at'] !== '', 'validated_at present');
test_assert(isset($valid['errors']) && is_array($valid['errors']) && count($valid['errors']) === 0, 'valid result has no errors');

$missingCompanyProfile = build_valid_normalized();
unset($missingCompanyProfile['company_profile']);
$missingCompanyProfileResult = $validator->validate($missingCompanyProfile);
test_assert($missingCompanyProfileResult['ok'] === false, 'missing company_profile fails');
test_assert(has_error_code($missingCompanyProfileResult, 'BDC_TAB_MISSING', 'company_profile'), 'missing company_profile reports BDC_TAB_MISSING');

$missingQuestion = build_valid_normalized();
$missingQuestion['qa'][0]['question'] = '';
$missingQuestionResult = $validator->validate($missingQuestion);
test_assert($missingQuestionResult['ok'] === false, 'missing qa.question fails');
test_assert(has_error_code($missingQuestionResult, 'BDC_REQUIRED_FIELD_MISSING', 'qa', 'question'), 'missing qa.question error code');

$missingAnswer = build_valid_normalized();
unset($missingAnswer['qa'][0]['answer']);
$missingAnswerResult = $validator->validate($missingAnswer);
test_assert($missingAnswerResult['ok'] === false, 'missing qa.answer fails');
test_assert(has_error_code($missingAnswerResult, 'BDC_REQUIRED_FIELD_MISSING', 'qa', 'answer'), 'missing qa.answer error code');

$missingUrl = build_valid_normalized();
unset($missingUrl['external_product_links'][0]['url']);
$missingUrlResult = $validator->validate($missingUrl);
test_assert($missingUrlResult['ok'] === false, 'missing external_product_links.url fails');
test_assert(has_error_code($missingUrlResult, 'BDC_REQUIRED_FIELD_MISSING', 'external_product_links', 'url'), 'missing url error code');

$missingServiceId = build_valid_normalized();
unset($missingServiceId['service_items'][0]['service_id']);
$missingServiceIdResult = $validator->validate($missingServiceId);
test_assert($missingServiceIdResult['ok'] === false, 'missing service_items.service_id fails');
test_assert(has_error_code($missingServiceIdResult, 'BDC_REQUIRED_FIELD_MISSING', 'service_items', 'service_id'), 'missing service_id error code');

$missingPriceAmount = build_valid_normalized();
unset($missingPriceAmount['special_prices'][0]['price_amount']);
$missingPriceAmountResult = $validator->validate($missingPriceAmount);
test_assert($missingPriceAmountResult['ok'] === false, 'missing special_prices.price_amount fails');
test_assert(has_error_code($missingPriceAmountResult, 'BDC_REQUIRED_FIELD_MISSING', 'special_prices', 'price_amount'), 'missing price_amount error code');

$chineseField = build_valid_normalized();
$chineseField['qa'][0]['問題'] = '中文欄位';
$chineseFieldResult = $validator->validate($chineseField);
test_assert($chineseFieldResult['ok'] === false, 'chinese field name fails');
test_assert(has_error_code($chineseFieldResult, 'BDC_INVALID_FIELD_NAME', 'qa', '問題'), 'chinese field reports BDC_INVALID_FIELD_NAME');
test_assert(count($chineseFieldResult['errors']) > 0, 'fail result has non-empty errors');

$emptyCompanyName = build_valid_normalized();
$emptyCompanyName['company_profile'] = ['summary' => 'No legal name'];
$emptyCompanyNameResult = $validator->validate($emptyCompanyName);
test_assert($emptyCompanyNameResult['ok'] === false, 'missing company_name fails');
test_assert(has_error_code($emptyCompanyNameResult, 'BDC_REQUIRED_FIELD_MISSING', 'company_profile', 'company_name'), 'missing company_name error code');

$otherTenant = build_valid_normalized();
$otherTenant['tenant_sno'] = 'hotel_demo_7788';
$otherTenantResult = $validator->validate($otherTenant);
test_assert($otherTenantResult['ok'] === true, 'validator works for non-travel_b tenant data');

$validatorSource = file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsValidator.php');
test_assert(is_string($validatorSource), 'validator source readable for hardcode check');
test_assert(strpos($validatorSource, 'travel_b') === false, 'validator does not hardcode travel_b');
test_assert(strpos($validatorSource, '5f99b8d665e8444d') === false, 'validator does not hardcode pilot sno');

if ($failures === 0) {
  fwrite(STDOUT, "OK: test_bds_validator (all passed)\n");
  exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
