<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;

final class CodeModeCancellationToken implements CancellationTokenInterface
{
    private bool $cancelled = false;

    public function __construct(private CancellationTokenInterface $parent, private DeferredToolCompletionRepositoryInterface $deferred, private string $id)
    {
    }

    public function isCancellationRequested(): bool
    {
        return $this->cancelled = $this->cancelled || $this->parent->isCancellationRequested()
            || null !== $this->deferred->findMetadataByDeferredId($this->id)?->interruptionReason;
    }
}
