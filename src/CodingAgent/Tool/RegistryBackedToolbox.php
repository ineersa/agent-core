<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Registry-backed Symfony AI Toolbox.
 *
 * Reads all active tool definitions (permanent, dynamic, and extension-registered)
 * from ToolRegistryInterface and makes them available for execution through the
 * Symfony AI ToolboxInterface contract.
 *
 * - getTools(): converts each ToolDefinitionDTO to a Symfony Tool DTO.
 * - execute(): invokes the stored ToolHandlerInterface handler for the matching tool.
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
            $tools[] = new Tool(
                reference: new ExecutionReference(
                    class: $definition->handler::class,
                    method: '__invoke',
                ),
                name: $definition->name,
                description: $definition->description,
                parameters: $definition->parametersJsonSchema,
            );
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

        $result = ($definition->handler)($toolCall->getArguments());

        return new ToolResult($toolCall, $result);
    }
}
