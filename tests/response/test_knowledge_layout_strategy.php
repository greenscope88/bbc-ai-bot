<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-2b — KnowledgeLayoutStrategy tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedResponseComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'KnowledgeLayoutStrategy.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'LayoutStrategySelector.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'ResponseBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'RuntimeType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'LayoutProfile.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'knowledge' . DIRECTORY_SEPARATOR . 'LocalTenantPrivateKnowledgeProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'knowledge' . DIRECTORY_SEPARATOR . 'TenantPrivateKnowledgeRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'knowledge' . DIRECTORY_SEPARATOR . 'KnowledgeResponseComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'knowledge' . DIRECTORY_SEPARATOR . 'LocalIndustrySharedKnowledgeProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'knowledge' . DIRECTORY_SEPARATOR . 'IndustrySharedKnowledgeRuntime.php';

$failures = 0;

function kls_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$indexPicker = static function (string $poolKey, int $count): int {
    return 0;
};
$baselineComposer = new KnowledgeResponseComposer($indexPicker);
$strategy = new KnowledgeLayoutStrategy($baselineComposer);
$builder = new ResponseBuilder();

$fixtureRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'knowledge' . DIRECTORY_SEPARATOR
    . 'fixtures' . DIRECTORY_SEPARATOR . 'travel_b';
$sharedFixtureRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'knowledge' . DIRECTORY_SEPARATOR
    . 'fixtures' . DIRECTORY_SEPARATOR . 'shared';
$provider = new LocalTenantPrivateKnowledgeProvider('5f99b8d665e8444d', $fixtureRoot);
$runtime = new TenantPrivateKnowledgeRuntime($provider, null, $baselineComposer);
$sharedRuntime = new IndustrySharedKnowledgeRuntime(null, $baselineComposer, static function (string $industryCode) use ($sharedFixtureRoot): LocalIndustrySharedKnowledgeProvider {
    return new LocalIndustrySharedKnowledgeProvider($industryCode, $sharedFixtureRoot . DIRECTORY_SEPARATOR . $industryCode);
});

$tenant = [
    'tenant_key' => 'travel_b',
    'tenant_sno' => '5f99b8d665e8444d',
    'company_name' => '旅行蜜優惠',
];

// --- supports() ---
$phoneResult = $runtime->handle('請問客服電話', $tenant);
$privateInput = GroundedInput::fromKnowledgeRuntimeResult($phoneResult, $tenant);
kls_assert($strategy->supports($privateInput) === true, 'supports: knowledge_private');
kls_assert(
    $privateInput->getRuntimeType() === RuntimeType::KNOWLEDGE_PRIVATE,
    'input: knowledge_private runtime_type'
);

$sharedResult = $sharedRuntime->handle('BATS測試國際線多久前到機場', 'travel');
kls_assert($sharedResult !== null, 'shared runtime: result exists');
$sharedInput = GroundedInput::fromKnowledgeRuntimeResult($sharedResult, $tenant);
kls_assert($strategy->supports($sharedInput) === true, 'supports: knowledge_shared');
kls_assert(
    $sharedInput->getRuntimeType() === RuntimeType::KNOWLEDGE_SHARED,
    'input: knowledge_shared runtime_type'
);

$productInput = GroundedInput::fromProductRuntimeResult([
    'reply_text' => 'product copy',
    'grounded' => true,
    'product_list' => [['title' => '東京 5 日']],
    'recommendation_summary' => ['result_count' => 1],
], $tenant);
kls_assert($strategy->supports($productInput) === false, 'supports: rejects product_search');

// --- Strategy does not pass-through raw reply_text verbatim for recomposable knowledge ---
$draft = $strategy->compose($privateInput);
kls_assert(
    $draft->getReplyText() !== '' && strpos($draft->getReplyText(), '07-5224856') !== false,
    'strategy: recomposed phone reply contains grounded value'
);
kls_assert(
    $draft->getReplyText() === $phoneResult['reply_text'],
    'strategy phone: matches KnowledgeResponseComposer baseline'
);

