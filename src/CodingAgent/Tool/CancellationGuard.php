<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCancelledException;
use Ineersa\AgentCore\Contract\Tool\ToolExecutionContextAccessorInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutionContextInterface;

/**
 * Cooperative cancellation checkpoint helper for short app-owned tools.
 *
 * Tools call checkpoints before starting work and at safe boundaries.
 * CancellationGuard depends only on AgentCore context/exception contracts
 * and does not import any CodingAgent domain/infrastructure classes.
 */
final readonly class CancellationGuard
{
    /**
     * Check whether cancellation has been requested and throw if so.
     *
     * @throws ToolCancelledException
     */
    public function checkpoint(ToolExecutionContextInterface $context): void
    {
        if ($context->cancellationToken()->isCancellationRequested()) {
            throw new ToolCancelledException();
        }
    }

    /**
     * Check cancellation using the accessor's current context.
     *
     * @throws ToolCancelledException
     * @throws \LogicException        when no context is active
     */
    public function checkpointFromAccessor(ToolExecutionContextAccessorInterface $accessor): void
    {
        $this->checkpoint($accessor->requireCurrent());
    }
}
