<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Execution\ForkExecutionServiceInterface;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Psr\Container\ContainerInterface;

final class ForkToolHandler implements ToolHandlerInterface
{
    private const string EXECUTION_SERVICE_LOCATOR_KEY = 'execution';

    public function __construct(
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ToolRuntime $toolRuntime,
        private readonly ContainerInterface $executionServiceLocator,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function __invoke(array $arguments): DeferredToolCompletionOutcome
    {
        return $this->toolRuntime->run(function () use ($arguments): DeferredToolCompletionOutcome {
            $context = $this->contextAccessor->current();
            if (null === $context) {
                throw new ToolCallException('The fork tool requires an active parent run context.', retryable: false);
            }

            $parentRunId = $context->runId();
            if ('' === $parentRunId) {
                throw new ToolCallException('Fork tool requires a valid parent run ID.', retryable: false);
            }

            $task = $arguments['task'] ?? null;
            if (!\is_string($task) || '' === trim($task)) {
                throw new ToolCallException('fork requires a non-empty task string.', retryable: false);
            }

            return $this->executionService()->execute(
                parentRunId: $parentRunId,
                task: trim($task),
                modelOverride: $this->parseOptionalNonEmptyString($arguments['model'] ?? null, 'model'),
                reasoningOverride: $this->parseOptionalThinking($arguments['thinking'] ?? null),
            );
        });
    }

    private function parseOptionalNonEmptyString(mixed $value, string $fieldName): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw new ToolCallException(\sprintf('fork %s must be a string when provided.', $fieldName), retryable: false);
        }

        $trimmed = trim($value);
        if ('' === $trimmed) {
            throw new ToolCallException(\sprintf('fork %s must be a non-empty string when provided.', $fieldName), retryable: false);
        }

        return $trimmed;
    }

    private function parseOptionalThinking(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw new ToolCallException('fork thinking must be a string when provided.', retryable: false);
        }

        $trimmed = trim($value);
        if ('' === $trimmed) {
            throw new ToolCallException('fork thinking must be a non-empty string when provided.', retryable: false);
        }

        if (!\in_array($trimmed, ModelResolver::LEVELS, true)) {
            throw new ToolCallException(\sprintf('fork thinking must be one of: %s.', implode(', ', ModelResolver::LEVELS)), retryable: false);
        }

        return $trimmed;
    }

    private function executionService(): ForkExecutionServiceInterface
    {
        if (!$this->executionServiceLocator->has(self::EXECUTION_SERVICE_LOCATOR_KEY)) {
            throw new \LogicException('Fork execution service is not registered in the fork tool locator.');
        }

        $service = $this->executionServiceLocator->get(self::EXECUTION_SERVICE_LOCATOR_KEY);
        if (!$service instanceof ForkExecutionServiceInterface) {
            throw new \LogicException(\sprintf('Fork tool locator entry must implement ForkExecutionServiceInterface, got %s.', get_debug_type($service)));
        }

        return $service;
    }
}
