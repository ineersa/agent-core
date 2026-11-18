<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Claude;

use HelgeSverre\Toon\Toon;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;
use Ineersa\HatfieldExt\ObservationalMemory\Tool\OmToolHandlerInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Tool\OmToolRegistrationFactory;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server;
use Mcp\Server\RequestContext;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the Claude Code stdio MCP server: exactly the Hatfield `memory_search`
 * and `recall` tools, with the same schemas and results, for the project
 * Claude Code was started in. No commands, projection or compaction.
 */
final readonly class ObservationalMemoryMcpServerFactory
{
    public const string RUN_ID = 'claude-code';

    public function __construct(
        private ClaudeOmSettingsProvider $settings,
        private ExecInterface $exec,
        private LoggerInterface $logger,
        private ?HttpClientInterface $httpClient = null,
    ) {
    }

    /** @param string $projectDir CLAUDE_PROJECT_DIR, the directory Claude Code was started in */
    public function create(string $projectDir, string $version): Server
    {
        $builder = Server::builder()
            ->setServerInfo('observational-memory', $version, 'Librarian observational memory from Hatfield')
            ->setLogger($this->logger);
        if (!ClaudeProjectRootPolicy::allows($projectDir)) {
            return $builder->setInstructions('Observational memory is unavailable: Claude Code was started below the Git checkout root. Start it in the project root to use memory_search and recall.')->build();
        }
        try {
            $settings = $this->settings->settings($projectDir);
        } catch (\Throwable $error) {
            // Start without tools rather than fail the server; the message may quote settings, so it is not logged.
            $this->logger->error('om.claude.settings_invalid', [
                'component' => 'observational_memory', 'event_type' => 'om.claude.settings_invalid',
                'run_id' => self::RUN_ID, 'session_id' => self::RUN_ID,
                'exception_class' => $error::class,
            ]);

            return $builder->setInstructions('Observational memory settings are invalid: fix `extensions.settings.observational_memory` in `~/.hatfield/settings.yaml` or the project `.hatfield/settings.yaml`, then reconnect this server with /mcp.')->build();
        }
        if (null === $settings) {
            return $builder->setInstructions('Observational memory is not configured: set `extensions.settings.observational_memory.librarian.url` in `~/.hatfield/settings.yaml` or the project `.hatfield/settings.yaml`, then reconnect this server with /mcp.')->build();
        }

        // Claude Code has no Hatfield child runs; its subagents share the main session's memory.
        $query = new OmQueryService($this->exec, $projectDir, static fn (string $runId): bool => false, $settings, $this->logger, $this->httpClient);
        foreach ([OmToolRegistrationFactory::search($query), OmToolRegistrationFactory::recall($query)] as $tool) {
            $builder->addTool(
                fn (RequestContext $context): CallToolResult => $this->callTool($tool, $context),
                name: $tool->name,
                description: $tool->description."\n\n".implode("\n", $tool->promptGuidelines),
                inputSchema: $tool->parametersJsonSchema,
            );
        }

        return $builder->build();
    }

    private function callTool(ToolRegistrationDTO $tool, RequestContext $context): CallToolResult
    {
        $request = $context->getRequest();
        $arguments = $request instanceof CallToolRequest ? $request->arguments : [];
        try {
            $handler = $tool->handler;
            if (!$handler instanceof OmToolHandlerInterface) {
                throw new \LogicException('Observational memory tools expose structured results.');
            }
            $result = $handler->result($arguments, new ToolInvocationContextDTO(self::RUN_ID, timeoutSeconds: $tool->timeoutSeconds));
        } catch (\Throwable $exception) {
            // Report the failure to the model as a tool error; the server keeps serving.
            $this->logger->error('om.claude.tool_failed', [
                'component' => 'observational_memory', 'event_type' => 'om.claude.tool_failed',
                'run_id' => self::RUN_ID, 'session_id' => self::RUN_ID,
                'tool' => $tool->name, 'exception_class' => $exception::class,
            ]);

            return CallToolResult::error([new TextContent($tool->name.' failed.')]);
        }
        // The same TOON text Hatfield receives. Invalid arguments, Librarian
        // failures and upstream tool errors (`ok: false` or `isError: true`),
        // cancellation and timeouts are MCP tool errors.
        $content = [new TextContent(Toon::encode($result))];
        $failed = false === ($result['ok'] ?? null) || true === ($result['isError'] ?? false)
            || true === ($result['cancelled'] ?? false) || true === ($result['timed_out'] ?? false);

        return $failed ? CallToolResult::error($content) : new CallToolResult($content);
    }
}
