<?php
declare(strict_types=1);

/**
 * Phase 9-B-26B-4B: SaaSRouter BATS hook tests (no HTTP / LINE / Gemini).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'saas_router.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/**
 * Extract a single PHP function body by name (brace-balanced).
 */
function extract_php_function_body(string $source, string $functionName): string
{
    $markers = [
        'public static function ' . $functionName,
        'private static function ' . $functionName,
    ];
    $start = false;
    foreach ($markers as $marker) {
        $pos = strpos($source, $marker);
        if ($pos !== false) {
            $start = $pos;
            break;
        }
    }
    if ($start === false) {
        return '';
    }

    $braceStart = strpos($source, '{', $start);
    if ($braceStart === false) {
        return '';
    }

    $depth = 0;
    $length = strlen($source);
    for ($i = $braceStart; $i < $length; ++$i) {
        $char = $source[$i];
        if ($char === '{') {
            ++$depth;
        } elseif ($char === '}') {
            --$depth;
            if ($depth === 0) {
                return substr($source, $braceStart, $i - $braceStart + 1);
            }
        }
    }

    return '';
}

$disabledConfig = [
    'defaults' => ['mode' => BatsFeatureGate::MODE_DISABLED],
    'tenants' => [],
    'channels' => [],
];

$dryRunConfig = [
    'defaults' => ['mode' => BatsFeatureGate::MODE_DISABLED],
    'tenants' => [
        'cccccccccccccccc' => ['mode' => BatsFeatureGate::MODE_DRY_RUN],
    ],
    'channels' => [],
];

$enabledConfig = [
    'defaults' => ['mode' => BatsFeatureGate::MODE_DISABLED],
    'tenants' => [
        'aaaaaaaaaaaaaaaa' => ['mode' => BatsFeatureGate::MODE_ENABLED],
    ],
    'channels' => [],
];

$enabledInvalidTenantConfig = [
    'defaults' => ['mode' => BatsFeatureGate::MODE_DISABLED],
    'tenants' => [
        'not-a-valid-sno' => ['mode' => BatsFeatureGate::MODE_ENABLED],
    ],
    'channels' => [],
];

$controlledConfigOff = [
    'defaults' => ['mode' => BatsFeatureGate::MODE_DISABLED],
    'controlled_reply_enabled' => false,
    'controlled_reply_tenants' => ['5f99b8d665e8444d'],
    'controlled_reply_keyword_prefix' => 'BATS測試',
    'tenants' => [
        '5f99b8d665e8444d' => ['mode' => BatsFeatureGate::MODE_DRY_RUN],
    ],
    'channels' => [],
];

$controlledConfigOn = [
    'defaults' => ['mode' => BatsFeatureGate::MODE_DISABLED],
    'controlled_reply_enabled' => true,
    'controlled_reply_tenants' => ['5f99b8d665e8444d'],
    'controlled_reply_keyword_prefix' => 'BATS測試',
    'tenants' => [
        '5f99b8d665e8444d' => ['mode' => BatsFeatureGate::MODE_DRY_RUN],
    ],
    'channels' => [],
];

$tenantDryRun = [
    'sno' => 'cccccccccccccccc',
    'channel_id' => 'line-channel-1',
];

$tenantEnabled = [
    'sno' => 'aaaaaaaaaaaaaaaa',
    'channel_id' => 'line-channel-2',
];

$tenantInvalid = [
    'sno' => 'not-a-valid-sno',
    'channel_id' => 'line-channel-3',
];

$tenantEmpty = [
    'sno' => '',
    'channel_id' => 'line-channel-4',
];

$tenantTravelB = [
    'sno' => '5f99b8d665e8444d',
    'channel_id' => 'travel-b-channel',
];

$tenantTravelBWithKey = [
    'sno' => '5f99b8d665e8444d',
    'tenant_key' => 'travel_b',
    'channel_id' => 'travel-b-channel',
];

$controlledRealConfigOff = [
    'defaults' => ['mode' => BatsFeatureGate::MODE_DISABLED],
    'controlled_real_reply_enabled' => false,
    'controlled_real_reply_tenant_sno' => '5f99b8d665e8444d',
    'controlled_real_reply_tenant_key' => 'travel_b',
    'controlled_real_reply_keyword_prefix' => 'BATS測試',
    'tenants' => [
        '5f99b8d665e8444d' => ['mode' => BatsFeatureGate::MODE_DRY_RUN],
    ],
    'channels' => [],
];

