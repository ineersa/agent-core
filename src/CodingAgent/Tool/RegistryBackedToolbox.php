<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Symfony\AI\Agent\Toolbox\Event\ToolCallArgumentsResolved;
use Symfony\AI\Agent\Toolbox\Event\ToolCallFailed;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Registry-backed Symfony AI Toolbox.
 *
 * Reads all active tool definitions (permanent, dynamic, and extension-registered)
 * from ToolRegistryInterface and makes them available for execution through the
 * Symfony AI ToolboxInterface contract.
 *
 * - getTools(): converts each ToolDefinitionDTO to a Symfony Tool DTO.
 * - execute(): invokes the stored ToolHandlerInterface handler for the matching tool.
 * - dispatches Symfony AI toolbox lifecycle events around registry-backed execution.
 *
 * Execution lifecycle:
 *   RegistryBackedToolbox::execute() is called inside a Messenger tool worker
 *   via FaultTolerantToolbox → ToolExecutor. The handler runs synchronously.
 *   Long-running process tools use Process::start() + polling internally.
 *
 * @see ToolDefinitionDTO  For the registry-side tool definition model.
 * @see ToolHandlerInterface  For the typed handler contract.
 */
final readonly class RegistryBackedToolbox implements ToolboxInterface
{
    public function __construct(
        private ToolRegistryInterface $registry,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    /**
     * Convert all active registry definitions to Symfony Tool DTOs.
     *
     * Permanent tools first (registration order), then dynamic tools
     * (insertion order). The ExecutionReference stores the handler's
     * class so FaultTolerantToolbox/TraceableToolbox metadata works.
     *
     * @return list<Tool>
     */
    public function getTools(): array
    {
        $definitions = $this->registry->activeToolDefinitions();
        $tools = [];

        foreach ($definitions as $definition) {
            $tools[] = $this->toSymfonyTool($definition);
        }

        return $tools;
    }

    /**
     * Execute a tool call by name.
     *
     * Looks up the tool definition from the registry, invokes the stored
     * handler with decoded arguments, and wraps the result.
     *
     * @throws ToolNotFoundException when the tool name is not in the registry
     */
    public function execute(ToolCall $toolCall): ToolResult
    {
        $definition = $this->registry->toolDefinition($toolCall->getName());

        if (null === $definition) {
            throw ToolNotFoundException::notFoundForToolCall($toolCall);
        }

        $metadata = $this->toSymfonyTool($definition);

        $requestedEvent = new ToolCallRequested($toolCall, $metadata);
        $this->eventDispatcher?->dispatch($requestedEvent);

        if ($requestedEvent->isDenied()) {
            return new ToolResult($toolCall, $requestedEvent->getDenialReason() ?? 'Tool execution denied.');
        }

        if ($requestedEvent->hasResult()) {
            return $requestedEvent->getResult() ?? new ToolResult($toolCall, null);
        }

        $handler = $definition->handler;
        $arguments = $toolCall->getArguments();

        try {
            $this->eventDispatcher?->dispatch(new ToolCallArgumentsResolved($handler, $metadata, $arguments));

            $result = new ToolResult($toolCall, ($handler)($arguments));

            $this->eventDispatcher?->dispatch(new ToolCallSucceeded($handler, $metadata, $arguments, $result));

            return $result;
        } catch (\Throwable $exception) {
            $this->eventDispatcher?->dispatch(new ToolCallFailed($handler, $metadata, $arguments, $exception));

            throw $exception;
        }
    }

    private function toSymfonyTool(ToolDefinitionDTO $definition): Tool
    {
        return new Tool(
            reference: new ExecutionReference(
                class: $definition->handler::class,
                method: '__invoke',
            ),
            name: $definition->name,
            description: $definition->description,
            parameters: $definition->parametersJsonSchema,
        );
    }
}
