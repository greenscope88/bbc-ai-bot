<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceContractException.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SourcePlatformContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantSourceInstanceContract.php';

/**
 * Builds base/search entry URLs from platform + tenant instance + URL template config (Phase 9-B-9).
 *
 * Core logic is identifier_type-driven only. Platform names must not appear here.
 * No keyword/date/area search parameters; no HTTP calls.
 */
final class SourceInstanceUrlTemplateBuilder
{
    /**
     * @param array<string, mixed> $platform
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $urlTemplate
     * @return array{
     *   entry_url: string,
     *   identifier_type: string,
     *   template_id: string,
     *   platform_id: string,
     *   source_instance_id: string
     * }
     */
    public function build(array $platform, array $instance, array $urlTemplate): array
    {
        SourcePlatformContract::validatePlatform($platform);
        TenantSourceInstanceContract::validateInstance($instance, $platform);

        $identifierType = trim((string) $instance['identifier_type']);
        $templateIdentifierType = isset($urlTemplate['identifier_type']) ? trim((string) $urlTemplate['identifier_type']) : '';
        if ($templateIdentifierType === '' || $templateIdentifierType !== $identifierType) {
            throw new ProductSourceContractException(
                'URL_TEMPLATE_IDENTIFIER_MISMATCH',
                'URL template identifier_type must match instance identifier_type.'
            );
        }

        $entryUrl = $this->buildEntryUrlByIdentifierType($identifierType, $platform, $instance, $urlTemplate);

        return [
            'entry_url' => $entryUrl,
            'identifier_type' => $identifierType,
            'template_id' => isset($urlTemplate['template_id']) ? trim((string) $urlTemplate['template_id']) : '',
            'platform_id' => trim((string) $platform['platform_id']),
            'source_instance_id' => trim((string) $instance['source_instance_id']),
        ];
    }

    /**
     * @param array<string, mixed> $platform
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $urlTemplate
     */
    private function buildEntryUrlByIdentifierType(
        string $identifierType,
        array $platform,
        array $instance,
        array $urlTemplate
    ): string {
        if ($identifierType === 'subdomain') {
            return $this->buildSubdomainUrl($platform, $instance, $urlTemplate);
        }

        if ($identifierType === 'query_param') {
            return $this->buildQueryParamUrl($platform, $instance, $urlTemplate);
        }

        if ($identifierType === 'affiliate_param') {
            return $this->buildAffiliateParamUrl($platform, $instance, $urlTemplate);
        }

        if ($identifierType === 'fixed_url') {
            return $this->buildFixedUrl($instance, $urlTemplate);
        }

        if ($identifierType === 'custom') {
            return $this->buildCustomUrl($platform, $instance, $urlTemplate);
        }

        throw new ProductSourceContractException(
            'URL_TEMPLATE_UNSUPPORTED_IDENTIFIER',
            'Unsupported identifier_type for URL template builder: ' . $identifierType
        );
    }

    /**
     * @param array<string, mixed> $platform
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $urlTemplate
     */
    private function buildSubdomainUrl(array $platform, array $instance, array $urlTemplate): string
    {
        $values = $this->identifierValues($instance);
        $subdomain = isset($values['subdomain']) ? trim((string) $values['subdomain']) : '';
        if ($subdomain === '') {
            throw new ProductSourceContractException(
                'URL_TEMPLATE_MISSING_IDENTIFIER',
                'subdomain identifier_values.subdomain is required.'
            );
        }

        $platformDomain = trim((string) $platform['platform_domain']);
        $hostPattern = $this->templateString($urlTemplate, 'host_pattern', '{subdomain}.{platform_domain}');
        $host = $this->substitutePlaceholders($hostPattern, [
            'subdomain' => $subdomain,
            'platform_domain' => $platformDomain,
        ], $values);

        return $this->composeUrl(
            $this->templateString($urlTemplate, 'scheme', 'https'),
            $host,
            $this->templateString($urlTemplate, 'path', '/'),
            []
        );
    }

    /**
     * @param array<string, mixed> $platform
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $urlTemplate
     */
    private function buildQueryParamUrl(array $platform, array $instance, array $urlTemplate): string
    {
        $values = $this->identifierValues($instance);
        $baseUrl = $this->resolveBaseUrl($platform, $urlTemplate, $values);
        $queryKeys = $this->resolveQueryParamKeys($platform, $urlTemplate);
        $query = $this->buildOrderedQuery($values, $queryKeys);

        return $this->appendQuery($baseUrl, $query);
    }

    /**
     * @param array<string, mixed> $platform
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $urlTemplate
     */
    private function buildAffiliateParamUrl(array $platform, array $instance, array $urlTemplate): string
    {
        $values = $this->identifierValues($instance);
        $baseUrl = $this->resolveBaseUrl($platform, $urlTemplate, $values);
        $queryKeys = $this->resolveQueryParamKeys($platform, $urlTemplate);
        $query = $this->buildOrderedQuery($values, $queryKeys);

        return $this->appendQuery($baseUrl, $query);
    }

