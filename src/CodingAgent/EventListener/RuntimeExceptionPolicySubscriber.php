<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\EventListener;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Central subscriber for RuntimeExceptionEvent dispatched by
 * RuntimeExceptionBoundary in capture mode (HATFIELD_CAPTURE_ERRORS=1).
 *
 * Responsibilities:
 *   - Log every caught runtime exception with structured context.
 *   - Provide a single extension point for cross-cutting error
 *     propagation (future: metrics, controller protocol.error emission).
 *
 * This subscriber does NOT enforce the capture=0 rethrow policy — that
 * happens inside RuntimeExceptionBoundary before dispatch.
 *
 * This subscriber does NOT handle TUI-visible error rendering — that
 * is done by the boundary caller (CancelListener, RuntimeEventPoller)
 * which has access to TuiSessionState and ChatScreen.
 */
final class RuntimeExceptionPolicySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onRuntimeException(RuntimeExceptionEvent $event): void
    {
        $this->logger->error('Runtime exception at boundary', [
            'operation' => $event->operation,
            'exception' => $event->exception,
            'run_id' => $event->runId,
            'context' => $event->context,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            RuntimeExceptionEvent::class => 'onRuntimeException',
        ];
    }
}
