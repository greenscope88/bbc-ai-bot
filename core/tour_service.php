<?php
declare(strict_types=1);

class TourService
{
    public static function isServiceSupported(PDO $pdo, string $sno, string $serviceName): array
    {
        if ($serviceName === '') {
            return ['supported' => true, 'note' => ''];
        }

        $sql = "SELECT is_supported, note
                FROM tenant_service_limits
                WHERE sno = :sno AND service_name = :service_name
                ORDER BY updated_at DESC
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':sno', $sno, PDO::PARAM_STR);
        $stmt->bindValue(':service_name', $serviceName, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return ['supported' => true, 'note' => ''];
        }

        return [
            'supported' => ((int) $row['is_supported']) === 1,
            'note' => (string) ($row['note'] ?? ''),
        ];
    }

    public static function fetchServiceData(PDO $pdo, string $sno, array $intent): array
    {
        if ($intent['intent'] === 'tour_query') {
            $stmt = $pdo->prepare('SELECT TOP 5 [編號], [產品名稱], [總計], [建立日期] FROM [dbo].[bs_Order] ORDER BY [建立日期] DESC');
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        }

        $stmt = $pdo->prepare('SELECT sno, service_name, is_supported, note, updated_at FROM tenant_service_limits WHERE sno = :sno ORDER BY updated_at DESC');
        $stmt->bindValue(':sno', $sno, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }
}
