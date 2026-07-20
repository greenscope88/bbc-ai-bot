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
        'dmFile' => 'photo' . $i . '.jpg',
        'depID' => 10,
        'storeNo' => 168,
        'couponAttr' => 0,
        'schLinks' => ['https://agt.tw/item' . $i],
        'tourDays' => 0,
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
$dupSet = $selDup->select([$dupA, $dupB], 168);
bbcflex_assert($dupSet->getCount() === 1, 'duplicate coupon identity publishes once');
bbcflex_assert($cDup->detailCalls === 1, 'duplicate product does not repeat Short URL call');
bbcflex_assert(
    $dupSet->getProducts()[0]['departure_dates'] === ['2026-08-01', '2026-09-01'],
    'duplicate identity aggregates and sorts departure dates'
);

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
 * Legal Flex bubble for byte-boundary tests — must satisfy schema-slot URI contract.
 *
 * @return array<string, mixed>
 */
function bbcflex_boundary_bubble(string $padText): array
{
    return [
        'type' => 'bubble',
        'size' => 'mega',
        'hero' => [
            'type' => 'image',
            'url' => 'https://kowanbo.com/pubimg/coupon/1/1/boundary.jpg',
            'size' => 'full',
            'aspectRatio' => '1:1',
            'aspectMode' => 'cover',
        ],
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
            'layout' => 'horizontal',
            'contents' => [
                [
                    'type' => 'button',
                    'style' => 'primary',
                    'color' => '#06C755',
                    'action' => [
                        'type' => 'uri',
                        'label' => '詳細內容',
                        'uri' => 'https://bbcshops.com/Ab1Cd',
                    ],
                ],
                [
                    'type' => 'button',
                    'style' => 'primary',
                    'color' => '#1E88E5',
                    'action' => [
                        'type' => 'uri',
                        'label' => '行程表',
                        'uri' => 'https://agt.tw/bound1',
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
        "detail slot URI retained for {$targetBytes}"
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

// ---- 06B-5G Flex UX / aggregation / label ----

// Identity 1/2/19 rows → one product; dates deduped ascending; renderer shows nearest 6 MM/DD
$aggRows = [];
for ($i = 1; $i <= 19; $i++) {
    $row = host_b_normalized_item(1);
    $day = (($i - 1) % 10) + 1;
    $row['tourDate'] = sprintf('2027/03/%02d', $day);
    $row['schLinks'] = ['https://agt.tw/first', 'https://agt.tw/second'];
    $row['price'] = 88800;
    $row['tourDays'] = 5;
    $row['dmFile'] = 'hero.jpg';
    $row['depID'] = 12;
    $row['couponAttr'] = 99;
    $aggRows[] = $row;
}
$aggRows[] = host_b_normalized_item(2);
$cAgg = new CountingShortUrlProvider(new MockShortUrlProvider());
$selAgg = new PublishedProductSetSelector($detailBuilder, $cAgg);
$setAgg = $selAgg->select($aggRows, 168);
bbcflex_assert($setAgg->getCount() === 2, '19 same-identity rows + 1 other → 2 published');
$aggProduct = $setAgg->getProducts()[0];
bbcflex_assert($aggProduct['couponNo'] === '1001', 'first identity preserved');
bbcflex_assert(count($aggProduct['departure_dates']) === 10, 'date dedupe across 19 rows');
bbcflex_assert($aggProduct['departure_dates'][0] === '2027-03-01', 'dates ascending first');
bbcflex_assert($aggProduct['departure_dates'][9] === '2027-03-10', 'dates ascending last');
bbcflex_assert($aggProduct['duration_days'] === 5, 'tourDays=5 saved as duration_days');
bbcflex_assert(
    $aggProduct['representative_image_url'] === 'https://kowanbo.com/pubimg/coupon/12/168/tour/hero.jpg',
    'hero kowanbo URL with tour/ segment'
);
bbcflex_assert($aggProduct['itinerary_url'] === 'https://agt.tw/first', 'schLinks ordered first HTTPS wins');
bbcflex_assert($cAgg->detailCalls === 2, 'one Short URL call per published identity');

$renderAgg = (new BbcshopsFlexCarouselRenderer())->render($setAgg);
$wireAgg = $renderAgg->getWireFlexMessage();
(new LineFlexCarouselPayloadValidator())->validate($wireAgg);
$bubble0 = $wireAgg['contents']['contents'][0];
bbcflex_assert(($bubble0['hero']['url'] ?? '') === $aggProduct['representative_image_url'], 'bubble hero matches published image');
bbcflex_assert(($bubble0['hero']['aspectMode'] ?? '') === 'cover', 'hero aspectMode=cover');
bbcflex_assert(($bubble0['hero']['aspectRatio'] ?? '') === '1:1', 'hero fixed aspectRatio');
bbcflex_assert(($bubble0['body']['paddingBottom'] ?? '') === '8px', 'body paddingBottom ~12px gap with footer');
$body0 = $bubble0['body']['contents'];
bbcflex_assert(($body0[0]['maxLines'] ?? null) === 3, 'title maxLines=3');
bbcflex_assert(strpos((string) ($body0[0]['text'] ?? ''), '東京行程') === 0, 'title original text');
$datesText = (string) ($body0[1]['text'] ?? '');
bbcflex_assert($datesText === '出發日期：03/01、03/02、03/03、03/04、03/05、03/06', 'renderer shows nearest 6 MM/DD');
bbcflex_assert(count($aggProduct['departure_dates']) === 10, 'presentation slice does not alter Published count/dates');
bbcflex_assert((string) ($body0[2]['text'] ?? '') === '旅遊天數：5 日', 'tourDays=5 displays duration');
bbcflex_assert((string) ($body0[3]['text'] ?? '') === '出發地：台北', 'departure line');
$priceRow = $body0[4];
bbcflex_assert(($priceRow['layout'] ?? '') === 'horizontal', 'price row horizontal with circular badge');
$priceBox = $priceRow['contents'][0];
bbcflex_assert(($priceBox['layout'] ?? '') === 'baseline', 'price uses baseline box');
$priceVisual = ((string) ($priceBox['contents'][0]['text'] ?? ''))
    . ((string) ($priceBox['contents'][1]['text'] ?? ''))
    . ((string) ($priceBox['contents'][2]['text'] ?? ''));
bbcflex_assert($priceVisual === 'NT$ 88,800 起', 'price visual NT$ 88,800 起');
bbcflex_assert(($priceBox['contents'][1]['weight'] ?? '') === 'bold', 'amount bold');
bbcflex_assert(($priceBox['contents'][1]['size'] ?? '') === 'xl', 'amount larger');
bbcflex_assert(($priceBox['contents'][1]['color'] ?? '') === '#E53935', 'amount red');
bbcflex_assert_circular_badge($bubble0, $priceRow, 0, 2);
$footer0 = $bubble0['footer'];
bbcflex_assert(($footer0['paddingTop'] ?? '') === '4px', 'footer paddingTop ~12px gap with price');
bbcflex_assert(($footer0['layout'] ?? '') === 'horizontal', 'footer buttons horizontal');
bbcflex_assert(($footer0['contents'][0]['color'] ?? '') === '#06C755', '詳細內容 green');
bbcflex_assert(($footer0['contents'][0]['action']['label'] ?? '') === '詳細內容', 'left button label');
bbcflex_assert(($footer0['contents'][0]['action']['uri'] ?? '') === $aggProduct['primary_url'], 'detail URI=primary_url');
bbcflex_assert(($footer0['contents'][1]['color'] ?? '') === '#1E88E5', '行程表 blue');
bbcflex_assert(($footer0['contents'][1]['action']['label'] ?? '') === '行程表', 'right button label');
bbcflex_assert(($footer0['contents'][1]['action']['uri'] ?? '') === $aggProduct['itinerary_url'], 'itinerary URI');

// tourDays=0 / missing → omit duration but still publish
$zeroDays = host_b_normalized_item(3);
$zeroDays['tourDays'] = 0;
$missingDays = host_b_normalized_item(4);
unset($missingDays['tourDays']);
$setDur = (new PublishedProductSetSelector($detailBuilder, new MockShortUrlProvider()))->select([$zeroDays, $missingDays], 168);
bbcflex_assert($setDur->getCount() === 2, 'tourDays 0/missing still publish');
bbcflex_assert($setDur->getProducts()[0]['duration_days'] === null, 'tourDays=0 → duration_days null');
bbcflex_assert($setDur->getProducts()[1]['duration_days'] === null, 'missing tourDays → duration_days null');
$wireDur = (new BbcshopsFlexCarouselRenderer())->render($setDur)->getWireFlexMessage();
foreach ($wireDur['contents']['contents'] as $b) {
    $joined = json_encode($b['body']['contents'], JSON_UNESCAPED_UNICODE);
    bbcflex_assert(strpos((string) $joined, '旅遊天數') === false, 'duration omitted when null');
}

// departure missing → exclude
$noDep = host_b_normalized_item(5);
$noDep['departureStr'] = '';
bbcflex_assert(
    (new PublishedProductSetSelector($detailBuilder, new MockShortUrlProvider()))->select([$noDep], 168)->getCount() === 0,
    'missing departure excluded'
);

// schLinks empty → exclude
$noSch = host_b_normalized_item(6);
$noSch['schLinks'] = [];
unset($noSch['schLink']);
bbcflex_assert(
    (new PublishedProductSetSelector($detailBuilder, new MockShortUrlProvider()))->select([$noSch], 168)->getCount() === 0,
    'schLinks 0 excluded'
);

// schLinks multi → first ordered HTTPS
$multiSch = host_b_normalized_item(7);
$multiSch['schLinks'] = ['https://reurl.cc/second', 'https://agt.tw/third'];
$multiSch['schLink'] = 'https://agt.tw/scalar-last';
$setSch = (new PublishedProductSetSelector($detailBuilder, new MockShortUrlProvider()))->select([$multiSch], 168);
bbcflex_assert($setSch->getProducts()[0]['itinerary_url'] === 'https://reurl.cc/second', 'first ordered schLinks wins');

// Opening label / other-source wording
$labelPipe = GroundingPipelineRuntime::composeBbcshopsFlexMultiSourceReply([
    'search_results' => [host_b_normalized_item(8)],
    'storeNo' => 168,
    'search_url' => 'https://bbcshops.com/s/GVPHP',
    'search_url_role' => 'search',
    'primary_search_display_label' => '日本',
    'multi_source_links' => [
        ['platform' => 'grp', 'search_url' => 'https://example.com/grp'],
        ['platform' => 'bbctravel', 'search_url' => 'https://example.com/bbc'],
        ['platform' => 'tourcenter', 'search_url' => 'https://example.com/tc'],
    ],
    'published_product_set_selector' => new PublishedProductSetSelector($detailBuilder, new MockShortUrlProvider()),
    'tenant' => ['tenant_sno' => '5f99b8d665e8444d'],
]);
bbcflex_assert($labelPipe['validation_passed'] === true, 'label pipeline validation passes');
$labelMsgs = $labelPipe['output']->getChannelMessages()->getMessages();
bbcflex_assert(count($labelMsgs) === 3, 'opening + flex + other-source');
$openText = (string) ($labelMsgs[0]['text'] ?? '');
bbcflex_assert(
    $openText === "以下為您整理日本精選行程。\n更多日本行程：\nhttps://bbcshops.com/s/GVPHP",
    'opening with label exact copy'
);
bbcflex_assert(strpos($openText, 'BBCShops') === false, 'opening does not show platform name');
$otherText = (string) ($labelMsgs[2]['text'] ?? '');
bbcflex_assert(strpos($otherText, '其他【日本】相關行程可參考：') === 0, 'other-source header with label');
bbcflex_assert(strpos($otherText, "一館：\nhttps://example.com/grp") !== false, 'ordinal 一館 label and URL on separate lines');
bbcflex_assert(strpos($otherText, "二館：\nhttps://example.com/bbc") !== false, 'ordinal 二館 multiline');
bbcflex_assert(strpos($otherText, "三館：\nhttps://example.com/tc") !== false, 'ordinal 三館 multiline');
$otherExpected = "其他【日本】相關行程可參考：\n\n一館：\nhttps://example.com/grp\n\n二館：\nhttps://example.com/bbc\n\n三館：\nhttps://example.com/tc";
bbcflex_assert($otherText === $otherExpected, 'other-source multiline exact layout');
bbcflex_assert(strpos($otherText, 'GRP') === false, 'customer text hides GRP');
bbcflex_assert(strpos($otherText, 'BBC Travel') === false, 'customer text hides BBC Travel');
bbcflex_assert(strpos($otherText, 'TourCenter') === false, 'customer text hides TourCenter');

$noLabelPipe = GroundingPipelineRuntime::composeBbcshopsFlexMultiSourceReply([
    'search_results' => [host_b_normalized_item(9)],
    'storeNo' => 168,
    'search_url' => 'https://bbcshops.com/s/GVPHP',
    'search_url_role' => 'search',
    'primary_search_display_label' => null,
    'multi_source_links' => [
        ['platform' => 'grp', 'search_url' => 'https://example.com/only'],
    ],
    'published_product_set_selector' => new PublishedProductSetSelector($detailBuilder, new MockShortUrlProvider()),
    'tenant' => ['tenant_sno' => '5f99b8d665e8444d'],
]);
$noLabelMsgs = $noLabelPipe['output']->getChannelMessages()->getMessages();
bbcflex_assert(
    (string) ($noLabelMsgs[0]['text'] ?? '') === "以下為您整理精選行程。\n更多行程：\nhttps://bbcshops.com/s/GVPHP",
    'opening without label exact copy'
);
bbcflex_assert(strpos((string) ($noLabelMsgs[2]['text'] ?? ''), '其他相關行程可參考：') === 0, 'other-source without label');

$zeroOther = GroundingPipelineRuntime::composeBbcshopsFlexMultiSourceReply([
    'search_results' => [host_b_normalized_item(10)],
    'storeNo' => 168,
    'search_url' => 'https://bbcshops.com/s/GVPHP',
    'search_url_role' => 'search',
    'primary_search_display_label' => '日本',
    'multi_source_links' => [],
    'published_product_set_selector' => new PublishedProductSetSelector($detailBuilder, new MockShortUrlProvider()),
    'tenant' => ['tenant_sno' => '5f99b8d665e8444d'],
]);
bbcflex_assert(count($zeroOther['output']->getChannelMessages()->getMessages()) === 2, 'other-source 0 links → no third message');

// ---- 06B-5H: schema-slot URI negatives + Published→Bubble field integrity ----

/**
 * @return array<string, mixed>
 */
function bbcflex_slot_valid_bubble(): array
{
    return [
        'type' => 'bubble',
        'size' => 'mega',
        'hero' => [
            'type' => 'image',
            'url' => 'https://kowanbo.com/pubimg/coupon/12/168/hero.jpg',
            'size' => 'full',
            'aspectRatio' => '1:1',
            'aspectMode' => 'cover',
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                ['type' => 'text', 'text' => 'slot-valid', 'wrap' => true],
            ],
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'horizontal',
            'contents' => [
                [
                    'type' => 'button',
                    'style' => 'primary',
                    'color' => '#06C755',
                    'action' => [
                        'type' => 'uri',
                        'label' => '詳細內容',
                        'uri' => 'https://bbcshops.com/DET01',
                    ],
                ],
                [
                    'type' => 'button',
                    'style' => 'primary',
                    'color' => '#1E88E5',
                    'action' => [
                        'type' => 'uri',
                        'label' => '行程表',
                        'uri' => 'https://agt.tw/ITIN01',
                    ],
                ],
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function bbcflex_slot_wire(array $bubble): array
{
    return [
        'type' => 'flex',
        'altText' => 'BBCShops 行程推薦',
        'contents' => [
            'type' => 'carousel',
            'contents' => [$bubble],
        ],
    ];
}

function bbcflex_expect_reject(array $wire, string $expectedReason, string $message): void
{
    try {
        (new LineFlexCarouselPayloadValidator())->validate($wire);
        bbcflex_assert(false, $message . ' — expected throw ' . $expectedReason);
    } catch (RuntimeException $e) {
        bbcflex_assert(
            $e->getMessage() === $expectedReason,
            $message . ' — got [' . $e->getMessage() . '] want [' . $expectedReason . ']'
        );
    }
}

/**
 * Circular badge contract — inspects real Renderer payload only (no Renderer mock).
 *
 * @param array<string, mixed> $bubble
 * @param array<string, mixed> $priceRow body contents entry that is the price+badge horizontal row
 */
function bbcflex_assert_circular_badge(array $bubble, array $priceRow, int $bubbleIndex, int $publishedCount): void
{
    $idx = 'bubbleIndex=' . $bubbleIndex;

    bbcflex_assert(($priceRow['type'] ?? null) === 'box', $idx . ' priceRow.type === box');
    bbcflex_assert(($priceRow['layout'] ?? null) === 'horizontal', $idx . ' priceRow.layout === horizontal');
    bbcflex_assert(isset($priceRow['contents']) && is_array($priceRow['contents']), $idx . ' priceRow.contents is array');
    bbcflex_assert(count($priceRow['contents']) === 2, $idx . ' priceRow.contents count === 2 (price+badge)');

    $priceSide = $priceRow['contents'][0];
    $badgeBox = $priceRow['contents'][1];
    bbcflex_assert(is_array($priceSide), $idx . ' priceSide is array');
    bbcflex_assert(is_array($badgeBox), $idx . ' badgeBox is array');

    // --- Badge container (right of price) ---
    bbcflex_assert(array_key_exists('type', $badgeBox), $idx . ' badge.type key exists');
    bbcflex_assert(is_string($badgeBox['type']), $idx . ' badge.type is string');
    bbcflex_assert($badgeBox['type'] === 'box', $idx . ' badge.type === box');

    bbcflex_assert(array_key_exists('width', $badgeBox), $idx . ' badge.width key exists');
    bbcflex_assert(is_string($badgeBox['width']), $idx . ' badge.width is string');
    bbcflex_assert($badgeBox['width'] === '48px', $idx . ' badge.width === 48px');

    bbcflex_assert(array_key_exists('height', $badgeBox), $idx . ' badge.height key exists');
    bbcflex_assert(is_string($badgeBox['height']), $idx . ' badge.height is string');
    bbcflex_assert($badgeBox['height'] === '48px', $idx . ' badge.height === 48px');
    bbcflex_assert($badgeBox['width'] === $badgeBox['height'], $idx . ' badge.width === badge.height');

    bbcflex_assert(array_key_exists('flex', $badgeBox), $idx . ' badge.flex key exists');
    bbcflex_assert(is_int($badgeBox['flex']), $idx . ' badge.flex is int');
    bbcflex_assert($badgeBox['flex'] === 0, $idx . ' badge.flex === 0');

    bbcflex_assert(array_key_exists('backgroundColor', $badgeBox), $idx . ' badge.backgroundColor key exists');
    bbcflex_assert(is_string($badgeBox['backgroundColor']), $idx . ' badge.backgroundColor is string');
    bbcflex_assert($badgeBox['backgroundColor'] === '#000000', $idx . ' badge.backgroundColor === #000000');

    bbcflex_assert(array_key_exists('cornerRadius', $badgeBox), $idx . ' badge.cornerRadius key exists');
    bbcflex_assert(is_string($badgeBox['cornerRadius']), $idx . ' badge.cornerRadius is string');
    bbcflex_assert($badgeBox['cornerRadius'] === '999px', $idx . ' badge.cornerRadius === 999px');

    bbcflex_assert(array_key_exists('justifyContent', $badgeBox), $idx . ' badge.justifyContent key exists');
    bbcflex_assert(is_string($badgeBox['justifyContent']), $idx . ' badge.justifyContent is string');
    bbcflex_assert($badgeBox['justifyContent'] === 'center', $idx . ' badge.justifyContent === center');

    bbcflex_assert(array_key_exists('alignItems', $badgeBox), $idx . ' badge.alignItems key exists');
    bbcflex_assert(is_string($badgeBox['alignItems']), $idx . ' badge.alignItems is string');
    bbcflex_assert($badgeBox['alignItems'] === 'center', $idx . ' badge.alignItems === center');

    bbcflex_assert(!array_key_exists('marginStart', $badgeBox), $idx . ' badge.marginStart absent');
    bbcflex_assert(array_key_exists('spacing', $priceRow), $idx . ' priceRow.spacing key exists');
    bbcflex_assert(is_string($priceRow['spacing']), $idx . ' priceRow.spacing is string');
    bbcflex_assert($priceRow['spacing'] === 'sm', $idx . ' priceRow.spacing === sm');

    bbcflex_assert(!array_key_exists('position', $badgeBox), $idx . ' badge.position absent');
    bbcflex_assert(!array_key_exists('offsetTop', $badgeBox), $idx . ' badge.offsetTop absent');
    bbcflex_assert(!array_key_exists('offsetBottom', $badgeBox), $idx . ' badge.offsetBottom absent');
    bbcflex_assert(!array_key_exists('offsetStart', $badgeBox), $idx . ' badge.offsetStart absent');
    bbcflex_assert(!array_key_exists('offsetEnd', $badgeBox), $idx . ' badge.offsetEnd absent');

    // --- Badge text (exactly one) ---
    bbcflex_assert(array_key_exists('contents', $badgeBox), $idx . ' badge.contents key exists');
    bbcflex_assert(is_array($badgeBox['contents']), $idx . ' badge.contents is array');
    bbcflex_assert(count($badgeBox['contents']) === 1, $idx . ' badge.contents count === 1');

    $badgeTextNode = $badgeBox['contents'][0];
    bbcflex_assert(is_array($badgeTextNode), $idx . ' badgeText is array');
    bbcflex_assert(array_key_exists('type', $badgeTextNode), $idx . ' badgeText.type key exists');
    bbcflex_assert(is_string($badgeTextNode['type']), $idx . ' badgeText.type is string');
    bbcflex_assert($badgeTextNode['type'] === 'text', $idx . ' badgeText.type === text');

    $expectedText = ($bubbleIndex + 1) . '/' . $publishedCount;
    bbcflex_assert(array_key_exists('text', $badgeTextNode), $idx . ' badgeText.text key exists');
    bbcflex_assert(is_string($badgeTextNode['text']), $idx . ' badgeText.text is string');
    bbcflex_assert($badgeTextNode['text'] !== '', $idx . ' badgeText.text not empty');
    bbcflex_assert($badgeTextNode['text'] === $expectedText, $idx . ' badgeText.text === ' . $expectedText);

    bbcflex_assert(array_key_exists('color', $badgeTextNode), $idx . ' badgeText.color key exists');
    bbcflex_assert(is_string($badgeTextNode['color']), $idx . ' badgeText.color is string');
    bbcflex_assert($badgeTextNode['color'] === '#FFFFFF', $idx . ' badgeText.color === #FFFFFF');

    bbcflex_assert(array_key_exists('weight', $badgeTextNode), $idx . ' badgeText.weight key exists');
    bbcflex_assert(is_string($badgeTextNode['weight']), $idx . ' badgeText.weight is string');
    bbcflex_assert($badgeTextNode['weight'] === 'bold', $idx . ' badgeText.weight === bold');

    bbcflex_assert(array_key_exists('align', $badgeTextNode), $idx . ' badgeText.align key exists');
    bbcflex_assert(is_string($badgeTextNode['align']), $idx . ' badgeText.align is string');
    bbcflex_assert($badgeTextNode['align'] === 'center', $idx . ' badgeText.align === center');

    bbcflex_assert(array_key_exists('gravity', $badgeTextNode), $idx . ' badgeText.gravity key exists');
    bbcflex_assert(is_string($badgeTextNode['gravity']), $idx . ' badgeText.gravity is string');
    bbcflex_assert($badgeTextNode['gravity'] === 'center', $idx . ' badgeText.gravity === center');

    // --- Uniqueness: one circular badge on price row; none in hero/footer; no legacy top badge ---
    $bodyContents = $bubble['body']['contents'] ?? null;
    bbcflex_assert(is_array($bodyContents), $idx . ' body.contents is array');
    bbcflex_assert(($bodyContents[0]['type'] ?? null) === 'text', $idx . ' body[0].type === text (no legacy top badge row)');
    bbcflex_assert(($bodyContents[0]['layout'] ?? null) === null, $idx . ' body[0] has no layout (not horizontal badge row)');

    $circularBadgeCount = 0;
    foreach ($bodyContents as $bodyNode) {
        if (!is_array($bodyNode) || ($bodyNode['layout'] ?? null) !== 'horizontal' || !isset($bodyNode['contents']) || !is_array($bodyNode['contents'])) {
            continue;
        }
        foreach ($bodyNode['contents'] as $child) {
            if (!is_array($child)) {
                continue;
            }
            if (
                ($child['type'] ?? null) === 'box'
                && ($child['width'] ?? null) === '48px'
                && ($child['height'] ?? null) === '48px'
                && ($child['backgroundColor'] ?? null) === '#000000'
                && ($child['cornerRadius'] ?? null) === '999px'
            ) {
                ++$circularBadgeCount;
            }
        }
    }
    bbcflex_assert($circularBadgeCount === 1, $idx . ' exactly one circular badge in body price row(s)');

    $heroJson = json_encode($bubble['hero'] ?? null, JSON_UNESCAPED_UNICODE);
    bbcflex_assert(
        is_string($heroJson) && strpos($heroJson, '"cornerRadius":"999px"') === false,
        $idx . ' badge not in hero'
    );
    $footerJson = json_encode($bubble['footer'] ?? null, JSON_UNESCAPED_UNICODE);
    bbcflex_assert(
        is_string($footerJson) && strpos($footerJson, '"cornerRadius":"999px"') === false,
        $idx . ' badge not in footer'
    );
    bbcflex_assert(
        ($priceSide['flex'] ?? null) === 1 || (($priceSide['type'] ?? null) === 'text' && ($priceSide['flex'] ?? null) === 1),
        $idx . ' priceSide.flex === 1 (badge shares price horizontal row)'
    );

    $bubbleJson = json_encode($bubble, JSON_UNESCAPED_UNICODE);
    bbcflex_assert(
        is_string($bubbleJson) && strpos($bubbleJson, 'marginStart') === false,
        $idx . ' bubble JSON contains no marginStart'
    );
}

$slotValidator = new LineFlexCarouselPayloadValidator();
$okWire = bbcflex_slot_wire(bbcflex_slot_valid_bubble());
try {
    $slotValidator->validate($okWire);
    bbcflex_assert(true, '1: normal hero+detail+itinerary passes');
} catch (RuntimeException $e) {
    bbcflex_assert(false, '1: normal hero+detail+itinerary passes; got ' . $e->getMessage());
}

$b = bbcflex_slot_valid_bubble();
$b['footer']['contents'] = [$b['footer']['contents'][0]];
bbcflex_expect_reject(bbcflex_slot_wire($b), 'line_flex_footer_schema_invalid', '2: footer <2 buttons');

$b = bbcflex_slot_valid_bubble();
$b['footer']['contents'][] = $b['footer']['contents'][1];
bbcflex_expect_reject(bbcflex_slot_wire($b), 'line_flex_footer_schema_invalid', '3: footer >2 buttons');

$b = bbcflex_slot_valid_bubble();
$b['footer']['layout'] = 'vertical';
bbcflex_expect_reject(bbcflex_slot_wire($b), 'line_flex_footer_schema_invalid', '4: footer not horizontal');

$b = bbcflex_slot_valid_bubble();
unset($b['footer']['contents'][0]['action']);
bbcflex_expect_reject(bbcflex_slot_wire($b), 'line_flex_footer_slot0_invalid', '5: slot0 missing action');

$b = bbcflex_slot_valid_bubble();
unset($b['footer']['contents'][1]['action']);
bbcflex_expect_reject(bbcflex_slot_wire($b), 'line_flex_footer_slot1_invalid', '6: slot1 missing action');

$b = bbcflex_slot_valid_bubble();
$b['footer']['contents'][0]['action']['type'] = 'message';
bbcflex_expect_reject(bbcflex_slot_wire($b), 'line_flex_footer_slot0_invalid', '7: action.type not uri');

$b = bbcflex_slot_valid_bubble();
$b['footer']['contents'][0]['action']['uri'] = 'https://agt.tw/not-detail';
bbcflex_expect_reject(bbcflex_slot_wire($b), 'line_flex_invalid_detail_url', '8: slot0 agt.tw rejected as detail');

$b = bbcflex_slot_valid_bubble();
$b['footer']['contents'][0]['action']['uri'] = 'https://bonusmee.com/view/cloud/tourdate_dm.php?x=1';
bbcflex_expect_reject(bbcflex_slot_wire($b), 'line_flex_invalid_detail_url', '9: slot0 bonusmee rejected');

$b = bbcflex_slot_valid_bubble();
$b['footer']['contents'][1]['action']['uri'] = 'http://agt.tw/http-only';
bbcflex_expect_reject(bbcflex_slot_wire($b), 'line_flex_invalid_itinerary_url', '10: slot1 HTTP rejected');

$b = bbcflex_slot_valid_bubble();
$b['footer']['contents'][1]['action']['uri'] = 'https:///no-host';
bbcflex_expect_reject(bbcflex_slot_wire($b), 'line_flex_invalid_itinerary_url', '11: slot1 missing host');

$b = bbcflex_slot_valid_bubble();
$b['footer']['contents'][1]['action']['uri'] = 'https://user:pass@agt.tw/secret';
bbcflex_expect_reject(bbcflex_slot_wire($b), 'line_flex_invalid_itinerary_url', '12: slot1 userinfo rejected');

$b = bbcflex_slot_valid_bubble();
$b['footer']['contents'][1]['action']['uri'] = $b['footer']['contents'][0]['action']['uri'];
bbcflex_expect_reject(bbcflex_slot_wire($b), 'line_flex_invalid_itinerary_url', '13: slot1 equals detail rejected');

$b = bbcflex_slot_valid_bubble();
$tmp = $b['footer']['contents'][0]['action']['uri'];
$b['footer']['contents'][0]['action']['uri'] = $b['footer']['contents'][1]['action']['uri'];
$b['footer']['contents'][1]['action']['uri'] = $tmp;
bbcflex_expect_reject(bbcflex_slot_wire($b), 'line_flex_invalid_detail_url', '14: swapped URIs — slot0 still detail-ruled');

$b = bbcflex_slot_valid_bubble();
$b['footer']['contents'][0]['action']['label'] = '行程表';
bbcflex_expect_reject(bbcflex_slot_wire($b), 'line_flex_footer_slot0_invalid', '15a: slot0 wrong label rejected');
$b = bbcflex_slot_valid_bubble();
$b['footer']['contents'][0]['action']['label'] = '行程表';
$b['footer']['contents'][0]['action']['uri'] = 'https://agt.tw/still-detail-slot';
bbcflex_expect_reject(bbcflex_slot_wire($b), 'line_flex_invalid_detail_url', '15b: slot0 still detail-ruled despite label 行程表');

$b = bbcflex_slot_valid_bubble();
$b['footer']['contents'][1]['action']['label'] = '詳細內容';
bbcflex_expect_reject(bbcflex_slot_wire($b), 'line_flex_footer_slot1_invalid', '16: slot1 wrong label rejected');

$b = bbcflex_slot_valid_bubble();
$b['hero']['url'] = 'https://bbcshops.com/not-image';
bbcflex_expect_reject(bbcflex_slot_wire($b), 'line_flex_invalid_hero_url', '17: hero non-kowanbo rejected');

$b = bbcflex_slot_valid_bubble();
$b['hero']['url'] = 'https://kowanbo.com/other/path/hero.jpg';
bbcflex_expect_reject(bbcflex_slot_wire($b), 'line_flex_invalid_hero_url', '18: hero path not /pubimg/coupon/ rejected');

$b = bbcflex_slot_valid_bubble();
$b['hero']['url'] = 'https://user:secret@kowanbo.com/pubimg/coupon/1/1/x.jpg';
bbcflex_expect_reject(bbcflex_slot_wire($b), 'line_flex_invalid_hero_url', '19: hero userinfo rejected');

// Published Product → Bubble field-by-field + Fact Scheme C
$integrityRows = [];
// Product 0: duration=5, >6 dates, price 88800
$p0 = host_b_normalized_item(20);
$p0['title'] = '北海道雪祭七日';
$p0['price'] = 88800;
$p0['departureStr'] = '台北';
$p0['dmFile'] = 'hokkaido.jpg';
$p0['depID'] = 21;
$p0['couponAttr'] = 0;
$p0['tourDays'] = 5;
$p0['schLinks'] = ['https://agt.tw/hokkaido-a', 'https://agt.tw/hokkaido-b'];
$p0['couponNo'] = 5001;
$p0['tourSeqNo'] = 6001;
for ($d = 1; $d <= 8; $d++) {
    $row = $p0;
    $row['tourDate'] = sprintf('2027-02-%02d', $d);
    $integrityRows[] = $row;
}
// Product 1: duration null (tourDays=0), different fields
$p1 = host_b_normalized_item(21);
$p1['title'] = '沖繩潛水三日';
$p1['price'] = 25800;
$p1['departureStr'] = '高雄';
$p1['dmFile'] = 'okinawa.png';
$p1['depID'] = 33;
$p1['couponAttr'] = 99;
$p1['tourDays'] = 0;
$p1['tourDate'] = '2027-04-15';
$p1['schLinks'] = ['https://reurl.cc/oki-1'];
$p1['couponNo'] = 5002;
$p1['tourSeqNo'] = 6002;
$integrityRows[] = $p1;
// Product 2: another distinct product
$p2 = host_b_normalized_item(22);
$p2['title'] = '京都賞楓五日';
$p2['price'] = 39900;
$p2['departureStr'] = '台中';
$p2['dmFile'] = 'kyoto.webp';
$p2['depID'] = 44;
$p2['couponAttr'] = 1;
$p2['tourDays'] = 7;
$p2['tourDate'] = '2027-11-03';
$p2['schLinks'] = ['https://agt.tw/kyoto'];
$p2['couponNo'] = 5003;
$p2['tourSeqNo'] = 6003;
$integrityRows[] = $p2;

$integritySelector = new PublishedProductSetSelector($detailBuilder, new MockShortUrlProvider());
$publishedSet = $integritySelector->select($integrityRows, 168);
bbcflex_assert($publishedSet->getCount() === 3, 'integrity fixture publishes 3 products');
$products = $publishedSet->getProducts();
bbcflex_assert($products[0]['duration_days'] === 5, 'product0 has duration');
bbcflex_assert($products[1]['duration_days'] === null, 'product1 duration null');
bbcflex_assert(count($products[0]['departure_dates']) === 8, 'product0 has >6 dates');

$integrityRender = (new BbcshopsFlexCarouselRenderer())->render($publishedSet);
$wireIntegrity = $integrityRender->getWireFlexMessage();
(new LineFlexCarouselPayloadValidator())->validate($wireIntegrity);
$bubbles = $wireIntegrity['contents']['contents'];
bbcflex_assert(count($bubbles) === $publishedSet->getCount(), 'bubble count = published count');

$productFacts = [];
foreach ($products as $product) {
    $productFacts[] = [
        'fact_id' => (string) $product['fact_id'],
        'fact_type' => 'product_row',
        'value' => (string) $product['title'],
        'source_ref' => 'bbcshops:product',
    ];
}
$factIds = array_values(array_map(static function (array $f): string {
    return (string) $f['fact_id'];
}, $productFacts));
(new PublicationIntegrityValidator())->validate($publishedSet, $productFacts, $integrityRender, $factIds);
bbcflex_assert($integrityRender->getBubbleFactIds() === $factIds, 'bubble fact id order = published order');

foreach ($products as $i => $product) {
    $bubble = $bubbles[$i];
    $body = $bubble['body']['contents'];
    bbcflex_assert(($bubble['hero']['aspectRatio'] ?? '') === '1:1', 'hero 1:1 idx=' . $i);
    bbcflex_assert(($bubble['hero']['url'] ?? '') === $product['representative_image_url'], 'hero URL parity idx=' . $i);
    bbcflex_assert(($body[0]['text'] ?? '') === $product['title'], 'title parity idx=' . $i);
    bbcflex_assert(($body[0]['wrap'] ?? null) === true, 'title wrap idx=' . $i);
    bbcflex_assert(($body[0]['maxLines'] ?? null) === 3, 'title maxLines idx=' . $i);

    $expectedDates = [];
    foreach (array_slice($product['departure_dates'], 0, 6) as $date) {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $m) === 1) {
            $expectedDates[] = $m[2] . '/' . $m[3];
        }
    }
    bbcflex_assert(
        (string) ($body[1]['text'] ?? '') === '出發日期：' . implode('、', $expectedDates),
        'dates parity idx=' . $i
    );

    $cursor = 2;
    if ($product['duration_days'] !== null && (int) $product['duration_days'] > 0) {
        bbcflex_assert(
            (string) ($body[$cursor]['text'] ?? '') === '旅遊天數：' . (int) $product['duration_days'] . ' 日',
            'duration shown idx=' . $i
        );
        ++$cursor;
    } else {
        bbcflex_assert(
            strpos(json_encode($body, JSON_UNESCAPED_UNICODE), '旅遊天數') === false,
            'duration omitted idx=' . $i
        );
    }

    bbcflex_assert(
        (string) ($body[$cursor]['text'] ?? '') === '出發地：' . $product['departure'],
        'departure parity idx=' . $i
    );
    ++$cursor;

    $priceRow = $body[$cursor];
    bbcflex_assert(($priceRow['layout'] ?? '') === 'horizontal', 'price row horizontal idx=' . $i);
    bbcflex_assert_circular_badge($bubble, $priceRow, $i, $publishedSet->getCount());
    $priceBox = $priceRow['contents'][0];
    bbcflex_assert(($priceBox['layout'] ?? '') === 'baseline', 'price baseline idx=' . $i);
    $nt = (string) ($priceBox['contents'][0]['text'] ?? '');
    $amt = (string) ($priceBox['contents'][1]['text'] ?? '');
    $suffix = (string) ($priceBox['contents'][2]['text'] ?? '');
    bbcflex_assert($nt === 'NT$ ', 'NT$ span idx=' . $i);
    bbcflex_assert($suffix === ' 起', '起 span idx=' . $i);
    bbcflex_assert(($priceBox['contents'][1]['weight'] ?? '') === 'bold', 'amount bold idx=' . $i);
    bbcflex_assert(($priceBox['contents'][1]['size'] ?? '') === 'xl', 'amount xl idx=' . $i);
    bbcflex_assert(($priceBox['contents'][1]['color'] ?? '') === '#E53935', 'amount red idx=' . $i);
    $visual = $nt . $amt . $suffix;
    if ((int) $product['price'] === 88800) {
        bbcflex_assert($visual === 'NT$ 88,800 起', 'price visual 88800 idx=' . $i);
    } else {
        $wantAmt = number_format((int) $product['price'], 0, '.', ',');
        bbcflex_assert($visual === 'NT$ ' . $wantAmt . ' 起', 'price visual idx=' . $i);
    }

    $footer = $bubble['footer'];
    bbcflex_assert(($footer['layout'] ?? '') === 'horizontal', 'footer horizontal idx=' . $i);
    bbcflex_assert(($footer['contents'][0]['action']['label'] ?? '') === '詳細內容', 'detail label idx=' . $i);
    bbcflex_assert(($footer['contents'][0]['color'] ?? '') === '#06C755', 'detail color idx=' . $i);
    bbcflex_assert(($footer['contents'][0]['action']['uri'] ?? '') === $product['primary_url'], 'detail URI parity idx=' . $i);
    bbcflex_assert(($footer['contents'][1]['action']['label'] ?? '') === '行程表', 'itinerary label idx=' . $i);
    bbcflex_assert(($footer['contents'][1]['color'] ?? '') === '#1E88E5', 'itinerary color idx=' . $i);
    bbcflex_assert(($footer['contents'][1]['action']['uri'] ?? '') === $product['itinerary_url'], 'itinerary URI parity idx=' . $i);

    // Fact Scheme C
    bbcflex_assert($productFacts[$i]['fact_id'] === $product['fact_id'], 'fact_id parity idx=' . $i);
    bbcflex_assert($productFacts[$i]['value'] === $product['title'], 'fact value=title idx=' . $i);
    bbcflex_assert($factIds[$i] === $product['fact_id'], 'referenced fact id idx=' . $i);
    foreach (['image', 'dates', 'price', 'representative_image_url', 'departure_dates'] as $forbiddenKey) {
        bbcflex_assert(!array_key_exists($forbiddenKey, $productFacts[$i]), 'fact no UX array key ' . $forbiddenKey . ' idx=' . $i);
    }
}
bbcflex_assert(count($productFacts) === $publishedSet->getCount(), 'fact count = published count');

$integrityWireJson = json_encode($wireIntegrity, JSON_UNESCAPED_UNICODE);
bbcflex_assert(
    is_string($integrityWireJson) && strpos($integrityWireJson, 'marginStart') === false,
    'integrity carousel JSON contains no marginStart'
);

bbcflex_assert($dbShortUrlCalls === 0, 'Short URL DB calls remain 0 for entire focused suite');

if ($failures > 0) {
    fwrite(STDERR, "bbcshops flex minimal coding failed: {$failures}\n");
    exit(1);
}

echo "bbcshops flex minimal coding tests passed\n";
