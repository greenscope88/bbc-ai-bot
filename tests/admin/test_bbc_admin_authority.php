<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/admin/BbcAdminAuthority.php';

$failures = 0;
$root = sys_get_temp_dir() . '/bbc_admin_auth_' . bin2hex(random_bytes(4));
mkdir($root, 0775, true);
$path = $root . '/bbc_admin_authority.php';
file_put_contents($path, "<?php\nreturn ['schema_version'=>1,'enabled'=>true,'owner_wire_snos'=>['cff796a33d94ea31']];\n");

$auth = new BbcAdminAuthority($path);
$assert = static function (bool $c, string $m) use (&$failures): void {
    if (!$c) { ++$failures; fwrite(STDERR, "FAIL: {$m}\n"); }
};

$assert($auth->authorizeWireSno('cff796a33d94ea31') === true, 'owner allowed');
$assert($auth->authorizeWireSno('deadbeefdeadbeef') === false, 'non-owner denied');
$res = $auth->authorizeFromStoreNo(1, 'k', static function () { return 'cff796a33d94ea31'; });
$assert(($res['ok'] ?? false) === true, 'storeNo encrypt path ok');
$res2 = $auth->authorizeFromStoreNo(0, 'k', static function () { return 'cff796a33d94ea31'; });
$assert(($res2['ok'] ?? false) === false, 'incomplete identity denied');
$masked = $auth->maskWireSno('cff796a33d94ea31');
$assert(strpos($masked, 'cff7') === 0 && strpos($masked, 'a31') !== false, 'mask keeps edges');
$assert(strpos($masked, '796a33d94e') === false, 'mask hides middle');

@unlink($path);
@rmdir($root);
if ($failures > 0) { fwrite(STDERR, "{$failures} failure(s)\n"); exit(1); }
fwrite(STDOUT, "OK: test_bbc_admin_authority\n");
exit(0);
