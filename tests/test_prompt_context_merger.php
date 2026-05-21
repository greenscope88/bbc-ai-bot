<?php
declare(strict_types=1);

/**
 * Stage 1-B-16 CLI tests for AiPromptBuilder::appendTourContext (draft helper).
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'ai_prompt_builder.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$basePrompt = "你是測試旅行社的 LINE 客服。\n使用者問題：我想找東京行程";

$tourContext = "【旅遊產品搜尋結果】\n本次查詢共找到 2 筆相關行程。\n"
    . "完整搜尋結果：\nhttps://bonusmee.com/view/cloud/cloud_store_tourdate.php?keyword=%E6%9D%B1%E4%BA%AC";

// 1. normal merge
$merged = AiPromptBuilder::appendTourContext($basePrompt, $tourContext);
test_assert(strpos($merged, $basePrompt) === 0, '1: keeps base prompt');
test_assert(strpos($merged, $tourContext) !== false, '1: includes tour context');
test_assert(strpos($merged, '以下為系統自動整理的旅遊商品搜尋結果資訊') !== false, '1: system header');

// 2. empty tourContext
$unchanged = AiPromptBuilder::appendTourContext($basePrompt, '');
test_assert($unchanged === rtrim($basePrompt), '2: empty context unchanged');
$unchangedWs = AiPromptBuilder::appendTourContext($basePrompt, "  \n  ");
test_assert($unchangedWs === rtrim($basePrompt), '2: whitespace-only context unchanged');

// 3. separator lines
test_assert(strpos($merged, '--------------------------------------------------') !== false, '3: separator present');
test_assert(substr_count($merged, '--------------------------------------------------') >= 2, '3: opening and closing separators');

// 4. Gemini instruction block
test_assert(strpos($merged, '請嚴格遵守（回覆給客人時）') !== false, '4: instruction header');
test_assert(strpos($merged, '固定清單') !== false, '4: list format');
test_assert(strpos($merged, 'MM/DD') !== false, '4: date format rule');
test_assert(strpos($merged, '直售價：') !== false, '4: price label');
test_assert(strpos($merged, '完整搜尋結果') !== false, '4: search url rule');
test_assert(strpos($merged, GeminiTourContextBuilder::LINE_TOUR_ITEM_SEPARATOR) !== false, '4: LINE item separator rule');
test_assert(strpos($merged, '行程內頁：') !== false, '4: detail url preserve rule');
test_assert(strpos($merged, '行程表：') !== false, '4: schedule url preserve rule');
test_assert(strpos($merged, '不得暴露任何內部系統欄位或敏感資訊') !== false, '4: sensitive rule');

// 5. merger does not inject sensitive values
foreach (['api_key', 'x-api-key', 'SECRET-TRACE', 'internal_url', 'upstream_url'] as $needle) {
    test_assert(stripos($merged, $needle) === false, '5: no sensitive marker in merge template: ' . $needle);
}

$leakyContext = "【旅遊產品搜尋結果】\napi_key=SHOULD-NOT-BE-ADDED-BY-MERGER";
$mergedLeak = AiPromptBuilder::appendTourContext($basePrompt, $leakyContext);
test_assert(strpos($mergedLeak, 'SHOULD-NOT-BE-ADDED-BY-MERGER') !== false, '5b: passes through caller context only');
test_assert(strpos($mergedLeak, 'api_key=SECRET-NEW') === false, '5b: merger adds no new secrets');

if ($failures === 0) {
    echo "OK: Prompt context merger tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
