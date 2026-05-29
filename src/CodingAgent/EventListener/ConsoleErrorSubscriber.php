<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\EventListener;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeErrorCaptureConfig;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Last-resort Symfony Console exception boundary.
 *
 * This subscriber fires for exceptions that escape all application-level
 * callback boundaries (Revolt callbacks, TUI listeners, controller event
 * loop). In a well-structured application, this should be rare — most
 * exceptions are caught earlier by RuntimeExceptionBoundary at individual
 * callback/runtime boundaries.
 *
 * When this subscriber fires, Symfony Console has already caught the
 * exception from doRunCommand() and dispatched ConsoleEvents::ERROR.
 * Returning from this listener does NOT resume the command or recover
 * the event loop — Symfony still owns the original error and will render
 * it to stderr with exit code 1 unless a listener explicitly marks it
 * handled by calling $event->setExitCode(0).
 *
 * Behavior:
 *
 * HATFIELD_CAPTURE_ERRORS=0 (test/CI mode):
 *   Rethrows the original exception so test harnesses see a loud,
 *   distinguishable crash.
 *
 * HATFIELD_CAPTURE_ERRORS=1 (default, user-facing mode):
 *   In controller/headless mode: emits a protocol.error JSONL on stdout
 *   as a final best-effort notification before Symfony exits non-zero.
 *   Logs the exception for diagnostics.
 */
final class ConsoleErrorSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RuntimeErrorCaptureConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $exception = $event->getError();
        $command = $event->getCommand();
        $commandName = $command?->getName();

        if (!$this->config->captureErrors) {
            $this->logger->notice(
                'Error capture disabled — rethrowing console exception',
                ['exception' => $exception],
            );

            throw $exception;
        }

        // Last-resort: in controller/headless mode, try to emit a final
        // protocol.error so the TUI/controller client sees the failure
        // before the process exits. This only fires when exceptions escape
        // all RuntimeExceptionBoundary boundaries.
        if ('agent' === $commandName) {
            $input = $event->getInput();
            if ($input->hasParameterOption('--controller') || $input->hasParameterOption('--headless')) {
                $this->emitControllerError($exception);
            }
        }

        $this->logger->error('Unhandled console exception (last-resort)', [
            'exception' => $exception,
            'command' => $commandName,
        ]);
    }

    /**
     * @return array<string, string|array{0: string, 1: int}|list<array{0: string, 1?: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::ERROR => ['onConsoleError', 0],
        ];
    }

    private function emitControllerError(\Throwable $exception): void
    {
        try {
            $event = new RuntimeEvent(
                type: 'protocol.error',
                runId: '',
                seq: 0,
                payload: [
                    'error' => 'Unhandled exception in controller mode: '.$exception->getMessage(),
                    'exception_class' => $exception::class,
                ],
            );

            fwrite(\STDOUT, JsonlCodec::encodeEvent($event)."\n");
            fflush(\STDOUT);
        } catch (\Throwable $emitError) {
            $this->logger->error('Failed to emit protocol.error in controller mode', [
                'exception' => $emitError,
            ]);
        }
    }
}