$controlledRealConfigOn = [
    'defaults' => ['mode' => BatsFeatureGate::MODE_DISABLED],
    'controlled_real_reply_enabled' => true,
    'controlled_real_reply_tenant_sno' => '5f99b8d665e8444d',
    'controlled_real_reply_tenant_key' => 'travel_b',
    'controlled_real_reply_keyword_prefix' => 'BATS測試',
    'tenants' => [
        '5f99b8d665e8444d' => ['mode' => BatsFeatureGate::MODE_DRY_RUN],
    ],
    'channels' => [],
];

// Case 1: default disabled → fall-through (null)
try {
    $gate = new BatsFeatureGate($disabledConfig);
    $result = SaaSRouter::attemptBatsWebhookHook(
        $tenantDryRun,
        '請推薦東京五日團',
        'trace-case-1',
        'line-channel-1',
        $gate
    );
    test_assert($result === null, 'case1 null for disabled gate');
    test_assert(true, 'case1 default disabled fall-through PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case1 should pass: ' . $e->getMessage());
}

// Case 2: dry_run tenant → bats_dry_run
try {
    $gate = new BatsFeatureGate($dryRunConfig);
    $result = SaaSRouter::attemptBatsWebhookHook(
        $tenantDryRun,
        '請推薦東京五日團',
        'trace-case-2',
        'line-channel-1',
        $gate
    );
    test_assert(is_array($result), 'case2 result array');
    test_assert(($result['message'] ?? '') === 'bats_dry_run', 'case2 bats_dry_run message');
    test_assert(($result['bats']['status'] ?? '') === 'dry_run', 'case2 orchestrator dry_run');
    test_assert(true, 'case2 dry_run tenant PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case2 should pass: ' . $e->getMessage());
}

// Case 3: enabled tenant → bats_enabled_skeleton (4B skeleton, no LINE)
try {
    $gate = new BatsFeatureGate($enabledConfig);
    $result = SaaSRouter::attemptBatsWebhookHook(
        $tenantEnabled,
        '請推薦大阪三日團',
        'trace-case-3',
        'line-channel-2',
        $gate
    );
    test_assert(is_array($result), 'case3 result array');
    test_assert(($result['message'] ?? '') === 'bats_enabled_skeleton', 'case3 bats_enabled_skeleton');
    test_assert(($result['bats']['status'] ?? '') === 'accepted', 'case3 orchestrator accepted');
    test_assert(true, 'case3 enabled tenant PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case3 should pass: ' . $e->getMessage());
}

// Case 4: invalid tenant_sno → fall-through (BATS not activated)
try {
    $gate = new BatsFeatureGate($enabledInvalidTenantConfig);
    $result = SaaSRouter::attemptBatsWebhookHook(
        $tenantInvalid,
        '請推薦行程',
        'trace-case-4',
        'line-channel-3',
        $gate
    );
    test_assert($result === null, 'case4 null for invalid tenant');
    test_assert(true, 'case4 invalid tenant fall-through PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case4 should pass: ' . $e->getMessage());
}

// Case 5: hello/weather run before hook (documentation guard via source order)
try {
    $routerSource = (string) file_get_contents(
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'saas_router.php'
    );
    $helloPos = strpos($routerSource, "if (\$userMessage === '你好')");
    $weatherPos = strpos($routerSource, "weather_query");
    $resolvePos = strpos($routerSource, 'TenantResolver::resolve');
    $hookPos = strpos($routerSource, 'attemptBatsWebhookHook');
    test_assert($helloPos !== false && $weatherPos !== false && $resolvePos !== false && $hookPos !== false, 'case5 markers exist');
    test_assert($helloPos < $resolvePos, 'case5 hello before tenant resolve');
    test_assert($weatherPos < $resolvePos, 'case5 weather before tenant resolve');
    test_assert($resolvePos < $hookPos, 'case5 hook after tenant resolve');
    test_assert(true, 'case5 hello/weather before BATS hook PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case5 should pass: ' . $e->getMessage());
}

