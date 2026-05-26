<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bootstrap.php';
$base = rtrim((string) app_config_get('gateway.host_b.base_url', 'http://103.1.222.11:8080'), '/');
$key = (string) app_config_get('gateway.host_b.api_key', '');
$sno = 'e1fd133c7e8e45a1';

$tests = [
    'E-camel' => ['sno'=>$sno,'keyword'=>'東京','departureCity'=>'高雄','page'=>1,'pageSize'=>5],
    'E-native' => ['Sno'=>$sno,'Keyword'=>'東京','Departure'=>'高雄','Page'=>1,'PageSize'=>5],
    'mapped-full-native' => [
        'Sno'=>$sno,'Keyword'=>'東京','TourDateS'=>'2026-06-21','TourDateE'=>'2026-06-30',
        'AmountMax'=>30000,'Departure'=>'高雄','Page'=>1,'PageSize'=>5,
    ],
];

foreach ($tests as $label => $q) {
    $url = $base.'/api/tour/search?'.http_build_query($q);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>25, CURLOPT_HTTPHEADER=>['x-api-key: '.$key]]);
    $d = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    $total = (int)($d['pagination']['total'] ?? 0);
    echo "=== $label total=$total ===\n";
    foreach (array_slice($d['items'] ?? [], 0, 2) as $i => $row) {
        echo "  [$i] ".mb_substr((string)($row['couponName']??''),0,50)." | ".($row['price']??'')." | ".($row['tourDate']??'')."\n";
    }
}
