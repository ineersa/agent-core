<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * Non-blocking headless controller using Revolt event loop.
 *
 * Replaces the synchronous fgets loop in AgentCommand::runHeadless().
 * Reads JSONL commands from stdin via EventLoop::onReadable, ACKs
 * immediately, dispatches to the in-process client, and forwards
 * runtime events to stdout from both the in-process client and the
 * Messenger publish transport.
 *
 * Event flow (evolution):
 *   ASYNC-03: controller forwards events from in-process client events()
 *   ASYNC-04+: controller polls publish transport for forwarded streaming deltas
 *
 * Command protocol:
 *   TUI → stdin JSONL  → controller parses → emits command.ack → dispatches
 *   Controller → stdout JSONL → TUI reads events including command.ack
 */
final class HeadlessController
{
    /** 10ms publish transport poll interval. */
    private const float PUBLISH_POLL_INTERVAL = 0.01;

    /** 5s consumer supervision interval. */
    private const float SUPERVISE_INTERVAL = 5.0;

    /** @var resource|null */
    private $stdout = null;

    /** @var list<int> Consumer child process PIDs for supervision. */
    private array $consumerPids = [];

    private bool $shuttingDown = false;

    public function __construct(
        private readonly InProcessAgentSessionClient $client,
        private readonly ReceiverInterface $publishReceiver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(): int
    {
        $this->stdout = fopen('php://stdout', 'w');
        if (false === $this->stdout) {
            throw new \RuntimeException('Cannot open stdout for controller mode');
        }

        $this->emit(new RuntimeEvent(
            type: 'runtime_ready',
            runId: '',
            seq: 0,
            payload: ['version' => '1.0', 'transport' => 'controller'],
        ));

        // Non-blocking stdin: read JSONL commands from TUI.
        EventLoop::onReadable(\STDIN, function (string $watcherId, $stream): void {
            if ($this->shuttingDown) {
                return;
            }

            $line = fgets($stream);
            if (false === $line) {
                // EOF or error — close stdin watcher.
                EventLoop::cancel($watcherId);

                return;
            }

            $trimmed = trim($line);
            if ('' === $trimmed) {
                return;
            }

            $this->handleCommandLine($trimmed);
        });

        // Poll the publish Doctrine transport for forwarded runtime events.
        EventLoop::repeat(self::PUBLISH_POLL_INTERVAL, function (): void {
            if ($this->shuttingDown) {
                return;
            }

            $this->pollPublishTransport();
        });

        // Consumer supervision: check child process health and restart if crashed.
        EventLoop::repeat(self::SUPERVISE_INTERVAL, function (): void {
            if ($this->shuttingDown) {
                return;
            }

            $this->superviseConsumers();
        });

        // Graceful shutdown on termination signals.
        EventLoop::onSignal(\SIGTERM, function (): void {
            $this->shutdown();
            EventLoop::stop();
        });
        EventLoop::onSignal(\SIGINT, function (): void {
            $this->shutdown();
            EventLoop::stop();
        });

        EventLoop::run();

        return Command::SUCCESS;
    }

    // ── Command handling ─────────────────────────────────────────────────

    private function handleCommandLine(string $line): void
    {
        try {
            $command = JsonlCodec::decodeCommand($line);
        } catch (\Throwable $e) {
            $this->emit(new RuntimeEvent(
                type: 'protocol_error',
                runId: '',
                seq: 0,
                payload: ['error' => \sprintf('JSONL decode error: %s', $e->getMessage())],
            ));

            return;
        }

        // ACK immediately before any processing.
        $this->ackCommand($command, 'accepted');

        // Dispatch command (may block — command bus is sync until ASYNC-05).
        try {
            $this->dispatchCommand($command);
        } catch (\Throwable $e) {
            $this->logger->error('Controller command dispatch failed', [
                'command_type' => $command->type,
                'command_id' => $command->id,
                'exception' => $e,
            ]);
            $this->emit(new RuntimeEvent(
                type: 'command_rejected',
                runId: $command->runId ?? '',
                seq: 0,
                payload: [
                    'commandId' => $command->id,
                    'commandType' => $command->type,
                    'status' => 'rejected',
                    'reason' => $e->getMessage(),
                ],
            ));
        }
    }

    private function dispatchCommand(RuntimeCommand $command): void
    {
        match ($command->type) {
            'start_run' => $this->handleStartRun($command),
            'user_message' => $this->handleUserMessage($command),
            'cancel' => $this->handleCancel($command),
            'resume' => $this->handleResume($command),
            default => $this->emit(new RuntimeEvent(
                type: 'command_rejected',
                runId: $command->runId ?? '',
                seq: 0,
                payload: [
                    'commandId' => $command->id,
                    'commandType' => $command->type,
                    'status' => 'rejected',
                    'reason' => \sprintf('Unknown command type: "%s"', $command->type),
                ],
            )),
        };
    }

    private function handleStartRun(RuntimeCommand $command): void
    {
        $prompt = (string) ($command->payload['prompt'] ?? '');
        $model = isset($command->payload['model']) ? (string) $command->payload['model'] : null;
        $reasoning = isset($command->payload['reasoning']) ? (string) $command->payload['reasoning'] : null;

        $handle = $this->client->start(new StartRunRequest(
            prompt: $prompt,
            model: '' !== $model ? $model : null,
            reasoning: '' !== $reasoning ? $reasoning : null,
        ));

        $this->emit(new RuntimeEvent(
            type: 'run.started',
            runId: $handle->runId,
            seq: 1,
            payload: ['status' => 'running'],
        ));

        // Forward all events from the run (blocks until run completes).
        // In ASYNC-05, this is replaced by polling the publish transport.
        foreach ($this->client->events($handle->runId) as $event) {
            $this->emit($event);
        }
    }

    private function handleUserMessage(RuntimeCommand $command): void
    {
        $runId = $command->runId ?? '';
        if ('' === $runId) {
            $this->emit(new RuntimeEvent(
                type: 'protocol_error',
                runId: '',
                seq: 0,
                payload: ['error' => 'user_message requires runId'],
            ));

            return;
        }

        $this->client->send($runId, new UserCommand(
            type: 'message',
            text: (string) ($command->payload['text'] ?? ''),
        ));

        // Forward all events from the follow-up steer/response.
        foreach ($this->client->events($runId) as $event) {
            $this->emit($event);
        }
    }

    private function handleCancel(RuntimeCommand $command): void
    {
        $runId = $command->runId ?? '';
        if ('' === $runId) {
            return;
        }

        $this->client->cancel($runId);

        $this->emit(new RuntimeEvent(
            type: 'run.cancelled',
            runId: $runId,
            seq: 0,
        ));
    }

    private function handleResume(RuntimeCommand $command): void
    {
        $runId = $command->runId ?? '';
        if ('' === $runId) {
            $this->emit(new RuntimeEvent(
                type: 'protocol_error',
                runId: '',
                seq: 0,
                payload: ['error' => 'resume requires runId'],
            ));

            return;
        }

        $handle = $this->client->resume($runId);

        $this->emit(new RuntimeEvent(
            type: 'run.resumed',
            runId: $handle->runId,
            seq: 1,
            payload: ['status' => 'running'],
        ));

        foreach ($this->client->events($handle->runId) as $event) {
            $this->emit($event);
        }
    }

    // ── ACK protocol ─────────────────────────────────────────────────────

    private function ackCommand(RuntimeCommand $command, string $status, ?string $reason = null): void
    {
        $payload = [
            'commandId' => $command->id,
            'commandType' => $command->type,
            'status' => $status,
        ];

        if (null !== $reason) {
            $payload['reason'] = $reason;
        }

        $eventType = 'accepted' === $status ? 'command.ack' : 'command.rejected';

        $this->emit(new RuntimeEvent(
            type: $eventType,
            runId: $command->runId ?? '',
            seq: 0,
            payload: $payload,
        ));
    }

    // ── Publish transport polling ────────────────────────────────────────

    /**
     * Poll the Messenger publish Doctrine transport for runtime events
     * and forward them to stdout.
     *
     * In ASYNC-02, stream subscribers begin publishing transient deltas
     * to the publish bus. This method polls the resulting Doctrine queue
     * and forwards each event as JSONL to stdout for the TUI to consume.
     *
     * Until ASYNC-02 is complete, this method is a no-op (no messages
     * on the publish transport).
     */
    private function pollPublishTransport(): void
    {
        foreach ($this->publishReceiver->get() as $envelope) {
            try {
                $message = $envelope->getMessage();

                if (!$message instanceof \Ineersa\AgentCore\Domain\Message\PublishRuntimeEvent) {
                    $this->logger->warning('Unexpected message on publish transport', [
                        'message_class' => $message::class,
                    ]);
                    $this->publishReceiver->reject($envelope);

                    continue;
                }

                $this->emit(new RuntimeEvent(
                    type: $message->type,
                    runId: $message->runId,
                    seq: $message->seq,
                    payload: $message->payload,
                ));

                $this->publishReceiver->ack($envelope);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to process publish transport message', [
                    'exception' => $e,
                ]);
                $this->publishReceiver->reject($envelope);
            }
        }
    }

    // ── Consumer supervision ─────────────────────────────────────────────

    /**
     * Launch a messenger:consume child process for the given transport.
     *
     * Keeps track of child PIDs for supervision and restart.
     * Currently not called — consumers are launched when async transport
     * is enabled (ASYNC-04+).
     */
    public function launchConsumer(string $transportName): void
    {
        $projectDir = \dirname(__DIR__, 4);
        $console = $projectDir.'/bin/console';

        $process = \proc_open(
            [
                \PHP_BINARY,
                $console,
                'messenger:consume',
                $transportName,
                '--no-interaction',
                '--time-limit=3600',
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (false === $process || !\is_resource($process)) {
            $this->logger->error('Failed to launch consumer', [
                'transport' => $transportName,
            ]);

            return;
        }

        $status = \proc_get_status($process);
        $pid = $status['pid'] ?? 0;

        $this->consumerPids[] = $pid;

        $this->logger->info('Launched messenger consumer', [
            'transport' => $transportName,
            'pid' => $pid,
        ]);
    }

    /**
     * Check consumer child process health and restart crashed ones.
     *
     * Polls each tracked PID. If a process has exited unexpectedly,
     * removes it from the tracked list. Does NOT restart by default
     * — the controller's run_control consumer is single-instance and
     * should be restarted manually or via a supervisor layer.
     *
     * Logs warnings for diagnostics.
     */
    private function superviseConsumers(): void
    {
        if ([] === $this->consumerPids) {
            return;
        }

        $alive = [];
        foreach ($this->consumerPids as $pid) {
            $result = \proc_open(
                \sprintf('kill -0 %d 2>/dev/null', $pid),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if (\is_resource($result)) {
                \proc_close($result);
                $alive[] = $pid;
            } else {
                $this->logger->warning('Consumer process exited unexpectedly', [
                    'pid' => $pid,
                ]);
            }
        }

        $this->consumerPids = $alive;
    }

    // ── Shutdown ─────────────────────────────────────────────────────────

    private function shutdown(): void
    {
        if ($this->shuttingDown) {
            return;
        }

        $this->shuttingDown = true;

        $this->logger->info('Controller shutting down gracefully');

        foreach ($this->consumerPids as $pid) {
            // Send SIGTERM to consumer children.
            @\proc_open(
                \sprintf('kill %d 2>/dev/null', $pid),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if (isset($pipes)) {
                foreach ($pipes as $pipe) {
                    if (\is_resource($pipe)) {
                        \fclose($pipe);
                    }
                }
            }
        }
    }

    // ── Output ───────────────────────────────────────────────────────────

    private function emit(RuntimeEvent $event): void
    {
        if (null === $this->stdout) {
            return;
        }

        $line = JsonlCodec::encodeEvent($event);
        $written = @\fwrite($this->stdout, $line);

        if (false === $written || 0 === $written) {
            $this->logger->warning('Controller stdout write failed', [
                'event_type' => $event->type,
            ]);
        }

        \fflush($this->stdout);
    }
}
