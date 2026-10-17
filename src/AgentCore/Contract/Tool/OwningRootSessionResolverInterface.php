<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

/**
 * Resolves the durable owning root session for a source run.
 *
 * Nested child runs store artifacts and deferred work under their parent tree.
 * Retained completion evidence must seal and authorize against the owning root,
 * never a phantom sessions/<childRunId> directory.
 */
interface OwningRootSessionResolverInterface
{
    public function resolveOwningRootSessionId(string $sourceRunId): string;
}
