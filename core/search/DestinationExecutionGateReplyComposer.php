<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'DestinationFeasibilityContracts.php';

/**
 * Static customer-facing copy for destination execution gate blocks (no search).
 */
final class DestinationExecutionGateReplyComposer
{
    public static function compose(string $executionDecision): string
    {
        if ($executionDecision === DestinationFeasibilityContracts::EXECUTION_DENY_RELATION_CAPABILITY_UNAVAILABLE) {
            return '目前我還無法一次處理多個目的地的組合查詢（例如同時去兩地、擇一或依序安排）。'
                . '請先告訴我想查的單一目的地，我再幫您找行程 😊';
        }
        if ($executionDecision === DestinationFeasibilityContracts::EXECUTION_DENY_NON_EXECUTABLE) {
            return '這個地點目前無法作為旅遊商品搜尋條件。若您有想去的城市或國家，請直接告訴我，我再協助查詢 😊';
        }
        if ($executionDecision === DestinationFeasibilityContracts::EXECUTION_DENY_MIXED_DESTINATION) {
            return '您提到的目的地裡，有些目前無法一起查詢。請先選擇一個想去的城市或地區，我再幫您找行程 😊';
        }
        if ($executionDecision === DestinationFeasibilityContracts::EXECUTION_DENY_UNCERTAIN) {
            return '我還無法確認您想查的目的地。請直接告訴我想去的城市或地區，我再協助查詢 😊';
        }
        if ($executionDecision === DestinationFeasibilityContracts::EXECUTION_DENY_RELATION_UNCERTAIN) {
            return '我還無法判斷您想怎麼安排多個目的地。請先告訴我想查的單一目的地，或說明想先查哪一個 😊';
        }

        return '目前無法完成這次查詢，請稍後再試或直接告訴我想去的單一目的地 😊';
    }
}
