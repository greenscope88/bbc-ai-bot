<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'gemini' . DIRECTORY_SEPARATOR . 'GeminiContextDocument.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'gemini' . DIRECTORY_SEPARATOR . 'GeminiResponseContract.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'gemini' . DIRECTORY_SEPARATOR . 'GeminiResponseContractValidator.php';

/**
 * Gemini client adapter skeleton (Phase 9-B-26B-2).
 *
 * GeminiContextDocument → GeminiResponseContract. No HTTP, curl, or Gemini API calls.
 */
final class GeminiClient
{
    private GeminiResponseContractValidator $responseValidator;

    public function __construct(?GeminiResponseContractValidator $responseValidator = null)
    {
        $this->responseValidator = $responseValidator ?? new GeminiResponseContractValidator();
    }

    /**
     * Skeleton entry point. Currently delegates to mock only (no API).
     */
    public function generateResponse(GeminiContextDocument $context): GeminiResponseContract
    {
        return $this->generateMockResponse($context);
    }

    public function generateMockResponse(GeminiContextDocument $context): GeminiResponseContract
    {
        $responseDocument = $this->buildMockResponseDocument($context);

        return $this->responseValidator->validateWithContext(
            $responseDocument,
            $context->toArray()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMockResponseDocument(GeminiContextDocument $context): array
    {
        $ctx = $context->toArray();
        $voicePersona = isset($ctx['voice_profile']['persona'])
            ? trim((string) $ctx['voice_profile']['persona'])
            : 'young_female';
        $scope = $this->detectServiceScope(
            (string) $ctx['customer_query'],
            $ctx['tenant_service_scope']
        );

        if ($scope === null) {
            return [
                'reply_text' => '抱歉，這個問題超出了我們旅行社的服務範圍，建議您洽詢相關專業單位，謝謝您的理解。',
                'reply_type' => 'out_of_scope',
                'used_fallback' => false,
                'used_service_scope' => null,
                'voice_profile_used' => $voicePersona,
            ];
        }

        if ($ctx['search_results'] === []) {
            return [
                'reply_text' => (string) ($ctx['fallback_policy']['fallback_message'] ?? ''),
                'reply_type' => 'human_agent_fallback',
                'used_fallback' => true,
                'used_service_scope' => $scope,
                'voice_profile_used' => $voicePersona,
            ];
        }

        $lines = ['為您找到以下行程參考 😊 ✈️'];
        foreach ($ctx['search_results'] as $item) {
            $title = isset($item['title']) ? trim((string) $item['title']) : '';
            $summary = isset($item['summary']) && is_string($item['summary']) ? trim($item['summary']) : '';
            $url = isset($item['primary_url']) ? trim((string) $item['primary_url']) : '';
            $line = '📌 ' . $title;
            if ($summary !== '') {
                $line .= ' — ' . $summary;
            }
            if ($url !== '') {
                $line .= "\n" . $url;
            }
            $lines[] = $line;
        }

        return [
            'reply_text' => implode("\n\n", $lines),
            'reply_type' => 'normal_reply',
            'used_fallback' => false,
            'used_service_scope' => $scope,
            'voice_profile_used' => $voicePersona,
        ];
    }

    /**
     * @param list<string> $tenantServiceScope
     */
    private function detectServiceScope(string $customerQuery, array $tenantServiceScope): ?string
    {
        $query = mb_strtolower($customerQuery);
        $rules = [
            'tour' => ['團', '行程', '旅遊', 'tour'],
            'visa' => ['簽證', 'visa'],
            'passport' => ['護照', 'passport'],
            'ticket' => ['機票', 'ticket'],
            'hotel' => ['飯店', 'hotel', '住宿'],
        ];

        foreach ($rules as $scope => $keywords) {
            if (!in_array($scope, $tenantServiceScope, true)) {
                continue;
            }
            foreach ($keywords as $keyword) {
                if (mb_strpos($query, mb_strtolower($keyword)) !== false) {
                    return $scope;
                }
            }
        }

        return null;
    }
}
