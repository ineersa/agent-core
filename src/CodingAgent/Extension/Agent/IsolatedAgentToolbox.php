<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Agent;

use Ineersa\CodingAgent\Tool\DefinitionToolFactory;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentToolDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolverInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Request-scoped toolbox built only from extension-supplied AgentToolDTO entries.
 *
 * Never consults Hatfield's ambient tool registry. Execution is delegated to
 * the native Symfony AI Toolbox: arguments are passed through to the raw-array
 * extension handler via the raw-arguments resolver path, and failures are
 * wrapped by the native toolbox for FaultTolerantToolbox at the runner.
 *
 * Dynamic extension-agent tools keep raw-array handlers; Symfony AI's Validator
 * listener only validates typed objects, so raw arguments are passed to the
 * extension handler verbatim — missing/unknown/constraint handling is the
 * extension's responsibility, not simulated here.
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
    public function __construct(
        array $tools,
        private readonly ToolCallArgumentResolverInterface $argumentResolver,
        private readonly ?LoggerInterface $logger = null,
    ) {
        foreach ($tools as $tool) {
            if (!$tool instanceof AgentToolDTO) {
                throw new \InvalidArgumentException('IsolatedAgentToolbox expects AgentToolDTO entries only.');
            }

            if (isset($this->handlersByName[$tool->name])) {
                throw new \InvalidArgumentException(\sprintf('Duplicate isolated agent tool name "%s".', $tool->name));
            }

            $this->handlersByName[$tool->name] = $tool->handler;
            $this->metadataByName[$tool->name] = new Tool(
                reference: new ExecutionReference($tool->handler::class, '__invoke'),
                name: $tool->name,
                description: $tool->description,
                parameters: $tool->parametersJsonSchema,
                metadata: ['raw_arguments' => true],
            );
        }
    }

    public function getTools(): array
    {
        return array_values($this->metadataByName);
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        $handler = $this->handlersByName[$toolCall->getName()] ?? null;
        if (null === $handler) {
            throw ToolNotFoundException::notFoundForToolCall($toolCall);
        }

        $toolbox = new Toolbox(
            tools: [$handler],
            toolFactory: new DefinitionToolFactory($this->metadataByName[$toolCall->getName()]),
            argumentResolver: $this->argumentResolver,
            logger: $this->logger ?? new NullLogger(),
        );

        return $toolbox->execute($toolCall);
    }
}
