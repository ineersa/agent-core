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
 */
final class CodeModeTool implements HatfieldToolProviderInterface
{
    public const string NAME = 'code_mode';

    public const string DESCRIPTION = 'Execute a PHP script that can call existing tools through tool(name, arguments) and return a final value. Intermediate tool results stay in the script; only the returned value becomes this tool result.';

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
                'Call tool(string $name, array $arguments = []) to invoke existing tools. Use return for the final value.',
                'The script runs in a separate PHP process with a minimal bootstrap. Do not expect the application container or autoloader.',
            ],
        );
    }
}
