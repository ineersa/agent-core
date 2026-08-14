<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\ChildRun\Metadata;

/**
 * Typed tools_scope block from RunStarted metadata.
 *
 * allowed_tools is the fixed child policy list (empty means none); mcp remains a dynamic map.
 */
final readonly class RunStartedToolsScopeDTO
{
    /**
     * @param list<string>         $allowedTools
     * @param array<string, mixed> $mcp
     */
    public function __construct(
        public array $allowedTools = [],
        public array $mcp = [],
    ) {
    }
}
