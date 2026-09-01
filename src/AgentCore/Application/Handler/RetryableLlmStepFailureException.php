<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Notification\ModelNotificationDTO;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * Signals that ExecuteLlmStep handling failed with a classified retryable
 * provider-operation error. Symfony Messenger owns max retries, delay, and
 * jitter; after exhaustion the llm WorkerMessageFailedEvent bridge rebuilds
 * one terminal non-retryable {@see \Ineersa\AgentCore\Domain\Message\LlmStepResult}.
 */
final class RetryableLlmStepFailureException extends RecoverableMessageHandlingException
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
        parent::__construct(
            message: \sprintf('Retryable LLM provider failure for run %s step %s.', $runId, $stepId),
            previous: null,
            retryDelay: null,
            forceRetry: false,
        );
    }
}
