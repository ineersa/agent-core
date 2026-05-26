<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

/**
 * Contract for tool execution handlers.
 *
 * Implementations receive decoded tool call arguments as an associative array
 * and must return a result that the Toolbox serializes for LLM consumption.
 *
 * Invokable objects, closures, and class instances with this interface are
 * all supported. PHP's `callable` pseudo-type cannot be used as a property
 * type, so this interface provides the necessary type safety for tool
 * definition storage and RegistryBackedToolbox execution.
 */
interface ToolHandlerInterface
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
     *               the Toolbox serializes into the LLM response. Large text
     *               results should use OutputCap before returning.
     */
    public function __invoke(array $arguments): mixed;
}
