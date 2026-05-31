<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ChannelRendererInterface.php';
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
}
