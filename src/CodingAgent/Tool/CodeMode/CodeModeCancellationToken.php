<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionStatusEnum;

final class CodeModeCancellationToken implements CancellationTokenInterface
{
    private bool $cancelled = false;
    private bool $backgrounded = false;

    public function __construct(private CancellationTokenInterface $parent, private DeferredToolCompletionRepositoryInterface $deferred, private string $id, private readonly ?\Closure $invocationUnavailable = null)
    {
    }

    public function isCancellationRequested(): bool
    {
        $metadata = $this->deferred->findMetadataByDeferredId($this->id);
        $this->backgrounded = $this->backgrounded || null !== $metadata?->backgroundedAt;

        return $this->cancelled = $this->cancelled || DeferredToolCompletionStatusEnum::Pending !== $metadata?->status || null !== $metadata->interruptionReason
            || (null !== $this->invocationUnavailable && ($this->invocationUnavailable)($this->backgrounded))
            || (!$this->backgrounded && $this->parent->isCancellationRequested());
    }
}