$qaResult = $runtime->handle('國際線多久前報到？', $tenant);
$qaInput = GroundedInput::fromKnowledgeRuntimeResult($qaResult, $tenant);
$qaDraft = $strategy->compose($qaInput);
kls_assert(
    $qaDraft->getReplyText() === $qaResult['reply_text'],
    'strategy service_qa: matches runtime baseline'
);
kls_assert(
    strpos($qaDraft->getReplyText(), '建議於航班起飛前二至三小時') !== false
    || strpos($qaDraft->getReplyText(), '飛機起飛前二個小時前') !== false,
    'strategy service_qa: contains grounded fact body'
);

$sharedDraft = $strategy->compose($sharedInput);
kls_assert(
    $sharedDraft->getReplyText() === $sharedResult['reply_text'],
    'strategy knowledge_shared: matches industry shared baseline'
);

// --- ResponseBuilder + GroundedResponseComposer flag ON ---
$legacyComposer = new GroundedResponseComposer(['grounded_composer_generative_enabled' => false]);
$runtimeComposer = new GroundedResponseComposer(['grounded_composer_generative_enabled' => true]);

$legacyOut = $legacyComposer->composeFromKnowledgeResult($phoneResult, $tenant);
$runtimeOut = $runtimeComposer->composeFromKnowledgeResult($phoneResult, $tenant);
kls_assert(
    $legacyOut->getReplyText() === $phoneResult['reply_text'],
    'flag OFF: legacy passthrough baseline'
);
kls_assert(
    $runtimeOut->getReplyText() === $legacyOut->getReplyText(),
    'flag ON knowledge_private: strategy matches legacy output'
);
kls_assert(
    $runtimeOut->getReplyText() !== '' && $runtimeOut->getReplyText() !== 'product copy',
    'flag ON knowledge: not product fallback'
);

// --- flag ON product_search uses ProductLayoutStrategy (not legacy pass-through) ---
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'product_source' . DIRECTORY_SEPARATOR . 'recommendation' . DIRECTORY_SEPARATOR
    . 'TravelConsultantPersonaRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'product_source' . DIRECTORY_SEPARATOR . 'recommendation' . DIRECTORY_SEPARATOR
    . 'TravelConsultantPersonaFormatter.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'ProductLayoutStrategy.php';

$personaRuntime = new TravelConsultantPersonaRuntime(TravelConsultantPersonaFormatter::createWithFixedIndex(0));
$productSummary = [
    'result_count' => 1,
    'top_products' => [['title' => '東京 5 日', 'primary_url' => 'https://example.com/t']],
    'primary_url' => 'https://example.com/t',
];
$productBaseline = $personaRuntime->composeProductRecommendation($productSummary);
$productLegacy = $legacyComposer->composeProductReply([
    'reply_text' => 'product copy',
    'grounded' => true,
    'product_list' => [['title' => '東京 5 日', 'primary_url' => 'https://example.com/t']],
    'recommendation_summary' => $productSummary,
], $tenant);
$productRuntime = $runtimeComposer->composeProductReply([
    'reply_text' => 'product copy',
    'grounded' => true,
    'product_list' => [['title' => '東京 5 日', 'primary_url' => 'https://example.com/t']],
    'recommendation_summary' => $productSummary,
], $tenant);
kls_assert(
    $productLegacy->getReplyText() === 'product copy',
    'flag OFF product: legacy pass-through unchanged'
);
kls_assert(
    $productRuntime->getReplyText() === $productBaseline,
    'flag ON product_search: matches PersonaRuntime baseline'
);
kls_assert(
    $productRuntime->getReplyText() !== 'product copy',
    'flag ON product_search: does not pass through raw reply_text'
);

// --- LayoutStrategySelector ---
$selector = LayoutStrategySelector::createDefault();
kls_assert($selector->resolve($privateInput) !== null, 'selector: resolves knowledge_private');
kls_assert($selector->resolve($sharedInput) !== null, 'selector: resolves knowledge_shared');
kls_assert($selector->resolve($productInput) instanceof ProductLayoutStrategy, 'selector: resolves product_search');

// --- ResponseBuilder ---
$built = $builder->build($privateInput, $draft);
kls_assert($built->getReplyText() === $draft->getReplyText(), 'builder: reply_text from draft');
kls_assert($built->getLayoutProfile() === LayoutProfile::KNOWLEDGE_STANDARD, 'builder: knowledge layout profile');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_knowledge_layout_strategy (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
