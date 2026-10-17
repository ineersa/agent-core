<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Tool;

use Ineersa\AgentCore\Contract\Tool\OwningRootSessionResolverInterface;

/**
 * Identity fallback used when no host ownership graph is available.
 *
 * CodingAgent replaces this with the durable artifact/session parent walk.
 */
final class SourceRunOwningRootSessionResolver implements OwningRootSessionResolverInterface
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
