<?php
declare(strict_types=1);

/**
 * Phase 9-C-2A.1: reusable opening/closing pools for travel consultant persona.
 */
final class TravelConsultantPersonaFormatter
{
    /** @var list<string> */
    private const OPENING_POOL = [
        "您好 😊\n\n幫您整理了近期符合需求的熱門行程，\n提供您參考看看 ✈️",
        "哈囉 👋\n\n已經幫您查詢到相關行程資訊，\n一起來看看有哪些不錯的選擇吧 😊",
        "您好呀 🌸\n\n依照您的需求，\n我幫您整理了最新出團資訊如下：",
        "很高興為您服務 😊\n\n以下是目前符合條件的熱門行程推薦：",
        "旅遊小幫手來囉 ✈️\n\n我幫您找到幾個不錯的行程，\n提供您參考看看 😊",
    ];

    /** @var list<string> */
    private const CLOSING_POOL = [
        "如需更多協助，\n歡迎再告訴我喔 😊",
        "若想進一步了解行程內容、\n價格或出發日期，\n都可以再詢問我 ✈️",
        "如果有特別偏好的日期、\n預算或出發地，\n我也可以再幫您縮小範圍推薦 🌸",
        "祝您找到喜歡的旅遊行程 😊\n\n有任何問題都歡迎再與我聯繫。",
        "如果還想看看其他行程，\n隨時告訴我您的需求喔 💡",
    ];

    /** @var callable(int): int|null */
    private $indexPicker;

    private function __construct()
    {
    }

    public static function createRandomized(): self
    {
        return new self();
    }

    public static function createWithFixedIndex(int $index): self
    {
        $instance = new self();
        $instance->indexPicker = static function (int $count) use ($index): int {
            if ($count <= 0) {
                return 0;
            }

            return max(0, min($count - 1, $index));
        };

        return $instance;
    }

    /**
     * @param callable(int): int $indexPicker
     */
    public static function createWithIndexPicker(callable $indexPicker): self
    {
        $instance = new self();
        $instance->indexPicker = $indexPicker;

        return $instance;
    }

    /**
     * @return list<string>
     */
    public static function openingPool(): array
    {
        return self::OPENING_POOL;
    }

    /**
     * @return list<string>
     */
    public static function closingPool(): array
    {
        return self::CLOSING_POOL;
    }

    public function pickOpeningText(): string
    {
        $pool = self::OPENING_POOL;
        if ($pool === []) {
            return '';
        }

        return $pool[$this->pickIndex(count($pool))];
    }

    public function pickClosingText(): string
    {
        $pool = self::CLOSING_POOL;
        if ($pool === []) {
            return '';
        }

        return $pool[$this->pickIndex(count($pool))];
    }

    /**
     * @return list<string>
     */
    public function openingLines(): array
    {
        return $this->splitMessageLines($this->pickOpeningText());
    }

    /**
     * @return list<string>
     */
    public function closingLines(): array
    {
        return $this->splitMessageLines($this->pickClosingText());
    }

    public function countEmojis(string $text): int
    {
        $allowed = ['😊', '✈️', '🌸', '📌', '💡', '👋', '♨️', '🧳'];
        $count = 0;
        foreach ($allowed as $emoji) {
            $count += mb_substr_count($text, $emoji);
        }

        return $count;
    }

    private function pickIndex(int $count): int
    {
        if ($count <= 0) {
            return 0;
        }

        if ($this->indexPicker !== null) {
            return ($this->indexPicker)($count);
        }

        return random_int(0, $count - 1);
    }

    /**
     * @return list<string>
     */
    private function splitMessageLines(string $message): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $message) ?: [];

        return array_values(array_map(
            static fn (string $line): string => $line,
            $lines
        ));
    }
}
