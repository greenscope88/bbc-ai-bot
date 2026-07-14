<?php
declare(strict_types=1);

/**
 * Phase 2-F Step 2-F-2a — GroundingOrchestratorContextFactory tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'grounding' . DIRECTORY_SEPARATOR . 'GroundingOrchestratorContextFactory.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'RuntimeType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedInput.php';

$failures = 0;

function gocf_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$context = GroundingOrchestratorContextFactory::build([
    'trace_id' => 'trace-factory',
    'conversation_id' => 'conv-factory',
    'tenant_sno' => '1001',
    'customer_query' => '北海道 8月 五天',
    'runtime_type' => RuntimeType::PRODUCT_SEARCH,
    'runtime_result' => [
        'reply_text' => '推薦',
        'grounded' => false,
        'recommendation_summary' => ['result_count' => 0],
        'product_list' => [],
    ],
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_PRODUCT_REPLY],
    'bats_search_intent' => [
        'destination' => ['北海道'],
        'date_from' => '8月',
        'duration' => '五天',
        'free_text' => '北海道 8月 五天',
    ],
]);

gocf_assert($context->getCustomerQuery() === '北海道 8月 五天', 'customer_query preserved');
gocf_assert($context->getRuntimeType() === RuntimeType::PRODUCT_SEARCH, 'runtime_type product_search');
gocf_assert($context->getSourceType() === GroundedInput::SOURCE_PRODUCT_SEARCH, 'source_type mapped');

$aiu = $context->getAiuProjection();
$entities = is_array($aiu['entities'] ?? null) ? $aiu['entities'] : [];
gocf_assert(($entities['destination'] ?? []) === ['北海道'], 'entities destination from bats_search_intent');
gocf_assert(($entities['travel_dates'] ?? '') === '8月', 'entities travel_dates from bats_search_intent');
gocf_assert(($entities['duration'] ?? '') === '五天', 'entities duration from bats_search_intent');

$knowledgeType = GroundingOrchestratorContextFactory::resolveKnowledgeRuntimeType([
    'fallback_layer' => 'industry_shared',
    'reply_text' => 'shared',
    'grounded' => true,
]);
gocf_assert($knowledgeType === RuntimeType::KNOWLEDGE_SHARED, 'knowledge shared runtime type');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_grounding_orchestrator_context_factory.php\n");
exit(0);
