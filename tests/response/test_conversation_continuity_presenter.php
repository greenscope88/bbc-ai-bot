<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-2e — ConversationContinuityPresenter tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'experience' . DIRECTORY_SEPARATOR
    . 'ConversationContinuityPresenter.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'experience' . DIRECTORY_SEPARATOR
    . 'ConversationContextReader.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'experience' . DIRECTORY_SEPARATOR . 'PersonaRenderHints.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedResponseComposer.php';

$failures = 0;

function ccp_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$presenter = new ConversationContinuityPresenter();
$hints = new PersonaRenderHints('travel_consultant', true);

$emptyInput = GroundedInput::fromArray([
    'runtime_type' => 'knowledge_private',
    'tenant' => ['tenant_key' => 'travel_b', 'tenant_sno' => '1', 'company_name' => '旅行蜜'],
    'tone' => ['persona' => 'travel_consultant', 'allow_emoji' => true],
    'grounded_facts' => [],
    'product_list' => [],
    'external_links' => [],
    'conversation_context' => [],
    'reply_policy' => ['mode' => 'recommend', 'grounded_only' => true],
    'raw_runtime_result' => [],
]);
$emptySegments = $presenter->buildSegments(
    $emptyInput,
    ConversationContextReader::fromInput($emptyInput),
    $hints
);
ccp_assert($emptySegments->isEmpty(), 'empty conversation_context: no segments');

$requirement = '東京自由行、八月、四萬預算';
$contextInput = GroundedInput::fromArray([
    'runtime_type' => 'knowledge_private',
    'tenant' => ['tenant_key' => 'travel_b', 'tenant_sno' => '1', 'company_name' => '旅行蜜'],
    'tone' => ['persona' => 'travel_consultant', 'allow_emoji' => true],
    'grounded_facts' => [],
    'product_list' => [],
    'external_links' => [],
    'conversation_context' => [
        'current_requirement' => $requirement,
        'outstanding_issues' => ['等待客服報價'],
    ],
    'reply_policy' => ['mode' => 'recommend', 'grounded_only' => true],
    'raw_runtime_result' => [],
]);
$context = ConversationContextReader::fromInput($contextInput);
$segments = $presenter->buildSegments($contextInput, $context, $hints);
$prefix = (string) $segments->getPrefix();
ccp_assert(strpos($prefix, $requirement) !== false, 'echoes current_requirement from snapshot');
ccp_assert(strpos($prefix, '等待客服報價') !== false, 'echoes outstanding issue from snapshot');
ccp_assert(strpos($prefix, '北海道') === false, 'does not invent facts not in snapshot');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_conversation_continuity_presenter (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
