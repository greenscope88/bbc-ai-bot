<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'channel_publish_plan' . DIRECTORY_SEPARATOR . 'ChannelPublishPlan.php';

/**
 * BATS channel renderer contract (Phase 9-B-22).
 *
 * Converts ChannelPublishPlan into channel-specific payload documents. No I/O.
 */
interface ChannelRendererInterface
{
    /**
     * @param array<string, mixed> $renderContext
     * @return array<string, mixed>
     */
    public function render(ChannelPublishPlan $plan, array $renderContext = []): array;
}
