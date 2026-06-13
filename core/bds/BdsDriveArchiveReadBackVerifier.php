<?php
declare(strict_types=1);

/**
 * BDS Phase 6D-1b — GCS archive object read-back verification (current object only).
 *
 * @see docs/BATS_DRIVE_GCS_MAPPING.md §8.1 Gate 7、§12
 * @see docs/BATS_DRIVE_METADATA_CONTRACT.md §15、§16
 */
final class BdsDriveArchiveReadBackVerifier
{
    public const CHECKSUM_METHOD = 'sha256_content';

    /**
     * @return array{
     *   ok: bool,
     *   checks: list<array{code: string, passed: bool, message: string}>,
     *   errors: list<string>,
     *   computed_checksum: string
     * }
     */
    public function verify(
        string $objectPath,
        string $expectedChecksum,
        string $expectedDriveFileId,
        string $expectedTenantSno,
        string $objectBytes,
        array $gcsMetadata,
        int $httpStatus
    ): array {
        $checks = [];
        $errors = [];
        $computedChecksum = $this->computeSha256Content($objectBytes);

        $checks[] = $this->check(
            'object_exists',
            $httpStatus === 200 && $objectBytes !== '',
            'GCS object exists and has body'
        );

        $checks[] = $this->check(
            'metadata_readable',
            $gcsMetadata !== [],
            'GCS object metadata is readable'
        );

        $metaChecksum = isset($gcsMetadata['content_checksum']) ? (string) $gcsMetadata['content_checksum'] : '';
        $checks[] = $this->check(
            'content_checksum',
            $metaChecksum === $expectedChecksum && $computedChecksum === $expectedChecksum,
            'content_checksum matches sha256_content'
        );

        $metaDriveId = isset($gcsMetadata['drive_file_id']) ? (string) $gcsMetadata['drive_file_id'] : '';
        $checks[] = $this->check(
            'drive_file_id',
            $metaDriveId === $expectedDriveFileId,
            'drive_file_id metadata matches'
        );

        $metaTenantSno = isset($gcsMetadata['tenant_sno']) ? (string) $gcsMetadata['tenant_sno'] : '';
        $checks[] = $this->check(
            'tenant_sno',
            $metaTenantSno === $expectedTenantSno,
            'tenant_sno metadata matches'
        );

        $checks[] = $this->check(
            'not_knowledge_path',
            strpos($objectPath, '/knowledge/') === false,
            'object path is not under knowledge/'
        );

        $expectedPrefix = 'tenants/' . $expectedTenantSno . '/archive/';
        $checks[] = $this->check(
            'tenant_path_boundary',
            strpos($objectPath, $expectedPrefix) === 0,
            'object path is under pilot tenant archive prefix'
        );

        foreach ($checks as $check) {
            if (!$check['passed']) {
                $errors[] = $check['code'] . ': ' . $check['message'];
            }
        }

        return [
            'ok' => count($errors) === 0,
            'checks' => $checks,
            'errors' => $errors,
            'computed_checksum' => $computedChecksum,
        ];
    }

    public function computeSha256Content(string $bytes): string
    {
        return 'sha256:' . hash('sha256', $bytes);
    }

    /**
     * @return array{code: string, passed: bool, message: string}
     */
    private function check(string $code, bool $passed, string $message): array
    {
        return [
            'code' => $code,
            'passed' => $passed,
            'message' => $message,
        ];
    }
}
