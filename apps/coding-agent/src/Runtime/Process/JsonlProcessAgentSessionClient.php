<?php

declare(strict_types=1);

namespace App\Runtime\Process;

use App\Runtime\Contract\AgentSessionClient;
use App\Runtime\Contract\RunHandle;
use App\Runtime\Contract\StartRunRequest;
use App\Runtime\Contract\UserCommand;
use App\Runtime\Protocol\JsonlCodec;
use App\Runtime\Protocol\RuntimeCommand;
use App\Runtime\Protocol\RuntimeEvent;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Process-isolated implementation of AgentSessionClient.
 *
 * Spawns `bin/console agent --headless` and communicates via JSONL over stdin/stdout.
 * Each command is written as a JSONL line to stdin; events are read from stdout.
 * Stderr is reserved for logs/debug output.
 *
 * @todo Implement full process lifecycle, reconnection, heartbeat detection.
 */
final class JsonlProcessAgentSessionClient implements AgentSessionClient
{
    private ?Process $process = null;

    /** @var \SplQueue<RuntimeEvent> */
    private \SplQueue $eventBuffer;

    private string $projectDir;

    /** @var array<string, string> */
    private array $runIdMap = [];

    public function __construct(
        private readonly JsonlCodec $codec = new JsonlCodec(),
    ) {
        $this->eventBuffer = new \SplQueue();
        $this->projectDir = \dirname(__DIR__, 4);
    }

    public function start(StartRunRequest $request): RunHandle
    {
        $this->ensureProcessRunning();

        $cmd = new RuntimeCommand(
            id: uniqid('cmd_', true),
            type: 'start_run',
            payload: [
                'prompt' => $request->prompt,
                'cwd' => $request->cwd,
                'options' => $request->options,
            ],
        );

        $this->writeCommand($cmd);

        // Read the first event to get the run ID
        /** @var RuntimeEvent $event */
        foreach ($this->readEvents() as $event) {
            if ('run_started' === $event->type) {
                $this->runIdMap[$cmd->id] = $event->runId;

                return new RunHandle(runId: $event->runId, status: 'running');
            }

            // Buffer non-matching events
            $this->eventBuffer->enqueue($event);
        }

        throw new \RuntimeException('Agent process did not emit run_started event');
    }

    public function resume(string $runId): RunHandle
    {
        $this->ensureProcessRunning();

        $cmd = new RuntimeCommand(
            id: uniqid('cmd_', true),
            type: 'resume',
            runId: $runId,
        );

        $this->writeCommand($cmd);

        return new RunHandle(runId: $runId, status: 'running');
    }

    public function send(string $runId, UserCommand $command): void
    {
        $this->ensureProcessRunning();

        $type = match ($command->type) {
            'steer', 'message' => 'user_message',
            'follow_up' => 'follow_up',
            'answer_human' => 'answer_human',
            default => throw new \InvalidArgumentException(\sprintf('Unknown command type: "%s"', $command->type)),
        };

        $cmd = new RuntimeCommand(
            id: uniqid('cmd_', true),
            type: $type,
            runId: $runId,
            payload: \array_filter([
                'text' => $command->text,
                'question_id' => $command->payload['question_id'] ?? null,
                'answer' => $command->payload['answer'] ?? null,
            ]),
        );

        $this->writeCommand($cmd);
    }

    public function events(string $runId): iterable
    {
        // Drain buffered events first
        while (!$this->eventBuffer->isEmpty()) {
            $event = $this->eventBuffer->dequeue();
            if ($event->runId === $runId) {
                yield $event;
            }
        }

        // Read new events from the process
        foreach ($this->readEvents() as $event) {
            if ($event->runId === $runId) {
                yield $event;
            }
        }
    }

    public function cancel(string $runId): void
    {
        $this->ensureProcessRunning();

        $cmd = new RuntimeCommand(
            id: uniqid('cmd_', true),
            type: 'cancel',
            runId: $runId,
        );

        $this->writeCommand($cmd);
    }

    public function __destruct()
    {
        $this->stopProcess();
    }

    private function ensureProcessRunning(): void
    {
        if (null !== $this->process && $this->process->isRunning()) {
            return;
        }

        $consolePath = $this->projectDir.'/bin/console';

        if (!is_file($consolePath)) {
            throw new \RuntimeException(\sprintf('Console not found at %s', $consolePath));
        }

        $this->process = new Process(
            command: ['php', $consolePath, 'agent', '--headless'],
            cwd: $this->projectDir,
            env: [
                'APP_ENV' => $_SERVER['APP_ENV'] ?? 'dev',
                'APP_DEBUG' => $_SERVER['APP_DEBUG'] ?? '1',
            ],
        );

        $this->process->setTimeout(null);
        $this->process->start();
    }

    private function writeCommand(RuntimeCommand $command): void
    {
        if (null === $this->process) {
            throw new \RuntimeException('Process not started');
        }

        $this->process->getInputStream()->write(JsonlCodec::encodeCommand($command));
    }

    /**
     * @return iterable<RuntimeEvent>
     */
    private function readEvents(): iterable
    {
        if (null === $this->process) {
            return;
        }

        $output = $this->process->getIncrementalOutput();

        foreach (explode("\n", $output) as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed) {
                continue;
            }

            try {
                yield JsonlCodec::decodeEvent($trimmed);
            } catch (\JsonException) {
                // Skip malformed lines (stderr noise that leaked to stdout)
                continue;
            }
        }
    }

    private function stopProcess(): void
    {
        if (null === $this->process) {
            return;
        }

        if ($this->process->isRunning()) {
            $this->process->stop(3);
        }

        $this->process = null;
    }
}
