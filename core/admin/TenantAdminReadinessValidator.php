<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantAdminAuthorityReader.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . 'LineCredentialResolver.php';

/**
 * Readiness checks for Admin activation operations.
 * Production evidence mode reads existing reports/logs; tests inject fixture roots.
 */
final class TenantAdminReadinessValidator
{
    /** @var TenantAdminAuthorityReader */
    private $reader;
    /** @var string */
    private $reportsRoot;
    /** @var string */
    private $uploadsRoot;
    /** @var string */
    private $saasRouterLogPath;
    /** @var bool */
    private $requireProductionEvidence;

    /** @var callable(string):array<string,mixed> */
    private $credentialResolver;

    public function __construct(
        TenantAdminAuthorityReader $reader,
        ?string $reportsRoot = null,
        ?string $uploadsRoot = null,
        ?string $saasRouterLogPath = null,
        bool $requireProductionEvidence = true,
        ?callable $credentialResolver = null
    ) {
        $root = dirname(__DIR__, 2);
        $this->reader = $reader;
        $this->reportsRoot = $reportsRoot ?? ($root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'tenants');
        $this->uploadsRoot = $uploadsRoot ?? ($root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'tenants');
        $this->saasRouterLogPath = $saasRouterLogPath ?? ($root . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'saas_router.log');
        $this->requireProductionEvidence = $requireProductionEvidence;
        $this->credentialResolver = $credentialResolver ?? static function (string $channelId): array {
            return LineCredentialResolver::resolveByChannelId($channelId);
        };
    }

    /**
     * @return array{ok:bool,checks:array<string,bool>,reason?:string,credential_status?:string}
     */
    public function preflightIdentity(string $tenantKey): array
    {
        $tenant = $this->reader->getTenantRow($tenantKey);
        $bds = $this->reader->getBdsRow($tenantKey);
        $checks = [
            'tenant_present' => $tenant !== null,
            'bds_present' => $bds !== null,
            'identity_aligned' => false,
            'product_set_present' => false,
            'no_empty_destination' => false,
        ];
        if ($tenant === null || $bds === null) {
            return ['ok' => false, 'checks' => $checks, 'reason' => 'authority_incomplete'];
        }
        $sno = trim((string) ($tenant['sno'] ?? ''));
        $checks['identity_aligned'] =
            $sno !== ''
            && $sno === trim((string) ($bds['sno'] ?? ''))
            && (int) ($tenant['storeNo'] ?? 0) === (int) ($bds['store_no'] ?? -1)
            && trim((string) ($bds['tenant_key'] ?? '')) === $tenantKey
            && trim((string) ($bds['gcs_prefix'] ?? '')) === ('tenants/' . $sno . '/');
        $productPath = $this->reader->productSourcesPath($sno);
        $checks['product_set_present'] = is_file($productPath);
        if ($checks['product_set_present']) {
            $raw = file_get_contents($productPath);
            $doc = is_string($raw) ? json_decode($raw, true) : null;
            $checks['product_set_present'] = is_array($doc)
                && trim((string) ($doc['tenant_sno'] ?? '')) === $sno
                && trim((string) ($doc['tenant_key'] ?? '')) === $tenantKey;
        }
        $checks['no_empty_destination'] = trim((string) ($tenant['line_channel_id'] ?? '')) !== '';

        $ok = !in_array(false, $checks, true);

        return ['ok' => $ok, 'checks' => $checks, 'reason' => $ok ? 'pass' : 'preflight_failed'];
    }

    /**
     * @return array{ok:bool,status:string,tenant_key?:string|null}
     */
    public function credentialStatus(string $tenantKey): array
    {
        $tenant = $this->reader->getTenantRow($tenantKey);
        if ($tenant === null) {
            return ['ok' => false, 'status' => 'missing', 'tenant_key' => null];
        }
        $channel = trim((string) ($tenant['line_channel_id'] ?? ''));
        if ($channel === '') {
            return ['ok' => false, 'status' => 'missing', 'tenant_key' => $tenantKey];
        }

        // Status-only via BBC LineCredentialResolver (entrypoint must load BBC bootstrap/.env).
        // Never return or log secret/token values from this method.
        $resolved = ($this->credentialResolver)($channel);
        if (($resolved['ok'] ?? false) !== true) {
            $code = (string) ($resolved['errorCode'] ?? '');
            if ($code === 'MISSING_CREDENTIALS' || $code === 'TENANT_NOT_FOUND' || $code === 'MISSING_CHANNEL_ID') {
                return ['ok' => false, 'status' => 'missing', 'tenant_key' => $tenantKey];
            }

            return ['ok' => false, 'status' => 'rotation_required', 'tenant_key' => $tenantKey];
        }
        if (trim((string) ($resolved['tenant_key'] ?? '')) !== $tenantKey) {
            return ['ok' => false, 'status' => 'rotation_required', 'tenant_key' => $tenantKey];
        }

        return ['ok' => true, 'status' => 'configured', 'tenant_key' => $tenantKey];
    }

    /**
     * @return array{ok:bool,checks:array<string,bool>,reason?:string}
     */
    public function forEnterLineValidation(string $tenantKey): array
    {
        $pre = $this->preflightIdentity($tenantKey);
        $cred = $this->credentialStatus($tenantKey);
        $bds = $this->reader->getBdsRow($tenantKey);
        $checks = $pre['checks'];
        $checks['bds_enabled'] = is_array($bds) && (($bds['enabled'] ?? false) === true);
        $checks['credential_configured'] = ($cred['ok'] ?? false) === true;
        $ok = ($pre['ok'] ?? false) === true && $checks['bds_enabled'] && $checks['credential_configured'];

        return [
            'ok' => $ok,
            'checks' => $checks,
            'credential_status' => $cred['status'],
            'reason' => $ok ? 'pass' : 'enter_validation_not_ready',
        ];
    }

