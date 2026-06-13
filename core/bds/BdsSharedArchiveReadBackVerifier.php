<?php
declare(strict_types=1);

/**
 * BDS Phase 6E-4b — GCS shared archive object read-back verification (current object only).
 *
 * @see docs/BATS_DRIVE_GCS_MAPPING.md §6.1、§8.1
 * @see docs/BATS_DRIVE_METADATA_CONTRACT.md §15、§16
 */
final class BdsSharedArchiveReadBackVerifier
{
    public const CHECKSUM_METHOD = 'sha256_content';

    public const PILOT_INDUSTRY_CODE = 'travel';

    public const PILOT_OWNER_SCOPE = 'industry';

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
        string $expectedIndustryCode,
        string $expectedOwnerScope,
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

        $metaIndustryCode = isset($gcsMetadata['industry_code']) ? (string) $gcsMetadata['industry_code'] : '';
        $checks[] = $this->check(
            'industry_code',
            $metaIndustryCode === $expectedIndustryCode,
            'industry_code metadata matches'
        );

        $metaOwnerScope = isset($gcsMetadata['owner_scope']) ? (string) $gcsMetadata['owner_scope'] : '';
        $checks[] = $this->check(
            'owner_scope',
            $metaOwnerScope === $expectedOwnerScope,
            'owner_scope metadata matches'
        );

        $checks[] = $this->check(
            'not_tenant_path',
            strpos($objectPath, 'tenants/') !== 0,
            'object path is not under tenants/'
        );

        $checks[] = $this->check(
            'not_knowledge_path',
            strpos($objectPath, '/knowledge/') === false,
            'object path is not under knowledge/'
        );

        $expectedPrefix = 'shared/' . $expectedIndustryCode . '/archive/';
        $checks[] = $this->check(
            'shared_archive_path_boundary',
            strpos($objectPath, $expectedPrefix) === 0,
            'object path is under shared industry archive prefix'
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
