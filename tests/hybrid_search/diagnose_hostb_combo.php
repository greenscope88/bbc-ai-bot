<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bootstrap.php';
$base = rtrim((string) app_config_get('gateway.host_b.base_url', 'http://103.1.222.11:8080'), '/');
$key = (string) app_config_get('gateway.host_b.api_key', '');
$sno = 'e1fd133c7e8e45a1';

$tests = [
    'kw+amt+dep' => ['Sno'=>$sno,'Keyword'=>'東京','AmountMax'=>30000,'Departure'=>'高雄','Page'=>1,'PageSize'=>3],
    'kw+amt+dep+date' => ['Sno'=>$sno,'Keyword'=>'東京','AmountMax'=>30000,'Departure'=>'高雄','TourDateS'=>'2026-06-21','TourDateE'=>'2026-06-30','Page'=>1,'PageSize'=>3],
];

foreach ($tests as $label => $q) {
    $url = $base.'/api/tour/search?'.http_build_query($q);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>25, CURLOPT_HTTPHEADER=>['x-api-key: '.$key]]);
    $d = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    $total = (int)($d['pagination']['total'] ?? 0);
    echo "$label total=$total\n";
    foreach ($d['items'] ?? [] as $i => $row) {
        echo "  [$i] ".($row['tourDate']??'')." ".($row['price']??'')."\n";
    }
}
