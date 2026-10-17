<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\Tool\OwningRootSessionResolverInterface;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;

/**
 * Walks durable child-artifact and session parent links to the owning root.
 *
 * Uses AgentChildRunDirectory and HatfieldSession.parentId only. Does not create
 * a separate ownership registry or invent session directories.
 */
final readonly class OwningRootSessionResolver implements OwningRootSessionResolverInterface
{
    public function __construct(
        private AgentChildRunDirectory $children,
        private HatfieldSessionStore $sessions,
    ) {
    }

    public function resolveOwningRootSessionId(string $sourceRunId): string
    {
        $current = trim($sourceRunId);
        if ('' === $current) {
            throw new \InvalidArgumentException('sourceRunId must be nonblank.');
        }
        if (false !== strpbrk($current, "/\\\0")) {
            throw new \InvalidArgumentException('sourceRunId must not contain path separators.');
        }

        $seen = [];
        while (!isset($seen[$current])) {
            $seen[$current] = true;
            $parent = $this->parentOf($current);
            if (null === $parent || $parent === $current) {
                return $current;
            }
            $current = $parent;
        }

        throw new \RuntimeException(\sprintf('Cyclic ownership while resolving root session for "%s".', $sourceRunId));
    }

    private function parentOf(string $runId): ?string
    {
        $entry = $this->children->locate($runId);
        if (null !== $entry) {
            $parent = trim($entry->parentRunId);

            return '' === $parent ? null : $parent;
        }

        $session = $this->sessions->findSession($runId);
        if (null === $session) {
            return null;
        }
        $parent = $session->parentId;
        if (!\is_string($parent)) {
            return null;
        }
        $parent = trim($parent);

        return '' === $parent ? null : $parent;
    }
}