// Case 6: read-only hook / preview gate / real reply gate are separated
try {
    $routerSource = (string) file_get_contents(
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'saas_router.php'
    );

    $hookBody = extract_php_function_body($routerSource, 'attemptBatsWebhookHook');
    $previewBody = extract_php_function_body($routerSource, 'attemptControlledReplyPath');
    $realBody = extract_php_function_body($routerSource, 'attemptControlledRealLineReplyPath');

    test_assert($hookBody !== '', 'case6 hook function body found');
    test_assert($previewBody !== '', 'case6 preview gate function body found');
    test_assert($realBody !== '', 'case6 real reply gate function body found');

    // A: read-only BATS hook must not send LINE replies
    test_assert(stripos($hookBody, 'LineService::replyToLine') === false, 'case6 hook no replyToLine');
    test_assert(stripos($hookBody, 'callGemini') === false, 'case6 hook no callGemini');
    test_assert(stripos($hookBody, 'curl_') === false, 'case6 hook no curl');
    test_assert(stripos($hookBody, 'api.line.me') === false, 'case6 hook no LINE API url');
    test_assert(stripos($hookBody, 'attemptControlledReplyPath') === false, 'case6 hook does not call preview gate');
    test_assert(stripos($hookBody, 'attemptControlledRealLineReplyPath') === false, 'case6 hook does not call real gate');

    // B: controlled real reply gate may call LINE Reply API
    test_assert(stripos($realBody, 'LineService::replyToLine') !== false, 'case6 real gate uses replyToLine');

    // C: preview gate stays preview-only (no real LINE send)
    test_assert(stripos($previewBody, 'LineService::replyToLine') === false, 'case6 preview gate no replyToLine');
    test_assert(stripos($previewBody, 'preview_only') !== false, 'case6 preview gate preview_only mode');

    // C: read-only trace hooks exist separately from controlled gates
    test_assert(strpos($routerSource, 'traceBatsHookReadOnly') !== false, 'case6 read-only pre-resolve trace exists');
    test_assert(strpos($routerSource, 'traceBatsHookAfterTenantReadOnly') !== false, 'case6 read-only post-resolve trace exists');

    test_assert(true, 'case6 hook/preview/real separation PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case6 should pass: ' . $e->getMessage());
}

// Case 7: config/bats_feature.php loads
try {
    $configPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bats_feature.php';
    test_assert(is_file($configPath), 'case7 config file exists');
    $loaded = require $configPath;
    test_assert(is_array($loaded), 'case7 config is array');
    test_assert(
        ($loaded['defaults']['mode'] ?? '') === BatsFeatureGate::MODE_DISABLED,
        'case7 defaults disabled'
    );
    $gate = new BatsFeatureGate($loaded);
    test_assert(
        $gate->resolveMode(['tenant_sno' => '5f99b8d665e8444d', 'channel' => '']) === BatsFeatureGate::MODE_DRY_RUN,
        'case7 pilot tenant dry_run'
    );
    test_assert(array_key_exists('controlled_reply_enabled', $loaded), 'case7 controlled_reply_enabled key exists');
    test_assert(array_key_exists('controlled_reply_tenants', $loaded), 'case7 controlled_reply_tenants key exists');
    test_assert(array_key_exists('controlled_reply_keyword_prefix', $loaded), 'case7 controlled_reply_keyword_prefix key exists');
    test_assert(array_key_exists('controlled_real_reply_enabled', $loaded), 'case7 controlled_real_reply_enabled key exists');
    test_assert(
        ($loaded['controlled_real_reply_enabled'] ?? true) === false,
        'case7 controlled_real_reply_enabled default off'
    );
    test_assert(true, 'case7 feature config load PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case7 should pass: ' . $e->getMessage());
}

// Case 8: controlled gate global flag off -> fallthrough
try {
    $eligible = SaaSRouter::isControlledReplyEligible($tenantTravelB, 'BATS測試 東京', $controlledConfigOff);
    test_assert($eligible === false, 'case8 controlled gate disabled');
    $result = SaaSRouter::attemptControlledReplyPath(
        $tenantTravelB,
        'BATS測試 東京',
        'trace-case-8',
        'travel-b-channel',
        $controlledConfigOff
    );
    test_assert($result === null, 'case8 controlled path falls through');
    test_assert(true, 'case8 controlled flag off PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case8 should pass: ' . $e->getMessage());
}

// Case 9: tenant not in allowlist -> fallthrough
try {
    $eligible = SaaSRouter::isControlledReplyEligible(
        ['sno' => 'aaaaaaaaaaaaaaaa', 'channel_id' => 'travel-b-channel'],
        'BATS測試 東京',
        $controlledConfigOn
    );
    test_assert($eligible === false, 'case9 tenant not allowlisted');
    test_assert(true, 'case9 tenant allowlist PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case9 should pass: ' . $e->getMessage());
}

// Case 10: keyword mismatch -> fallthrough
try {
    $eligible = SaaSRouter::isControlledReplyEligible($tenantTravelB, '東京', $controlledConfigOn);
    test_assert($eligible === false, 'case10 keyword mismatch');
    test_assert(true, 'case10 keyword trigger PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case10 should pass: ' . $e->getMessage());
}

