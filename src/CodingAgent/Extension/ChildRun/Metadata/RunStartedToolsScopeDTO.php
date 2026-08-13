<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\ChildRun\Metadata;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Typed tools_scope block from RunStarted metadata.
 *
 * allowed_tools is the fixed child policy list; mcp remains a dynamic map.
 */
final readonly class RunStartedToolsScopeDTO
{
    /**
     * @param list<string>|null    $allowedTools
     * @param array<string, mixed> $mcp
     */
    public function __construct(
        #[SerializedName('allowed_tools')]
        public ?array $allowedTools = null,
        public array $mcp = [],
    ) {
    }
}
