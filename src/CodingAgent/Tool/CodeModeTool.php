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

    public const string DESCRIPTION = 'Execute PHP to batch tool calls, compare results, filter data, or choose the next call programmatically. Call tools through tool(name, arguments) and return the relevant evidence. Intermediate tool results stay in the script.';

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
        return $this->hostBridge->execute(
            $arguments->script,
            $arguments->timeout_seconds,
            $arguments->memory_limit_mb,
        );
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
                'Prefer code_mode for multi-step tool work when intermediate results do not need model interpretation. Batch related reads and lookups, filter or compare inside the script, and return the evidence needed for the next decision. Use direct tools for single calls, images, human input, and child-agent operations. Do not wrap a single call or dump entire intermediate results merely to use code_mode.',
                'Provide one PHP script source string. It is executed as a function body.',
                'Call tool(string $name, array $arguments = []) to invoke existing tools by their registered runtime names, including MCP tools. Use return for the final value.',
                'tool() returns values as-is. Strings stay strings. Use json_decode(), toon_decode(), and toon_encode() for explicit format conversion. Nested tool failures throw RuntimeException and can be caught.',
                'Raw PHP filesystem and process functions are available. They bypass toolbox hooks and approvals; use tool() when you need audited tool behavior.',
                'Use timeout_seconds (default 60, max 300) and memory_limit_mb (default 256, max 1024) to set execution budgets. The remaining parent tool budget still applies.',
                'Use echo or stderr for diagnostics. stdout/stderr accompany the returned value and then follow ordinary output capping with saved-output recovery.',
            ],
        );
    }
}
