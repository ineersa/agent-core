<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;

/** The invocation fence outlives the parent run status that first cancelled it. */
final readonly class CodeModeCancellationToken implements CancellationTokenInterface
{
    public function __construct(private CodeModeExecutionRepository $executions, private string $identity, private CancellationTokenInterface $parent)
    {
    }

    public function isCancellationRequested(): bool
    {
        if ($this->parent->isCancellationRequested()) {
            $this->executions->interrupt($this->identity, CodeModeInterruptionReasonEnum::Cancelled);
        }

        return !$this->executions->isActive($this->identity);
    }
}
