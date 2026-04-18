<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunHandle;
use Ineersa\AgentCore\Domain\Run\StartRunInput;

interface AgentRunnerInterface
{
    public function start(StartRunInput $input): RunHandle;

    public function continue(string $runId): void;

    public function steer(string $runId, AgentMessage $message): void;

    public function followUp(string $runId, AgentMessage $message): void;

    public function cancel(string $runId, ?string $reason = null): void;

    public function answerHuman(string $runId, string $questionId, mixed $answer): void;
}
