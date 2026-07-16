<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeIdentity.php';

$failures = 0;
function assert_true(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$a = StructuredSearchResumeIdentity::fromParts('tenantA', 'OA1', 'Uuser1');
$a2 = StructuredSearchResumeIdentity::fromParts('tenantA', 'OA1', 'Uuser1');
assert_true(
    $a->getConversationId() === 'tenantA:line:OA1:Uuser1',
    'canonical identity format'
);
assert_true($a->getStorageKey() === $a2->getStorageKey(), 'SHA-256 deterministic');
assert_true(
    $a->getStorageKey() === hash('sha256', 'tenantA:line:OA1:Uuser1'),
    'SHA-256 matches hash()'
);
assert_true(preg_match('/^[a-f0-9]{64}$/', $a->getStorageKey()) === 1, 'lowercase hex');

$b = StructuredSearchResumeIdentity::fromParts('tenantB', 'OA1', 'Uuser1');
$c = StructuredSearchResumeIdentity::fromParts('tenantA', 'OA2', 'Uuser1');
$d = StructuredSearchResumeIdentity::fromParts('tenantA', 'OA1', 'Uuser2');
assert_true($a->getStorageKey() !== $b->getStorageKey(), 'tenant isolation');
assert_true($a->getStorageKey() !== $c->getStorageKey(), 'channel isolation');
assert_true($a->getStorageKey() !== $d->getStorageKey(), 'user isolation');

$doc = $a->toArray();
assert_true($a->matchesDocument($doc), 'document matches identity');
$doc['channel_id'] = 'OTHER';
assert_true(!$a->matchesDocument($doc), 'document mismatch detected');

if ($failures === 0) {
    echo "ALL PASS test_structured_search_resume_identity\n";
    exit(0);
}
echo "FAILED {$failures}\n";
exit(1);
