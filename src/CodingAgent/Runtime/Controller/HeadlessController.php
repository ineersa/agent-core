<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Non-blocking headless controller using Revolt event loop.
 *
 * Orchestrates the controller event loop: reads JSONL commands from stdin,
 * ACKs immediately, dispatches through Symfony EventDispatcher to focused
 * #[AsEventListener] command handlers, and forwards runtime events to
 * stdout for the TUI.
 *
 * Event sources:
 * - Canonical events: polled from events.jsonl via InProcessAgentSessionClient
 *   event drain (seq > 0).
 * - Transient streaming deltas: read from the LLM consumer child process stdout
 *   pipe. Stream subscribers inside the LLM consumer write JSONL to STDOUT;
 *   the controller reads incrementally and forwards.
 *
 * @see ConsumerSupervisor
 */
final class HeadlessController
{
    /** 10ms LLM stdout poll interval for responsive streaming. */
    private const float LLM_STDOUT_POLL_INTERVAL = 0.01;

    /** 50ms event drain poll interval. */
    private const float EVENT_DRAIN_INTERVAL = 0.05;

    /** 5s consumer supervision interval. */
    private const float SUPERVISE_INTERVAL = 5.0;

    /** @var resource|null */
    private $stdout;

    private bool $shuttingDown = false;

    /** @var array<string, int> runId => lastForwardedSeq */
    private array $runEventCursors = [];

    public function __construct(
        private readonly ConsumerSupervisor $consumerSupervisor,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly ?InProcessAgentSessionClient $eventClient = null,
    ) {
    }

