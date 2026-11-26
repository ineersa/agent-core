<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Session;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\SessionClosingEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\SessionSelectedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Dispatches the public session events of the interactive process.
 *
 * Listeners run synchronously on the interactive event loop, so they must only
 * record state; I/O belongs in a fiber or timer. Only live events of the
 * selected session's main run are published, never history replay.
 */
final class ExtensionSessionEventPublisher
{
    private string $sessionId = '';

    // Canonical events may be read again after a controller restart.
    private int $sequence = 0;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    /** Selecting the already selected session, e.g. a new run in it, is not a new selection. */
    public function select(string $sessionId, bool $resumed): void
    {
        if ('' === $sessionId || $sessionId === $this->sessionId) {
            return;
        }
        $this->sessionId = $sessionId;
        $this->sequence = 0;
        $this->dispatch($sessionId, new SessionSelectedEvent($sessionId, $resumed));
    }

    /** A session boundary without a graceful close: reload, switch or new draft. */
    public function clear(): void
    {
        $this->sessionId = '';
        $this->sequence = 0;
    }

    public function close(): void
    {
        $sessionId = $this->sessionId;
        $this->clear();
        if ('' !== $sessionId) {
            $this->dispatch($sessionId, new SessionClosingEvent($sessionId));
        }
    }

    /** Restored history up to the bootstrap cut is not live, even if its stdout copy arrives later. */
    public function resumeAfter(int $sequence): void
    {
        $this->sequence = max($this->sequence, $sequence);
    }

    public function publish(RuntimeEvent $event): void
    {
        if ('' === $this->sessionId || $event->runId !== $this->sessionId) {
            return;
        }
        if (0 !== $event->seq) {
            if ($event->seq <= $this->sequence) {
                return;
            }
            $this->sequence = $event->seq;
        }
        $public = ExtensionSessionEventMapper::map($event);
        if (null !== $public) {
            $this->dispatch($event->runId, $public);
        }
    }

    /**
     * Calls each listener separately: dispatch() would stop at the first
     * throwing listener, and the advanced cursor never redelivers the event.
     * Session events are not stoppable, so this keeps dispatch semantics.
     */
    private function dispatch(string $sessionId, object $event): void
    {
        foreach ($this->eventDispatcher->getListeners($event::class) as $listener) {
            try {
                $listener($event, $event::class, $this->eventDispatcher);
            } catch (\Throwable $exception) {
                // An extension listener must not break runtime event delivery to the TUI or other listeners.
                $this->logger->warning('extension.session_event_listener_failed', [
                    'component' => 'extension_session_events', 'event_type' => 'session_event_listener_failed',
                    'run_id' => $sessionId, 'session_id' => $sessionId,
                    'extension_event' => $event::class, 'exception_class' => $exception::class,
                ]);
            }
        }
    }
}
