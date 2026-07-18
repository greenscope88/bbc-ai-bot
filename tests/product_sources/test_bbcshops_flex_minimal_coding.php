<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'LineJsonEncoder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'line_service.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'legacy_storefront_crypto.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_detail_url_builder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ShortUrlProviderInterface.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'MockShortUrlProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'published' . DIRECTORY_SEPARATOR . 'PublishedProductSetSelector.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'published' . DIRECTORY_SEPARATOR . 'PublicationIntegrityValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'BbcshopsFlexCarouselRenderer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'LineFlexCarouselPayloadValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'facts' . DIRECTORY_SEPARATOR . 'GroundedListingLinkFactBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'facts' . DIRECTORY_SEPARATOR . 'GroundedListingLinkFactsValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'grounding' . DIRECTORY_SEPARATOR . 'GroundingPipelineRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'grounding' . DIRECTORY_SEPARATOR . 'GroundingRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'grounding' . DIRECTORY_SEPARATOR . 'GroundingAssemblyContext.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'response' . DIRECTORY_SEPARATOR . 'GroundedResponseComposer.php';

$failures = 0;
$dbShortUrlCalls = 0;

function bbcflex_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/**
 * Host B TourSearchService::mapItem()-equivalent shape — no public URL fields.
 *
 * @return array<string, mixed>
 */
function host_b_normalized_item(int $i): array
{
    return [
        'title' => '東京行程 ' . $i,
        'price' => 30000 + $i,
        'tourDate' => '2026-08-' . sprintf('%02d', min($i, 28)),
        'couponNo' => 1000 + $i,
        'tourSeqNo' => 2000 + $i,
        'areaNames' => '東京',
        'departureStr' => '台北',
        'storeName' => 'BBC Travel',
    ];
}

/**
 * Test-only counter over formal ShortUrlProviderInterface — never calls ShortUrl_Add / DB.
 */
final class CountingShortUrlProvider implements ShortUrlProviderInterface
{
    /** @var ShortUrlProviderInterface */
    private $inner;

    public int $detailCalls = 0;

    public int $searchCalls = 0;

    public function __construct(ShortUrlProviderInterface $inner)
    {
        $this->inner = $inner;
    }

    public function shortenSearchUrl(string $longUrl, array $context): string
    {
        ++$this->searchCalls;

        return $this->inner->shortenSearchUrl($longUrl, $context);
    }

    public function shortenDetailUrl(string $longUrl, array $context): string
    {
        ++$this->detailCalls;

        return $this->inner->shortenDetailUrl($longUrl, $context);
    }
}

final class FailingShortUrlProvider implements ShortUrlProviderInterface
{
    public int $detailCalls = 0;

    public function shortenSearchUrl(string $longUrl, array $context): string
    {
        return $longUrl;
    }

    public function shortenDetailUrl(string $longUrl, array $context): string
    {
        ++$this->detailCalls;

        return 'https://bonusmee.com/view/cloud/tourdate_dm.php?fail=1';
    }
}

$detailBuilder = new TourDetailUrlBuilder(new LegacyStorefrontCrypto('testkey8'));
$mockShort = new MockShortUrlProvider();
$counter = new CountingShortUrlProvider($mockShort);
$selector = new PublishedProductSetSelector($detailBuilder, $counter);

// 1–2: normalized shape has no primary_url; enrichment yields bbcshops short URL
$raw1 = host_b_normalized_item(1);
bbcflex_assert(!array_key_exists('primary_url', $raw1) && !array_key_exists('url', $raw1), 'normalized item starts without primary_url/url');
$set1 = $selector->select([$raw1], 168);
bbcflex_assert($set1->getCount() === 1, '1 successful item → 1 Published Product');
$pub1 = $set1->getProducts()[0];
bbcflex_assert(preg_match('~^https://bbcshops\.com/[^/\s?#]+$~', (string) $pub1['primary_url']) === 1, 'enriched URL is https://bbcshops.com/{code}');
bbcflex_assert(strpos($pub1['primary_url'], 'bonusmee.com') === false, 'customer URL is not bonusmee');
bbcflex_assert($counter->detailCalls === 1, 'exactly one Short URL detail call for 1 item');
bbcflex_assert($dbShortUrlCalls === 0, 'Short URL DB call count remains 0');