    public function run(): int
    {
        $this->stdout = fopen('php://stdout', 'w');
        if (false === $this->stdout) {
            throw new \RuntimeException('Cannot open stdout for controller mode');
        }

        // Reap orphaned messenger:consume processes left behind by SIGKILL'd
        // previous runs.
        $this->killOrphanedConsumers();

        $this->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RuntimeReady->value,
            runId: '',
            seq: 0,
            payload: ['version' => '1.0', 'transport' => 'controller'],
        ));

        // Launch messenger consumers for async execution and command transports.
        $this->consumerSupervisor->launch('run_control');
        $this->consumerSupervisor->launch('llm');
        $this->consumerSupervisor->launch('tool');

        // Non-blocking stdin: read JSONL commands from TUI.
        EventLoop::onReadable(\STDIN, function (string $watcherId, $stream): void {
            if ($this->shuttingDown) {
                return;
            }

            $line = fgets($stream);
            if (false === $line) {
                EventLoop::cancel($watcherId);

                return;
            }

            $trimmed = trim($line);
            if ('' === $trimmed) {
                return;
            }

            $this->handleCommandLine($trimmed);
        });

        // Poll LLM consumer stdout for transient streaming deltas.
        EventLoop::repeat(self::LLM_STDOUT_POLL_INTERVAL, function (): void {
            if ($this->shuttingDown) {
                return;
            }

            $this->pollLlmStdout();
        });

        // Periodic event drain: poll canonical events from events.jsonl.
        EventLoop::repeat(self::EVENT_DRAIN_INTERVAL, function (): void {
            if ($this->shuttingDown || null === $this->eventClient) {
                return;
            }

            $activeRuns = array_keys($this->runEventCursors);

            foreach ($activeRuns as $runId) {
                $cursor = $this->runEventCursors[$runId] ?? null;
                if (null === $cursor) {
                    continue;
                }

                try {
                    foreach ($this->eventClient->events($runId) as $event) {
                        if (0 === $event->seq) {
                            continue;
                        }

                        if ($event->seq <= $cursor) {
                            continue;
                        }

                        $this->emitInternal($event);

                        if ($event->seq > 0) {
                            $this->runEventCursors[$runId] = max($cursor, $event->seq);
                            $cursor = $this->runEventCursors[$runId];
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Controller event drain error', [
                        'run_id' => $runId,
                        'exception' => $e,
                    ]);
                }
            }
        });

        // Consumer supervision: check child process health.
        EventLoop::repeat(self::SUPERVISE_INTERVAL, function (): void {
            if ($this->shuttingDown) {
                return;
            }

            $this->consumerSupervisor->supervise();
        });

        // Graceful shutdown on termination signals.
        EventLoop::onSignal(\SIGTERM, function (): void {
            $this->shutdown();
            EventLoop::getDriver()->stop();
        });
        EventLoop::onSignal(\SIGINT, function (): void {
            $this->shutdown();
            EventLoop::getDriver()->stop();
        });

        EventLoop::run();

        return Command::SUCCESS;
    }

    // ── LLM stdout polling ───────────────────────────────────────────────

    /**
     * Poll the LLM consumer child process stdout for transient streaming deltas.
     *
     * Stream subscribers running inside the LLM consumer process write JSONL
     * lines to STDOUT. This reads incremental output from the child process
     * pipe, parses valid RuntimeEvent JSONL, and forwards to the TUI.
     *
     * Non-JSONL lines (e.g. messenger:consume output) are silently skipped.
     * The output cursor tracks the byte offset to avoid re-reading.
     */
    private function pollLlmStdout(): void
    {
        $llmProcess = $this->consumerSupervisor->getProcess('llm');

        if (null === $llmProcess) {
            return;
        }

        $output = $llmProcess->getIncrementalOutput();

        if ('' === $output) {
            return;
        }

        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed) {
                continue;
            }

            $data = json_decode($trimmed, true);

            if (!\is_array($data) || !isset($data['v'], $data['type'])) {
                // Not a valid RuntimeEvent — likely messenger:consume
                // informational output. Silently skip.
                continue;
            }

            try {
                $event = RuntimeEvent::fromArray($data);
                $this->emit($event);
            } catch (\Throwable $e) {
                $this->logger->debug('Skipping unparseable JSONL from LLM consumer stdout', [
                    'line' => mb_substr($trimmed, 0, 200),
                    'exception' => $e,
                ]);
            }
        }
    }

    // ── Command handling ─────────────────────────────────────────────────

    private function handleCommandLine(string $line): void
    {
        $command = $this->decodeCommand($line);
        if (null === $command) {
            return;
        }

        $this->ackCommand($command);

        try {
            $emit = $this->emit(...);
            $event = new ControllerCommandEvent($command, $emit);
            $this->dispatcher->dispatch($event);
        } catch (\Throwable $e) {
            $this->logger->error('Controller command dispatch failed', [
                'command_type' => $command->type,
                'command_id' => $command->id,
                'exception' => $e,
            ]);
            $this->emitCommandRejected($command, $e->getMessage());
        }
    }

    private function decodeCommand(string $line): ?RuntimeCommand
    {
        try {
            return JsonlCodec::decodeCommand($line);
        } catch (\Throwable $e) {
            $this->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: '',
                seq: 0,
                payload: ['error' => \sprintf('JSONL decode error: %s', $e->getMessage())],
            ));

            return null;
        }
    }

    // ── ACK protocol ─────────────────────────────────────────────────────

    private function ackCommand(RuntimeCommand $command): void
    {
        $this->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::CommandAck->value,
            runId: $command->runId ?? '',
            seq: 0,
            payload: [
                'commandId' => $command->id,
                'commandType' => $command->type,
                'status' => 'accepted',
            ],
        ));
    }

    private function emitCommandRejected(RuntimeCommand $command, string $reason): void
    {
        $this->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::CommandRejected->value,
            runId: $command->runId ?? '',
            seq: 0,
            payload: [
                'commandId' => $command->id,
                'commandType' => $command->type,
                'status' => 'rejected',
                'reason' => $reason,
            ],
        ));
    }

    // ── Shutdown ─────────────────────────────────────────────────────────

    private function killOrphanedConsumers(): void
    {
        $cwd = getcwd();
        if (false === $cwd) {
            return;
        }

        $knownTransportNames = ['run_control', 'llm', 'tool'];

        $pgrepOutput = [];
        $pgrepStatus = 0;
        exec('pgrep -f messenger:consume 2>/dev/null', $pgrepOutput, $pgrepStatus);
        if (0 !== $pgrepStatus || [] === $pgrepOutput) {
            return;
        }

        $killedPids = [];

        foreach ($pgrepOutput as $line) {
            $pid = (int) trim($line);
            if ($pid <= 0) {
                continue;
            }

            $stat = @file_get_contents("/proc/{$pid}/stat");
            if (false === $stat) {
                continue;
            }

            $fields = explode(' ', $stat);
            $ppid = (int) ($fields[3] ?? 0);
            if (1 !== $ppid) {
                continue;
            }

            $procCwd = @readlink("/proc/{$pid}/cwd");
            if (false === $procCwd || $procCwd !== $cwd) {
                continue;
            }

            $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
            if (false === $cmdline) {
                continue;
            }

            $queueName = null;
            foreach ($knownTransportNames as $name) {
                if (str_contains($cmdline, $name)) {
                    $queueName = $name;
                    break;
                }
            }
            if (null === $queueName) {
                continue;
            }

            $this->logger->info('Reaping orphaned consumer', [
                'pid' => $pid,
                'ppid' => $ppid,
                'transport' => $queueName,
            ]);

            @posix_kill($pid, \SIGTERM);
            $killedPids[] = $pid;
        }

        if ([] !== $killedPids) {
            usleep(500_000);

            foreach ($killedPids as $pid) {
                if (false !== @file_get_contents("/proc/{$pid}/stat")) {
                    @posix_kill($pid, \SIGKILL);
                    $this->logger->warning('Orphaned consumer did not stop gracefully, sent SIGKILL', [
                        'pid' => $pid,
                    ]);
                }
            }
        }
    }

    private function shutdown(): void
    {
        if ($this->shuttingDown) {
            return;
        }

        $this->shuttingDown = true;

        $this->logger->info('Controller shutting down gracefully');

        $this->consumerSupervisor->shutdown();
    }

    // ── Output ───────────────────────────────────────────────────────────

    private function emit(RuntimeEvent $event): void
    {
        if (RuntimeEventTypeEnum::RunStarted->value === $event->type
            || RuntimeEventTypeEnum::RunResumed->value === $event->type
        ) {
            $this->runEventCursors[$event->runId] = $this->runEventCursors[$event->runId] ?? 0;
        } elseif (RuntimeEventTypeEnum::RunCompleted->value === $event->type
            || RuntimeEventTypeEnum::RunFailed->value === $event->type
            || RuntimeEventTypeEnum::RunCancelled->value === $event->type
        ) {
            unset($this->runEventCursors[$event->runId]);
        }

        $this->emitInternal($event);
    }

    private function emitInternal(RuntimeEvent $event): void
    {
        if (null === $this->stdout) {
            return;
        }

        $line = JsonlCodec::encodeEvent($event);
        $written = @fwrite($this->stdout, $line);

        if (false === $written || 0 === $written) {
            $this->logger->error('Controller stdout write failed, initiating shutdown', [
                'event_type' => $event->type,
            ]);
            $this->shutdown();
            EventLoop::getDriver()->stop();

            return;
        }

        fflush($this->stdout);
    }
}
