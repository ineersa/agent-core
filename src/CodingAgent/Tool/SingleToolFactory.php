<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Symfony\AI\Agent\Toolbox\ToolFactoryInterface;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Symfony AI ToolFactoryInterface adapter returning exactly one precomputed
 * Tool metadata.
 *
 * Used by per-definition native Toolboxes: each one-definition Toolbox passes
 * its single handler instance to getTool(), which returns the metadata
 * computed from the canonical registry definition (or the isolated-agent tool
 * descriptor). The metadata is fixed at construction time, so the factory
 * needs no handler→metadata lookup.
 */
final readonly class SingleToolFactory implements ToolFactoryInterface
{
    public function __construct(
        private Tool $tool,
    ) {
    }

    public function getTool(object|string $reference): iterable
    {
        if (!\is_object($reference)) {
            // The native Toolbox always invokes the factory with the handler
            // instances from its `tools` iterable; class-name references are
            // never produced by it. Refuse instead of guessing.
            throw new \LogicException(\sprintf('SingleToolFactory only supports handler object references, got class string "%s". The native Toolbox always passes handler instances.', $reference));
        }

        yield $this->tool;
    }
}
