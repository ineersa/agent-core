<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Process;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Symfony\Component\Process\Process;

/**
 * Process-isolated implementation of AgentSessionClient.
 *
 * Spawns `bin/console agent --controller` and communicates via JSONL over stdin/stdout.
 * The controller process launches messenger:consume run_control, llm, and tool
 * subprocesses, enabling fully async execution. Each command is written as a
 * JSONL line to stdin; events are read from stdout. Stderr is reserved for
 * logs/debug output.
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

        /*
         * SOURCE-CHECKOUT ASSUMPTION:
         *
         * dirname(__DIR__, 4) walks up from
         *   src/CodingAgent/Runtime/Process/
         * to the project root, where bin/console lives.
         *
         * This depends on the source-tree layout and will NOT work inside
         * a PHAR, Docker image, or other redistributable build where the
         * executable binary and source tree are packaged differently.
         *
         * TODO: Replace with a SelfExecutableLocator / BinaryLocator that
         * resolves the headless-agent binary path from:
         *   1. An explicit config/key (e.g., agent.runtime.binary_path).
         *   2. Phar::running() / PHP_BINARY introspection inside a PHAR.
         *   3. PATH-based discovery (e.g., `which agent-core-headless`).
         *
         * See: src/CodingAgent/Runtime/Process/AGENTS.md
         */
        $this->projectDir = \dirname(__DIR__, 4);
    }

    public function __destruct()
    {
        $this->stopProcess();
    }

    public function start(StartRunRequest $request): RunHandle
    {
        $this->ensureProcessRunning();
        $this->waitForRuntimeReady();

        $cmd = new RuntimeCommand(
            id: uniqid('cmd_', true),
            type: 'start_run',
            payload: array_filter([
                'prompt' => $request->prompt,
                'cwd' => $request->cwd,
                'options' => $request->options,
                'model' => $request->model,
                'reasoning' => $request->reasoning,
            ], static fn (mixed $v) => null !== $v),
        );

        $this->writeCommand($cmd);

        // Read events until run_started arrives.
        $timeout = 15.0;
        $start = microtime(true);

        while (microtime(true) - $start < $timeout) {
            /** @var RuntimeEvent $event */
            foreach ($this->readEvents() as $event) {
                if ('run_started' === $event->type) {
                    $this->runIdMap[$cmd->id] = $event->runId;

                    return new RunHandle(runId: $event->runId, status: 'running');
                }

                if ('runtime.ready' === $event->type) {
                    continue; // Already ready, skip.
                }

                // Buffer non-matching events
                $this->eventBuffer->enqueue($event);
            }

            // No events yet — brief sleep to avoid busy-wait.
            usleep(10_000);
        }

        throw new \RuntimeException('Agent process did not emit run_started event within '.$timeout.'s');
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
            payload: array_filter([
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

    private function ensureProcessRunning(): void
    {
        if (null !== $this->process && $this->process->isRunning()) {
            return;
        }

        /*
         * TODO: Replace hard-coded bin/console path with the
         * SelfExecutableLocator pattern (see constructor docblock and
         * src/CodingAgent/Runtime/Process/AGENTS.md).
         *
         * In a PHAR/distribution build there is no bin/console — the
         * binary is the PHAR itself or a renamed shim.  The locator
         * should return the correct binary path for the current build
         * type (source checkout, PHAR, Docker, etc.).
         */
        $consolePath = $this->projectDir.'/bin/console';

        if (!is_file($consolePath)) {
            throw new \RuntimeException(\sprintf('Console not found at %s', $consolePath));
        }

        // Spawn controller mode instead of legacy headless mode.
        // The controller launches messenger:consume run_control, llm, and
        // tool processes, enabling full async execution (ASYNC-05).
        $this->process = new Process(
            command: ['php', $consolePath, 'agent', '--controller'],
            cwd: $this->projectDir,
            env: [
                'APP_ENV' => $_SERVER['APP_ENV'] ?? 'dev',
                'APP_DEBUG' => $_SERVER['APP_DEBUG'] ?? '1',
            ],
        );

        $this->process->setTimeout(null);
        $this->process->start();
    }

    /**
     * Wait for the controller to emit runtime.ready before sending commands.
     *
     * The controller boots the kernel and launches consumers before
     * entering the event loop. This method blocks until runtime.ready
     * is received, up to 15 seconds.
     */
    private function waitForRuntimeReady(): void
    {
        $timeout = 15.0;
        $start = microtime(true);

        while (microtime(true) - $start < $timeout) {
            foreach ($this->readEvents() as $event) {
                if ('runtime.ready' === $event->type) {
                    return;
                }

                $this->eventBuffer->enqueue($event);
            }

            usleep(10_000);
        }

        throw new \RuntimeException('Controller did not emit runtime.ready within '.$timeout.'s');
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
