<?php
declare(strict_types=1);

/**
 * BDS Phase 6B-3 — classified Drive folder scan errors.
 */
final class BdsDriveFolderScanException extends \RuntimeException
{
    public const NOT_FOUND = 'not_found';

    public const PERMISSION_DENIED = 'permission_denied';

    public const NOT_A_FOLDER = 'not_a_folder';

    public const REGISTRY_MISSING = 'registry_missing';

    public const REGISTRY_NOT_FOUND = 'registry_not_found';

    public const SCAN_FAILED = 'scan_failed';

    /** @var string */
    private $errorCode;

    /** @var string|null */
    private $folderId;

    public function __construct(
        string $message,
        string $errorCode,
        ?string $folderId = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
        $this->folderId = $folderId;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getFolderId(): ?string
    {
        return $this->folderId;
    }
}
