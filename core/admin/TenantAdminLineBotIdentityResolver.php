<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . 'LineCredentialResolver.php';

/**
 * Server-side LINE Bot Identity Authority — GET /v2/bot/info → userId.
 * Access token only via LineCredentialResolver; never returns or logs tokens.
 */
final class TenantAdminLineBotIdentityResolver
{
    public const BOT_INFO_URL = 'https://api.line.me/v2/bot/info';
    public const USER_ID_PATTERN = '/^U[0-9a-fA-F]{32}$/';

    /** @var callable|null fn(string $url, string $accessToken): array{http_status:int,body:string} */
    private $httpTransport;

    public function __construct(?callable $httpTransport = null)
    {
        $this->httpTransport = $httpTransport;
    }

    /**
     * @return array{
     *   ok: bool,
     *   reason?: string,
     *   user_id?: string,
     *   display_name?: string,
     *   basic_id?: string,
     *   fingerprint?: string
     * }
     */
    public function resolveByCredentialEnvPrefix(string $credentialEnvPrefix, ?string $expectedTenantKey = null): array
    {
        $prefix = trim($credentialEnvPrefix);
        if ($prefix === '') {
            return ['ok' => false, 'reason' => 'missing_credential_env_prefix'];
        }
        if ($expectedTenantKey !== null && trim($expectedTenantKey) !== '') {
            // Bind call site intent; prefix itself remains Credential Authority input.
            $expectedTenantKey = trim($expectedTenantKey);
        }

        $cred = LineCredentialResolver::resolveAccessTokenByCredentialEnvPrefix($prefix);
        if (($cred['ok'] ?? false) !== true) {
            return ['ok' => false, 'reason' => 'credential_' . (string) ($cred['errorCode'] ?? 'missing')];
        }
        $token = (string) ($cred['channel_access_token'] ?? '');
        if ($token === '') {
            return ['ok' => false, 'reason' => 'credential_MISSING_CREDENTIALS'];
        }

        try {
            $resp = $this->httpGetBotInfo($token);
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'bot_info_transport_error'];
        } finally {
            $token = '';
            unset($cred);
        }

        $status = (int) ($resp['http_status'] ?? 0);
        $body = (string) ($resp['body'] ?? '');
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'reason' => 'bot_info_http_' . $status];
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return ['ok' => false, 'reason' => 'bot_info_invalid_json'];
        }
        $userId = trim((string) ($json['userId'] ?? ''));
        if ($userId === '' || preg_match(self::USER_ID_PATTERN, $userId) !== 1) {
            return ['ok' => false, 'reason' => 'bot_info_invalid_user_id'];
        }

        return [
            'ok' => true,
            'user_id' => $userId,
            'display_name' => trim((string) ($json['displayName'] ?? '')),
            'basic_id' => trim((string) ($json['basicId'] ?? '')),
            'fingerprint' => self::fingerprint($userId),
        ];
    }

    public static function fingerprint(string $userId): string
    {
        return hash('sha256', 'line_bot_user_id|' . trim($userId));
    }

    public static function maskUserId(string $userId): string
    {
        $userId = trim($userId);
        $len = strlen($userId);
        if ($len <= 8) {
            return str_repeat('*', max(0, $len));
        }

        return substr($userId, 0, 4) . '…' . substr($userId, -4);
    }

    /**
     * @return array{http_status:int,body:string}
     */
    private function httpGetBotInfo(string $accessToken): array
    {
        if (is_callable($this->httpTransport)) {
            $out = ($this->httpTransport)(self::BOT_INFO_URL, $accessToken);
            if (!is_array($out)) {
                throw new \RuntimeException('invalid_transport_result');
            }

            return [
                'http_status' => (int) ($out['http_status'] ?? 0),
                'body' => (string) ($out['body'] ?? ''),
            ];
        }

        $ch = curl_init(self::BOT_INFO_URL);
        if ($ch === false) {
            throw new \RuntimeException('curl_init_failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body)) {
            $body = '';
        }

        return ['http_status' => $status, 'body' => $body];
    }
}
