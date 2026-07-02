<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationContextView.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'PersonaRenderHints.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'NextBestActionResult.php';

/**
 * Phase 2-E Step 2-E-2e — consumes reply_policy.next_best_action_hint only.
 *
 * SSOT: docs/BATS_AI_GROUNDED_RESPONSE_COMPOSER.md §8.4 / §9.2.
 */
final class NextBestActionPresenter
{
    public const HINT_VIEW_PRODUCT = 'view_product';
    public const HINT_VIEW_TOUR = 'view_tour';
    public const HINT_CLICK_REGISTER = 'click_register';
    public const HINT_ASK_TRAVEL_DATES = 'ask_travel_dates';
    public const HINT_ASK_PARTY_SIZE = 'ask_party_size';
    public const HINT_CONTACT_HUMAN_SERVICE = 'contact_human_service';

    public function present(
        GroundedInput $input,
        ConversationContextView $context,
        PersonaRenderHints $hints
    ): NextBestActionResult {
        $hint = $this->resolveHint($input);
        if ($hint === null || $hint === '') {
            return new NextBestActionResult(null, null, 'no_hint');
        }

        switch ($hint) {
            case self::HINT_VIEW_PRODUCT:
            case self::HINT_VIEW_TOUR:
                if (!$this->hasGroundedUrl($input)) {
                    return new NextBestActionResult(null, null, 'no_grounded_url');
                }

                return new NextBestActionResult(
                    $hint,
                    $this->buildClosingLine(
                        $hint === self::HINT_VIEW_TOUR
                            ? '若想進一步了解，歡迎點選上方連結查看行程詳情'
                            : '若想進一步了解，歡迎點選上方連結查看商品詳情',
                        $hints
                    )
                );

            case self::HINT_CLICK_REGISTER:
                if (!$this->hasGroundedUrl($input)) {
                    return new NextBestActionResult(null, null, 'no_grounded_url');
                }

                return new NextBestActionResult(
                    $hint,
                    $this->buildClosingLine('歡迎點選連結完成報名', $hints)
                );

            case self::HINT_ASK_TRAVEL_DATES:
                if ($context->hasTravelDates()) {
                    return new NextBestActionResult(null, null, 'travel_dates_already_known');
                }

                return new NextBestActionResult(
                    $hint,
                    $this->buildClosingLine('若方便，也請告訴我您的出發日期', $hints)
                );

            case self::HINT_ASK_PARTY_SIZE:
                if ($context->hasPartySize()) {
                    return new NextBestActionResult(null, null, 'party_size_already_known');
                }

                return new NextBestActionResult(
                    $hint,
                    $this->buildClosingLine('若方便，也請告訴我同行人數', $hints)
                );

            case self::HINT_CONTACT_HUMAN_SERVICE:
                if (!$this->canPresentContactHuman($input)) {
                    return new NextBestActionResult(null, null, 'contact_human_not_grounded');
                }

                return new NextBestActionResult(
                    $hint,
                    $this->buildClosingLine('如需進一步協助，歡迎聯繫客服', $hints)
                );

            default:
                return new NextBestActionResult(null, null, 'unknown_hint');
        }
    }

    private function resolveHint(GroundedInput $input): ?string
    {
        $replyPolicy = $input->getReplyPolicy();
        if (isset($replyPolicy['next_best_action_hint'])) {
            $hint = trim((string) $replyPolicy['next_best_action_hint']);
            if ($hint !== '') {
                return $hint;
            }
        }

        $metadata = $input->getMetadata();
        if (isset($metadata['next_best_action_hint'])) {
            $hint = trim((string) $metadata['next_best_action_hint']);
            if ($hint !== '') {
                return $hint;
            }
        }

        return null;
    }

    private function hasGroundedUrl(GroundedInput $input): bool
    {
        foreach ($input->getProductList() as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string) ($row['primary_url'] ?? '')) !== '') {
                return true;
            }
        }

        foreach ($input->getExternalLinks() as $link) {
            if (!is_array($link)) {
                continue;
            }
            if (trim((string) ($link['url'] ?? '')) !== '') {
                return true;
            }
        }

        $summary = $input->getRecommendationSummary();
        if (trim((string) ($summary['primary_url'] ?? '')) !== '') {
            return true;
        }

        $topProducts = $summary['top_products'] ?? [];
        if (is_array($topProducts)) {
            foreach ($topProducts as $product) {
                if (!is_array($product)) {
                    continue;
                }
                if (trim((string) ($product['primary_url'] ?? '')) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    private function canPresentContactHuman(GroundedInput $input): bool
    {
        if ($input->getSourceType() === GroundedInput::SOURCE_HUMAN_SERVICE) {
            return true;
        }

        $mode = $input->getReplyPolicyMode();
        if ($mode === 'human_handoff') {
            return true;
        }

        foreach ($input->getGroundedFacts() as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $factType = (string) ($fact['fact_type'] ?? '');
            if ($factType === 'qa' || $factType === 'profile_field') {
                $value = trim((string) ($fact['value'] ?? ''));
                if ($value !== '' && preg_match('/\d[\d\-\s\(\)]{7,}\d/u', $value)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function buildClosingLine(string $base, PersonaRenderHints $hints): string
    {
        if ($hints->isAllowEmoji()) {
            return $base . ' 😊';
        }

        return $base;
    }
}
