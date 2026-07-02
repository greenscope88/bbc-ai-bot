<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-2e — PersonaAdapter tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'experience' . DIRECTORY_SEPARATOR . 'PersonaAdapter.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'experience' . DIRECTORY_SEPARATOR . 'PersonaRenderHints.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'LayoutDraft.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'LayoutProfile.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedResponseComposer.php';

$failures = 0;

function pa_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$adapter = new PersonaAdapter();
$layout = new LayoutDraft(
    "推薦行程 ✈️\n\n東京 5 日",
    true,
    1,
    ReplyType::NORMAL,
    LayoutProfile::PRODUCT_RICH
);

$input = GroundedInput::fromArray([
    'runtime_type' => 'product_search',
    'tenant' => ['tenant_key' => 'travel_b', 'tenant_sno' => '1', 'company_name' => '旅行蜜'],
    'tone' => ['persona' => 'travel_consultant', 'allow_emoji' => true],
    'grounded_facts' => [],
    'product_list' => [],
    'external_links' => [],
    'reply_policy' => ['mode' => 'recommend', 'grounded_only' => true],
    'raw_runtime_result' => [],
]);

$withEmoji = $adapter->adapt($input, $layout);
pa_assert(strpos($withEmoji->getReplyText(), '✈️') !== false, 'allow_emoji true: emoji preserved');
pa_assert(
    $withEmoji->getHints()->getVoiceProfileUsed() === 'travel_consultant',
    'persona key consumed'
);
pa_assert(
    $withEmoji->getHints()->getPersonaSsotRef() === PersonaRenderHints::SSOT_REF,
    'references BATS_AI_PERSONA.md'
);

$noEmojiInput = GroundedInput::fromArray([
    'runtime_type' => 'product_search',
    'tenant' => ['tenant_key' => 'travel_b', 'tenant_sno' => '1', 'company_name' => '旅行蜜'],
    'tone' => ['persona' => 'travel_consultant', 'allow_emoji' => false],
    'grounded_facts' => [],
    'product_list' => [],
    'external_links' => [],
    'reply_policy' => ['mode' => 'recommend', 'grounded_only' => true],
    'raw_runtime_result' => [],
]);
$withoutEmoji = $adapter->adapt($noEmojiInput, $layout);
pa_assert(strpos($withoutEmoji->getReplyText(), '✈️') === false, 'allow_emoji false: emoji stripped');

$resumeInput = GroundedInput::fromArray([
    'runtime_type' => 'knowledge_private',
    'tenant' => ['tenant_key' => 'travel_b', 'tenant_sno' => '1', 'company_name' => '旅行蜜'],
    'tone' => ['persona' => 'travel_consultant', 'allow_emoji' => true],
    'grounded_facts' => [],
    'product_list' => [],
    'external_links' => [],
    'reply_policy' => ['mode' => 'recommend', 'grounded_only' => true],
    'resume_context' => ['last_human' => '2026-06-30'],
    'raw_runtime_result' => [],
]);
$resumeDraft = $adapter->adapt($resumeInput, $layout);
pa_assert(
    $resumeDraft->getHints()->getOpeningStyle() === PersonaRenderHints::OPENING_RESUME_ACK,
    'resume_context: resume_ack opening style'
);

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_persona_adapter (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
