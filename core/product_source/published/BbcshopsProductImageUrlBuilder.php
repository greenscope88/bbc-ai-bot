<?php
declare(strict_types=1);

/**
 * Unique BBCShops LINE product-image URL owner.
 *
 * Formula (storefront-proven):
 * https://kowanbo.com/pubimg/coupon/{depID}/{storeNo}/[{couponAttr==99 ? "tour/" : ""}]{dmFile}
 */
final class BbcshopsProductImageUrlBuilder
{
    private const MEDIA_BASE = 'https://kowanbo.com';

    private const ALLOWED_EXTENSIONS = [
        'jpg' => true,
        'jpeg' => true,
        'png' => true,
        'gif' => true,
        'webp' => true,
    ];

    /**
     * @param mixed $dmFile
     * @param mixed $depID
     * @param mixed $storeNo
     * @param mixed $couponAttr
     */
    public function build($dmFile, $depID, $storeNo, $couponAttr): ?string
    {
        $file = $this->safeImageBasename($dmFile);
        if ($file === null) {
            return null;
        }

        $depToken = $this->positiveIntegerToken($depID);
        $storeToken = $this->positiveIntegerToken($storeNo);
        if ($depToken === null || $storeToken === null) {
            return null;
        }

        $tourSegment = $this->isTourCouponAttr($couponAttr) ? 'tour/' : '';
        $url = self::MEDIA_BASE
            . '/pubimg/coupon/'
            . $depToken
            . '/'
            . $storeToken
            . '/'
            . $tourSegment
            . $file;

        if (stripos($url, 'https://') !== 0) {
            return null;
        }

        return $url;
    }

    /**
     * @param mixed $value
     */
    private function safeImageBasename($value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        if (strpos($raw, '..') !== false
            || strpos($raw, '/') !== false
            || strpos($raw, '\\') !== false
        ) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9._-]+$/', $raw) !== 1) {
            return null;
        }
        $dot = strrpos($raw, '.');
        if ($dot === false || $dot === 0 || $dot === strlen($raw) - 1) {
            return null;
        }
        $ext = strtolower(substr($raw, $dot + 1));
        if (!isset(self::ALLOWED_EXTENSIONS[$ext])) {
            return null;
        }

        return $raw;
    }

    /**
     * @param mixed $value
     */
    private function positiveIntegerToken($value): ?string
    {
        if (is_int($value) && $value > 0) {
            return (string) $value;
        }
        if (is_float($value) && $value > 0 && floor($value) === $value) {
            return (string) (int) $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1 && (int) trim($value) > 0) {
            return (string) (int) trim($value);
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private function isTourCouponAttr($value): bool
    {
        if (is_int($value)) {
            return $value === 99;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value) === 99;
        }

        return false;
    }
}
