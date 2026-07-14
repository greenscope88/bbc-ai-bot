<?php
declare(strict_types=1);

/**
 * Stage 1-B-15 CLI dry-run: Gemini-authoritative tour context path only.
 * Non-Gemini intent detection removed from formal runtime.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';

$failures = 0;
$stagingSno = 'e1fd133c7e8e45a1';

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$service = new TourPromptContextService();

$withoutAuthoritative = $service->buildTourContextResult([
    'userText' => '我想找東京行程',
    'sno' => $stagingSno,
    'featureEnabled' => true,
]);
test_assert(
    $withoutAuthoritative->getSearchCondition() === null
    && $withoutAuthoritative->getSearchResults() === []
    && !$withoutAuthoritative->isClarificationRequired(),
    'buildTourContextResult fail-closed without authoritativeIntent'
);

if ($failures > 0) {
    echo "DONE with {$failures} failure(s).\n";
    exit(1);
}

echo "OK: Tour Gemini Context dry-run passed (Gemini-authoritative only).\n";
exit(0);
