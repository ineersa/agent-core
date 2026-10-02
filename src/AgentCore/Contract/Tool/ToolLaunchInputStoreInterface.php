<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Tool\ToolLaunchContextDTO;
use Ineersa\AgentCore\Domain\Tool\ToolLaunchInputReferenceDTO;

interface ToolLaunchInputStoreInterface
{
    /** @param iterable<AgentMessage> $messages */
    public function publish(string $kind, string $runId, int $turnNo, string $stepId, string $toolCallId, string $model, string $agentsContext, iterable $messages): ToolLaunchInputReferenceDTO;

    public function read(ToolLaunchInputReferenceDTO $reference): ToolLaunchContextDTO;

    public function delete(string $runId, string $toolCallId): void;

    public function deleteAllForRun(string $runId): void;
}
