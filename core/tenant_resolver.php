<?php
declare(strict_types=1);

class TenantResolver
{
    public static function resolve(PDO $pdo, array $event, array $config): array
    {
        $channelId = isset($event['destination']) ? (string) $event['destination'] : '';

        $sql = "SELECT
                    t.sno,
                    t.company_name,
                    t.ai_tone,
                    t.travel_specialties,
                    t.price_catalog_json,
                    c.channel_id
                FROM tenant_profiles t
                INNER JOIN tenant_line_channels c ON c.sno = t.sno
                WHERE c.channel_id = :channel_id
                LIMIT 1";

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