// 3–5: 12 / 13 / 30 cap and call stop
$counter12 = new CountingShortUrlProvider(new MockShortUrlProvider());
$sel12 = new PublishedProductSetSelector($detailBuilder, $counter12);
$rows12 = [];
for ($i = 1; $i <= 12; $i++) {
    $rows12[] = host_b_normalized_item($i);
}
$set12 = $sel12->select($rows12, 168);
bbcflex_assert($set12->getCount() === 12, '12 successful → 12 Published Products');
bbcflex_assert($counter12->detailCalls === 12, '12 Short URL calls for 12 successes');

$counter13 = new CountingShortUrlProvider(new MockShortUrlProvider());
$sel13 = new PublishedProductSetSelector($detailBuilder, $counter13);
$rows13 = [];
for ($i = 1; $i <= 13; $i++) {
    $rows13[] = host_b_normalized_item($i);
}
$set13 = $sel13->select($rows13, 168);
bbcflex_assert($set13->getCount() === 12, '13 eligible → publish first 12 only');
bbcflex_assert($counter13->detailCalls === 12, 'Short URL calls stop after 12 successes');

$counter30 = new CountingShortUrlProvider(new MockShortUrlProvider());
$sel30 = new PublishedProductSetSelector($detailBuilder, $counter30);
$rows30 = [];
for ($i = 1; $i <= 30; $i++) {
    $rows30[] = host_b_normalized_item($i);
}
$set30 = $sel30->select($rows30, 168);
bbcflex_assert($set30->getCount() === 12, '30 eligible → publish first 12 only');
bbcflex_assert($counter30->detailCalls === 12, '30-case Short URL calls stop at 12');
bbcflex_assert($set30->getProducts()[0]['couponNo'] === '1001', 'preserves eligible order (first coupon)');
bbcflex_assert($set30->getProducts()[11]['couponNo'] === '1012', 'preserves eligible order (12th coupon)');

// 6: earlier URL fail, later success — keep order and continue
$failThenOk = new CountingShortUrlProvider(new class implements ShortUrlProviderInterface {
    private int $n = 0;

    public function shortenSearchUrl(string $longUrl, array $context): string
    {
        return $longUrl;
    }

    public function shortenDetailUrl(string $longUrl, array $context): string
    {
        ++$this->n;
        if ($this->n === 1) {
            return 'https://bonusmee.com/bad';
        }

        return (new MockShortUrlProvider())->shortenDetailUrl($longUrl, $context);
    }
});
$selSkip = new PublishedProductSetSelector($detailBuilder, $failThenOk);
$setSkip = $selSkip->select([host_b_normalized_item(1), host_b_normalized_item(2)], 168);
bbcflex_assert($setSkip->getCount() === 1, 'URL fail then success publishes the success');
bbcflex_assert($setSkip->getProducts()[0]['couponNo'] === '1002', 'keeps eligible order of first success');

// 7: all URL failures → technical fail-closed via pipeline
$failAll = new FailingShortUrlProvider();
$selFail = new PublishedProductSetSelector($detailBuilder, $failAll);
$failPipeline = GroundingPipelineRuntime::composeBbcshopsFlexMultiSourceReply([
    'search_results' => [host_b_normalized_item(1), host_b_normalized_item(2)],
    'storeNo' => 168,
    'published_product_set_selector' => $selFail,
    'tenant' => ['tenant_sno' => '5f99b8d665e8444d'],
]);
bbcflex_assert($failPipeline['validation_passed'] === false, 'all URL failures → validation_passed=false');
bbcflex_assert(($failPipeline['failure_reason'] ?? '') === 'bbcshops_published_set_empty_despite_eligible', 'all URL failures → technical fail-closed reason');
$failMsgs = $failPipeline['output']->getChannelMessages()->getMessages();
bbcflex_assert(count($failMsgs) === 1 && ($failMsgs[0]['type'] ?? '') === 'text', 'fail-closed has one text message, no bubble');
bbcflex_assert(strpos((string) ($failMsgs[0]['text'] ?? ''), 'bonusmee.com') === false, 'fail-closed text has no bonusmee URL');

