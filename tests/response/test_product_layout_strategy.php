<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-2c — ProductLayoutStrategy tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedResponseComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'ProductLayoutStrategy.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'LayoutStrategySelector.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'ResponseBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'RuntimeType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'LayoutProfile.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'product_source' . DIRECTORY_SEPARATOR . 'recommendation' . DIRECTORY_SEPARATOR
    . 'TravelConsultantPersonaRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'product_source' . DIRECTORY_SEPARATOR . 'recommendation' . DIRECTORY_SEPARATOR
    . 'TravelConsultantPersonaFormatter.php';

$failures = 0;

function pls_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$formatter = TravelConsultantPersonaFormatter::createWithFixedIndex(0);
$personaRuntime = new TravelConsultantPersonaRuntime($formatter);
$strategy = new ProductLayoutStrategy($personaRuntime);
$builder = new ResponseBuilder();

$tenant = [
    'tenant_key' => 'travel_b',
    'tenant_sno' => '5f99b8d665e8444d',
    'company_name' => '旅行蜜優惠',
];

$recommendationSummary = [
    'result_count' => 2,
    'top_products' => [
        ['title' => '東京自由行 5 日', 'primary_url' => 'https://example.com/a', 'display_emoji' => '✈️'],
        ['title' => '東京親子 6 日', 'primary_url' => 'https://example.com/b', 'display_emoji' => '🌸'],
    ],
    'primary_url' => 'https://example.com/a',
    'recommendation_reason' => '為您整理近期東京熱門行程',
];

$baselineReply = $personaRuntime->composeProductRecommendation($recommendationSummary);

$productResult = [
    'reply_text' => 'SHOULD_NOT_PASS_THROUGH_THIS_TEXT',
    'grounded' => true,
    'recommendation_summary' => $recommendationSummary,
    'product_list' => [
        ['title' => '東京自由行 5 日', 'primary_url' => 'https://example.com/a'],
        ['title' => '東京親子 6 日', 'primary_url' => 'https://example.com/b'],
    ],
];

$productInput = GroundedInput::fromProductRuntimeResult($productResult, $tenant);

// --- supports() ---
pls_assert($strategy->supports($productInput) === true, 'supports: product_search');
pls_assert(
    $productInput->getRuntimeType() === RuntimeType::PRODUCT_SEARCH,
    'input: product_search runtime_type'
);

$knowledgeInput = GroundedInput::fromKnowledgeRuntimeResult([
    'reply_text' => 'knowledge',
    'grounded' => true,
    'query_type' => 'service_qa',
    'qa_id' => 'qa-1',
], $tenant);
pls_assert($strategy->supports($knowledgeInput) === false, 'supports: rejects knowledge_private');

// --- Strategy does not pass-through raw reply_text ---
$draft = $strategy->compose($productInput);
pls_assert(
    $draft->getReplyText() !== 'SHOULD_NOT_PASS_THROUGH_THIS_TEXT',
    'strategy: does not pass through rawRuntimeResult.reply_text'
);
pls_assert(
    $draft->getReplyText() === $baselineReply,
    'strategy: matches TravelConsultantPersonaRuntime baseline'
);
pls_assert(strpos($draft->getReplyText(), '東京自由行 5 日') !== false, 'strategy: contains product title');
pls_assert($draft->getLayoutProfile() === LayoutProfile::PRODUCT_RICH, 'strategy: product_rich_v1');
pls_assert($draft->getReplyType() === ReplyType::NORMAL, 'strategy: normal_reply when results');
pls_assert($draft->getUsedFactsCount() === 2, 'strategy: used_facts_count from products');

// --- no results ---
$noResultsSummary = ['result_count' => 0, 'top_products' => []];
$noResultsBaseline = $personaRuntime->composeNoResultsMessage();
$noResultsInput = GroundedInput::fromProductRuntimeResult([
    'reply_text' => 'WRONG_NO_RESULTS_TEXT',
    'grounded' => false,
    'recommendation_summary' => $noResultsSummary,
    'product_list' => [],
], $tenant);
$noResultsDraft = $strategy->compose($noResultsInput);
pls_assert(
    $noResultsDraft->getReplyText() === $noResultsBaseline,
    'strategy no_results: matches composeNoResultsMessage baseline'
);
pls_assert($noResultsDraft->getReplyType() === ReplyType::NO_RESULTS, 'strategy no_results: reply_type');
pls_assert($noResultsDraft->isGrounded() === false, 'strategy no_results: not grounded');

// --- LayoutStrategySelector ---
$selector = LayoutStrategySelector::createDefault();
pls_assert($selector->resolve($productInput) !== null, 'selector: resolves product_search');
pls_assert($selector->resolve($knowledgeInput) !== null, 'selector: still resolves knowledge');
$resolvedProduct = $selector->resolve($productInput);
pls_assert($resolvedProduct instanceof ProductLayoutStrategy, 'selector: product strategy instance');

// --- GroundedResponseComposer flag ON/OFF ---
$legacyComposer = new GroundedResponseComposer(['grounded_composer_generative_enabled' => false]);
$runtimeComposer = new GroundedResponseComposer(['grounded_composer_generative_enabled' => true]);

$legacyOut = $legacyComposer->composeProductReply($productResult, $tenant);
$runtimeOut = $runtimeComposer->composeProductReply($productResult, $tenant);

pls_assert(
    $legacyOut->getReplyText() === 'SHOULD_NOT_PASS_THROUGH_THIS_TEXT',
    'flag OFF: legacy pass-through unchanged'
);
pls_assert(
    $runtimeOut->getReplyText() === $baselineReply,
    'flag ON product_search: strategy matches PersonaRuntime baseline'
);
pls_assert(
    $runtimeOut->getLayoutProfile() === LayoutProfile::PRODUCT_RICH,
    'flag ON: product_rich layout'
);

// --- ResponseBuilder ---
$built = $builder->build($productInput, $draft);
pls_assert($built->getReplyText() === $draft->getReplyText(), 'builder: reply from draft');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_product_layout_strategy (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
