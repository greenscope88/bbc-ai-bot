<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'PublisherStrategyContract.php';

/**
 * Validates BATS publisher strategy contract documents (Phase 9-B-18.1).
 */
final class PublisherStrategyContractValidator
{
    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    public function collectViolations(array $document): array
    {
        $violations = [];
        $normalized = PublisherStrategyContract::normalizeDocument($document);

        if (!in_array($normalized['schema_version'], PublisherStrategyContract::SUPPORTED_SCHEMA_VERSIONS, true)) {
            $violations[] = 'unsupported schema_version';
        }

        if ($normalized['channel'] === '') {
            $violations[] = 'channel is required';
        } elseif (!in_array($normalized['channel'], PublisherStrategyContract::CHANNELS, true)) {
            $violations[] = 'invalid channel';
        }

        if ($normalized['strategy_name'] === '') {
            $violations[] = 'strategy_name is required';
        }

        if ($normalized['message_mode'] === '') {
            $violations[] = 'message_mode is required';
        }

        if ($normalized['max_items'] <= 0) {
            $violations[] = 'max_items must be a positive integer';
        }

        if ($normalized['payload_schema_version'] <= 0) {
            $violations[] = 'payload_schema_version must be a positive integer';
        }

        foreach (PublisherStrategyContract::POLICY_FIELDS as $field) {
            if (!isset($document[$field])) {
                $violations[] = $field . ' is required';
                continue;
            }

            if (!is_array($document[$field])) {
                $violations[] = $field . ' must be an array';
            }
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $document
     * @throws \InvalidArgumentException
     */
    public function validate(array $document): PublisherStrategyContract
    {
        $violations = $this->collectViolations($document);
        if ($violations !== []) {
            throw new \InvalidArgumentException(
                'Publisher strategy contract invalid (' . count($violations) . ' issue(s)): '
                . implode('; ', $violations)
            );
        }

        return PublisherStrategyContract::fromArray($document);
    }
}