// 8: missing storeNo / couponNo must not call Short URL
$cMiss = new CountingShortUrlProvider(new MockShortUrlProvider());
$selMiss = new PublishedProductSetSelector($detailBuilder, $cMiss);
$missingCoupon = host_b_normalized_item(1);
$missingCoupon['couponNo'] = null;
bbcflex_assert($selMiss->select([$missingCoupon], 168)->getCount() === 0, 'missing couponNo not published');
bbcflex_assert($cMiss->detailCalls === 0, 'missing couponNo does not call Short URL');
bbcflex_assert($selMiss->select([host_b_normalized_item(1)], null)->getCount() === 0, 'missing storeNo not published');
bbcflex_assert($cMiss->detailCalls === 0, 'missing storeNo does not call Short URL');

// 9–10: bonusmee / malformed bbcshops rejected
$malformed = new CountingShortUrlProvider(new class implements ShortUrlProviderInterface {
    public function shortenSearchUrl(string $longUrl, array $context): string
    {
        return $longUrl;
    }

    public function shortenDetailUrl(string $longUrl, array $context): string
    {
        return 'https://bbcshops.com/';
    }
});
bbcflex_assert(
    (new PublishedProductSetSelector($detailBuilder, $malformed))->select([host_b_normalized_item(1)], 168)->getCount() === 0,
    'malformed bbcshops URL (empty code) not published'
);

// 11: duplicate product identity → no second Short URL call
$cDup = new CountingShortUrlProvider(new MockShortUrlProvider());
$selDup = new PublishedProductSetSelector($detailBuilder, $cDup);
$dupA = host_b_normalized_item(1);
$dupB = host_b_normalized_item(1);
$dupB['tourDate'] = '2026-09-01';
bbcflex_assert($selDup->select([$dupA, $dupB], 168)->getCount() === 1, 'duplicate coupon identity publishes once');
bbcflex_assert($cDup->detailCalls === 1, 'duplicate product does not repeat Short URL call');

// 12: Products = Facts = Bubbles = Product refs via Production pipeline
$pipeCounter = new CountingShortUrlProvider(new MockShortUrlProvider());
$pipeSelector = new PublishedProductSetSelector($detailBuilder, $pipeCounter);
$pipeline = GroundingPipelineRuntime::composeBbcshopsFlexMultiSourceReply([
    'search_results' => [host_b_normalized_item(1), host_b_normalized_item(2)],
    'storeNo' => 168,
    'search_url' => 'https://bbcshops.com/s/GVPHP',
    'search_url_role' => 'search',
    'multi_source_links' => [
        ['platform' => 'grp', 'search_url' => 'https://bbcshops.com/s/grp'],
    ],
    'published_product_set_selector' => $pipeSelector,
    'tenant' => ['tenant_sno' => '5f99b8d665e8444d', 'company_name' => 'BBC Travel'],
]);
$output = $pipeline['output'];
bbcflex_assert($output->isValidationPassed(), 'pipeline validation passes with enriched URLs');
bbcflex_assert($output->getReplyType() === ReplyType::NORMAL, 'positive reply is normal_reply');
$messages = $output->getChannelMessages()->getMessages();
bbcflex_assert(count($messages) === 3, 'text + flex + other-source');
bbcflex_assert(($messages[1]['type'] ?? '') === 'flex', 'message 2 is Flex carousel');
$bubbleCount = count($messages[1]['contents']['contents'] ?? []);
$productRefs = array_values(array_filter($output->getReferencedFactIds(), static function (string $id): bool {
    return strpos($id, 'bbcshops:') === 0 && strpos($id, 'link:') !== 0;
}));
bbcflex_assert($bubbleCount === 2 && count($productRefs) === 2, 'published products = bubbles = product refs');
bbcflex_assert($pipeCounter->detailCalls === 2, 'pipeline Short URL detail calls = published count');

// 13: Host B API endpoint / mapping unchanged (static contract in TourPromptContextService)
$tpsSource = file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php');
bbcflex_assert(
    is_string($tpsSource) && strpos($tpsSource, "/api/tour/search") !== false,
    'Host B API path /api/tour/search unchanged'
);

