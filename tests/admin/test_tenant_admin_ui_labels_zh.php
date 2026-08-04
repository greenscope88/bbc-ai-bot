<?php
declare(strict_types=1);

$failures = 0;
$assert = static function (bool $c, string $m) use (&$failures): void {
    if (!$c) { ++$failures; fwrite(STDERR, "FAIL: {$m}\n"); }
};

$path = 'C:/Web/xampp/htdocs/www/bds/admin.php';
$raw = file_get_contents($path);
$assert(is_string($raw) && $raw !== '', 'admin.php readable');

$assert(strpos($raw, '3. 建立新租戶') !== false, 'create section zh');
$assert(strpos($raw, '4. 更新 LINE OA／服務啟用管理') !== false, 'ops section zh');
$assert(strpos($raw, '開啟 BBC AI 測試流程') !== false, 'bats label zh');
$assert(strpos($raw, '啟用 AI 語意理解') !== false, 'aiu label zh');
$assert(strpos($raw, '啟用知識依據回覆') !== false, 'grounding label zh');
$assert(strpos($raw, '解析 LINE OA／預覽建立內容') !== false, 'preview create zh');
$assert(strpos($raw, '解析 LINE OA／預覽操作內容') !== false, 'preview op zh');
$assert(strpos($raw, '解析店家資料') !== false, 'resolve button zh');
$assert(strpos($raw, 'Bot Info') !== false, 'bot info authority label');
$assert(strpos($raw, 'name="line_channel_id"') === false, 'no client line_channel_id input');

// internal keys unchanged
foreach (['create_tenant', 'update_line_oa', 'enable_bds_upload', 'enter_line_validation', 'finalize_tenant_production', 'deactivate_tenant_production', 'bats_runtime', 'aiu_authoritative', 'grounding_authoritative'] as $key) {
    $assert(strpos($raw, $key) !== false, "internal key present: {$key}");
}

if ($failures > 0) { fwrite(STDERR, "{$failures} failure(s)\n"); exit(1); }
fwrite(STDOUT, "OK: test_tenant_admin_ui_labels_zh\n");
exit(0);
