<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Domain\Run\RunStatus;

final readonly class RunCancellationToken implements CancellationTokenInterface
{
    public function __construct(
        private RunOperationalStatusReaderInterface $statusReader,
        private string $runId,
    ) {
    }

    public function isCancellationRequested(): bool
    {
        $state = $this->statusReader->findOperationalStatus($this->runId);
        if (null === $state) {
            return false;
        }

        return RunStatus::Cancelling === $state->status || RunStatus::Cancelled === $state->status;
    }
}
