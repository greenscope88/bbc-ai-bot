<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'IndustrySharedKnowledgeGcsPathConfig.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'IndustrySharedKnowledgeProviderInterface.php';

/**
 * Local fixture provider for industry shared knowledge runtime tests.
 */
final class LocalIndustrySharedKnowledgeProvider implements IndustrySharedKnowledgeProviderInterface
{
    /** @var string */
    private $industryCode;

    /** @var string */
    private $fixtureRoot;

    public function __construct(string $industryCode, string $fixtureRoot)
    {
        $industryCode = trim($industryCode);
        $fixtureRoot = trim($fixtureRoot);
        if ($industryCode === '' || $fixtureRoot === '') {
            throw new InvalidArgumentException('industry_code and fixture_root are required.');
        }
        if (!is_dir($fixtureRoot)) {
            throw new InvalidArgumentException('fixture_root does not exist: ' . $fixtureRoot);
        }

        $this->industryCode = $industryCode;
        $this->fixtureRoot = rtrim($fixtureRoot, "/\\");
    }

    public function getIndustryCode(): string
    {
        return $this->industryCode;
    }

    public function fetchFaqDocument(): array
    {
        $path = $this->fixtureRoot . DIRECTORY_SEPARATOR . IndustrySharedKnowledgeGcsPathConfig::FAQ_FILE;
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