// 14: Generative ON/OFF via formal pipeline entry — same Published Set fact IDs
$genSelectorFactory = static function () use ($detailBuilder): PublishedProductSetSelector {
    return new PublishedProductSetSelector($detailBuilder, new CountingShortUrlProvider(new MockShortUrlProvider()));
};
$genInput = [
    'search_results' => [host_b_normalized_item(3), host_b_normalized_item(4)],
    'storeNo' => 168,
    'tenant' => ['tenant_sno' => '5f99b8d665e8444d'],
];
$genOn = GroundingPipelineRuntime::composeBbcshopsFlexMultiSourceReply(array_merge($genInput, [
    'published_product_set_selector' => $genSelectorFactory(),
    'config' => [GroundedResponseComposer::FEATURE_GROUNDED_COMPOSER_GENERATIVE_ENABLED => true],
]));
$genOff = GroundingPipelineRuntime::composeBbcshopsFlexMultiSourceReply(array_merge($genInput, [
    'published_product_set_selector' => $genSelectorFactory(),
    'config' => [GroundedResponseComposer::FEATURE_GROUNDED_COMPOSER_GENERATIVE_ENABLED => false],
]));
$idsOn = array_values(array_filter($genOn['output']->getReferencedFactIds(), static function (string $id): bool {
    return preg_match('#^bbcshops:\\d+:\\d+$#', $id) === 1;
}));
$idsOff = array_values(array_filter($genOff['output']->getReferencedFactIds(), static function (string $id): bool {
    return preg_match('#^bbcshops:\\d+:\\d+$#', $id) === 1;
}));
bbcflex_assert($idsOn === $idsOff && $idsOn !== [], 'Generative ON/OFF yield same product fact IDs via pipeline');

// 15: Formatter zero-reference on formal saas_router BBCShops product branch
$routerSrc = file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'saas_router.php');
bbcflex_assert(is_string($routerSrc), 'saas_router readable');
$composePos = strpos((string) $routerSrc, 'composeBbcshopsFlexMultiSourceReply');
$formatterPos = strpos((string) $routerSrc, 'TourFallbackFormatter::');
bbcflex_assert($composePos !== false, 'saas_router wires composeBbcshopsFlexMultiSourceReply');
bbcflex_assert($formatterPos === false, 'saas_router has zero TourFallbackFormatter:: calls after 06B-4 product wiring');

// 16: waiting後唯一 final — exclusive reply XOR push with same typed payload
$shared = $output->getChannelMessages();
bbcflex_assert($shared instanceof LineMessagePayload, 'typed channel payload present');
$waitingPaths = [true, false];
foreach ($waitingPaths as $waiting) {
    $sendCount = 0;
    $transport = 'http://127.0.0.1:1/line-stub';
    if ($waiting) {
        $res = LineService::pushMessages($transport, 'tok', 'Uuser', $shared);
        ++$sendCount;
        bbcflex_assert(isset($res['request_payload']['messages']), 'push path builds messages payload');
        bbcflex_assert($res['request_payload']['messages'] === $shared->getMessages(), 'push preserves typed messages');
    } else {
        $res = LineService::replyMessages($transport, 'tok', 'reply-token', $shared);
        ++$sendCount;
        bbcflex_assert(isset($res['request_payload']['messages']), 'reply path builds messages payload');
        bbcflex_assert($res['request_payload']['messages'] === $shared->getMessages(), 'reply preserves typed messages');
    }
    bbcflex_assert($sendCount === 1, 'waiting path sends exactly once (reply XOR push)');
}

// 17: replyMessages / pushMessages same typed messages; Flex not rewrapped as text
$replyRes = LineService::replyMessages('http://127.0.0.1:1/r', 't', 'rt', $shared);
$pushRes = LineService::pushMessages('http://127.0.0.1:1/p', 't', 'uid', $shared);
bbcflex_assert($replyRes['request_payload']['messages'] === $pushRes['request_payload']['messages'], 'reply/push share identical messages');
bbcflex_assert(($replyRes['request_payload']['messages'][1]['type'] ?? '') === 'flex', 'Flex not rewrapped as text');

