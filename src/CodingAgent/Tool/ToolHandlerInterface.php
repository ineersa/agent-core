<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

/**
 * Marker for invokable tool execution handlers.
 *
 * Built-in handlers accept a single typed argument DTO (Symfony AI native
 * resolution + Validator). Dynamic MCP and public extension adapters keep a
 * single array parameter so their published contracts stay raw-array based.
 *
 * PHP cannot express both signatures on one interface method, so call shape is
 * enforced by RegistryBackedToolbox reflection + ToolCallArgumentResolver.
 */
interface ToolHandlerInterface
{
}
