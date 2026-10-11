<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeTransportException;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

/**
 * Owns the controller stdout emit pipeline for live runtime events.
 *
 * Live canonical and transient events arrive on messenger consumer stdout and
 * are forwarded via emit(). Recovery/backfill from events.jsonl is not part of
 * the live controller path.
 */
final class RuntimeEventEmitter
{
    /** @var resource|null */
    private $stdout;

    private bool $shuttingDown = false;

    private bool $bootstrapOutput = false;
    private ?string $writeWatcher = null;
    /** @var list<array{line: string, after: ?\Closure, started: bool, run_id: string}> */
    private array $pending = [];
    private int $pendingBytes = 0;
    private ?\Closure $bootstrapFilter = null;
    private ?\Closure $onDrained = null;

    /** @var (\Closure(): void)|null Callback invoked on fatal stdout write failure before event loop stop. */
    private ?\Closure $onFatalShutdown = null;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Set the callback invoked when a stdout write failure triggers shutdown.
     * The controller uses this to perform consumer supervision shutdown and
     * background process cleanup before the event loop stops.
     */
    public function setFatalShutdownHandler(\Closure $handler): void
    {
        $this->onFatalShutdown = $handler;
    }

    /**
     * Open the stdout resource for writing.
     *
     * Must be called before emit(). The controller calls this once at startup.
     */
    public function openStdout(): void
    {
        $this->stdout = fopen('php://stdout', 'wb');
        if (false === $this->stdout) {
            throw new RuntimeTransportException('Cannot open stdout for controller mode');
        }
    }

    /**
     * Emit a runtime event to stdout.
     */
    public function emit(RuntimeEvent $event): void
    {
        $this->tryEmit($event);
    }

    /**
     * Returns true only for a completed live write. Filtered, queued, or
     * unavailable output must not acknowledge separately persisted questions.
     */
    public function tryEmit(RuntimeEvent $event): bool
    {
        if (null !== $this->bootstrapFilter && ($this->bootstrapFilter)($event)) {
            return false;
        }
        if ($this->bootstrapOutput) {
            $this->emitTransfer($event);

            return false;
        }

        return $this->emitInternal($event);
    }

    /** @param (\Closure(RuntimeEvent): bool)|null $filter */
    public function setBootstrapFilter(?\Closure $filter): void
    {
        $this->bootstrapFilter = $filter;
    }

    public function beginBootstrapOutput(): void
    {
        $this->bootstrapOutput = true;
        $this->onDrained = null;
        if (null !== $this->stdout) {
            stream_set_blocking($this->stdout, false);
        }
    }

    /** At most 64 KiB of encoded startup output is retained, including control replies. */
    public function emitTransfer(RuntimeEvent $event, ?\Closure $after = null): void
    {
        if (null === $this->stdout || $this->shuttingDown) {
            return;
        }
        $line = JsonlCodec::encodeEvent($event);
        if ($this->pendingBytes + \strlen($line) > 65536) {
            throw new RuntimeTransportException('Bootstrap stdout exceeds its bounded pending budget.');
        }
        $this->pending[] = ['line' => $line, 'after' => $after, 'started' => false, 'run_id' => $event->runId];
        $this->pendingBytes += \strlen($line);
        if (null === $this->writeWatcher) {
            $this->writeWatcher = EventLoop::onWritable($this->stdout, $this->flushBootstrap(...));
        }
    }

    public function whenDrained(\Closure $after): void
    {
        if ([] === $this->pending) {
            $after();
        } else {
            $this->onDrained = $after;
        }
    }

    /** Finish a partial JSONL line, but release cancelled frames and callbacks.
     * Stay nonblocking and detached until a replacement bootstrap completes. */
    public function cancelBootstrapOutput(): void
    {
        $partial = ($this->pending[0]['started'] ?? false) ? $this->pending[0] : null;
        if (null !== $partial) {
            $partial['after'] = null;
        }
        $this->pending = null === $partial ? [] : [$partial];
        $this->pendingBytes = null === $partial ? 0 : \strlen($partial['line']);
        $this->onDrained = null;
        $this->bootstrapFilter = static fn (RuntimeEvent $event): bool => !\in_array($event->type, [
            \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum::CommandAck->value,
            \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum::CommandRejected->value,
            \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum::ProtocolError->value,
        ], true);
        if ([] === $this->pending && null !== $this->writeWatcher) {
            EventLoop::cancel($this->writeWatcher);
            $this->writeWatcher = null;
        }
    }

    public function finishBootstrapOutput(): void
    {
        if ([] !== $this->pending) {
            throw new \LogicException('Bootstrap output is still pending.');
        }
        $this->bootstrapOutput = false;
        if (null !== $this->stdout) {
            stream_set_blocking($this->stdout, true);
        }
    }

    /**
     * Whether the emitter has been asked to shut down.
     */
    public function isShuttingDown(): bool
    {
        return $this->shuttingDown;
    }

    /**
     * Signal the emitter to stop.
     */
    public function shutdown(): void
    {
        $this->shuttingDown = true;
        if (null !== $this->writeWatcher) {
            EventLoop::cancel($this->writeWatcher);
            $this->writeWatcher = null;
        }
        $this->pending = [];
        $this->pendingBytes = 0;
        $this->onDrained = null;
        $this->bootstrapFilter = null;
    }

    private function flushBootstrap(): void
    {
        if ([] === $this->pending || null === $this->stdout) {
            return;
        }
        $written = @fwrite($this->stdout, $this->pending[0]['line']);
        if (false === $written) {
            $runId = $this->pending[0]['run_id'];
            $this->logger->error('session.bootstrap.stdout_failed', ['run_id' => $runId, 'session_id' => $runId,
                'component' => 'RuntimeEventEmitter', 'event_type' => 'session.bootstrap.stdout_failed']);
            $this->shutdown();
            if (null !== $this->onFatalShutdown) {
                ($this->onFatalShutdown)();
            }
            EventLoop::getDriver()->stop();

            return;
        }
        $this->pendingBytes -= $written;
        $this->pending[0]['started'] = $this->pending[0]['started'] || $written > 0;
        $this->pending[0]['line'] = substr($this->pending[0]['line'], $written);
        if ('' === $this->pending[0]['line']) {
            $finished = array_shift($this->pending);
            if (null !== $finished['after']) {
                EventLoop::queue($finished['after']);
            }
        }
        if ([] === $this->pending) {
            EventLoop::cancel($this->writeWatcher);
            $this->writeWatcher = null;
            $after = $this->onDrained;
            $this->onDrained = null;
            if (null !== $after) {
                $after();
            }
        }
    }

    private function emitInternal(RuntimeEvent $event): bool
    {
        if (null === $this->stdout || $this->shuttingDown) {
            return false;
        }

        $line = JsonlCodec::encodeEvent($event);
        $written = JsonlCodec::write($this->stdout, $line);
        $writeError = error_get_last();

        if (!$written) {
            $error = $writeError;
            $logContext = [
                'component' => 'RuntimeEventEmitter',
                'event_type' => $event->type,
                'error' => $error['message'] ?? 'unknown',
            ];
            if ('' !== $event->runId) {
                $logContext['run_id'] = $event->runId;
            }
            $this->logger->error('Controller stdout write failed, initiating shutdown', $logContext);
            $this->shuttingDown = true;

            // Delegate full shutdown (consumer supervision, bg process cleanup)
            // to the controller via the fatal shutdown handler.
            if (null !== $this->onFatalShutdown) {
                ($this->onFatalShutdown)();
            }

            EventLoop::getDriver()->stop();

            return false;
        }

        fflush($this->stdout);

        return true;
    }
}
