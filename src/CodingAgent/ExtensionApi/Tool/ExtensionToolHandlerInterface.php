<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tool;

/**
 * Extension-facing contract for permanent tool execution handlers.
 *
 * Extensions register tools via {@see ToolRegistrationDTO} with a handler that
 * implements this interface. Hatfield adapts it internally to
 * {@see \Ineersa\CodingAgent\Tool\ToolHandlerInterface} at registration time
 * so extension packages stay within the ExtensionApi boundary.
 *
 * Mirrors {@see \Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface}
 * naming: the "Extension" prefix marks the public extension contract.
 */
interface ExtensionToolHandlerInterface
{
    /**
     * Execute the tool with the given arguments.
     *
     * @param array<string, mixed> $arguments Decoded tool call arguments as
     *                                        an associative array, keyed by
     *                                        the parameter names defined in
     *                                        parametersJsonSchema
     *
     * @return mixed Tool execution result. Typically a string or array that
     *               the Toolbox serializes into the LLM response. Output
     *               capping is handled centrally by tool-result processors
     *               after execution — tools can return full output directly.
     */
    public function __invoke(array $arguments): mixed;
}
