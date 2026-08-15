<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Symfony\AI\Agent\Toolbox\ToolFactoryInterface;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Minimal Symfony AI ToolFactoryInterface adapter for a single registered
 * tool definition whose metadata was already computed from the registry.
 *
 * RegistryBackedToolbox builds one native Toolbox per execution with exactly
 * one handler instance, so this factory simply hands back the precomputed
 * metadata for that definition.
 */
final readonly class DefinitionToolFactory implements ToolFactoryInterface
{
    public function __construct(
        private Tool $metadata,
    ) {
    }

    public function getTool(object|string $reference): iterable
    {
        return [$this->metadata];
    }
}
