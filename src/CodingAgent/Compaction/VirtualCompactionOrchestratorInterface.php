<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

interface VirtualCompactionOrchestratorInterface
{
    /**
     * @param list<AgentMessage> $messages
     */
    public function compactForRun(string $runId, array $messages, bool $force = false): VirtualCompactionResult;
}
