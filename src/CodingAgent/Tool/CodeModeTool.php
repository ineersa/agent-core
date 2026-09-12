<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Tool\Arguments\CodeModeArgumentsDTO;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeHostBridge;

/**
 * Execute a PHP script that can call existing tools through tool(name, arguments).
 *
 * Implements HatfieldToolProviderInterface for automatic registration.
 * Nested tool calls are delegated to RegistryBackedToolbox through an owned
 * PHP subprocess and a minimal bootstrap (no application autoloader).
 *
 * Disabled by default via tools.code_mode.enabled. Direct PHP filesystem and
 * process calls are available inside the script and bypass toolbox hooks.
 */
final class CodeModeTool implements HatfieldToolProviderInterface
{
    public const string NAME = 'code_mode';

    public const string DESCRIPTION = 'Execute a PHP script that can call existing tools through tool(name, arguments) and return a final value. Intermediate tool results stay in the script; only the returned value becomes this tool result. Disabled until tools.code_mode.enabled is true.';

    public function __construct(
        private readonly CodeModeHostBridge $hostBridge,
    ) {
    }

    /**
     * Execute the code_mode tool.
     *
     * @return mixed Final script return value
     */
    public function __invoke(CodeModeArgumentsDTO $arguments): mixed
    {
        return $this->hostBridge->execute($arguments->script);
    }

    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: self::NAME,
            description: self::DESCRIPTION,
            handler: $this,
            executionMode: ToolExecutionMode::Sequential,
            promptLine: 'code_mode script — run PHP that calls existing tools via tool(name, arguments)',
            promptGuidelines: [
                'Provide one PHP script source string. It is executed as a function body.',
                'Call tool(string $name, array $arguments = []) to invoke existing tools by their registered runtime names, including MCP tools. Use return for the final value.',
                'tool() returns JSON-compatible values as-is. Strings stay strings. There is no automatic JSON or TOON decoding.',
                'Use toon_encode($value) and toon_decode($text) for explicit TOON conversion. Nested tool failures throw RuntimeException and can be caught.',
                'The script runs in a separate PHP process with a minimal bootstrap. Do not expect the application container or autoloader.',
                'Raw PHP filesystem and process functions work and bypass toolbox hooks and approvals. Prefer tool() when you need audited tool behavior.',
                'Do not call subagent, fork, agent_resume, or ask_human from tool(). Those paths are rejected before invocation because code_mode cannot complete deferred or interactive work.',
                'Script returns must be JSON-compatible scalars or arrays. Closures, resources, objects, non-finite floats, invalid UTF-8, and cyclic graphs fail instead of being silently dropped or substituted.',
                'Each script has a 60-second wall budget (or the remaining parent tool budget when smaller) and a 256 MiB PHP memory_limit. Nested calls receive the remaining budget cooperatively; a blocking nested handler can still overrun until it returns.',
                'code_mode is disabled by default. Enable it with settings path tools.code_mode.enabled = true (user or project scope), then restart Hatfield.',
            ],
        );
    }
}
