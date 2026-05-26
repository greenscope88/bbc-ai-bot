<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchFeatureGate.php';

$stagingSno = 'e1fd133c7e8e45a1';
$stagingChannel = 'Ustagingchannel123';

$offConfig = [
    'enabled' => false,
    'allowed_sno' => [$stagingSno],
    'allowed_channels' => [],
    'dry_run_log_enabled' => true,
];
hybrid_test_assert(
    HybridSearchFeatureGate::isEnabled(['sno' => $stagingSno], $offConfig) === false,
    'flag: master OFF'
);

$onNoAllowlist = [
    'enabled' => true,
    'allowed_sno' => [],
    'allowed_channels' => [],
];
hybrid_test_assert(
    HybridSearchFeatureGate::isEnabled(['sno' => $stagingSno], $onNoAllowlist) === false,
    'flag: ON but empty allowlist'
);

$onSnoOnly = [
    'enabled' => true,
    'allowed_sno' => [$stagingSno],
    'allowed_channels' => [],
];
hybrid_test_assert(
    HybridSearchFeatureGate::isEnabled(['sno' => $stagingSno], $onSnoOnly) === true,
    'flag: ON + sno allowlist'
);
hybrid_test_assert(
    HybridSearchFeatureGate::isEnabled(['sno' => 'other-sno'], $onSnoOnly) === false,
    'flag: ON + sno not allowed'
);

$onChannelGate = [
    'enabled' => true,
    'allowed_sno' => [$stagingSno],
    'allowed_channels' => [$stagingChannel],
];
hybrid_test_assert(
    HybridSearchFeatureGate::isEnabled(['sno' => $stagingSno, 'channelId' => $stagingChannel], $onChannelGate) === true,
    'flag: channel match'
);
hybrid_test_assert(
    HybridSearchFeatureGate::isEnabled(['sno' => $stagingSno, 'channelId' => 'wrong'], $onChannelGate) === false,
    'flag: channel mismatch'
);

$normalized = HybridSearchFeatureGate::normalize(['enabled' => 'true', 'allowed_sno' => '  ']);
hybrid_test_assert($normalized['enabled'] === true && $normalized['allowed_sno'] === [], 'flag: normalize');

hybrid_test_finish('HybridSearchFeatureGate');
