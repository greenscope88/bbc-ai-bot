<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'clarification' . DIRECTORY_SEPARATOR . 'ClarificationContract.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'clarification' . DIRECTORY_SEPARATOR . 'ClarificationContractFactory.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'clarification' . DIRECTORY_SEPARATOR . 'ClarificationOutputValidator.php';

$failures = 0;

function validator_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$factory = new ClarificationContractFactory();
$validator = new ClarificationOutputValidator();

$contract = $factory->create([
    'clarification_reason' => ClarificationContract::REASON_DESTINATION_UNKNOWN,
    'known_entities' => [
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
        'departure' => '台北',
    ],
]);

function validBase(ClarificationContract $contract, string $replyText, array $ack = []): array
{
    return [
        'schema_version' => 1,
        'reply_type' => 'clarification',
        'clarification_reason' => $contract->getClarificationReason(),
        'asked_entity' => $contract->getMissingEntity(),
        'reply_text' => $replyText,
        'acknowledged_entities' => $ack,
        'search_claimed' => false,
        'product_facts_used' => false,
    ];
}

// Variable valid replies (wording not asserted)
$v1 = $validator->validate(
    validBase($contract, '方便告訴我想去哪個目的地嗎？出發地台北、八月都能幫您看。', [
        'departure' => '台北',
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
    ]),
    $contract
);
validator_assert($v1['ok'] === true, 'valid reply 1 ok');
validator_assert($v1['used_facts_count'] === 3, 'valid reply 1 used_facts_count');
validator_assert(count($v1['referenced_fact_ids']) === 3, 'valid reply 1 referenced ids');

$v2 = $validator->validate(
    validBase($contract, '想先確認行程要往哪個城市呢？', ['departure' => '台北']),
    $contract
);
validator_assert($v2['ok'] === true, 'valid reply 2 ok');
validator_assert($v2['used_facts_count'] === 1, 'valid reply 2 partial ack');

$v0 = $validator->validate(validBase($contract, '想請問目的地是哪裡呢？', []), $contract);
validator_assert($v0['ok'] === true, 'valid zero ack ok');
validator_assert($v0['used_facts_count'] === 0, 'valid zero ack count');
validator_assert($v0['grounded'] === false, 'valid zero ack grounded false');

// Wrong asked entity
$badAsk = validBase($contract, '請問要哪天出發？', ['departure' => '台北']);
$badAsk['asked_entity'] = 'date';
$rBadAsk = $validator->validate($badAsk, $contract);
validator_assert($rBadAsk['ok'] === false, 'wrong asked entity rejected');

// Invalid acknowledged entities
$badAck = validBase($contract, '目的地呢？', ['destination' => '東京']);
$rBadAck = $validator->validate($badAck, $contract);
validator_assert($rBadAck['ok'] === false, 'invented ack rejected');

$badAckValue = validBase($contract, '目的地呢？', ['departure' => '台中']);
$rBadAckValue = $validator->validate($badAckValue, $contract);
validator_assert($rBadAckValue['ok'] === false, 'ack value mismatch rejected');

// False search / product claims
$searchClaim = validBase($contract, '目的地呢？', []);
$searchClaim['search_claimed'] = true;
validator_assert($validator->validate($searchClaim, $contract)['ok'] === false, 'search_claimed true rejected');

$productClaim = validBase($contract, '目的地呢？', []);
$productClaim['product_facts_used'] = true;
validator_assert($validator->validate($productClaim, $contract)['ok'] === false, 'product_facts_used true rejected');

// Malformed / empty / URL
$malformed = validBase($contract, '目的地呢？', []);
unset($malformed['reply_type']);
validator_assert($validator->validate($malformed, $contract)['ok'] === false, 'missing field rejected');

$empty = validBase($contract, '   ', []);
validator_assert($validator->validate($empty, $contract)['ok'] === false, 'empty reply rejected');

$urlLeak = validBase($contract, '請看 https://example.com/p/1 再告訴我目的地', []);
validator_assert($validator->validate($urlLeak, $contract)['ok'] === false, 'URL leakage rejected');

// date_required path + destination subset ack
$dateContract = $factory->create([
    'clarification_reason' => ClarificationContract::REASON_DATE_REQUIRED,
    'known_entities' => [
        'departure' => '台北',
        'destination' => ['大阪', '京都'],
    ],
]);
$dateOk = $validator->validate(
    validBase($dateContract, '想請問大概哪一段時間出發比較適合？', [
        'destination' => ['大阪'],
        'departure' => '台北',
    ]),
    $dateContract
);
validator_assert($dateOk['ok'] === true, 'date reason valid');
validator_assert($dateOk['used_facts_count'] === 2, 'date reason used facts (subset dest)');
validator_assert(
    in_array('known.destination.0', $dateOk['referenced_fact_ids'], true),
    'date reason references destination.0'
);
validator_assert(
    !in_array('known.destination.1', $dateOk['referenced_fact_ids'], true),
    'date reason does not reference unused destination.1'
);

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_clarification_output_validator (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
