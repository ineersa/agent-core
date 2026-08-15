<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Agent;

use Ineersa\CodingAgent\Tool\ToolCallArgumentsValidator;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentToolDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionExceptionInterface;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Request-scoped toolbox built only from extension-supplied AgentToolDTO entries.
 *
 * Never consults Hatfield's ambient tool registry.
 *
 * Dynamic extension-agent tools keep raw-array handlers; reflection cannot invent
 * DTOs for runtime schemas, so arguments are validated against each tool's
 * canonical parametersJsonSchema before invocation. Invalid calls become
 * model-visible fault results via FaultTolerantToolbox wrapping at the runner.
 */
final class IsolatedAgentToolbox implements ToolboxInterface
{
    /** @var array<string, Tool> */
    private array $metadataByName = [];

    /** @var array<string, ExtensionToolHandlerInterface> */
    private array $handlersByName = [];

    /** @var array<string, array<string, mixed>> */
    private array $schemasByName = [];

    private readonly ToolCallArgumentsValidator $argumentsValidator;

    /**
     * @param list<AgentToolDTO> $tools
     */
    public function __construct(
        array $tools,
        ?ToolCallArgumentsValidator $argumentsValidator = null,
    ) {
        $this->argumentsValidator = $argumentsValidator ?? new ToolCallArgumentsValidator();

        foreach ($tools as $tool) {
            if (!$tool instanceof AgentToolDTO) {
                throw new \InvalidArgumentException('IsolatedAgentToolbox expects AgentToolDTO entries only.');
            }

            if (isset($this->handlersByName[$tool->name])) {
                throw new \InvalidArgumentException(\sprintf('Duplicate isolated agent tool name "%s".', $tool->name));
            }

            // Reject unusable schemas before the tool is exposed to the model loop.
            $this->argumentsValidator->assertSchemaIsUsable($tool->parametersJsonSchema, $tool->name);

            $this->handlersByName[$tool->name] = $tool->handler;
            $this->schemasByName[$tool->name] = $tool->parametersJsonSchema;
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

        try {
            $this->argumentsValidator->assertValid(
                $toolCall->getArguments(),
                $this->schemasByName[$name],
                $name,
            );

            $result = ($handler)($toolCall->getArguments());

            return new ToolResult($toolCall, $result);
        } catch (ToolExecutionExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ToolExecutionException::executionFailed($toolCall, $e);
        }
    }

    /**
     * Wrap an isolated toolbox so invalid arguments become model-visible results.
     */
    public static function faultTolerant(self $toolbox): FaultTolerantToolbox
    {
        return new FaultTolerantToolbox($toolbox);
    }
}
