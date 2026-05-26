<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/search/HybridSearchConditionBuilder.php';
require_once dirname(__DIR__, 2) . '/core/search/ApiQueryMapper.php';
require_once dirname(__DIR__, 2) . '/core/tour_search_api_client.php';

$ref = new DateTimeImmutable('2026-05-26', new DateTimeZone('Asia/Taipei'));
$c = (new HybridSearchConditionBuilder())->parse('六月底東京三萬以下', ['reference_date' => $ref]);
$p = (new ApiQueryMapper())->toClientParams($c, ['include_sno' => 'e1fd133c7e8e45a1', 'page' => 1, 'pageSize' => 10]);
echo json_encode($c->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
echo (new TourSearchApiClient())->buildRequestUrlFromParams('e1fd133c7e8e45a1', $p, 1, 10, 'diag') . "\n";
