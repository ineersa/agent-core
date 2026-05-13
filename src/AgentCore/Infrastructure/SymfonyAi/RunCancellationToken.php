<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Run\RunStatus;

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
