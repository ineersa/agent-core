<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

/**
 * Interface that built-in tool implementations may implement to provide
 * their own definition metadata for automatic registration.
 *
 * Tool classes may self-implement this interface (recommended for tools
 * that own a single definition) or a separate provider class can wrap
 * them (useful when one provider registers multiple tool definitions).
 *
 * Providers are collected by ToolRegistry via the `hatfield.tool_provider`
 * autoconfiguration tag and registered as permanent tools during registry
 * construction.
 */
interface HatfieldToolProviderInterface
{
    /**
     * Return the tool's full definition for registration.
     *
     * Called when ToolRegistry is constructed. The returned ToolDefinitionDTO
     * must be fully populated including a valid ToolHandlerInterface
     * implementation for execution.
     */
    public function definition(): ToolDefinitionDTO;
}