// Fixture primary_url must not be Production authority
$fixtureTrap = host_b_normalized_item(9);
$fixtureTrap['primary_url'] = 'https://bbcshops.com/FIXTURE';
$cTrap = new CountingShortUrlProvider(new MockShortUrlProvider());
$selTrap = new PublishedProductSetSelector($detailBuilder, $cTrap);
$setTrap = $selTrap->select([$fixtureTrap], 168);
bbcflex_assert($setTrap->getCount() === 1, 'item still published via Short URL owner');
bbcflex_assert($setTrap->getProducts()[0]['primary_url'] !== 'https://bbcshops.com/FIXTURE', 'fixture primary_url is not authority');
bbcflex_assert($cTrap->detailCalls === 1, 'fixture primary_url still goes through Short URL owner');

// Flex boundary / integrity retained
$renderer = new BbcshopsFlexCarouselRenderer();
$render = $renderer->render($set12);
bbcflex_assert($render->getBubbleCount() === 12, '12 bubbles legal');
(new LineFlexCarouselPayloadValidator())->validate($render->getWireFlexMessage());
$tooMany = $render->getWireFlexMessage();
$tooMany['contents']['contents'][] = $tooMany['contents']['contents'][0];
try {
    (new LineFlexCarouselPayloadValidator())->validate($tooMany);
    bbcflex_assert(false, '13 bubbles rejected');
} catch (RuntimeException $e) {
    bbcflex_assert(true, '13 bubbles rejected');
}

/**
 * Legal Flex bubble with bbcshops URI (slash path for encoding coverage) and ASCII pad text.
 *
 * @return array<string, mixed>
 */
function bbcflex_boundary_bubble(string $padText): array
{
    return [
        'type' => 'bubble',
        'size' => 'mega',
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => '東京行程邊界測試',
                    'weight' => 'bold',
                    'wrap' => true,
                ],
                [
                    'type' => 'text',
                    'text' => $padText,
                    'wrap' => true,
                    'size' => 'sm',
                ],
            ],
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                [
                    'type' => 'button',
                    'style' => 'primary',
                    'action' => [
                        'type' => 'uri',
                        'label' => '查看行程',
                        'uri' => 'https://bbcshops.com/Ab1/Cd2',
                    ],
                ],
            ],
        ],
    ];
}

/**
 * @return array{type: string, contents: list<array<string, mixed>>}
 */
function bbcflex_boundary_carousel(string $padText): array
{
    return [
        'type' => 'carousel',
        'contents' => [bbcflex_boundary_bubble($padText)],
    ];
}

/**
 * Build carousel whose LineJsonEncoder JSON is exactly $targetBytes (JSON_UNESCAPED_UNICODE).
 *
 * @return array{type: string, contents: list<array<string, mixed>>}
 */
function bbcflex_exact_byte_carousel(int $targetBytes): array
{
    $base = bbcflex_boundary_carousel('');
    $baseLen = strlen(LineJsonEncoder::encode($base));
    if ($baseLen > $targetBytes) {
        throw new RuntimeException('bbcflex_boundary_base_exceeds_target:' . $baseLen . '>' . $targetBytes);
    }

    $padLen = $targetBytes - $baseLen;
    $carousel = bbcflex_boundary_carousel(str_repeat('x', $padLen));
    $encodedLen = strlen(LineJsonEncoder::encode($carousel));
    if ($encodedLen !== $targetBytes) {
        // ASCII pad is 1:1 under JSON_UNESCAPED_UNICODE; nudge if ever off.
        $delta = $targetBytes - $encodedLen;
        $padLen += $delta;
        if ($padLen < 0) {
            throw new RuntimeException('bbcflex_boundary_pad_underflow');
        }
        $carousel = bbcflex_boundary_carousel(str_repeat('x', $padLen));
        $encodedLen = strlen(LineJsonEncoder::encode($carousel));
    }
    if ($encodedLen !== $targetBytes) {
        throw new RuntimeException(
            'bbcflex_boundary_exact_size_unreachable:got=' . $encodedLen . ';want=' . $targetBytes
        );
    }

    return $carousel;
}

/**
 * @param array{type: string, contents: list<array<string, mixed>>} $carousel
 * @return array<string, mixed>
 */
function bbcflex_wire_flex(array $carousel): array
{
    return [
        'type' => 'flex',
        'altText' => 'BBCShops 行程推薦',
        'contents' => $carousel,
    ];
}

