<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeIdentity.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeState.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeStateFactory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeLoadResult.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeMutationResult.php';

/**
 * File-backed Structured Search Resume Store.
 *
 * Single pending JSON per identity; fixed .lock; direct rename publish (no unlink-before-rename).
 */
final class StructuredSearchResumeStateStore
{
    private string $storeDirectory;

    public function __construct(?string $storeDirectory = null)
    {
        $this->storeDirectory = $storeDirectory ?? self::defaultStoreDirectory();
    }

    public static function defaultStoreDirectory(): string
    {
        return dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'structured_search_resume';
    }

    public function getStoreDirectory(): string
    {
        return $this->storeDirectory;
    }

    public function load(
        StructuredSearchResumeIdentity $identity,
        ?\DateTimeImmutable $now = null
    ): StructuredSearchResumeLoadResult {
        $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));
        $path = $this->dataPath($identity);
        if (!is_file($path)) {
            return StructuredSearchResumeLoadResult::notFound();
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return StructuredSearchResumeLoadResult::ioError('read_failed');
        }

        return $this->interpretLoadedBytes($identity, $raw, $now, false);
    }

    public function create(
        StructuredSearchResumeIdentity $identity,
        StructuredSearchResumeState $state,
        string $eventId
    ): StructuredSearchResumeMutationResult {
        $eventId = trim($eventId);
        if ($eventId === '') {
            return StructuredSearchResumeMutationResult::invalidState('create', 'missing_webhook_event_id');
        }
        if ($state->getLastEventId() !== $eventId) {
            return StructuredSearchResumeMutationResult::invalidState('create', 'event_id_mismatch');
        }
        if (!$identity->matchesDocument($state->toArray())) {
            return StructuredSearchResumeMutationResult::identityMismatch('create');
        }

        return $this->withExclusiveLock($identity, function () use ($identity, $state, $eventId) {
            $existing = $this->readFinalUnderLock($identity);
            if ($existing['status'] === StructuredSearchResumeLoadResult::IO_ERROR) {
                return StructuredSearchResumeMutationResult::ioError('create', $existing['reason']);
            }
            if ($existing['status'] === StructuredSearchResumeLoadResult::CORRUPT) {
                return StructuredSearchResumeMutationResult::ioError('create', 'corrupt_final');
            }
            if ($existing['status'] === StructuredSearchResumeLoadResult::IDENTITY_MISMATCH) {
                return StructuredSearchResumeMutationResult::identityMismatch('create', $existing['reason']);
            }
            if ($existing['status'] === StructuredSearchResumeLoadResult::FOUND) {
                /** @var StructuredSearchResumeState $current */
                $current = $existing['state'];
                if ($current->getLastEventId() === $eventId) {
                    return StructuredSearchResumeMutationResult::idempotentReplay('create', $current);
                }

                return StructuredSearchResumeMutationResult::versionConflict(
                    'create',
                    'pending_exists event=' . $current->getLastEventId()
                );
            }

            // EXPIRED / NOT_FOUND → publish as create v1 (caller supplies version 1 state).
            if ($state->getStateVersion() !== 1
                || $state->getLastEventOperation() !== StructuredSearchResumeState::OP_CREATE) {
                return StructuredSearchResumeMutationResult::invalidState('create', 'create_version_contract');
            }

            return $this->publishRename($identity, $state, 'create');
        });
    }

    public function replace(
        StructuredSearchResumeIdentity $identity,
        StructuredSearchResumeState $state,
        string $eventId,
        int $expectedVersion
    ): StructuredSearchResumeMutationResult {
        $eventId = trim($eventId);
        if ($eventId === '') {
            return StructuredSearchResumeMutationResult::invalidState('replace', 'missing_webhook_event_id');
        }
        if ($expectedVersion < 1) {
            return StructuredSearchResumeMutationResult::invalidState('replace', 'expected_version_invalid');
        }
        if (!$identity->matchesDocument($state->toArray())) {
            return StructuredSearchResumeMutationResult::identityMismatch('replace');
        }

        return $this->withExclusiveLock($identity, function () use ($identity, $state, $eventId, $expectedVersion) {
            $existing = $this->readFinalUnderLock($identity);
            if ($existing['status'] === StructuredSearchResumeLoadResult::IO_ERROR) {
                return StructuredSearchResumeMutationResult::ioError('replace', $existing['reason']);
            }
            if ($existing['status'] === StructuredSearchResumeLoadResult::CORRUPT) {
                return StructuredSearchResumeMutationResult::ioError('replace', 'corrupt_final');
            }
            if ($existing['status'] === StructuredSearchResumeLoadResult::IDENTITY_MISMATCH) {
                return StructuredSearchResumeMutationResult::identityMismatch('replace', $existing['reason']);
            }
            if ($existing['status'] !== StructuredSearchResumeLoadResult::FOUND) {
                return StructuredSearchResumeMutationResult::versionConflict('replace', 'pending_absent');
            }

            /** @var StructuredSearchResumeState $current */
            $current = $existing['state'];
            if ($current->getLastEventId() === $eventId) {
                return StructuredSearchResumeMutationResult::idempotentReplay('replace', $current);
            }
            if ($current->getStateVersion() !== $expectedVersion) {
                return StructuredSearchResumeMutationResult::versionConflict(
                    'replace',
                    'expected=' . $expectedVersion . ' actual=' . $current->getStateVersion()
                );
            }
            if ($state->getStateVersion() !== $expectedVersion + 1
                || $state->getLastEventOperation() !== StructuredSearchResumeState::OP_REPLACE) {
                return StructuredSearchResumeMutationResult::invalidState('replace', 'replace_version_contract');
            }

            return $this->publishRename($identity, $state, 'replace');
        });
    }

    public function clear(
        StructuredSearchResumeIdentity $identity,
        int $expectedVersion
    ): StructuredSearchResumeMutationResult {
        if ($expectedVersion < 1) {
            return StructuredSearchResumeMutationResult::invalidState('clear', 'expected_version_invalid');
        }

        return $this->withExclusiveLock($identity, function () use ($identity, $expectedVersion) {
            $existing = $this->readFinalUnderLock($identity);
            if ($existing['status'] === StructuredSearchResumeLoadResult::IO_ERROR) {
                return StructuredSearchResumeMutationResult::ioError('clear', $existing['reason']);
            }
            if ($existing['status'] === StructuredSearchResumeLoadResult::CORRUPT) {
                return StructuredSearchResumeMutationResult::ioError('clear', 'corrupt_final');
            }
            if ($existing['status'] === StructuredSearchResumeLoadResult::IDENTITY_MISMATCH) {
                return StructuredSearchResumeMutationResult::identityMismatch('clear', $existing['reason']);
            }
            if ($existing['status'] !== StructuredSearchResumeLoadResult::FOUND) {
                return StructuredSearchResumeMutationResult::alreadyAbsent();
            }

            /** @var StructuredSearchResumeState $current */
            $current = $existing['state'];
            if ($current->getStateVersion() !== $expectedVersion) {
                return StructuredSearchResumeMutationResult::versionConflict(
                    'clear',
                    'expected=' . $expectedVersion . ' actual=' . $current->getStateVersion()
                );
            }

            $path = $this->dataPath($identity);
            if (!@unlink($path)) {
                if (is_file($path)) {
                    return StructuredSearchResumeMutationResult::ioError('clear', 'unlink_failed');
                }
            }

            return StructuredSearchResumeMutationResult::cleared($expectedVersion);
        });
    }

    /**
     * CAS-safe expire delete: only removes if still expired at the observed version.
     */
    public function expireIfStillExpired(
        StructuredSearchResumeIdentity $identity,
        int $observedVersion,
        \DateTimeImmutable $now
    ): StructuredSearchResumeMutationResult {
        return $this->withExclusiveLock($identity, function () use ($identity, $observedVersion, $now) {
            $existing = $this->readFinalUnderLock($identity);
            if ($existing['status'] === StructuredSearchResumeLoadResult::NOT_FOUND) {
                return StructuredSearchResumeMutationResult::alreadyAbsent();
            }
            if ($existing['status'] !== StructuredSearchResumeLoadResult::FOUND
                && $existing['status'] !== StructuredSearchResumeLoadResult::EXPIRED) {
                if ($existing['status'] === StructuredSearchResumeLoadResult::IO_ERROR) {
                    return StructuredSearchResumeMutationResult::ioError('expire', (string) ($existing['reason'] ?? ''));
                }
                if ($existing['status'] === StructuredSearchResumeLoadResult::IDENTITY_MISMATCH) {
                    return StructuredSearchResumeMutationResult::identityMismatch(
                        'expire',
                        (string) ($existing['reason'] ?? '')
                    );
                }

                return StructuredSearchResumeMutationResult::ioError('expire', (string) $existing['status']);
            }

            /** @var StructuredSearchResumeState|null $current */
            $current = $existing['state'] ?? null;
            if ($current === null) {
                return StructuredSearchResumeMutationResult::alreadyAbsent();
            }
            if ($current->getStateVersion() !== $observedVersion) {
                return StructuredSearchResumeMutationResult::versionConflict(
                    'expire',
                    'newer_state_present'
                );
            }
            if (!$current->isExpiredAt($now)) {
                return StructuredSearchResumeMutationResult::versionConflict('expire', 'no_longer_expired');
            }

            $path = $this->dataPath($identity);
            if (!@unlink($path) && is_file($path)) {
                return StructuredSearchResumeMutationResult::ioError('expire', 'unlink_failed');
            }

            return StructuredSearchResumeMutationResult::cleared($observedVersion);
        });
    }

    public function quarantineCorrupt(StructuredSearchResumeIdentity $identity): StructuredSearchResumeMutationResult
    {
        return $this->withExclusiveLock($identity, function () use ($identity) {
            $path = $this->dataPath($identity);
            if (!is_file($path)) {
                return StructuredSearchResumeMutationResult::alreadyAbsent();
            }
            $dest = $this->storeDirectory
                . DIRECTORY_SEPARATOR
                . $identity->getStorageKey()
                . '.corrupt.'
                . str_replace('.', '', uniqid('', true))
                . '.json';
            if (!@rename($path, $dest)) {
                return StructuredSearchResumeMutationResult::ioError('quarantine', 'rename_failed');
            }

            return StructuredSearchResumeMutationResult::cleared(0);
        });
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T|StructuredSearchResumeMutationResult
     */
    private function withExclusiveLock(StructuredSearchResumeIdentity $identity, callable $fn)
    {
        if (!$this->ensureDirectory()) {
            return StructuredSearchResumeMutationResult::ioError('lock', 'mkdir_failed');
        }

        $lockPath = $this->lockPath($identity);
        $fh = @fopen($lockPath, 'c+');
        if ($fh === false) {
            return StructuredSearchResumeMutationResult::ioError('lock', 'lock_open_failed');
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);

            return StructuredSearchResumeMutationResult::ioError('lock', 'flock_failed');
        }

        try {
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * @return array{status: string, state?: StructuredSearchResumeState, reason?: string}
     */
    private function readFinalUnderLock(StructuredSearchResumeIdentity $identity): array
    {
        $path = $this->dataPath($identity);
        if (!is_file($path)) {
            return ['status' => StructuredSearchResumeLoadResult::NOT_FOUND];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return ['status' => StructuredSearchResumeLoadResult::IO_ERROR, 'reason' => 'read_failed'];
        }
        $interpreted = $this->interpretLoadedBytes(
            $identity,
            $raw,
            new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei')),
            true
        );
        if ($interpreted->getStatus() === StructuredSearchResumeLoadResult::FOUND) {
            return ['status' => StructuredSearchResumeLoadResult::FOUND, 'state' => $interpreted->getState()];
        }
        if ($interpreted->getStatus() === StructuredSearchResumeLoadResult::EXPIRED) {
            return ['status' => StructuredSearchResumeLoadResult::EXPIRED, 'state' => $interpreted->getState()];
        }

        return [
            'status' => $interpreted->getStatus(),
            'reason' => $interpreted->getFailureReason(),
        ];
    }

    private function interpretLoadedBytes(
        StructuredSearchResumeIdentity $identity,
        string $raw,
        \DateTimeImmutable $now,
        bool $underLock
    ): StructuredSearchResumeLoadResult {
        unset($underLock);

        // Filename stem must equal identity storage key (callers always use identity path).
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return StructuredSearchResumeLoadResult::corrupt('json_decode');
        }

        try {
            $state = StructuredSearchResumeStateFactory::fromDocument($decoded);
        } catch (\InvalidArgumentException $e) {
            return StructuredSearchResumeLoadResult::corrupt($e->getMessage());
        }

        if (!$identity->matchesDocument($state->toArray())) {
            return StructuredSearchResumeLoadResult::identityMismatch('document_identity');
        }

        $expectedKey = $identity->getStorageKey();
        $rebuilt = $state->toIdentity()->getStorageKey();
        if ($rebuilt !== $expectedKey) {
            return StructuredSearchResumeLoadResult::identityMismatch('hash_mismatch');
        }

        if ($state->isExpiredAt($now)) {
            return StructuredSearchResumeLoadResult::expired($state);
        }

        return StructuredSearchResumeLoadResult::found($state);
    }

    private function publishRename(
        StructuredSearchResumeIdentity $identity,
        StructuredSearchResumeState $state,
        string $operation
    ): StructuredSearchResumeMutationResult {
        if (!$this->ensureDirectory()) {
            return StructuredSearchResumeMutationResult::ioError($operation, 'mkdir_failed');
        }

        $json = json_encode($state->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return StructuredSearchResumeMutationResult::ioError($operation, 'json_encode_failed');
        }

        $final = $this->dataPath($identity);
        $tmp = $final . '.tmp.' . getmypid() . '.' . str_replace('.', '', uniqid('', true));
        $fh = @fopen($tmp, 'wb');
        if ($fh === false) {
            return StructuredSearchResumeMutationResult::ioError($operation, 'temp_open_failed');
        }
        $written = fwrite($fh, $json);
        if ($written === false || $written !== strlen($json)) {
            fclose($fh);
            @unlink($tmp);

            return StructuredSearchResumeMutationResult::ioError($operation, 'temp_write_failed');
        }
        if (!fflush($fh)) {
            fclose($fh);
            @unlink($tmp);

            return StructuredSearchResumeMutationResult::ioError($operation, 'temp_flush_failed');
        }
        fclose($fh);

        // Direct rename only — never unlink(final) before rename.
        if (!@rename($tmp, $final)) {
            @unlink($tmp);

            return StructuredSearchResumeMutationResult::ioError($operation, 'rename_failed');
        }

        if ($operation === 'create') {
            return StructuredSearchResumeMutationResult::created($state);
        }

        return StructuredSearchResumeMutationResult::replaced($state);
    }

    private function ensureDirectory(): bool
    {
        if (is_dir($this->storeDirectory)) {
            return true;
        }

        return @mkdir($this->storeDirectory, 0775, true) || is_dir($this->storeDirectory);
    }

    private function dataPath(StructuredSearchResumeIdentity $identity): string
    {
        return $this->storeDirectory
            . DIRECTORY_SEPARATOR
            . $identity->getStorageKey()
            . '.json';
    }

    private function lockPath(StructuredSearchResumeIdentity $identity): string
    {
        return $this->storeDirectory
            . DIRECTORY_SEPARATOR
            . $identity->getStorageKey()
            . '.lock';
    }
}
