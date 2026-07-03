<?php
declare(strict_types=1);

/**
 * Phase 2-F Step 2-F-2b — GroundingPipelineRuntime authoritative pilot tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'grounding' . DIRECTORY_SEPARATOR . 'GroundingPipelineRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedResponseComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'RuntimeType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedInput.php';

$failures = 0;
const TRAVEL_B_SNO = '5f99b8d665e8444d';

function gpr_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$composer = new GroundedResponseComposer(['grounded_composer_generative_enabled' => false]);
$knowledgeResult = [
    'reply_text' => '02-1234-5678',
    'grounded' => true,
    'query_type' => 'company_profile',
    'qa_id' => 'qa-phone',
];
$tenant = ['tenant_sno' => TRAVEL_B_SNO, 'tenant_key' => 'travel_b'];

$legacyDirect = $composer->composeFromKnowledgeResult($knowledgeResult, $tenant);
$legacyViaPipeline = GroundingPipelineRuntime::composeKnowledgeReply([
    'config' => [
        'grounding_layer_authoritative_enabled' => false,
        'grounding_layer_authoritative_tenant_snos' => [TRAVEL_B_SNO],
    ],
    'trace_id' => 't-off',
    'tenant_sno' => TRAVEL_B_SNO,
    'conversation_id' => 'conv-off',
    'customer_query' => '電話',
    'runtime_type' => RuntimeType::KNOWLEDGE_PRIVATE,
    'runtime_result' => $knowledgeResult,
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_KNOWLEDGE_REPLY],
    'tenant' => $tenant,
    'legacy_tenant' => $tenant,
    'composer' => $composer,
]);

gpr_assert($legacyViaPipeline['pipeline'] === GroundingPipelineRuntime::PIPELINE_LEGACY, 'flag off uses legacy pipeline');
gpr_assert(
    $legacyDirect->getReplyText() === $legacyViaPipeline['output']->getReplyText(),
    'flag off reply identical to composeFromKnowledgeResult'
);

$productResult = [
    'reply_text' => 'legacy product reply',
    'grounded' => false,
    'recommendation_summary' => ['result_count' => 0],
    'product_list' => [],
];
$productLegacy = GroundingPipelineRuntime::composeProductReply([
    'config' => [
        'grounding_layer_authoritative_enabled' => false,
        'grounding_layer_authoritative_tenant_snos' => [TRAVEL_B_SNO],
    ],
    'trace_id' => 't-off-p',
    'tenant_sno' => TRAVEL_B_SNO,
    'conversation_id' => 'conv-off-p',
    'customer_query' => '北海道 8月 五天',
    'runtime_type' => RuntimeType::PRODUCT_SEARCH,
    'runtime_result' => $productResult,
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_PRODUCT_REPLY],
    'tenant' => $tenant,
    'legacy_tenant' => $tenant,
    'bats_search_intent' => [
        'destination' => '北海道',
        'date_from' => '8月',
    ],
    'composer' => $composer,
]);
gpr_assert($productLegacy['pipeline'] === GroundingPipelineRuntime::PIPELINE_LEGACY, 'product flag off legacy');

$authoritative = GroundingPipelineRuntime::composeProductReply([
    'config' => [
        'grounding_layer_authoritative_enabled' => true,
        'grounding_layer_authoritative_tenant_snos' => [TRAVEL_B_SNO],
        'grounded_composer_generative_enabled' => false,
    ],
    'trace_id' => 't-on',
    'tenant_sno' => TRAVEL_B_SNO,
    'conversation_id' => 'conv-on',
    'customer_query' => '北海道 8月 五天',
    'runtime_type' => RuntimeType::PRODUCT_SEARCH,
    'runtime_result' => $productResult,
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_PRODUCT_REPLY],
    'tenant' => $tenant,
    'legacy_tenant' => $tenant,
    'bats_search_intent' => [
        'destination' => '北海道',
        'date_from' => '8月',
    ],
    'composer' => $composer,
]);
gpr_assert(
    $authoritative['pipeline'] === GroundingPipelineRuntime::PIPELINE_AUTHORITATIVE,
    'travel_b authoritative pipeline'
);

$nonPilot = GroundingPipelineRuntime::composeKnowledgeReply([
    'config' => [
        'grounding_layer_authoritative_enabled' => true,
        'grounding_layer_authoritative_tenant_snos' => [TRAVEL_B_SNO],
    ],
    'trace_id' => 't-other',
    'tenant_sno' => '9999',
    'conversation_id' => 'conv-other',
    'customer_query' => '電話',
    'runtime_type' => RuntimeType::KNOWLEDGE_PRIVATE,
    'runtime_result' => $knowledgeResult,
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_KNOWLEDGE_REPLY],
    'tenant' => ['tenant_sno' => '9999'],
    'legacy_tenant' => ['tenant_sno' => '9999'],
    'composer' => $composer,
]);
gpr_assert($nonPilot['pipeline'] === GroundingPipelineRuntime::PIPELINE_LEGACY, 'non-allowlist tenant stays legacy');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_grounding_pipeline_runtime.php\n");
exit(0);