// Exact 50KB carousel JSON boundary via real LineFlexCarouselPayloadValidator + LineJsonEncoder
$flexValidator = new LineFlexCarouselPayloadValidator();
$boundaryResults = [];
foreach ([49999, 50000, 50001] as $targetBytes) {
    $carousel = bbcflex_exact_byte_carousel($targetBytes);
    $encodedBytes = strlen(LineJsonEncoder::encode($carousel));
    bbcflex_assert($encodedBytes === $targetBytes, "encoded carousel bytes exact {$targetBytes}");
    bbcflex_assert(($carousel['type'] ?? '') === 'carousel', "carousel type for {$targetBytes}");
    bbcflex_assert(count($carousel['contents']) === 1, "bubble count 1 for {$targetBytes}");
    bbcflex_assert(
        strpos((string) ($carousel['contents'][0]['footer']['contents'][0]['action']['uri'] ?? ''), 'https://bbcshops.com/') === 0,
        "bbcshops URI retained for {$targetBytes}"
    );

    $wire = bbcflex_wire_flex($carousel);
    if ($targetBytes <= 50000) {
        try {
            $flexValidator->validate($wire);
            $boundaryResults[$targetBytes] = 'PASS';
            bbcflex_assert(true, "validator accepts {$targetBytes}-byte carousel");
            echo "50KB boundary: {$targetBytes} PASS (encoded={$encodedBytes})\n";
        } catch (RuntimeException $e) {
            $boundaryResults[$targetBytes] = 'FAIL:' . $e->getMessage();
            bbcflex_assert(false, "validator must accept {$targetBytes}-byte carousel; got " . $e->getMessage());
            echo "50KB boundary: {$targetBytes} FAIL (" . $e->getMessage() . ")\n";
        }
    } else {
        try {
            $flexValidator->validate($wire);
            $boundaryResults[$targetBytes] = 'UNEXPECTED_PASS';
            bbcflex_assert(false, 'validator must reject 50001-byte carousel');
            echo "50KB boundary: 50001 UNEXPECTED_PASS (encoded={$encodedBytes})\n";
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            bbcflex_assert($msg === 'line_flex_carousel_json_too_large', '50001 reject reason line_flex_carousel_json_too_large');
            $boundaryResults[$targetBytes] = 'REJECT:' . $msg;
            echo "50KB boundary: 50001 REJECT line_flex_carousel_json_too_large (encoded={$encodedBytes})\n";
        }
    }
}
bbcflex_assert(
    ($boundaryResults[49999] ?? '') === 'PASS'
    && ($boundaryResults[50000] ?? '') === 'PASS'
    && strpos((string) ($boundaryResults[50001] ?? ''), 'REJECT:line_flex_carousel_json_too_large') === 0,
    '50KB boundary triad closed'
);

// Option B raw
$assembleContext = GroundingAssemblyContext::fromArray([
    'trace_id' => 't-1',
    'conversation_id' => 'c-1',
    'tenant_sno' => '5f99b8d665e8444d',
    'customer_query' => '東京',
    'runtime_type' => RuntimeType::PRODUCT_SEARCH,
    'source_type' => GroundedInput::SOURCE_PRODUCT_SEARCH,
    'runtime_result' => [
        'reply_text' => 'raw-reply',
        'grounded' => true,
        'recommendation_summary' => ['result_count' => 1],
        'product_list' => [host_b_normalized_item(1)],
    ],
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_PRODUCT_REPLY],
    'tenant' => ['tenant_sno' => '5f99b8d665e8444d'],
]);
$assembled = (new GroundingRuntime())->assemble($assembleContext);
bbcflex_assert($assembled->getRawRuntimeResult() !== [], 'Option B assemble preserves raw');
bbcflex_assert(!array_key_exists('raw_runtime_result', $assembled->toArray()), 'toArray hides raw');

bbcflex_assert($dbShortUrlCalls === 0, 'Short URL DB calls remain 0 for entire focused suite');

if ($failures > 0) {
    fwrite(STDERR, "bbcshops flex minimal coding failed: {$failures}\n");
    exit(1);
}

echo "bbcshops flex minimal coding tests passed\n";
