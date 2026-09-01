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
        $this->emitInternal($event);
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
    }

    private function emitInternal(RuntimeEvent $event): bool
    {
        if (null === $this->stdout) {
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
