<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;

/** Records start/send/cancel for scenario tests. */
final class RecordingAgentSessionClient implements AgentSessionClient
{
    /** @var list<array{op: string, runId: ?string, command: ?UserCommand}> */
    public array $ops = [];

    public function start(StartRunRequest $request): RunHandle
    {
        $this->ops[] = ['op' => 'start', 'runId' => null, 'command' => null];

        return new RunHandle('started-run');
    }

    public function attach(string $runId): RunHandle
    {
        $this->ops[] = ['op' => 'attach', 'runId' => $runId, 'command' => null];

        return new RunHandle($runId);
    }

    public function send(string $runId, UserCommand $command): void
    {
        $this->ops[] = ['op' => 'send', 'runId' => $runId, 'command' => $command];
    }

    public function beginObservingChildRun(string $childRunId): void
    {
    }

    public function endObservingChildRun(string $childRunId): void
    {
    }

    public function events(string $runId): iterable
    {
        return [];
    }

    public function cancel(string $runId): void
    {
        $this->ops[] = ['op' => 'cancel', 'runId' => $runId, 'command' => null];
    }

    public function shellExecute(\Ineersa\CodingAgent\Runtime\Contract\ShellExecutionRequestDTO $request): RunHandle
    {
        $this->ops[] = ['op' => 'shellExecute', 'runId' => $sessionId, 'command' => null];

        return new RunHandle($sessionId);
    }

    public function completeRun(string $runId): void
    {
        $this->ops[] = ['op' => 'completeRun', 'runId' => $runId, 'command' => null];
    }

    public function compact(string $runId, ?string $customInstructions = null): void
    {
        $this->ops[] = ['op' => 'compact', 'runId' => $runId, 'command' => null];
    }

    public function lastSend(): ?array
    {
        for ($i = \count($this->ops) - 1; $i >= 0; --$i) {
            if ('send' === $this->ops[$i]['op']) {
                return $this->ops[$i];
            }
        }

        return null;
    }

    /** @return list<array{op: string, runId: ?string, command: ?UserCommand}> */
    public function sendsTo(string $runId): array
    {
        return array_values(array_filter(
            $this->ops,
            static fn (array $op): bool => 'send' === $op['op'] && $op['runId'] === $runId,
        ));
    }
}
