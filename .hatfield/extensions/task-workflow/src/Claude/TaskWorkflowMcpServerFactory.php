<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Claude;

use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ContextualExtensionToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\HatfieldExt\TaskWorkflow\TaskWorkflowExtension;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server;
use Mcp\Server\RequestContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;

/**
 * Builds the Claude Code stdio MCP server for task-workflow.
 *
 * The extension registers itself against {@see ClaudeExtensionHost} exactly as
 * it does in Hatfield; registered tools become MCP tools with the same names
 * and schemas, and registered slash commands are reachable via one
 * `run_command` tool. Prompt contributions become server instructions.
 */
final readonly class TaskWorkflowMcpServerFactory
{
    public const string RUN_ID = 'claude-code';

    public function __construct(
        private LoggerInterface $logger,
        private ExecInterface $exec = new ProcessExec(),
        private ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    public function create(string $cwd, ?string $home, string $version): Server
    {
        $host = new ClaudeExtensionHost($cwd, (new HatfieldSettingsReader($home))->extensionSettings($cwd), $this->exec);
        // The plugin ships skills/ itself; do not write .hatfield/skills into the project.
        // MCP resumes its own fibers and does not drive Revolt. Amp delays there
        // would be resumed early by the transport instead of their timers.
        $extension = new TaskWorkflowExtension(installSkill: false, clock: $this->clock);
        $extension->setLogger($this->logger);
        $extension->register($host);

        $builder = Server::builder()
            ->setServerInfo('task-workflow', $version, 'GitHub issue task workflow from Hatfield')
            ->setLogger($this->logger)
            ->setInstructions($this->instructions($host));

        foreach ($host->registeredTools() as $tool) {
            $builder->addTool(
                fn (RequestContext $context): CallToolResult => $this->callTool($tool, $context),
                name: $tool->name,
                description: $tool->description.$this->guidelines($tool),
                inputSchema: $tool->parametersJsonSchema,
            );
        }

        $commands = $host->registeredCommands();
        if ([] !== $commands) {
            $builder->addTool(
                fn (RequestContext $context): CallToolResult => $this->runCommand($commands, $context),
                name: 'run_command',
                description: "Run a task-workflow slash command and return its output. Run tasks-init only when the user explicitly asks for it.\n".implode("\n", array_map(static fn (array $command): string => '- '.$command['definition']->name.': '.$command['definition']->description, $commands)),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'command' => ['type' => 'string', 'enum' => array_map(static fn (array $command): string => $command['definition']->name, $commands)],
                        'args' => ['type' => 'string', 'description' => 'Command arguments, as typed after the command name.'],
                    ],
                    'required' => ['command'],
                    'additionalProperties' => false,
                ],
            );
        }

        return $builder->build();
    }

    private function instructions(ClaudeExtensionHost $host): string
    {
        if ([] === $host->registeredTools()) {
            return 'Task workflow is not configured for this project: set `extensions.settings.task_workflow.repository` in `.hatfield/settings.yaml` by following the task-workflow skill\'s setup guide, then reconnect this server with /mcp.';
        }

        return $host->instructions();
    }

    private function guidelines(ToolRegistrationDTO $tool): string
    {
        return [] === $tool->promptGuidelines ? '' : "\n\n".implode("\n", $tool->promptGuidelines);
    }

    private function callTool(ToolRegistrationDTO $tool, RequestContext $context): CallToolResult
    {
        $arguments = $this->arguments($context);
        $this->logger->info('task_workflow.claude.tool_started', ['component' => 'task_workflow', 'event_type' => 'tool_started', 'run_id' => self::RUN_ID, 'tool' => $tool->name]);
        try {
            $handler = $tool->handler;
            $result = $handler instanceof ContextualExtensionToolHandlerInterface
                ? $handler($arguments, new ToolInvocationContextDTO(self::RUN_ID, timeoutSeconds: $tool->timeoutSeconds))
                : $handler($arguments);
        } catch (\Throwable $exception) {
            // Report the failure to the model as a tool error; the server keeps serving.
            $this->logger->error('task_workflow.claude.tool_failed', ['component' => 'task_workflow', 'event_type' => 'tool_failed', 'run_id' => self::RUN_ID, 'tool' => $tool->name, 'exception_class' => $exception::class]);

            return CallToolResult::error([new TextContent($tool->name.' failed: '.$exception->getMessage())]);
        }

        return new CallToolResult([new TextContent(\is_string($result) ? $result : (string) json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))]);
    }

    /**
     * @param list<array{definition: CommandDefinitionDTO, handler: ExtensionCommandHandlerInterface}> $commands
     */
    private function runCommand(array $commands, RequestContext $context): CallToolResult
    {
        $arguments = $this->arguments($context);
        foreach ($commands as $command) {
            if ($command['definition']->name !== ($arguments['command'] ?? null)) {
                continue;
            }
            $output = new CapturingCommandContext();
            try {
                $command['handler']->handle((string) ($arguments['args'] ?? ''), $output);
            } catch (\Throwable $exception) {
                // Same contract as task tools: a logged tool error, the server keeps serving.
                $this->logger->error('task_workflow.claude.command_failed', ['component' => 'task_workflow', 'event_type' => 'command_failed', 'run_id' => self::RUN_ID, 'command' => $command['definition']->name, 'exception_class' => $exception::class]);

                return CallToolResult::error([new TextContent($command['definition']->name.' failed: '.$exception->getMessage())]);
            }

            return new CallToolResult([new TextContent($output->text())], $output->hasError());
        }

        return CallToolResult::error([new TextContent('Unknown task-workflow command.')]);
    }

    /** @return array<string, mixed> */
    private function arguments(RequestContext $context): array
    {
        $request = $context->getRequest();

        return $request instanceof CallToolRequest ? $request->arguments : [];
    }
}