// Case 11: all three conditions pass -> controlled preview path
try {
    $eligible = SaaSRouter::isControlledReplyEligible($tenantTravelB, 'BATS測試 東京', $controlledConfigOn);
    test_assert($eligible === true, 'case11 controlled gate eligible');
    $result = SaaSRouter::attemptControlledReplyPath(
        $tenantTravelB,
        'BATS測試 東京',
        'trace-case-11',
        'travel-b-channel',
        $controlledConfigOn
    );
    test_assert(is_array($result), 'case11 result array');
    test_assert(($result['message'] ?? '') === 'bats_controlled_reply_preview', 'case11 preview mode message');
    test_assert(($result['controlled_reply']['mode'] ?? '') === 'preview_only', 'case11 preview_only mode');
    test_assert(($result['controlled_reply']['line_render_available'] ?? false) === true, 'case11 line render available');
    test_assert(($result['controlled_reply']['line_sender_available'] ?? false) === true, 'case11 line sender available');
    test_assert(($result['controlled_reply']['line_message_count'] ?? 0) > 0, 'case11 line message count');
    test_assert(true, 'case11 controlled path PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case11 should pass: ' . $e->getMessage());
}

// Case 12: hello/weather keywords do not match controlled prefix -> legacy gate
try {
    test_assert(
        SaaSRouter::isControlledReplyEligible($tenantTravelB, '你好', $controlledConfigOn) === false,
        'case12 hello not controlled'
    );
    test_assert(
        SaaSRouter::isControlledReplyEligible($tenantTravelB, '今天天氣如何', $controlledConfigOn) === false,
        'case12 weather not controlled'
    );
    test_assert(true, 'case12 hello/weather legacy PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case12 should pass: ' . $e->getMessage());
}

// Case 13: controlled exception -> fallback legacy (null)
try {
    $result = SaaSRouter::attemptControlledReplyPath(
        $tenantTravelB,
        'BATS測試 東京',
        'trace-case-13',
        'travel-b-channel',
        $controlledConfigOn,
        static function (BatsFeatureGate $gate): BatsWebhookOrchestrator {
            unset($gate);
            throw new \RuntimeException('forced controlled path failure');
        }
    );
    test_assert($result === null, 'case13 fallback to legacy on exception');
    test_assert(true, 'case13 controlled error fallback PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case13 should pass: ' . $e->getMessage());
}

// Phase 9-B-26C-7: controlled real LINE reply gate

$mockLineSender = static function (string $url, string $token, string $replyToken, string $text): array {
    unset($url, $token);
    return [
        'status' => 200,
        'reply_token' => $replyToken,
        'text_length' => mb_strlen($text),
        'mock' => true,
    ];
};

// Case 14 (26C-7 #1): travel_b + correct sno + BATS測試* -> real route
try {
    $decision = SaaSRouter::evaluateControlledRealLineReplyGate(
        $tenantTravelBWithKey,
        'BATS測試 東京五日',
        $controlledRealConfigOn
    );
    test_assert(($decision['controlled_real_reply_allowed'] ?? false) === true, 'case14 allowed');
    test_assert(($decision['final_route'] ?? '') === 'bats_real_line_reply', 'case14 final_route real');
    test_assert(($decision['message_prefix_check_passed'] ?? false) === true, 'case14 prefix ok');
    $result = SaaSRouter::attemptControlledRealLineReplyPath(
        $tenantTravelBWithKey,
        'BATS測試 東京五日',
        'trace-case-14',
        'reply-token-14',
        'https://api.line.me/v2/bot/message/reply',
        'channel-token-14',
        'travel-b-channel',
        $controlledRealConfigOn,
        null,
        $mockLineSender
    );
    test_assert(is_array($result), 'case14 result array');
    test_assert(($result['message'] ?? '') === 'bats_controlled_real_line_reply', 'case14 real reply message');
    test_assert(
        ($result['controlled_real_reply']['final_route'] ?? '') === 'bats_real_line_reply',
        'case14 payload final_route'
    );
    test_assert(
        (int) ($result['controlled_real_reply']['reply_text_length'] ?? 0) > 0,
        'case14 reply text produced'
    );
    test_assert(true, 'case14 real route PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case14 should pass: ' . $e->getMessage());
}