    /**
     * Finalize requires Tenant-bound Production Evidence unless tests disable the hard gate.
     *
     * @return array{ok:bool,checks:array<string,bool>,reason?:string}
     */
    public function forFinalize(string $tenantKey, ?int $notBeforeUnix = null): array
    {
        $enter = $this->forEnterLineValidation($tenantKey);
        $tenant = $this->reader->getTenantRow($tenantKey);
        $checks = $enter['checks'];
        $checks['status_staging'] = is_array($tenant) && (($tenant['status'] ?? '') === 'staging');
        $sno = is_array($tenant) ? trim((string) ($tenant['sno'] ?? '')) : '';

        if (!$this->requireProductionEvidence) {
            $checks['sync_report'] = true;
            $checks['pk_objects'] = true;
            $checks['line_evidence'] = true;
            $ok = ($enter['ok'] ?? false) === true && $checks['status_staging'];

            return ['ok' => $ok, 'checks' => $checks, 'reason' => $ok ? 'pass_fixture' : 'finalize_not_ready'];
        }

        $sync = $this->readSyncReport($sno);
        $checks['sync_report'] = ($sync['ok'] ?? false) === true
            && ($notBeforeUnix === null || (int) ($sync['finished_unix'] ?? 0) >= $notBeforeUnix);
        $checks['pk_objects'] = ($sync['pk_ok'] ?? false) === true;
        $line = $this->scanLineEvidence($sno, $notBeforeUnix);
        $checks['line_evidence'] = ($line['ok'] ?? false) === true;

        $ok = ($enter['ok'] ?? false) === true
            && $checks['status_staging']
            && $checks['sync_report']
            && $checks['pk_objects']
            && $checks['line_evidence'];

        return ['ok' => $ok, 'checks' => $checks, 'reason' => $ok ? 'pass' : 'finalize_evidence_incomplete'];
    }

    /**
     * @return array{ok:bool,pk_ok?:bool,finished_unix?:int}
     */
    private function readSyncReport(string $sno): array
    {
        if ($sno === '') {
            return ['ok' => false];
        }
        $path = rtrim($this->reportsRoot, '\\/') . DIRECTORY_SEPARATOR . $sno . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'sync_report.json';
        if (!is_file($path)) {
            return ['ok' => false];
        }
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return ['ok' => false];
        }
        if (trim((string) ($data['tenant_sno'] ?? '')) !== $sno) {
            return ['ok' => false];
        }
        if (($data['status'] ?? '') !== 'success' || ($data['dry_run'] ?? true) !== false) {
            return ['ok' => false];
        }
        $objects = is_array($data['uploaded_objects'] ?? null) ? $data['uploaded_objects'] : [];
        $prefix = 'tenants/' . $sno . '/knowledge/';
        $pkOk = false;
        foreach ($objects as $object) {
            if (is_string($object) && strpos($object, $prefix) === 0) {
                $pkOk = true;
                break;
            }
        }
        $finished = strtotime((string) ($data['finished_at'] ?? $data['published_at'] ?? ''));

        return [
            'ok' => true,
            'pk_ok' => $pkOk,
            'finished_unix' => is_int($finished) ? $finished : 0,
        ];
    }

    /**
     * @return array{ok:bool}
     */
    private function scanLineEvidence(string $sno, ?int $notBeforeUnix): array
    {
        if ($sno === '' || !is_file($this->saasRouterLogPath)) {
            return ['ok' => false];
        }
        $raw = @file_get_contents($this->saasRouterLogPath);
        if (!is_string($raw) || $raw === '') {
            return ['ok' => false];
        }
        // Bound read: last 2MB only.
        if (strlen($raw) > 2 * 1024 * 1024) {
            $raw = substr($raw, -2 * 1024 * 1024);
        }
        $hasGate = false;
        $hasAiu = false;
        $hasPk = false;
        $hasReply = false;
        foreach (preg_split('/\r\n|\n|\r/', $raw) as $line) {
            if (strpos($line, $sno) === false) {
                continue;
            }
            if ($notBeforeUnix !== null && preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m)) {
                $ts = strtotime($m[1] . ' Asia/Taipei');
                if (is_int($ts) && $ts < $notBeforeUnix) {
                    continue;
                }
            }
            if (strpos($line, 'phase_9c1_gate_decision') !== false && strpos($line, 'phase_9c1_structured_pilot') !== false) {
                $hasGate = true;
            }
            if (strpos($line, 'runtime_source":"aiu') !== false || strpos($line, '"runtime_source":"aiu"') !== false) {
                $hasAiu = true;
            }
            if (strpos($line, 'phase_9c2b_knowledge_runtime_reply') !== false
                && strpos($line, '"knowledge_grounded":true') !== false
                && strpos($line, 'tenant_private') !== false) {
                $hasPk = true;
            }
            if (preg_match('/"line_http_status"\s*:\s*200/', $line)
                || preg_match('/"status"\s*:\s*200/', $line)
                || preg_match('/"http_code"\s*:\s*200/', $line)
                || preg_match('/"ok"\s*:\s*true/', $line) && strpos($line, 'line_reply') !== false) {
                $hasReply = true;
            }
        }

        return ['ok' => $hasGate && $hasAiu && $hasPk && $hasReply];
    }
}
