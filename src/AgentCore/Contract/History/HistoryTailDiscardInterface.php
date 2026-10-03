<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\History;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Run\RunState;

/**
 * Append-only linear history: discard forward tail before context mutation.
 *
 * Implemented by CodingAgent session layer; Core pipeline depends on this only.
 */
interface HistoryTailDiscardInterface
{
    public function isContextMutatingMessage(AbstractAgentBusMessage $message): bool;

    public function prepareForwardTailDiscard(string $runId, RunState $state): ?RunEvent;

    public function afterDiscardCommitted(string $runId): void;
}
