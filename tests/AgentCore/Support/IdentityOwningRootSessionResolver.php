<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\Tool\OwningRootSessionResolverInterface;

/**
 * Tests-only identity owning-root resolver.
 *
 * Production wiring uses CodingAgent's durable artifact/deferred/session walk.
 */
final class IdentityOwningRootSessionResolver implements OwningRootSessionResolverInterface
{
    public function resolveOwningRootSessionId(string $sourceRunId): string
    {
        $sourceRunId = trim($sourceRunId);
        if ('' === $sourceRunId) {
            throw new \InvalidArgumentException('sourceRunId must be nonblank.');
        }
        if (false !== strpbrk($sourceRunId, "/\\\0")) {
            throw new \InvalidArgumentException('sourceRunId must not contain path separators.');
        }

        return $sourceRunId;
    }
}
