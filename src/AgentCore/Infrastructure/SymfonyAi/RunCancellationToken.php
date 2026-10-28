<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Domain\Run\RunStatus;

final class RunCancellationToken implements CancellationTokenInterface
{
    private bool $cancelled = false;

    public function __construct(
        private readonly RunOperationalStatusReaderInterface $statusReader,
        private readonly string $runId,
    ) {
    }

    public function isCancellationRequested(): bool
    {
        if ($this->cancelled) {
            return true;
        }
        $state = $this->statusReader->findOperationalStatus($this->runId);
        if (null === $state) {
            return false;
        }

        // A later continuation cannot make this invocation's interrupted result fresh.
        return $this->cancelled = RunStatus::Cancelling === $state->status || RunStatus::Cancelled === $state->status;
    }
}
