<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'legacy_storefront_crypto.php';

/**
 * Builds bonusmee tour detail URLs for LINE / Gemini context (Host A only).
 */
final class TourDetailUrlBuilder
{
    private const DEFAULT_DETAIL_PATH = 'https://bonusmee.com/view/cloud/tourdate_dm.php';

    private LegacyStorefrontCrypto $crypto;

    public function __construct(?LegacyStorefrontCrypto $crypto = null)
    {
        $this->crypto = $crypto ?? LegacyStorefrontCrypto::fromAppConfig();
    }

    /**
     * @param int|string|null $storeNo
     * @param int|string|null $couponNo
     * @param int|string|null $tourSeqNo
     * @param array<string, mixed> $options baseUrl (string)
     *
     * @return string|null Full detail URL, or null when inputs/crypto unavailable (fail closed).
     */
    public function buildDetailUrl($storeNo, $couponNo, $tourSeqNo, array $options = []): ?string
    {
        if (!$this->crypto->isConfigured()) {
            return null;
        }

        $storeToken = self::normalizeRequiredScalar($storeNo);
        $couponToken = self::normalizeRequiredScalar($couponNo);
        $tourSeqToken = self::normalizeRequiredScalar($tourSeqNo);

        if ($storeToken === null || $couponToken === null || $tourSeqToken === null) {
            return null;
        }

        $encryptedStore = $this->crypto->encryptString($storeToken);
        $encryptedCoupon = $this->crypto->encryptString($couponToken);

        if ($encryptedStore === null || $encryptedCoupon === null) {
            return null;
        }

        $baseUrl = isset($options['baseUrl']) && is_string($options['baseUrl']) && trim($options['baseUrl']) !== ''
            ? trim($options['baseUrl'])
            : self::DEFAULT_DETAIL_PATH;

        $query = http_build_query([
            'openExternalBrowser' => '1',
            'red2Store' => '1',
            'srcPg' => 'CDST',
            'sno' => $encryptedStore,
            'cid' => $encryptedCoupon,
            'trsno' => $tourSeqToken,
            'suld' => '1',
        ]);

        return $baseUrl . '?' . $query;
    }

    /**
     * @param mixed $value
     */
    private static function normalizeRequiredScalar($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            if ($value <= 0) {
                return null;
            }

            return (string) $value;
        }

        if (is_float($value)) {
            if ($value <= 0) {
                return null;
            }

            return (string) (int) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }

        $asInt = (int) $trimmed;
        if ($asInt <= 0) {
            return null;
        }

        return (string) $asInt;
    }
}
