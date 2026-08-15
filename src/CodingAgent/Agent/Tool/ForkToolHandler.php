<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Fork\ForkExecutionServiceInterface;
use Ineersa\CodingAgent\Tool\Arguments\ForkArgumentsDTO;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Psr\Container\ContainerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(self::NAME, self::DESCRIPTION)]
final class ForkToolHandler
{
    public const string NAME = 'fork';

    /** Provider-visible description; shared with the registry definition. */
    public const string DESCRIPTION = 'Launch an isolated fork child with inherited parent conversation context. Blocks until completion and returns a dense handoff.';

    private const string EXECUTION_SERVICE_LOCATOR_KEY = 'execution';

    public function __construct(
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ToolRuntime $toolRuntime,
        private readonly ContainerInterface $executionServiceLocator,
    ) {
    }

    public function __invoke(ForkArgumentsDTO $arguments): DeferredToolCompletionOutcome
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

            $model = null === $arguments->model ? null : trim($arguments->model);

            $thinking = null === $arguments->thinking ? null : trim($arguments->thinking);

            return $this->executionService()->execute(
                parentRunId: $parentRunId,
                task: trim($arguments->task),
                modelOverride: $model,
                reasoningOverride: $thinking,
            );
        });
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
