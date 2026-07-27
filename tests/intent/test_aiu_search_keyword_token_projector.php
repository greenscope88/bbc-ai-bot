<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/intent/AiuSearchKeywordTokenProjector.php';
require_once $root . '/core/intent/AiuSearchKeywordTokenException.php';
require_once $root . '/core/intent/AiuSemanticJsonNormalizer.php';

$failures = 0;
function skt_assert(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

function skt_expect_exception(string $code, callable $fn): void
{
    try {
        $fn();
        skt_assert(false, "expected {$code}");
    } catch (AiuSearchKeywordTokenException $e) {
        skt_assert($e->getReasonCode() === $code, "reason {$code} got " . $e->getReasonCode());
    }
}

$projector = new AiuSearchKeywordTokenProjector();
$normalizer = new AiuSemanticJsonNormalizer();

// Core case projections
$r1a = $projector->project(['日本', '賞花'], AiuSearchKeywordTokenProjector::MODE_REQUIRED);
skt_assert($r1a !== null && $r1a->getKeyword() === '日本 賞花', 'case1A keyword preserves 賞花');
$r1b = $projector->project(['日本', '花季'], AiuSearchKeywordTokenProjector::MODE_REQUIRED);
skt_assert($r1b !== null && $r1b->getKeyword() === '日本 花季', 'case1B keyword preserves 花季');
skt_assert($r1a->getKeyword() !== $r1b->getKeyword(), 'case1A/1B tokens not interchangeable');
$r2 = $projector->project(['北海道', '母親節', '溫泉'], AiuSearchKeywordTokenProjector::MODE_REQUIRED);
skt_assert($r2 !== null && $r2->getKeyword() === '北海道 母親節 溫泉', 'case2 keyword');
$r3 = $projector->project(['歐洲', '荷蘭', '賞花', '鬱金香'], AiuSearchKeywordTokenProjector::MODE_REQUIRED);
skt_assert($r3 !== null && $r3->getKeyword() === '歐洲 荷蘭 賞花 鬱金香', 'case3 keyword');

// Malformed raw inputs
skt_expect_exception(AiuSearchKeywordTokenException::TOKEN_ELEMENT_NON_STRING, static function () use ($projector): void {
    $projector->project([null], AiuSearchKeywordTokenProjector::MODE_REQUIRED);
});
skt_expect_exception(AiuSearchKeywordTokenException::TOKEN_ELEMENT_NON_STRING, static function () use ($projector): void {
    $projector->project([new stdClass()], AiuSearchKeywordTokenProjector::MODE_REQUIRED);
});
skt_expect_exception(AiuSearchKeywordTokenException::TOKEN_ELEMENT_NON_STRING, static function () use ($projector): void {
    $projector->project([['nested']], AiuSearchKeywordTokenProjector::MODE_REQUIRED);
});
skt_expect_exception(AiuSearchKeywordTokenException::TOKEN_ELEMENT_NON_STRING, static function () use ($projector): void {
    $projector->project([123], AiuSearchKeywordTokenProjector::MODE_REQUIRED);
});

// Normalizer pass-through preserves malformed
$raw = [
    'intent' => 'knowledge',
    'entities' => ['search_keyword_tokens' => [null, 'ok']],
    'clarification' => ['required' => false, 'reason' => ''],
];
$norm = $normalizer->normalize($raw, '', null);
skt_assert($norm['entities']['search_keyword_tokens'] === [null, 'ok'], 'normalizer preserves null element');

// Invalid UTF-8
$badUtf8 = "\xC3\x28";
skt_expect_exception(AiuSearchKeywordTokenException::TOKEN_ELEMENT_INVALID_UTF8, static function () use ($projector, $badUtf8): void {
    $projector->project([$badUtf8], AiuSearchKeywordTokenProjector::MODE_REQUIRED);
});

// Prohibited control char
skt_expect_exception(AiuSearchKeywordTokenException::TOKEN_ELEMENT_PROHIBITED_CHAR, static function () use ($projector): void {
    $projector->project(["a\x07b"], AiuSearchKeywordTokenProjector::MODE_REQUIRED);
});

// Approved spaces normalize (U+3000 fullwidth space)
$rSpace = $projector->project(["\xE3\x80\x80日本\xE3\x80\x80"], AiuSearchKeywordTokenProjector::MODE_REQUIRED);
skt_assert($rSpace !== null && $rSpace->getTokens() === ['日本'], 'approved space normalize');

// Empty / whitespace-only
skt_expect_exception(AiuSearchKeywordTokenException::TOKEN_ELEMENT_EMPTY, static function () use ($projector): void {
    $projector->project([''], AiuSearchKeywordTokenProjector::MODE_REQUIRED);
});
skt_expect_exception(AiuSearchKeywordTokenException::TOKEN_ELEMENT_EMPTY, static function () use ($projector): void {
    $projector->project([" \xE3\x80\x80 "], AiuSearchKeywordTokenProjector::MODE_REQUIRED);
});

// Exact duplicates preserve first order
$rDup = $projector->project(['日本', '花季', '日本'], AiuSearchKeywordTokenProjector::MODE_REQUIRED);
skt_assert($rDup !== null && $rDup->getTokens() === ['日本', '花季'], 'duplicate removal');
skt_assert($rDup->getDuplicateRemovedCount() === 1, 'duplicate count');

// Length 48 passes (48 UTF-8 code points)
$fortyEight = str_repeat('字', 48);
$r48 = $projector->project([$fortyEight], AiuSearchKeywordTokenProjector::MODE_REQUIRED);
skt_assert($r48 !== null && $r48->getProjectionLength() === 48, 'length 48 pass');

// Length 49 fails
$fortyNine = str_repeat('字', 49);
skt_expect_exception(AiuSearchKeywordTokenException::TOKEN_PROJECTION_TOO_LONG, static function () use ($projector, $fortyNine): void {
    $projector->project([$fortyNine], AiuSearchKeywordTokenProjector::MODE_REQUIRED);
});

// No truncation — long multi-token still fails
$longTokens = [str_repeat('一二三四五', 10)];
skt_expect_exception(AiuSearchKeywordTokenException::TOKEN_PROJECTION_TOO_LONG, static function () use ($projector, $longTokens): void {
    $projector->project($longTokens, AiuSearchKeywordTokenProjector::MODE_REQUIRED);
});

// OPTIONAL mode skipped
skt_assert($projector->project(null, AiuSearchKeywordTokenProjector::MODE_OPTIONAL) === null, 'optional skip');

// REQUIRED missing key
skt_expect_exception(AiuSearchKeywordTokenException::TOKEN_ARRAY_MISSING, static function () use ($projector): void {
    $projector->project(null, AiuSearchKeywordTokenProjector::MODE_REQUIRED);
});

if ($failures === 0) {
    echo "ALL PASS test_aiu_search_keyword_token_projector\n";
    exit(0);
}
fwrite(STDERR, "{$failures} FAILURE(S)\n");
exit(1);
