<?php
declare(strict_types=1);

/**
 * Sample URL template registry (Phase 9-B-2). Long URLs only; no live external APIs.
 */
final class UrlTemplateRegistry
{
    public const DEFAULT_SAMPLE_PATH = 'docs/sample/product_source_url_templates.sample.json';

    /** @var array<string, array{template_id: string, kind: string, url: string}> */
    private $templatesById = [];

    /**
     * @param array<string, array{template_id: string, kind: string, url: string}>|null $templatesById
     */
    public function __construct(?array $templatesById = null)
    {
        if ($templatesById !== null) {
            $this->templatesById = $templatesById;

            return;
        }

        $this->templatesById = self::loadTemplatesFromSampleFile(null);
    }

    public static function fromSampleFile(?string $path = null): self
    {
        return new self(self::loadTemplatesFromSampleFile($path));
    }

    /**
     * @return array<string, array{template_id: string, kind: string, url: string}>
     */
    private static function loadTemplatesFromSampleFile(?string $path): array
    {
        $resolvedPath = self::resolvePath($path);
        if (!is_file($resolvedPath)) {
            return [];
        }

        $raw = file_get_contents($resolvedPath);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $rows = $decoded['templates'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        /** @var array<string, array{template_id: string, kind: string, url: string}> $byId */
        $byId = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['template_id']) ? trim((string) $row['template_id']) : '';
            $url = isset($row['url']) ? trim((string) $row['url']) : '';
            if ($id === '' || $url === '') {
                continue;
            }
            $byId[$id] = [
                'template_id' => $id,
                'kind' => isset($row['kind']) ? trim((string) $row['kind']) : 'search',
                'url' => $url,
            ];
        }

        return $byId;
    }

    public function hasTemplate(string $templateId): bool
    {
        $key = trim($templateId);

        return $key !== '' && isset($this->templatesById[$key]);
    }

    /**
     * @return array{template_id: string, kind: string, url: string}|null
     */
    public function getTemplate(string $templateId): ?array
    {
        $key = trim($templateId);
        if ($key === '') {
            return null;
        }

        return $this->templatesById[$key] ?? null;
    }

    public function getTemplateUrl(string $templateId): ?string
    {
        $row = $this->getTemplate($templateId);

        return $row !== null ? $row['url'] : null;
    }

    /**
     * @return list<string>
     */
    public function getTemplateIds(): array
    {
        return array_keys($this->templatesById);
    }

    private static function resolvePath(?string $path): string
    {
        if ($path !== null && trim($path) !== '') {
            $trimmed = trim($path);
            if (self::isAbsolutePath($trimmed)) {
                return $trimmed;
            }

            return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trimmed);
        }

        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::DEFAULT_SAMPLE_PATH);
    }

    private static function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':' && ($path[2] === '/' || $path[2] === '\\');
    }
}
