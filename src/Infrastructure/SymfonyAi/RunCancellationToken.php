<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Run\RunStatus;

/**
 * Provides a mechanism to check if a specific agent run has been cancelled by querying the persistent store. It encapsulates the run identifier and store dependency to determine cancellation status on demand.
 */
final readonly class RunCancellationToken implements CancellationTokenInterface
{
    public function __construct(
        private RunStoreInterface $runStore,
        private string $runId,
    ) {
    }

    public function isCancellationRequested(): bool
    {
        $state = $this->runStore->get($this->runId);
        if (null === $state) {
            return false;
        }

        return RunStatus::Cancelling === $state->status || RunStatus::Cancelled === $state->status;
    }
}
