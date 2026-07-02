<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-2e — NextBestActionPresenter tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'experience' . DIRECTORY_SEPARATOR . 'NextBestActionPresenter.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'experience' . DIRECTORY_SEPARATOR . 'ConversationContextReader.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'experience' . DIRECTORY_SEPARATOR . 'PersonaRenderHints.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedResponseComposer.php';

$failures = 0;

function nba_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$presenter = new NextBestActionPresenter();
$hints = new PersonaRenderHints('travel_consultant', true);

$base = [
    'runtime_type' => 'product_search',
    'tenant' => ['tenant_key' => 'travel_b', 'tenant_sno' => '1', 'company_name' => '旅行蜜'],
    'tone' => ['persona' => 'travel_consultant', 'allow_emoji' => true],
    'grounded_facts' => [],
    'product_list' => [['title' => '東京 5 日', 'primary_url' => 'https://example.com/tokyo']],
    'external_links' => [],
    'recommendation_summary' => ['primary_url' => 'https://example.com/tokyo'],
    'conversation_context' => [],
    'reply_policy' => ['mode' => 'recommend', 'grounded_only' => true],
    'raw_runtime_result' => [],
];

// --- no hint: must not present NBA ---
$noHintInput = GroundedInput::fromArray($base);
$noHint = $presenter->present(
    $noHintInput,
    ConversationContextReader::fromInput($noHintInput),
    $hints
);
nba_assert(!$noHint->isPresented(), 'no hint: NBA not presented');
nba_assert($noHint->getSkippedReason() === 'no_hint', 'no hint: skipped reason');

// --- view_product with grounded URL ---
$viewProductInput = GroundedInput::fromArray(array_merge($base, [
    'reply_policy' => [
        'mode' => 'recommend',
        'grounded_only' => true,
        'next_best_action_hint' => NextBestActionPresenter::HINT_VIEW_PRODUCT,
    ],
]));
$viewProduct = $presenter->present(
    $viewProductInput,
    ConversationContextReader::fromInput($viewProductInput),
    $hints
);
nba_assert($viewProduct->isPresented(), 'view_product with URL: presented');
nba_assert(
    $viewProduct->getPresentedKey() === NextBestActionPresenter::HINT_VIEW_PRODUCT,
    'view_product: correct key'
);

// --- view_product without URL ---
$noUrlInput = GroundedInput::fromArray(array_merge($base, [
    'product_list' => [['title' => '東京 5 日']],
    'recommendation_summary' => [],
    'reply_policy' => [
        'mode' => 'recommend',
        'grounded_only' => true,
        'next_best_action_hint' => NextBestActionPresenter::HINT_VIEW_PRODUCT,
    ],
]));
$noUrl = $presenter->present(
    $noUrlInput,
    ConversationContextReader::fromInput($noUrlInput),
    $hints
);
nba_assert(!$noUrl->isPresented(), 'view_product without URL: not presented');

// --- ask_travel_dates when dates already known ---
$datesKnownInput = GroundedInput::fromArray(array_merge($base, [
    'conversation_context' => ['travel_dates' => '2026-08-01'],
    'reply_policy' => [
        'mode' => 'recommend',
        'grounded_only' => true,
        'next_best_action_hint' => NextBestActionPresenter::HINT_ASK_TRAVEL_DATES,
    ],
]));
$datesKnown = $presenter->present(
    $datesKnownInput,
    ConversationContextReader::fromInput($datesKnownInput),
    $hints
);
nba_assert(!$datesKnown->isPresented(), 'ask_travel_dates with known dates: suppressed');
nba_assert($datesKnown->getSkippedReason() === 'travel_dates_already_known', 'dates skip reason');

// --- ask_party_size when party_size already known ---
$partyKnownInput = GroundedInput::fromArray(array_merge($base, [
    'conversation_context' => ['party_size' => '2 大 1 小'],
    'reply_policy' => [
        'mode' => 'recommend',
        'grounded_only' => true,
        'next_best_action_hint' => NextBestActionPresenter::HINT_ASK_PARTY_SIZE,
    ],
]));
$partyKnown = $presenter->present(
    $partyKnownInput,
    ConversationContextReader::fromInput($partyKnownInput),
    $hints
);
nba_assert(!$partyKnown->isPresented(), 'ask_party_size with known party_size: suppressed');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_next_best_action_presenter (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
