<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Psr\Container\ContainerInterface;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionExceptionInterface;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Result\ToolCall;

/** Invokes the normal toolbox under the script owner's nested execution context. */
final readonly class CodeModeNestedToolExecutor
{
    private const string TOOLBOX_LOCATOR_KEY = 'toolbox';

    public function __construct(
        private StackToolExecutionContextAccessor $contextAccessor,
        private ContainerInterface $toolboxLocator,
    ) {
    }

    /** @param array<string, mixed> $arguments */
    public function execute(
        string $toolCallId,
        string $name,
        array $arguments,
        ?ToolContext $parentContext,
        CancellationTokenInterface $cancelToken,
    ): mixed {
        $nestedContext = new ToolContext(
            runId: $parentContext?->runId() ?? '',
            turnNo: $parentContext?->turnNo() ?? 0,
            toolCallId: $toolCallId,
            toolName: $name,
            cancellationToken: $cancelToken,
            // The live token follows deadline changes when the script detaches.
            timeoutSeconds: null,
            orderIndex: 0,
            humanInputAnswer: null,
            stepId: $parentContext?->stepId(),
            parentModel: $parentContext?->parentModel(),
            allowsHumanInput: $parentContext?->allowsHumanInput() ?? true,
            retainOutput: $parentContext?->retainOutput() ?? false,
            attachmentOwnerToolCallId: $parentContext?->attachmentOwnerToolCallId(),
        );

        return $this->contextAccessor->with($nestedContext, function () use ($name, $arguments, $toolCallId): mixed {
            try {
                $toolboxResult = $this->toolbox()->execute(new ToolCall($toolCallId, $name, $arguments));
            } catch (ToolNotFoundException $exception) {
                throw new ToolCallException($exception->getMessage(), retryable: false, previous: $exception);
            } catch (ToolExecutionExceptionInterface $exception) {
                $previous = $exception->getPrevious();
                if ($previous instanceof ToolCallException) {
                    throw $previous;
                }

                throw new ToolCallException($previous?->getMessage() ?? $exception->getMessage(), retryable: false, previous: $previous ?? $exception);
            }

            return $toolboxResult->getResult();
        });
    }

    private function toolbox(): ToolboxInterface
    {
        if (!$this->toolboxLocator->has(self::TOOLBOX_LOCATOR_KEY)) {
            throw new \LogicException('Code-mode toolbox locator is missing the toolbox entry.');
        }

        $toolbox = $this->toolboxLocator->get(self::TOOLBOX_LOCATOR_KEY);
        if (!$toolbox instanceof ToolboxInterface) {
            throw new \LogicException(\sprintf('Code-mode toolbox locator must return ToolboxInterface, got %s.', get_debug_type($toolbox)));
        }

        return $toolbox;
    }
}
