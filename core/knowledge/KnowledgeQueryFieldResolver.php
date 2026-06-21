<?php
declare(strict_types=1);

/**
 * Maps knowledge_query messages to company_profile fields (Phase 9-C-2B-2A).
 */
final class KnowledgeQueryFieldResolver
{
    public const FIELD_PHONE = 'phone';
    public const FIELD_ADDRESS = 'address';
    public const FIELD_BUSINESS_HOURS = 'business_hours';
    public const FIELD_SUMMARY = 'summary';
    public const FIELD_COMPANY_NAME = 'company_name';
    public const FIELD_LINE_OFFICIAL = 'line_official';
    public const FIELD_EMAIL = 'email';
    public const FIELD_WEBSITE = 'website';

    /**
     * @return array{type: string}|null
     */
    public function resolve(string $message): ?array
    {
        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
        }

        $text = trim($message);
        if ($text === '') {
            return null;
        }

        $normalized = mb_strtolower($text, 'UTF-8');

        if ($this->matchesAny($text, ['客服電話', '聯絡電話', '公司電話', '電話多少'])
            || mb_strpos($text, '電話', 0, 'UTF-8') !== false) {
            return ['type' => self::FIELD_PHONE];
        }

        if ($this->matchesAny($text, ['公司地址', '地址在哪', '門市地址'])
            || mb_strpos($text, '地址', 0, 'UTF-8') !== false) {
            return ['type' => self::FIELD_ADDRESS];
        }

        if ($this->matchesAny($text, ['營業時間', '客服時間', '幾點上班', '上班時間', '幾點開', '開門', '打烊'])) {
            return ['type' => self::FIELD_BUSINESS_HOURS];
        }

        if ($this->matchesAny($text, ['官網', '官方網站', '網站'])
            || mb_strpos($normalized, 'website', 0, 'UTF-8') !== false) {
            return ['type' => self::FIELD_WEBSITE];
        }

        if ($this->matchesAny($text, ['電子郵件', '客服信箱'])
            || mb_strpos($normalized, 'email', 0, 'UTF-8') !== false
            || mb_strpos($text, '信箱', 0, 'UTF-8') !== false
            || mb_strpos($text, '郵件', 0, 'UTF-8') !== false) {
            return ['type' => self::FIELD_EMAIL];
        }

        if ($this->matchesLineOfficial($text, $normalized)) {
            return ['type' => self::FIELD_LINE_OFFICIAL];
        }

        if ($this->matchesAny($text, [
            '你們是做什麼的',
            '公司介紹',
            '旅行社介紹',
            '請介紹一下',
            '做什麼',
            '經營什麼',
            '專營',
        ])) {
            return ['type' => self::FIELD_SUMMARY];
        }

        if ($this->matchesAny($text, ['公司名稱', '公司叫什麼', '旅行社名稱'])) {
            return ['type' => self::FIELD_COMPANY_NAME];
        }

        return null;
    }

    private function matchesLineOfficial(string $text, string $normalized): bool
    {
        if ($this->matchesAny($text, ['官方LINE', '官方 LINE', 'LINE OA', 'LINE官方', 'line官方', 'line帳號', '官方帳號'])) {
            return true;
        }

        if (mb_strpos($normalized, 'line', 0, 'UTF-8') !== false) {
            return true;
        }

        return false;
    }

    /**
     * @param list<string> $markers
     */
    private function matchesAny(string $text, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (mb_strpos($text, $marker, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }
}
