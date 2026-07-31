<?php
declare(strict_types=1);

/**
 * B0-LINE-01D-3J-11: AiuProductSetContext / AiuProductSetContextResolver contract tests.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/core/intent/AiuProductSetContext.php';
require_once $root . '/core/intent/AiuProductSetContextResolver.php';
require_once $root . '/core/intent/AiuPromptRequest.php';
require_once $root . '/core/intent/AiuPromptBuilder.php';
require_once $root . '/core/intent/AiuGeminiUnderstandingClient.php';
require_once $root . '/core/intent/AiuGeminiUnderstandingClientStub.php';
require_once $root . '/core/intent/AiIntentUnderstandingRuntime.php';
require_once $root . '/core/intent/AiIntentUnderstandingRuntimeSelector.php';
require_once $root . '/core/product_source/ProductSourceRegistry.php';
require_once $root . '/core/product_source/SearchConditionContract.php';
require_once $root . '/core/conversation/ConversationOwner.php';

$failures = 0;

function apsc_assert(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");

        return;
    }
    echo "PASS: {$msg}\n";
}

function apsc_expect_throw(callable $fn, string $label, string $exceptionClass = \Throwable::class): void
{
    try {
        $fn();
        apsc_assert(false, "{$label}: expected throw");
    } catch (\Throwable $e) {
        apsc_assert($e instanceof $exceptionClass, "{$label}: throws " . $exceptionClass);
    }
}

const APSC_REAL_TENANT_SNO = '5f99b8d665e8444d';

// --- Resolver resolves categories/dimensions from the existing registry authority ---
$registry = ProductSourceRegistry::fromLocalFiles();
$expectedCategories = [];
foreach ($registry->getEnabledSources() as $source) {
    foreach ($source->getProductCategories() as $category) {
        if (!in_array($category, $expectedCategories, true)) {
            $expectedCategories[] = $category;
        }
    }
}

$resolver = new AiuProductSetContextResolver();
$context = $resolver->resolve(APSC_REAL_TENANT_SNO);
apsc_assert($expectedCategories !== [], 'registry authority reports at least one enabled-source category');
apsc_assert(
    $context->getSearchableProductCategories() === $expectedCategories,
    'resolver categories equal the registry-derived union (first-seen order, de-duplicated)'
);
apsc_assert(
    $context->getExecutableSearchDimensions() === array_values(SearchConditionContract::OPTIONAL_FIELDS),
    'resolver dimensions equal SearchConditionContract::OPTIONAL_FIELDS'
);
apsc_assert(
    $context->getSearchDomain() === $registry->getCatalogId() . ':' . $registry->getTenantKey(),
    'resolver search_domain derived from registry catalog_id + tenant_key'
);
apsc_assert(
    $context->getResolutionStatus() === AiuProductSetContext::RESOLUTION_STATUS_RESOLVED,
    'resolver resolution_status is resolved'
);
apsc_assert($context->getTenantSno() === APSC_REAL_TENANT_SNO, 'resolver context carries requested tenant_sno');

// --- Tenant scope: unknown/different tenant must not borrow another tenant's context ---
apsc_expect_throw(
    static function () use ($resolver): void {
        $resolver->resolve('some-other-unknown-tenant');
    },
    'resolve() with unknown tenant_sno throws (no borrowing)',
    \RuntimeException::class
);

// --- Blank tenant_sno throws ---
apsc_expect_throw(
    static function () use ($resolver): void {
        $resolver->resolve('');
    },
    'resolve() with blank tenant_sno throws',
    \InvalidArgumentException::class
);
apsc_expect_throw(
    static function () use ($resolver): void {
        $resolver->resolve('   ');
    },
    'resolve() with whitespace-only tenant_sno throws',
    \InvalidArgumentException::class
);

// --- AiuProductSetContext strict validation ---
function apsc_valid_args(): array
{
    return [
        APSC_REAL_TENANT_SNO,
        'catalog:tenant',
        ['group_tour'],
        ['keyword', 'destination'],
        AiuProductSetContext::RESOLUTION_STATUS_RESOLVED,
    ];
}

// sanity: valid args construct fine
$sane = new AiuProductSetContext(...apsc_valid_args());
apsc_assert($sane->getSearchDomain() === 'catalog:tenant', 'sanity: valid AiuProductSetContext constructs');

apsc_expect_throw(static function (): void {
    new AiuProductSetContext('', 'catalog:tenant', ['group_tour'], ['keyword'], AiuProductSetContext::RESOLUTION_STATUS_RESOLVED);
}, 'blank tenantSno throws', \InvalidArgumentException::class);

apsc_expect_throw(static function (): void {
    new AiuProductSetContext(APSC_REAL_TENANT_SNO, '', ['group_tour'], ['keyword'], AiuProductSetContext::RESOLUTION_STATUS_RESOLVED);
}, 'blank searchDomain throws', \InvalidArgumentException::class);

apsc_expect_throw(static function (): void {
    new AiuProductSetContext(APSC_REAL_TENANT_SNO, 'catalog:tenant', [], ['keyword'], AiuProductSetContext::RESOLUTION_STATUS_RESOLVED);
}, 'empty categories throws', \InvalidArgumentException::class);

apsc_expect_throw(static function (): void {
    new AiuProductSetContext(APSC_REAL_TENANT_SNO, 'catalog:tenant', ['group_tour'], [], AiuProductSetContext::RESOLUTION_STATUS_RESOLVED);
}, 'empty dimensions throws', \InvalidArgumentException::class);

apsc_expect_throw(static function (): void {
    new AiuProductSetContext(APSC_REAL_TENANT_SNO, 'catalog:tenant', ['group_tour', 'group_tour'], ['keyword'], AiuProductSetContext::RESOLUTION_STATUS_RESOLVED);
}, 'duplicate categories throw', \InvalidArgumentException::class);

apsc_expect_throw(static function (): void {
    new AiuProductSetContext(APSC_REAL_TENANT_SNO, 'catalog:tenant', ['group_tour'], ['keyword', 'keyword'], AiuProductSetContext::RESOLUTION_STATUS_RESOLVED);
}, 'duplicate dimensions throw', \InvalidArgumentException::class);

apsc_expect_throw(static function (): void {
    new AiuProductSetContext(APSC_REAL_TENANT_SNO, 'catalog:tenant', [1 => 'group_tour', 2 => 'fit'], ['keyword'], AiuProductSetContext::RESOLUTION_STATUS_RESOLVED);
}, 'non-list (sparse-keyed) categories throw', \InvalidArgumentException::class);

apsc_expect_throw(static function (): void {
    new AiuProductSetContext(APSC_REAL_TENANT_SNO, 'catalog:tenant', ['group_tour' => 'group_tour'], ['keyword'], AiuProductSetContext::RESOLUTION_STATUS_RESOLVED);
}, 'non-list (string-keyed) categories throw', \InvalidArgumentException::class);

apsc_expect_throw(static function (): void {
    new AiuProductSetContext(APSC_REAL_TENANT_SNO, 'catalog:tenant', [123], ['keyword'], AiuProductSetContext::RESOLUTION_STATUS_RESOLVED);
}, 'non-string category element throws', \InvalidArgumentException::class);

apsc_expect_throw(static function (): void {
    new AiuProductSetContext(APSC_REAL_TENANT_SNO, 'catalog:tenant', ['   '], ['keyword'], AiuProductSetContext::RESOLUTION_STATUS_RESOLVED);
}, 'blank category element throws', \InvalidArgumentException::class);

apsc_expect_throw(static function (): void {
    new AiuProductSetContext(APSC_REAL_TENANT_SNO, 'catalog:tenant', ['group_tour'], ['keyword'], 'pending');
}, 'wrong resolution status throws', \InvalidArgumentException::class);

apsc_expect_throw(static function (): void {
    new AiuProductSetContext(APSC_REAL_TENANT_SNO, 'catalog:tenant', ['group_tour'], ['keyword'], '');
}, 'blank resolution status throws', \InvalidArgumentException::class);

// --- toPromptFactsArray(): exactly the five allowed keys; no tenant_sno / paths ---
$facts = $sane->toPromptFactsArray();
apsc_assert(
    array_keys($facts) === ['context_version', 'search_domain', 'searchable_product_categories', 'executable_search_dimensions', 'resolution_status'],
    'toPromptFactsArray() returns exactly the five allowed keys in order'
);
apsc_assert(!array_key_exists('tenant_sno', $facts), 'toPromptFactsArray() excludes tenant_sno');
foreach ($facts as $factKey => $factValue) {
    if (is_string($factValue)) {
        apsc_assert(strpos($factValue, '/') === false && strpos($factValue, '\\') === false, "toPromptFactsArray() value for {$factKey} contains no path separators");
    }
}
apsc_assert($facts['context_version'] === AiuProductSetContext::CONTEXT_VERSION, 'toPromptFactsArray() context_version matches constant');

// --- AiuPromptRequest transports the context ---
$refDate = new \DateTimeImmutable('2026-07-30', new \DateTimeZone('Asia/Taipei'));
$reqWithContext = new AiuPromptRequest(
    APSC_REAL_TENANT_SNO,
    'line',
    'utterance',
    [],
    ConversationOwner::AI,
    'active',
    null,
    $refDate,
    null,
    null,
    $context
);
apsc_assert($reqWithContext->hasProductSetContext() === true, 'AiuPromptRequest.hasProductSetContext() true when supplied');
apsc_assert($reqWithContext->getProductSetContext() === $context, 'AiuPromptRequest.getProductSetContext() returns the supplied context');

$reqWithoutContext = new AiuPromptRequest(
    APSC_REAL_TENANT_SNO,
    'line',
    'utterance',
    [],
    ConversationOwner::AI,
    'active',
    null,
    $refDate
);
apsc_assert($reqWithoutContext->hasProductSetContext() === false, 'AiuPromptRequest.hasProductSetContext() false when absent');
apsc_expect_throw(
    static function () use ($reqWithoutContext): void {
        $reqWithoutContext->getProductSetContext();
    },
    'AiuPromptRequest.getProductSetContext() throws when absent',
    \RuntimeException::class
);

// --- AiuPromptBuilder::build() renders [B-03a] with resolved categories/dimensions; [B-03d] references [B-03a] ---
$builder = new AiuPromptBuilder();
$prompt = $builder->build($reqWithContext);
apsc_assert(strpos($prompt, '[B-03a Product-Set Context') !== false, 'build() renders [B-03a] Product-Set Context block');
foreach ($context->getSearchableProductCategories() as $category) {
    apsc_assert(strpos($prompt, $category) !== false, "build() [B-03a] block includes resolved category {$category}");
}
foreach ($context->getExecutableSearchDimensions() as $dimension) {
    apsc_assert(strpos($prompt, $dimension) !== false, "build() [B-03a] block includes resolved dimension {$dimension}");
}
apsc_assert(
    strpos($prompt, '[B-03a] Product-Set Context searchable_product_categories for this request') !== false,
    'build() [B-03d] Step 1 references [B-03a] for the active product set'
);
apsc_assert(
    strpos($prompt, 'Executability MUST be judged against [B-03a] executable_search_dimensions') !== false,
    'build() [B-03d] Step 3 references [B-03a] for executability'
);
apsc_assert(
    strpos($prompt, '[B-03a Product-Set Context') < strpos($prompt, '[B-03 Entity Schema Reference]'),
    'build() places [B-03a] before [B-03] entity schema / [B-03d] rules that consume it'
);

// Resume path uses the same context authority.
$resumeReq = new AiuPromptRequest(
    APSC_REAL_TENANT_SNO,
    'line',
    '9月',
    [],
    ConversationOwner::AI,
    'active',
    null,
    $refDate,
    null,
    ['version' => 1],
    $context
);
$resumePrompt = $builder->build($resumeReq);
apsc_assert(strpos($resumePrompt, '[B-03a Product-Set Context') !== false, 'build() renders [B-03a] on the resume path too');
apsc_assert(
    strpos($resumePrompt, '[B-03a] Product-Set Context searchable_product_categories for this request') !== false,
    'build() resume path [B-03d] Step 1 references [B-03a]'
);
apsc_assert(
    strpos($resumePrompt, 'Executability MUST be judged against [B-03a] executable_search_dimensions') !== false,
    'build() resume path [B-03d] Step 3 references [B-03a]'
);

// --- Fail-closed before any Gemini call: build() throws when the request lacks context ---
$httpCallerInvoked = false;
$client = new AiuGeminiUnderstandingClient(null, static function () use (&$httpCallerInvoked): array {
    $httpCallerInvoked = true;

    throw new \RuntimeException('httpCaller must never be invoked when Product-Set Context is absent');
});
apsc_expect_throw(
    static function () use ($client, $reqWithoutContext): void {
        $client->understand($reqWithoutContext);
    },
    'client.understand() fails closed (via build()) before any Gemini call when context absent',
    \RuntimeException::class
);
apsc_assert($httpCallerInvoked === false, 'fail-closed: httpCaller was never invoked when context absent');

// Sanity: with context supplied, the same client + httpCaller path can be exercised safely.
$httpCallerInvokedOk = false;
$clientOk = new AiuGeminiUnderstandingClient(null, static function (string $promptArg) use (&$httpCallerInvokedOk): array {
    $httpCallerInvokedOk = true;

    return [
        'ok' => true,
        'text' => json_encode([
            'intent' => 'knowledge',
            'entities' => [
                'search_keyword_components' => [],
                'search_keyword_tokens' => [],
            ],
            'confidence' => 0.9,
            'clarification' => ['required' => false, 'reason' => ''],
        ], JSON_UNESCAPED_UNICODE),
        'error' => null,
    ];
});
$clientOk->understand($reqWithContext);
apsc_assert($httpCallerInvokedOk === true, 'sanity: httpCaller is invoked once context is present');

// --- Categories/dimensions are not production hardcode ---
// Note: "destination" and "confidence" are pre-existing AIU entity/output schema field
// names in AiuPromptBuilder.php (unrelated to SearchConditionContract) and are excluded
// from this scan; every other resolved category/dimension must not appear as a literal.
$builderSource = (string) file_get_contents($root . '/core/intent/AiuPromptBuilder.php');
$apscPreexistingSchemaFieldNames = ['destination', 'confidence'];
foreach ($context->getSearchableProductCategories() as $category) {
    if (in_array($category, $apscPreexistingSchemaFieldNames, true)) {
        continue;
    }
    apsc_assert(
        strpos($builderSource, "'{$category}'") === false && strpos($builderSource, "\"{$category}\"") === false,
        "AiuPromptBuilder.php source has no hardcoded category literal {$category}"
    );
}
foreach ($context->getExecutableSearchDimensions() as $dimension) {
    if (in_array($dimension, $apscPreexistingSchemaFieldNames, true)) {
        continue;
    }
    apsc_assert(
        strpos($builderSource, "'{$dimension}'") === false && strpos($builderSource, "\"{$dimension}\"") === false,
        "AiuPromptBuilder.php source has no hardcoded dimension literal {$dimension}"
    );
}

// --- Test-only fixture cases documenting expected Gemini behaviour with grounded context ---
// Geography + standalone catalog restatement (test literal only; not asserted against production prompt).
$fixtureStandaloneCatalog = [
    'utterance' => '我想找日本旅遊團',
    'components' => [
        ['surface' => '日本', 'semantic_role' => 'product_constraint', 'decision' => 'keep', 'decision_reason' => 'destination narrows product set'],
        ['surface' => '旅遊團', 'semantic_role' => 'catalog_object_restatement', 'decision' => 'omit', 'decision_reason' => 'restates catalog inherent class only'],
    ],
    'expected_tokens' => ['日本'],
];
apsc_assert(
    array_column($fixtureStandaloneCatalog['components'], 'decision', 'surface')['日本'] === 'keep',
    'fixture: standalone catalog restatement keeps only the geography constraint'
);
apsc_assert(
    $fixtureStandaloneCatalog['expected_tokens'] === ['日本'],
    'fixture: standalone catalog restatement expected tokens'
);

// Geography + fused catalog restatement (test literal only).
$fixtureFusedCatalog = [
    'utterance' => '你們有日本團體行程嗎',
    'components' => [
        ['surface' => '日本', 'semantic_role' => 'product_constraint', 'decision' => 'keep', 'decision_reason' => 'destination narrows product set'],
        ['surface' => '團體行程', 'semantic_role' => 'catalog_object_restatement', 'decision' => 'omit', 'decision_reason' => 'fused catalog wording restates inherent class'],
    ],
    'expected_tokens' => ['日本'],
];
apsc_assert(
    !in_array('團體行程', $fixtureFusedCatalog['expected_tokens'], true),
    'fixture: fused catalog restatement is decomposed and excluded'
);
apsc_assert(
    $fixtureFusedCatalog['expected_tokens'] === ['日本'],
    'fixture: fused catalog restatement expected tokens'
);

// Geography + valid theme/activity/promotion/POI (test literal only).
$fixtureValidTheme = [
    'utterance' => '我想去泰國浮潛還有找便宜的早鳥優惠行程',
    'components' => [
        ['surface' => '泰國', 'semantic_role' => 'product_constraint', 'decision' => 'keep', 'decision_reason' => 'destination narrows product set'],
        ['surface' => '浮潛', 'semantic_role' => 'product_constraint', 'decision' => 'keep', 'decision_reason' => 'activity narrows product set'],
        ['surface' => '早鳥優惠', 'semantic_role' => 'product_constraint', 'decision' => 'keep', 'decision_reason' => 'promotion narrows product set'],
        ['surface' => '行程', 'semantic_role' => 'catalog_object_restatement', 'decision' => 'omit', 'decision_reason' => 'restates catalog inherent class only'],
    ],
    'expected_tokens' => ['泰國', '浮潛', '早鳥優惠'],
];
apsc_assert(
    $fixtureValidTheme['expected_tokens'] === ['泰國', '浮潛', '早鳥優惠'],
    'fixture: geography + activity + promotion all retained as constraints'
);
apsc_assert(
    !in_array('行程', $fixtureValidTheme['expected_tokens'], true),
    'fixture: generic catalog wording excluded from expected tokens'
);

// --- B0-LINE-01D-3J-11O1: safe observation facts + deterministic fingerprints ---
function apsc_is_fingerprint12(string $value): bool
{
    return strlen($value) === 12 && preg_match('/^[a-f0-9]{12}$/', $value) === 1;
}

$safeObs = $context->toSafeObservationFacts();
$expectedSafeKeys = [
    'context_version',
    'resolution_status',
    'category_count',
    'category_fingerprint',
    'executable_dimension_count',
    'executable_dimension_fingerprint',
    'resolved_context_fingerprint',
];
apsc_assert(array_keys($safeObs) === $expectedSafeKeys, 'toSafeObservationFacts() returns exactly the seven safe keys in order');
apsc_assert($safeObs['context_version'] === AiuProductSetContext::CONTEXT_VERSION, 'safe observation context_version');
apsc_assert($safeObs['resolution_status'] === AiuProductSetContext::RESOLUTION_STATUS_RESOLVED, 'safe observation resolution_status');
apsc_assert($safeObs['category_count'] === count($context->getSearchableProductCategories()), 'safe observation category_count');
apsc_assert(
    $safeObs['executable_dimension_count'] === count($context->getExecutableSearchDimensions()),
    'safe observation executable_dimension_count'
);
foreach (['category_fingerprint', 'executable_dimension_fingerprint', 'resolved_context_fingerprint'] as $fpKey) {
    apsc_assert(apsc_is_fingerprint12((string) $safeObs[$fpKey]), "safe observation {$fpKey} is 12-char lowercase hex");
}
apsc_assert(
    !array_key_exists('tenant_sno', $safeObs)
    && !array_key_exists('search_domain', $safeObs)
    && !array_key_exists('searchable_product_categories', $safeObs)
    && !array_key_exists('executable_search_dimensions', $safeObs),
    'safe observation excludes tenant, search_domain, and full category/dimension lists'
);

$ctxOrderA = new AiuProductSetContext(
    APSC_REAL_TENANT_SNO,
    'catalog_a:tenant_a',
    ['group_tour', 'fit'],
    ['keyword', 'destination'],
    AiuProductSetContext::RESOLUTION_STATUS_RESOLVED
);
$ctxOrderB = new AiuProductSetContext(
    APSC_REAL_TENANT_SNO,
    'catalog_a:tenant_a',
    ['fit', 'group_tour'],
    ['destination', 'keyword'],
    AiuProductSetContext::RESOLUTION_STATUS_RESOLVED
);
$obsA = $ctxOrderA->toSafeObservationFacts();
$obsB = $ctxOrderB->toSafeObservationFacts();
apsc_assert($obsA['category_fingerprint'] === $obsB['category_fingerprint'], 'category fingerprint is order-independent');
apsc_assert(
    $obsA['executable_dimension_fingerprint'] === $obsB['executable_dimension_fingerprint'],
    'dimension fingerprint is order-independent'
);
apsc_assert(
    $obsA['resolved_context_fingerprint'] === $obsB['resolved_context_fingerprint'],
    'resolved context fingerprint is order-independent'
);
apsc_assert($ctxOrderA->getSearchableProductCategories() === ['group_tour', 'fit'], 'original category order preserved on getter A');
apsc_assert($ctxOrderB->getSearchableProductCategories() === ['fit', 'group_tour'], 'original category order preserved on getter B');
apsc_assert(
    $ctxOrderA->toPromptFactsArray()['searchable_product_categories'] === ['group_tour', 'fit'],
    'toPromptFactsArray() category order is never mutated by fingerprinting'
);
apsc_assert($ctxOrderA->getExecutableSearchDimensions() === ['keyword', 'destination'], 'original dimension order preserved on getter A');
apsc_assert($ctxOrderB->getExecutableSearchDimensions() === ['destination', 'keyword'], 'original dimension order preserved on getter B');
apsc_assert(
    $ctxOrderA->toPromptFactsArray()['executable_search_dimensions'] === ['keyword', 'destination'],
    'toPromptFactsArray() dimension order is never mutated by fingerprinting'
);
apsc_assert(
    $ctxOrderB->toPromptFactsArray()['executable_search_dimensions'] === ['destination', 'keyword'],
    'toPromptFactsArray() dimension order is never mutated by fingerprinting (B)'
);

$promptFp = $builder->productSetContextFingerprint($reqWithContext);
apsc_assert(apsc_is_fingerprint12($promptFp), 'productSetContextFingerprint() returns 12-char lowercase hex');
apsc_assert(
    $promptFp === $context->getResolvedContextFingerprint(),
    'prompt fingerprint equals Context resolved_context_fingerprint'
);
apsc_assert(
    $promptFp === $safeObs['resolved_context_fingerprint'],
    'resolved_context_fingerprint matches prompt_context_fingerprint source'
);
$resumePromptFp = $builder->productSetContextFingerprint($resumeReq);
apsc_assert($resumePromptFp === $promptFp, 'resume and non-resume paths share the same prompt context fingerprint');

$authorizedEvent = AiIntentUnderstandingRuntime::buildProductSetContextAuthorizedObservation(
    'trace-obs-1',
    'req-obs-1',
    $safeObs,
    $promptFp
);
$authorizedKeys = [
    'trace_id',
    'request_id',
    'context_version',
    'resolution_status',
    'category_count',
    'category_fingerprint',
    'executable_dimension_count',
    'executable_dimension_fingerprint',
    'resolved_context_fingerprint',
    'prompt_context_fingerprint',
    'context_fingerprint_match',
    'gemini_call_authorized',
];
apsc_assert(array_keys($authorizedEvent) === $authorizedKeys, 'authorized event has exactly the required keys in order');
apsc_assert($authorizedEvent['trace_id'] === 'trace-obs-1', 'authorized event trace_id correlation');
apsc_assert($authorizedEvent['request_id'] === 'req-obs-1', 'authorized event request_id correlation');
apsc_assert($authorizedEvent['context_fingerprint_match'] === true, 'authorized event context_fingerprint_match true');
apsc_assert($authorizedEvent['gemini_call_authorized'] === true, 'authorized event gemini_call_authorized true');
apsc_assert(!array_key_exists('gemini_call_attempted', $authorizedEvent), 'authorized event does not claim gemini_call_attempted');
foreach (['tenant_sno', 'conversation_id', 'utterance', 'prompt', 'search_domain', 'error', 'message'] as $sensitiveKey) {
    apsc_assert(!array_key_exists($sensitiveKey, $authorizedEvent), "authorized event excludes sensitive key {$sensitiveKey}");
}

$failClosedObs = AiIntentUnderstandingRuntimeSelector::buildFailClosedObservation(
    ['trace_id' => 'trace-fc-1', 'request_id' => 'req-fc-1', 'tenant_sno' => 'secret', 'conversation_id' => 'secret-cid'],
    AiIntentUnderstandingRuntimeSelector::FAILURE_REASON_RUNTIME
);
apsc_assert(
    array_keys($failClosedObs) === ['trace_id', 'request_id', 'failure_stage', 'error_code'],
    'fail-closed observation has exactly four safe keys'
);
apsc_assert($failClosedObs['trace_id'] === 'trace-fc-1', 'fail-closed observation trace_id');
apsc_assert($failClosedObs['request_id'] === 'req-fc-1', 'fail-closed observation request_id');
apsc_assert($failClosedObs['failure_stage'] === 'aiu_runtime', 'fail-closed observation failure_stage');
apsc_assert(
    $failClosedObs['error_code'] === AiIntentUnderstandingRuntimeSelector::FAILURE_REASON_RUNTIME,
    'fail-closed observation error_code is stable failure reason'
);
foreach (['tenant_sno', 'conversation_id', 'error', 'message'] as $sensitiveKey) {
    apsc_assert(!array_key_exists($sensitiveKey, $failClosedObs), "fail-closed observation excludes sensitive key {$sensitiveKey}");
}

function apsc_valid_gemini_knowledge_payload(): array
{
    return [
        'intent' => 'knowledge',
        'entities' => [
            'search_keyword_components' => [],
            'search_keyword_tokens' => [],
        ],
        'confidence' => 0.9,
        'clarification' => ['required' => false, 'reason' => ''],
    ];
}

function apsc_runtime_with_resolver_and_client(
    AiuProductSetContextResolver $resolver,
    AiuGeminiUnderstandingClientInterface $client
): AiIntentUnderstandingRuntime {
    return new AiIntentUnderstandingRuntime(
        $client,
        null,
        null,
        null,
        null,
        null,
        $resolver
    );
}

// Runtime success path: context gate passes, Gemini stub invoked exactly once.
$runtimeGeminiCalls = 0;
$runtimeStub = new AiuGeminiUnderstandingClientStub(static function () use (&$runtimeGeminiCalls): array {
    ++$runtimeGeminiCalls;

    return apsc_valid_gemini_knowledge_payload();
});
$runtimeOk = apsc_runtime_with_resolver_and_client(new AiuProductSetContextResolver(), $runtimeStub);
$runtimeOk->understand('observation probe', [
    'tenant_sno' => APSC_REAL_TENANT_SNO,
    'conversation_id' => APSC_REAL_TENANT_SNO . ':line:U-obs-ok',
    'trace_id' => 'trace-runtime-ok',
    'request_id' => 'req-runtime-ok',
]);
apsc_assert($runtimeGeminiCalls === 1, 'runtime success: Gemini stub invoked exactly once after context authorization');

// Failure cases: Gemini/HTTP caller count must remain 0.
$runtimeBlankTenantCalls = 0;
$runtimeBlankTenant = apsc_runtime_with_resolver_and_client(
    new AiuProductSetContextResolver(),
    new AiuGeminiUnderstandingClientStub(static function () use (&$runtimeBlankTenantCalls): array {
        ++$runtimeBlankTenantCalls;

        return apsc_valid_gemini_knowledge_payload();
    })
);
apsc_expect_throw(
    static function () use ($runtimeBlankTenant): void {
        $runtimeBlankTenant->understand('x', [
            'tenant_sno' => '',
            'conversation_id' => APSC_REAL_TENANT_SNO . ':line:U-obs-blank',
        ]);
    },
    'runtime blank tenant fails before Gemini',
    \InvalidArgumentException::class
);
apsc_assert($runtimeBlankTenantCalls === 0, 'runtime blank tenant: Gemini stub never invoked');

$runtimeMismatchCalls = 0;
$runtimeMismatch = apsc_runtime_with_resolver_and_client(
    new AiuProductSetContextResolver(),
    new AiuGeminiUnderstandingClientStub(static function () use (&$runtimeMismatchCalls): array {
        ++$runtimeMismatchCalls;

        return apsc_valid_gemini_knowledge_payload();
    })
);
apsc_expect_throw(
    static function () use ($runtimeMismatch): void {
        $runtimeMismatch->understand('x', [
            'tenant_sno' => 'some-other-unknown-tenant',
            'conversation_id' => APSC_REAL_TENANT_SNO . ':line:U-obs-mismatch',
        ]);
    },
    'runtime tenant mismatch fails before Gemini',
    \RuntimeException::class
);
apsc_assert($runtimeMismatchCalls === 0, 'runtime tenant mismatch: Gemini stub never invoked');

$runtimeRegistryLoadCalls = 0;
$registryLoadResolver = new AiuProductSetContextResolver(null, static function (): ProductSourceRegistry {
    throw new \RuntimeException('registry_load_failure');
});
$runtimeRegistryLoad = apsc_runtime_with_resolver_and_client(
    $registryLoadResolver,
    new AiuGeminiUnderstandingClientStub(static function () use (&$runtimeRegistryLoadCalls): array {
        ++$runtimeRegistryLoadCalls;

        return apsc_valid_gemini_knowledge_payload();
    })
);
apsc_expect_throw(
    static function () use ($runtimeRegistryLoad): void {
        $runtimeRegistryLoad->understand('x', [
            'tenant_sno' => APSC_REAL_TENANT_SNO,
            'conversation_id' => APSC_REAL_TENANT_SNO . ':line:U-obs-regload',
        ]);
    },
    'runtime registry load failure fails before Gemini',
    \RuntimeException::class
);
apsc_assert($runtimeRegistryLoadCalls === 0, 'runtime registry load failure: Gemini stub never invoked');

$emptyUnionRegistry = ProductSourceRegistry::fromLocalFiles();
$tenantRowsProp = new ReflectionProperty(ProductSourceRegistry::class, 'tenantEnabledRows');
$tenantRowsProp->setAccessible(true);
$tenantRowsProp->setValue($emptyUnionRegistry, []);
$runtimeEmptyUnionCalls = 0;
$runtimeEmptyUnion = apsc_runtime_with_resolver_and_client(
    new AiuProductSetContextResolver($emptyUnionRegistry),
    new AiuGeminiUnderstandingClientStub(static function () use (&$runtimeEmptyUnionCalls): array {
        ++$runtimeEmptyUnionCalls;

        return apsc_valid_gemini_knowledge_payload();
    })
);
apsc_expect_throw(
    static function () use ($runtimeEmptyUnion): void {
        $runtimeEmptyUnion->understand('x', [
            'tenant_sno' => APSC_REAL_TENANT_SNO,
            'conversation_id' => APSC_REAL_TENANT_SNO . ':line:U-obs-empty',
        ]);
    },
    'runtime empty category union fails before Gemini',
    \RuntimeException::class
);
apsc_assert($runtimeEmptyUnionCalls === 0, 'runtime empty category union: Gemini stub never invoked');

$clientMissingContextCalls = 0;
$clientMissingContext = new AiuGeminiUnderstandingClient(null, static function () use (&$clientMissingContextCalls): array {
    ++$clientMissingContextCalls;

    return ['ok' => true, 'text' => '{}', 'error' => null];
});
apsc_expect_throw(
    static function () use ($clientMissingContext, $reqWithoutContext): void {
        $clientMissingContext->understand($reqWithoutContext);
    },
    'client missing context fails before HTTP caller',
    \RuntimeException::class
);
apsc_assert($clientMissingContextCalls === 0, 'client missing context: HTTP caller never invoked');

$serializationContext = new AiuProductSetContext(
    APSC_REAL_TENANT_SNO,
    "catalog\xC3\x28",
    ['group_tour'],
    ['keyword'],
    AiuProductSetContext::RESOLUTION_STATUS_RESOLVED
);
$serializationReq = new AiuPromptRequest(
    APSC_REAL_TENANT_SNO,
    'line',
    'x',
    [],
    ConversationOwner::AI,
    'active',
    null,
    $refDate,
    null,
    null,
    $serializationContext
);
$clientSerializationCalls = 0;
$clientSerialization = new AiuGeminiUnderstandingClient(null, static function () use (&$clientSerializationCalls): array {
    ++$clientSerializationCalls;

    return ['ok' => true, 'text' => '{}', 'error' => null];
});
apsc_expect_throw(
    static function () use ($clientSerialization, $serializationReq): void {
        $clientSerialization->understand($serializationReq);
    },
    'serialization failure fails before HTTP caller',
    \RuntimeException::class
);
apsc_assert($clientSerializationCalls === 0, 'serialization failure: HTTP caller never invoked');

// Selector still returns the existing 3-field fail-closed contract on runtime context failure.
$selectorGeminiCalls = 0;
$selectorRuntime = apsc_runtime_with_resolver_and_client(
    new AiuProductSetContextResolver(null, static function (): ProductSourceRegistry {
        throw new \RuntimeException('registry_load_failure');
    }),
    new AiuGeminiUnderstandingClientStub(static function () use (&$selectorGeminiCalls): array {
        ++$selectorGeminiCalls;

        return apsc_valid_gemini_knowledge_payload();
    })
);
$selectorFlagOn = [
    AiIntentUnderstandingRuntimeSelector::FLAG_ENABLED => true,
    AiIntentUnderstandingRuntimeSelector::FLAG_TENANTS => [APSC_REAL_TENANT_SNO],
];
$selectorRes = AiIntentUnderstandingRuntimeSelector::resolve([
    'tenant_sno' => APSC_REAL_TENANT_SNO,
    'conversation_id' => APSC_REAL_TENANT_SNO . ':line:U-obs-sel',
    'message' => 'x',
    'trace_id' => 'trace-sel-fc',
    'request_id' => 'req-sel-fc',
    'config' => $selectorFlagOn,
], $selectorRuntime);
apsc_assert(
    ($selectorRes['runtime_source'] ?? '') === AiIntentUnderstandingRuntimeSelector::SOURCE_FAIL_CLOSED,
    'selector context failure returns fail_closed runtime_source'
);
apsc_assert(
    ($selectorRes['failure_reason'] ?? '') === AiIntentUnderstandingRuntimeSelector::FAILURE_REASON_RUNTIME,
    'selector context failure returns AIU_RUNTIME_FAILURE'
);
apsc_assert(count($selectorRes) === 3, 'selector fail-closed return shape unchanged (3 fields)');
apsc_assert(
    array_key_exists('error', $selectorRes) && is_string($selectorRes['error']) && $selectorRes['error'] !== '',
    'selector fail-closed return still includes error string for callers'
);
apsc_assert($selectorGeminiCalls === 0, 'selector context failure: Gemini stub never invoked');

if ($failures === 0) {
    echo "\nALL PASS test_aiu_product_set_context\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_aiu_product_set_context\n");
exit(1);
