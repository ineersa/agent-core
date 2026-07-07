<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Execution\ForkExecutionService;
use Ineersa\CodingAgent\Config\ForkLevelEnum;
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
    public function __invoke(array $arguments): string
    {
        return $this->toolRuntime->run(function () use ($arguments): string {
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

            $level = null;
            if (isset($arguments['level'])) {
                if (!\is_string($arguments['level'])) {
                    throw new ToolCallException('fork level must be a string (junior, middle, senior).', retryable: false);
                }
                $level = ForkLevelEnum::fromStringOrNull($arguments['level']);
                if (null === $level) {
                    throw new ToolCallException('Invalid fork level. Use junior, middle, or senior.', retryable: false);
                }
            }

            return $this->executionService()->execute(
                parentRunId: $parentRunId,
                task: trim($task),
                requestedLevel: $level,
            );
        });
    }

    private function executionService(): ForkExecutionService
    {
        if (!$this->executionServiceLocator->has(self::EXECUTION_SERVICE_LOCATOR_KEY)) {
            throw new \LogicException('Fork execution service is not registered in the fork tool locator.');
        }

        $service = $this->executionServiceLocator->get(self::EXECUTION_SERVICE_LOCATOR_KEY);
        if (!$service instanceof ForkExecutionService) {
            throw new \LogicException(\sprintf('Fork tool locator entry must be ForkExecutionService, got %s.', get_debug_type($service)));
        }

        return $service;
    }
}