    /**
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $urlTemplate
     */
    private function buildFixedUrl(array $instance, array $urlTemplate): string
    {
        $values = $this->identifierValues($instance);
        $url = isset($values['url']) ? trim((string) $values['url']) : '';
        if ($url === '') {
            throw new ProductSourceContractException(
                'URL_TEMPLATE_MISSING_IDENTIFIER',
                'fixed_url identifier_values.url is required.'
            );
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $platform
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $urlTemplate
     */
    private function buildCustomUrl(array $platform, array $instance, array $urlTemplate): string
    {
        $values = $this->identifierValues($instance);
        $pattern = isset($urlTemplate['url_pattern']) ? trim((string) $urlTemplate['url_pattern']) : '';
        if ($pattern === '') {
            throw new ProductSourceContractException(
                'URL_TEMPLATE_MISSING_PATTERN',
                'custom identifier_type requires url_template.url_pattern.'
            );
        }

        $platformDomain = trim((string) $platform['platform_domain']);

        return $this->substitutePlaceholders($pattern, [
            'platform_domain' => $platformDomain,
        ], $values);
    }

    /**
     * @param array<string, mixed> $platform
     * @param array<string, mixed> $urlTemplate
     * @param array<string, string> $values
     */
    private function resolveBaseUrl(array $platform, array $urlTemplate, array $values): string
    {
        if (isset($urlTemplate['base_url']) && trim((string) $urlTemplate['base_url']) !== '') {
            return rtrim(trim((string) $urlTemplate['base_url']), '?&');
        }

        $platformDomain = trim((string) $platform['platform_domain']);
        $hostPattern = $this->templateString($urlTemplate, 'host_pattern', '{platform_domain}');
        $host = $this->substitutePlaceholders($hostPattern, [
            'platform_domain' => $platformDomain,
        ], $values);

        return $this->composeUrl(
            $this->templateString($urlTemplate, 'scheme', 'https'),
            $host,
            $this->templateString($urlTemplate, 'path', '/'),
            []
        );
    }

    /**
     * @param array<string, mixed> $platform
     * @param array<string, mixed> $urlTemplate
     * @return list<string>
     */
    private function resolveQueryParamKeys(array $platform, array $urlTemplate): array
    {
        if (isset($urlTemplate['query_param_keys']) && is_array($urlTemplate['query_param_keys'])) {
            return $this->normalizeStringList($urlTemplate['query_param_keys']);
        }

        if (isset($platform['identifier_param_keys']) && is_array($platform['identifier_param_keys'])) {
            return $this->normalizeStringList($platform['identifier_param_keys']);
        }

        return [];
    }

    /**
     * @param array<string, string> $values
     * @param list<string> $orderedKeys
     * @return array<string, string>
     */
    private function buildOrderedQuery(array $values, array $orderedKeys): array
    {
        $query = [];
        if ($orderedKeys !== []) {
            foreach ($orderedKeys as $key) {
                if (!isset($values[$key])) {
                    continue;
                }
                $value = trim((string) $values[$key]);
                if ($value !== '') {
                    $query[$key] = $value;
                }
            }

            return $query;
        }

        foreach ($values as $key => $value) {
            $trimmed = trim((string) $value);
            if ($trimmed !== '') {
                $query[$key] = $trimmed;
            }
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $instance
     * @return array<string, string>
     */
    private function identifierValues(array $instance): array
    {
        $raw = $instance['identifier_values'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $out[$key] = trim((string) $value);
        }

        return $out;
    }

    /**
     * @param array<string, string> $fixed
     * @param array<string, string> $extra
     */
    private function substitutePlaceholders(string $pattern, array $fixed, array $extra): string
    {
        $merged = array_merge($extra, $fixed);
        $result = $pattern;
        foreach ($merged as $key => $value) {
            $result = str_replace('{' . $key . '}', $value, $result);
        }

        return $result;
    }

    /**
     * @param array<string, string> $query
     */
    private function composeUrl(string $scheme, string $host, string $path, array $query): string
    {
        $normalizedPath = $path !== '' ? $path : '/';
        if ($normalizedPath[0] !== '/') {
            $normalizedPath = '/' . $normalizedPath;
        }

        $url = $scheme . '://' . $host . $normalizedPath;
        if ($query === []) {
            return $url;
        }

        return $this->appendQuery($url, $query);
    }

    /**
     * @param array<string, string> $query
     */
    private function appendQuery(string $baseUrl, array $query): string
    {
        if ($query === []) {
            return $baseUrl;
        }

        $separator = strpos($baseUrl, '?') === false ? '?' : '&';

        return $baseUrl . $separator . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed> $template
     */
    private function templateString(array $template, string $key, string $default): string
    {
        if (!isset($template[$key])) {
            return $default;
        }

        $value = trim((string) $template[$key]);

        return $value !== '' ? $value : $default;
    }

    /**
     * @param mixed $list
     * @return list<string>
     */
    private function normalizeStringList($list): array
    {
        if (!is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $item) {
            if (!is_string($item)) {
                continue;
            }
            $trimmed = trim($item);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }

        return $out;
    }
}
