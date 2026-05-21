<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'gemini_tour_context_builder.php';

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
            . "以下為系統自動整理的旅遊商品搜尋結果資訊（已偏向 LINE 清單用語）：\n"
            . $context . "\n"
            . "--------------------------------------------------\n\n"
            . "請嚴格遵守（回覆給客人時）：\n"
            . "1. 僅引用上述搜尋結果中實際出現的行程名稱、出團日期、價格；不得捏造或改寫。\n"
            . "2. 以固定清單回覆：每筆必須有 1. 2. 3. 編號；第一行為完整行程標題；避免「想玩○○？」等分類式小標。\n"
            . "3. 出團日期僅用 MM/DD（例 06/01、06/10），不要顯示年份；日期後勿加「可參考」「可選擇」等字。\n"
            . "4. 金額行請用「直售價：」開頭，格式盡量與參考一致（必要時句尾加「起」）。\n"
            . "5. 必須附上參考中的「完整搜尋結果」URL（若存在）；不得省略或改寫網址。\n"
            . "6. 若參考中已將同名行程多個出發日合併，請維持單筆列點與「、」分隔的日期。\n"
            . "7. 每兩筆行程之間必須保留參考中的「" . GeminiTourContextBuilder::LINE_TOUR_ITEM_SEPARATOR . "」分隔線（單獨一行，僅連續「=」組成；逐字一致）；最後一筆行程與「完整搜尋結果」之間不要重複空行，也不要再多加任何分隔線。\n"
            . "8. 參考中的「行程內頁：」URL 必須逐字保留，不得改寫或替換。\n"
            . "9. 參考中的「行程表：」URL 必須逐字保留；若參考無此行則不要自行新增行程表連結。\n"
            . "10. 不得暴露任何內部系統欄位或敏感資訊。";
    }
}
