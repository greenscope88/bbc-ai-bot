<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeIdentity.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeState.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeStateFactory.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeStateStore.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeLoadResult.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeMutationResult.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiIntentUnderstandingResult.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiIntentCategory.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiuClarificationReasonContract.php';

$failures = 0;
function assert_true(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bbc_resume_store_' . getmypid();
@mkdir($dir, 0775, true);
$store = new StructuredSearchResumeStateStore($dir);
$identity = StructuredSearchResumeIdentity::fromParts('tStore', 'OAx', 'Ustore');
$other = StructuredSearchResumeIdentity::fromParts('tStore', 'OAx', 'Uother');
$ref = new DateTimeImmutable('2026-07-16 12:00:00', new DateTimeZone('Asia/Taipei'));

function make_state(
    StructuredSearchResumeIdentity $identity,
    string $eventId,
    string $op,
    int $version,
    DateTimeImmutable $ref,
    ?string $createdAt = null,
    array $dest = ['日本']
): StructuredSearchResumeState {
    $result = new AiIntentUnderstandingResult(AiIntentCategory::PRODUCT_SEARCH);
    $result->setEntities([
        'destination' => $dest,
        'date_from' => null,
        'date_to' => null,
    ])->setClarification(true, AiuClarificationReasonContract::AIU_MISSING_TRAVEL_DATES);

    return StructuredSearchResumeStateFactory::fromValidatedClarification(
        $identity,
        $result,
        $eventId,
        $op,
        $ref,
        'tr',
        $version,
        $createdAt
    );
}

// 1 create direct rename
$s1 = make_state($identity, 'E1', StructuredSearchResumeState::OP_CREATE, 1, $ref);
$r = $store->create($identity, $s1, 'E1');
assert_true($r->getStatus() === StructuredSearchResumeMutationResult::CREATED, 'create');
assert_true(is_file($dir . DIRECTORY_SEPARATOR . $identity->getStorageKey() . '.json'), 'data file');
assert_true(is_file($dir . DIRECTORY_SEPARATOR . $identity->getStorageKey() . '.lock'), 'lock file');

// 2 replace existing final direct rename
$s2 = make_state($identity, 'E2', StructuredSearchResumeState::OP_REPLACE, 2, $ref, $s1->getCreatedAt(), ['日本', '東京']);
$r = $store->replace($identity, $s2, 'E2', 1);
assert_true($r->getStatus() === StructuredSearchResumeMutationResult::REPLACED, 'replace');
$load = $store->load($identity, $ref);
assert_true($load->isFound() && $load->getState()->getStateVersion() === 2, 'load v2');
assert_true($load->getState()->getKnownEntities()['destination'] === ['日本', '東京'], 'replace bytes');

// 3/4 no unlink-before-rename + source guard
$src = file_get_contents(dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeStateStore.php');
assert_true(strpos($src, 'unlink($final)') === false, 'no unlink(final)');
assert_true(strpos($src, 'unlink($path)') !== false, 'clear uses unlink path');
assert_true(strpos($src, 'rename($tmp, $final)') !== false, 'direct rename present');
if (PHP_OS_FAMILY === 'Windows') {
    $tmp = $dir . DIRECTORY_SEPARATOR . 'probe.tmp';
    $final = $dir . DIRECTORY_SEPARATOR . 'probe.json';
    file_put_contents($final, 'OLD');
    file_put_contents($tmp, 'NEW');
    assert_true(@rename($tmp, $final) === true, 'Windows rename over closed final');
    assert_true(file_get_contents($final) === 'NEW', 'Windows rename replaced bytes');
}

// 5 create same event replay (pending last_event_id is E2 after replace)
$r = $store->create($identity, $s2, 'E2');
assert_true($r->getStatus() === StructuredSearchResumeMutationResult::IDEMPOTENT_REPLAY, 'create replay same event');
assert_true($r->getVersion() === 2, 'replay no bump');

// 6 replace same event replay
$r = $store->replace($identity, $s2, 'E2', 2);
assert_true($r->getStatus() === StructuredSearchResumeMutationResult::IDEMPOTENT_REPLAY, 'replace replay');

// 15 stale CAS
$s3 = make_state($identity, 'E3', StructuredSearchResumeState::OP_REPLACE, 3, $ref, $s1->getCreatedAt());
$r = $store->replace($identity, $s3, 'E3', 1);
assert_true($r->getStatus() === StructuredSearchResumeMutationResult::VERSION_CONFLICT, 'stale replace');

// 7 clear correct version
$r = $store->clear($identity, 2);
assert_true($r->getStatus() === StructuredSearchResumeMutationResult::CLEARED, 'clear');
assert_true(!is_file($dir . DIRECTORY_SEPARATOR . $identity->getStorageKey() . '.json'), 'cleared absent');

// 9 clear absent
$r = $store->clear($identity, 2);
assert_true($r->getStatus() === StructuredSearchResumeMutationResult::ALREADY_ABSENT, 'clear absent');

// 19 duplicate after clear — no idempotency guarantee (fresh create allowed)
$sFresh = make_state($identity, 'E1', StructuredSearchResumeState::OP_CREATE, 1, $ref);
$r = $store->create($identity, $sFresh, 'E1');
assert_true($r->getStatus() === StructuredSearchResumeMutationResult::CREATED, 'after clear same event may create');

// 8 clear stale
$r = $store->clear($identity, 9);
assert_true($r->getStatus() === StructuredSearchResumeMutationResult::VERSION_CONFLICT, 'clear stale');

// 16 isolation
$sOther = make_state($other, 'EO', StructuredSearchResumeState::OP_CREATE, 1, $ref);
$store->create($other, $sOther, 'EO');
assert_true($store->load($identity, $ref)->isFound(), 'identity A still found');
assert_true($store->load($other, $ref)->isFound(), 'identity B found');

// 13 orphan temp ignored
file_put_contents($dir . DIRECTORY_SEPARATOR . $identity->getStorageKey() . '.json.tmp.999.abc', '{}');
assert_true($store->load($identity, $ref)->isFound(), 'orphan temp ignored');

// 10 identity mismatch
$mismatchPath = $dir . DIRECTORY_SEPARATOR . $identity->getStorageKey() . '.json';
$doc = json_decode(file_get_contents($mismatchPath), true);
$doc['user_id'] = 'U-HACKED';
file_put_contents($mismatchPath, json_encode($doc));
$load = $store->load($identity, $ref);
assert_true($load->getStatus() === StructuredSearchResumeLoadResult::IDENTITY_MISMATCH, 'identity mismatch');

// restore valid for further tests
$store->quarantineCorrupt($identity);
$sOk = make_state($identity, 'E9', StructuredSearchResumeState::OP_CREATE, 1, $ref);
$store->create($identity, $sOk, 'E9');

// 8 expired
$expiredDoc = $sOk->toArray();
$expiredDoc['expires_at'] = (new DateTimeImmutable('2020-01-01', new DateTimeZone('Asia/Taipei')))
    ->format(DateTimeInterface::ATOM);
file_put_contents($mismatchPath, json_encode($expiredDoc, JSON_UNESCAPED_UNICODE));
$load = $store->load($identity, $ref);
assert_true($load->getStatus() === StructuredSearchResumeLoadResult::EXPIRED, 'expired');
$expMut = $store->expireIfStillExpired($identity, 1, $ref);
assert_true($expMut->getStatus() === StructuredSearchResumeMutationResult::CLEARED, 'expire clear');

// 15 corrupt quarantine
file_put_contents($mismatchPath, '{not-json');
$load = $store->load($identity, $ref);
assert_true($load->getStatus() === StructuredSearchResumeLoadResult::CORRUPT, 'corrupt');
$q = $store->quarantineCorrupt($identity);
assert_true($q->isSuccess(), 'quarantine');
$corruptFiles = glob($dir . DIRECTORY_SEPARATOR . $identity->getStorageKey() . '.corrupt.*.json');
assert_true(is_array($corruptFiles) && count($corruptFiles) >= 1, 'quarantine file');

// 11 forced rename failure preserves prior — simulate by holding final open on Windows
$sHold = make_state($identity, 'EH', StructuredSearchResumeState::OP_CREATE, 1, $ref);
$store->create($identity, $sHold, 'EH');
$priorBytes = file_get_contents($mismatchPath);
if (PHP_OS_FAMILY === 'Windows') {
    $fh = fopen($mismatchPath, 'rb');
    $sHold2 = make_state($identity, 'EH2', StructuredSearchResumeState::OP_REPLACE, 2, $ref, $sHold->getCreatedAt());
    $r = $store->replace($identity, $sHold2, 'EH2', 1);
    assert_true($r->getStatus() === StructuredSearchResumeMutationResult::IO_ERROR, 'rename fail IO_ERROR');
    assert_true(file_get_contents($mismatchPath) === $priorBytes, 'prior preserved');
    fclose($fh);
}

// 17 lock remains
assert_true(is_file($dir . DIRECTORY_SEPARATOR . $identity->getStorageKey() . '.lock'), 'lock stable');

// 12 missing event id
$r = $store->create($identity, $sHold, '');
assert_true($r->getStatus() === StructuredSearchResumeMutationResult::INVALID_STATE, 'missing event id');

// cleanup best-effort
foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($dir);

if ($failures === 0) {
    echo "ALL PASS test_structured_search_resume_store\n";
    exit(0);
}
echo "FAILED {$failures}\n";
exit(1);
