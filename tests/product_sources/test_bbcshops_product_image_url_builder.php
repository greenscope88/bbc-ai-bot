<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'published' . DIRECTORY_SEPARATOR . 'BbcshopsProductImageUrlBuilder.php';

$failures = 0;

function img_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$builder = new BbcshopsProductImageUrlBuilder();

$normal = $builder->build('hero01.jpg', 12, 168, 0);
img_assert(
    $normal === 'https://kowanbo.com/pubimg/coupon/12/168/hero01.jpg',
    'normal path exact HTTPS URL'
);

$attrInt = $builder->build('tour99.jpg', 12, 168, 99);
img_assert(
    $attrInt === 'https://kowanbo.com/pubimg/coupon/12/168/tour/tour99.jpg',
    'couponAttr int 99 inserts tour/'
);

$attrStr = $builder->build('tour99.jpg', 12, 168, '99');
img_assert(
    $attrStr === 'https://kowanbo.com/pubimg/coupon/12/168/tour/tour99.jpg',
    'couponAttr string "99" inserts tour/'
);

img_assert($builder->build('', 12, 168, 0) === null, 'empty dmFile → null');
img_assert($builder->build('   ', 12, 168, 0) === null, 'whitespace dmFile → null');
img_assert($builder->build('../x.jpg', 12, 168, 0) === null, '.. rejected');
img_assert($builder->build('a/b.jpg', 12, 168, 0) === null, 'slash rejected');
img_assert($builder->build('a\\b.jpg', 12, 168, 0) === null, 'backslash rejected');
img_assert($builder->build('doc.pdf', 12, 168, 0) === null, 'non-image extension rejected');
img_assert($builder->build('hero01.jpg', 0, 168, 0) === null, 'illegal depID rejected');
img_assert($builder->build('hero01.jpg', 12, 0, 0) === null, 'illegal storeNo rejected');
img_assert($builder->build('hero01.jpg', 'x', 168, 0) === null, 'non-numeric depID rejected');

$logoLike = $builder->build('store_logo.png', 12, 168, 0);
img_assert(
    $logoLike === 'https://kowanbo.com/pubimg/coupon/12/168/store_logo.png',
    'builder does not special-case logo filename; caller must not pass storeLogoFile'
);
img_assert(strpos((string) $logoLike, 'storeLogo') === false, 'no storeLogo field usage');

foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
    $url = $builder->build('file.' . $ext, 1, 2, 1);
    img_assert(
        $url === 'https://kowanbo.com/pubimg/coupon/1/2/file.' . $ext,
        'extension allowed: ' . $ext
    );
}

if ($failures > 0) {
    fwrite(STDERR, "bbcshops product image url builder failed: {$failures}\n");
    exit(1);
}

echo "bbcshops product image url builder tests passed\n";
