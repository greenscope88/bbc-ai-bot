<?php
declare(strict_types=1);

/**
 * Legacy bonusmee storefront encryption (DES-ECB, PKCS#7, hex output).
 * Compatible with www/obj/lib/Jack.php::encryptStr + DES.php.
 *
 * Key must come from env only (BONUSMEE_STOREFRONT_DATA_KEY). Never log the key.
 */
final class LegacyStorefrontCrypto
{
    private const CIPHER_METHOD = 'DES-ECB';

    private string $key;

    public function __construct(string $key)
    {
        $this->key = $key;
    }

    public static function fromAppConfig(): self
    {
        if (function_exists('app_config_get')) {
            $key = (string) app_config_get('storefront.bonusmee_data_key', '');
        } else {
            $key = '';
        }

        return new self($key);
    }

    public function isConfigured(): bool
    {
        return trim($this->key) !== '';
    }

    /**
     * @return string|null Hex ciphertext, or null when key missing or encrypt fails.
     */
    public function encryptString(string $plain): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $padded = $this->pkcsPadding($plain, 8);
        $encrypted = openssl_encrypt(
            $padded,
            self::CIPHER_METHOD,
            $this->key,
            OPENSSL_RAW_DATA | OPENSSL_NO_PADDING,
            ''
        );

        if ($encrypted === false) {
            return null;
        }

        return bin2hex($encrypted);
    }

    private function pkcsPadding(string $str, int $blockSize): string
    {
        $pad = $blockSize - (strlen($str) % $blockSize);

        return $str . str_repeat(chr($pad), $pad);
    }
}
