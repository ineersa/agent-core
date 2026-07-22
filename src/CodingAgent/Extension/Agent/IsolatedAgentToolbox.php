<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Agent;

use Ineersa\Hatfield\ExtensionApi\Agent\AgentToolDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Request-scoped toolbox built only from extension-supplied AgentToolDTO entries.
 *
 * Never consults Hatfield's ambient tool registry.
 */
final class IsolatedAgentToolbox implements ToolboxInterface
{
    /** @var array<string, Tool> */
    private array $metadataByName = [];

    /** @var array<string, ExtensionToolHandlerInterface> */
    private array $handlersByName = [];

    /**
     * @param list<AgentToolDTO> $tools
     */
    public function __construct(array $tools)
    {
        foreach ($tools as $tool) {
            if (!$tool instanceof AgentToolDTO) {
                throw new \InvalidArgumentException('IsolatedAgentToolbox expects AgentToolDTO entries only.');
            }

            if (isset($this->handlersByName[$tool->name])) {
                throw new \InvalidArgumentException(\sprintf('Duplicate isolated agent tool name "%s".', $tool->name));
            }

            $this->handlersByName[$tool->name] = $tool->handler;
            $this->metadataByName[$tool->name] = new Tool(
                reference: new ExecutionReference(
                    class: $tool->handler::class,
                    method: '__invoke',
                ),
                name: $tool->name,
                description: $tool->description,
                parameters: $tool->parametersJsonSchema,
            );
        }
    }

    public function getTools(): array
    {
        return array_values($this->metadataByName);
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        $name = $toolCall->getName();
        $handler = $this->handlersByName[$name] ?? null;
        if (null === $handler) {
            throw ToolNotFoundException::notFoundForToolCall($toolCall);
        }

        $result = ($handler)($toolCall->getArguments());

        return new ToolResult($toolCall, $result);
    }
}
