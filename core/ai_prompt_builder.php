<?php
declare(strict_types=1);

class AiPromptBuilder
{
    public static function build(array $tenant, array $intent, array $serviceData, array $serviceLimits): string
    {
        $companyName = (string) ($tenant['company_name'] ?? '旅行社');
        $tone = (string) ($tenant['ai_tone'] ?? '親切');
        $specialties = (string) ($tenant['travel_specialties'] ?? '綜合旅遊');

        $available = [];
        $unavailable = [];
        foreach ($serviceLimits as $row) {
            $name = (string) ($row['service_name'] ?? '');
            $supported = isset($row['is_supported']) && (int) $row['is_supported'] === 1;
            if ($name === '') {
                continue;
            }
            if ($supported) {
                $available[] = $name;
            } else {
                $unavailable[] = $name;
            }
        }

        return "你是 {$companyName} 的 LINE 客服。\n"
            . "請使用繁體中文，語氣{$tone}，回答精簡。\n"
            . "旅遊專長：{$specialties}\n"
            . "可提供服務：" . implode('、', $available) . "\n"
            . "不提供服務：" . implode('、', $unavailable) . "\n"
            . "使用者問題：" . (string) $intent['user_message'] . "\n"
            . "若問到不提供服務，請友善引導可提供項目。\n"
            . "參考資料：" . json_encode($serviceData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
