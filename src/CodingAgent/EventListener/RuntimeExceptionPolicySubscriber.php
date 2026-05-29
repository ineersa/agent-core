<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\EventListener;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeErrorCaptureConfig;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Central subscriber for RuntimeExceptionEvent dispatched by
 * RuntimeExceptionBoundary in capture mode (HATFIELD_CAPTURE_ERRORS=1).
 *
 * Responsibilities:
 *   - Enforce capture=0 by rethrowing the original exception.
 *   - Log every captured runtime exception with structured context.
 *   - Provide a single extension point for cross-cutting error
 *     propagation (future: metrics, controller protocol.error emission).
 *
 * This subscriber does NOT handle TUI-visible error rendering — that is
 * still done by boundary callers with access to TuiSessionState/ChatScreen.
 */
final class RuntimeExceptionPolicySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RuntimeErrorCaptureConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onRuntimeException(RuntimeExceptionEvent $event): void
    {
        if (!$this->config->captureErrors) {
            throw $event->exception;
        }

        $this->logger->error('Runtime exception at boundary', [
            'operation' => $event->operation,
            'exception' => $event->exception,
            'run_id' => $event->runId,
            'context' => $event->context,
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            RuntimeExceptionEvent::class => ['onRuntimeException', 1024],
        ];
    }
}
