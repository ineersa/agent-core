<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Port;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunProgressUpdateDTO;

interface ChildRunProgressSinkPort
{
    public function progressSignature(ChildRunProgressUpdateDTO $update): string;

    public function emit(ChildRunProgressUpdateDTO $update): void;

    public function mapTerminalProgressStatus(RunState $state): string;
}
