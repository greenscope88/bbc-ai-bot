<?php
declare(strict_types=1);

class TenantResolver
{
    public static function resolve(PDO $pdo, array $event, array $config): array
    {
        $channelId = isset($event['destination']) ? (string) $event['destination'] : '';

        $mapPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'tenant_context_map.php';
        if ($channelId !== '' && is_file($mapPath)) {
            /** @var mixed $loaded */
            $loaded = require $mapPath;
            if (is_array($loaded) && isset($loaded[$channelId]) && is_array($loaded[$channelId])) {
                $entry = $loaded[$channelId];
                $mappedSno = isset($entry['sno']) ? trim((string) $entry['sno']) : '';
                if ($mappedSno !== '') {
                    return [
                        'sno' => $mappedSno,
                        'company_name' => isset($entry['company_name']) ? (string) $entry['company_name'] : '旅行社客服',
                        'ai_tone' => isset($entry['ai_tone']) ? (string) $entry['ai_tone'] : '親切',
                        'travel_specialties' => isset($entry['travel_specialties']) ? (string) $entry['travel_specialties'] : '綜合旅遊',
                        'price_catalog_json' => isset($entry['price_catalog_json']) ? (string) $entry['price_catalog_json'] : '{}',
                        'channel_id' => $channelId,
                    ];
                }
            }
        }

        $sql = "SELECT TOP 1
                    t.sno,
                    t.company_name,
                    t.ai_tone,
                    t.travel_specialties,
                    t.price_catalog_json,
                    c.channel_id
                FROM tenant_profiles t
                INNER JOIN tenant_line_channels c ON c.sno = t.sno
                WHERE c.channel_id = :channel_id";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':channel_id', $channelId, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return [
                'sno' => '',
                'company_name' => '旅行社客服',
                'ai_tone' => '親切',
                'travel_specialties' => '綜合旅遊',
                'price_catalog_json' => '{}',
                'channel_id' => $channelId,
            ];
        }

        return $row;
    }
}
