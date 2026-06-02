<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ChannelRendererInterface.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'gemini' . DIRECTORY_SEPARATOR . 'GeminiResponseContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'LineMessagePayload.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'LineMessagePayloadValidator.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'LineTextMessageBuilder.php';

/**
 * LINE channel renderer text MVP (Phase 9-B-22).
 *
 * ChannelPublishPlan → LineMessagePayload. No HTTP or LINE API calls.
 */
final class LineRenderer implements ChannelRendererInterface
{
    private const MAX_LINE_TEXT_LENGTH = 5000;

    private LineTextMessageBuilder $textMessageBuilder;

    private LineMessagePayloadValidator $payloadValidator;

    public function __construct(
        ?LineTextMessageBuilder $textMessageBuilder = null,
        ?LineMessagePayloadValidator $payloadValidator = null
    ) {
        $this->textMessageBuilder = $textMessageBuilder ?? new LineTextMessageBuilder();
        $this->payloadValidator = $payloadValidator ?? new LineMessagePayloadValidator();
    }

    /**
     * @param array<string, mixed> $renderContext
     */
    public function render(ChannelPublishPlan $plan, array $renderContext = []): array
    {
        return $this->renderPayload($plan, $renderContext)->toArray();
    }

    /**
     * @param array<string, mixed> $renderContext
     */
    public function renderPayload(ChannelPublishPlan $plan, array $renderContext = []): LineMessagePayload
    {
        unset($renderContext);

        if ($plan->getChannel() !== 'line') {
            throw new \InvalidArgumentException(
                'LineRenderer requires channel=line, got: ' . $plan->getChannel()
            );
        }

        $messages = $this->textMessageBuilder->buildMessages($plan);

        return $this->payloadValidator->validate([
            'messages' => $messages,
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function renderFromGeminiResponse(
        GeminiResponseContract $response,
        array $metadata = []
    ): LineMessagePayload {
        unset($metadata);

        $text = trim($response->getReplyText());
        if ($text === '') {
            $text = '目前資料不足，請稍候由專人客服協助您。';
        }

        $text = $this->truncateTextForLine($text);

        return $this->payloadValidator->validate([
            'messages' => [
                [
                    'type' => 'text',
                    'text' => $text,
                ],
            ],
        ]);
    }

    private function truncateTextForLine(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_LINE_TEXT_LENGTH) {
            return $text;
        }

        return mb_substr($text, 0, self::MAX_LINE_TEXT_LENGTH - 1) . '…';
    }
}
