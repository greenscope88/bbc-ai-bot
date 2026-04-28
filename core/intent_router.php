<?php
declare(strict_types=1);

class IntentRouter
{
    public static function detect(string $message): array
    {
        $text = trim($message);
        $lower = mb_strtolower($text, 'UTF-8');

        $serviceName = '';
        $intent = 'general_support';

        if (strpos($lower, '天氣') !== false || strpos($lower, '氣溫') !== false || strpos($lower, '溫度') !== false || strpos($lower, '東京天氣') !== false || strpos($lower, 'weather') !== false) {
            $intent = 'weather_query';
            $serviceName = '天氣';
        } elseif (strpos($lower, '護照') !== false) {
            $intent = 'service_query';
            $serviceName = '護照';
        } elseif (strpos($lower, '台胞證') !== false) {
            $intent = 'service_query';
            $serviceName = '台胞證';
        } elseif (strpos($lower, '行程') !== false || strpos($lower, '團') !== false) {
            $intent = 'tour_query';
            $serviceName = '行程';
        } elseif (strpos($lower, '價格') !== false || strpos($lower, '費用') !== false) {
            $intent = 'service_query';
            $serviceName = '價格';
        }

        return [
            'intent' => $intent,
            'service_name' => $serviceName,
            'user_message' => $text,
        ];
    }
}
