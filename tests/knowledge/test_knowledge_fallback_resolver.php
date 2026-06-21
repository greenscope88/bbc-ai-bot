<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'KnowledgeFallbackResolver.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'HumanServiceResponseComposer.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$humanComposer = new HumanServiceResponseComposer(static function (string $poolKey, int $count): int {
    return 0;
});
$resolver = new KnowledgeFallbackResolver($humanComposer);

$result = $resolver->resolve('找不到答案的問題', 'travel_b', '旅行蜜優惠');
test_assert(($result['grounded'] ?? true) === false, 'fallback: not grounded');
test_assert(($result['fallback_layer'] ?? '') === 'human_service', 'fallback: human_service layer');
test_assert(($result['final_route'] ?? '') === 'phase_9c2b3_knowledge_human_service', 'fallback: final_route');
test_assert(strpos((string) ($result['reply_text'] ?? ''), '人工客服') !== false, 'fallback: human service text');
test_assert(strpos((string) ($result['reply_text'] ?? ''), '旅行蜜優惠人工客服') !== false, 'fallback: company name in body');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_knowledge_fallback_resolver (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
