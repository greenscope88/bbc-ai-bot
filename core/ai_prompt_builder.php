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

    /**
     * Stage 1-B-16: Append tour search context block to an existing Gemini prompt (draft helper).
     */
    public static function appendTourContext(string $basePrompt, string $tourContext): string
    {
        $base = rtrim($basePrompt);
        $context = trim($tourContext);

        if ($context === '') {
            return $base;
        }

        return $base . "\n\n"
            . "--------------------------------------------------\n"
            . "以下為系統自動整理的旅遊商品搜尋結果資訊：\n"
            . $context . "\n"
            . "--------------------------------------------------\n\n"
            . "請嚴格遵守：\n"
            . "1. 僅引用上述搜尋結果中實際存在的資訊。\n"
            . "2. 不得捏造不存在的行程。\n"
            . "3. 必須附上搜尋結果連結（若有）。\n"
            . "4. 不得暴露任何內部系統欄位或敏感資訊。";
    }
}
