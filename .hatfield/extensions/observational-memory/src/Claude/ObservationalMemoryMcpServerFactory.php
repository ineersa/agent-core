<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Claude;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;
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
        $settings = $this->settings->settings($projectDir);
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
            $result = ($tool->handler)($arguments, new ToolInvocationContextDTO(self::RUN_ID, timeoutSeconds: $tool->timeoutSeconds));
        } catch (\Throwable $exception) {
            // Report the failure to the model as a tool error; the server keeps serving.
            $this->logger->error('om.claude.tool_failed', [
                'component' => 'observational_memory', 'event_type' => 'om.claude.tool_failed',
                'run_id' => self::RUN_ID, 'session_id' => self::RUN_ID,
                'tool' => $tool->name, 'exception_class' => $exception::class,
            ]);

            return CallToolResult::error([new TextContent($tool->name.' failed.')]);
        }
        // Handlers return TOON text, or a plain cancelled/timed_out map.
        if (\is_string($result)) {
            return new CallToolResult([new TextContent($result)]);
        }

        return CallToolResult::error([new TextContent((string) json_encode($result, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))]);
    }
}
