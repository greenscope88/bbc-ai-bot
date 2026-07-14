<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingResult.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiRuntimeIntent.php';

/**
 * Current Contract Translation: normalized AIU Result → Runtime Intent.
 *
 * SSOT: docs/BATS_AI_RUNTIME_INTEGRATION.md §11 / §12.
 */
final class AiRuntimeIntentTranslator
{
    public static function fromUnderstandingResult(AiIntentUnderstandingResult $result): string
    {
        $category = $result->getIntent();

        if ($category === AiIntentCategory::HUMAN_SERVICE) {
            $runtimeIntent = AiRuntimeIntent::HUMAN_SERVICE_REQUEST;
        } elseif ($category === AiIntentCategory::KNOWLEDGE) {
            $runtimeIntent = AiRuntimeIntent::KNOWLEDGE_QUERY;
        } elseif ($category === AiIntentCategory::PRODUCT_SEARCH) {
            $runtimeIntent = AiRuntimeIntent::PRODUCT_SEARCH;
        } elseif ($category === AiIntentCategory::AMBIGUOUS) {
            $runtimeIntent = AiRuntimeIntent::AMBIGUOUS;
        } else {
            throw new \InvalidArgumentException('unsupported ai intent category for runtime translation: ' . $category);
        }

        AiRuntimeIntent::assertValid($runtimeIntent);

        return $runtimeIntent;
    }
}
