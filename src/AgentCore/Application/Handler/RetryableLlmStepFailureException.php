<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Notification\ModelNotificationDTO;

/**
 * Signals that ExecuteLlmStep handling failed with a classified retryable
 * provider-operation error and carries the failure details needed after
 * retry exhaustion. The caller owns the retry policy.
 */
final class RetryableLlmStepFailureException extends \RuntimeException
{
    /**
     * @param array<string, mixed>       $error
     * @param list<ModelNotificationDTO> $modelNotifications
     * @param list<string>               $availableTools
     */
    public function __construct(
        string $runId,
        string $stepId,
        public readonly array $error,
        public readonly string $toolsRef,
        public readonly string $model = '',
        public readonly string $reasoning = '',
        public readonly array $modelNotifications = [],
        public readonly array $availableTools = [],
        public readonly int $availableToolsSchemaTokensEstimate = 0,
    ) {
        parent::__construct(\sprintf('Retryable LLM provider failure for run %s step %s.', $runId, $stepId));
    }
}