// Case 15 (26C-7 #2): 東京五日 without prefix -> legacy
try {
    $decision = SaaSRouter::evaluateControlledRealLineReplyGate(
        $tenantTravelBWithKey,
        '東京五日',
        $controlledRealConfigOn
    );
    test_assert(($decision['controlled_real_reply_allowed'] ?? false) === false, 'case15 not allowed');
    test_assert(($decision['final_route'] ?? '') === 'legacy', 'case15 legacy route');
    test_assert(($decision['reason'] ?? '') === 'message_prefix_mismatch', 'case15 prefix reason');
    test_assert(
        SaaSRouter::attemptControlledRealLineReplyPath(
            $tenantTravelBWithKey,
            '東京五日',
            'trace-case-15',
            'reply-token-15',
            'https://api.line.me/v2/bot/message/reply',
            'channel-token-15',
            'travel-b-channel',
            $controlledRealConfigOn,
            null,
            $mockLineSender
        ) === null,
        'case15 path null'
    );
    test_assert(true, 'case15 tour query legacy PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case15 should pass: ' . $e->getMessage());
}

// Case 16 (26C-7 #3): hello -> legacy
try {
    $decision = SaaSRouter::evaluateControlledRealLineReplyGate(
        $tenantTravelBWithKey,
        'hello',
        $controlledRealConfigOn
    );
    test_assert(($decision['controlled_real_reply_allowed'] ?? false) === false, 'case16 hello legacy');
    test_assert(true, 'case16 hello legacy PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case16 should pass: ' . $e->getMessage());
}

// Case 17 (26C-7 #4): non travel_b tenant -> legacy
try {
    $decision = SaaSRouter::evaluateControlledRealLineReplyGate(
        ['sno' => 'aaaaaaaaaaaaaaaa', 'tenant_key' => 'travel_a'],
        'BATS測試 東京五日',
        $controlledRealConfigOn
    );
    test_assert(($decision['controlled_real_reply_allowed'] ?? false) === false, 'case17 travel_a blocked');
    test_assert(($decision['reason'] ?? '') === 'tenant_sno_not_allowlisted', 'case17 sno reason');
    test_assert(true, 'case17 other tenant PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case17 should pass: ' . $e->getMessage());
}

// Case 18 (26C-7 #5): travel_b key but wrong sno -> legacy
try {
    $decision = SaaSRouter::evaluateControlledRealLineReplyGate(
        ['sno' => 'bbbbbbbbbbbbbbbb', 'tenant_key' => 'travel_b'],
        'BATS測試 東京五日',
        $controlledRealConfigOn
    );
    test_assert(($decision['controlled_real_reply_allowed'] ?? false) === false, 'case18 wrong sno');
    test_assert(true, 'case18 wrong sno PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case18 should pass: ' . $e->getMessage());
}

// Case 19 (26C-7 #6): prefix only BATS測試 -> allow + safe reply
try {
    $decision = SaaSRouter::evaluateControlledRealLineReplyGate(
        $tenantTravelBWithKey,
        'BATS測試',
        $controlledRealConfigOn
    );
    test_assert(($decision['controlled_real_reply_allowed'] ?? false) === true, 'case19 prefix-only allowed');
    $result = SaaSRouter::attemptControlledRealLineReplyPath(
        $tenantTravelBWithKey,
        'BATS測試',
        'trace-case-19',
        'reply-token-19',
        'https://api.line.me/v2/bot/message/reply',
        'channel-token-19',
        'travel-b-channel',
        $controlledRealConfigOn,
        null,
        $mockLineSender
    );
    test_assert(is_array($result), 'case19 result');
    test_assert(
        (int) ($result['controlled_real_reply']['reply_text_length'] ?? 0) > 0,
        'case19 reply text or fallback'
    );
    test_assert(true, 'case19 prefix-only PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case19 should pass: ' . $e->getMessage());
}

// Case 20: real gate disabled -> evaluate legacy, path null
try {
    $decision = SaaSRouter::evaluateControlledRealLineReplyGate(
        $tenantTravelBWithKey,
        'BATS測試 北海道',
        $controlledRealConfigOff
    );
    test_assert(($decision['controlled_real_reply_allowed'] ?? false) === false, 'case20 flag off');
    test_assert(($decision['reason'] ?? '') === 'real_reply_disabled', 'case20 disabled reason');
    test_assert(true, 'case20 real flag off PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case20 should pass: ' . $e->getMessage());
}

// Case 21: router orders real gate before preview gate
try {
    $routerSource = (string) file_get_contents(
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'saas_router.php'
    );
    $realPos = strpos($routerSource, 'evaluateControlledRealLineReplyGate');
    $previewPos = strpos($routerSource, 'attemptControlledReplyPath');
    test_assert($realPos !== false && $previewPos !== false && $realPos < $previewPos, 'case21 real before preview');
    test_assert(true, 'case21 router order PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case21 should pass: ' . $e->getMessage());
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_saas_router_bats_hook (all passed)\n");
exit(0);
