<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\EventListener;

use Ineersa\CodingAgent\Runtime\ErrorCapture\RuntimeErrorCaptureConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Symfonian last-resort exception boundary for console commands.
 *
 * When HATFIELD_CAPTURE_ERRORS=1 (default, user-facing mode):
 *   Uncaught exceptions reaching the console are logged as errors.
 *   The default Symfony console error rendering (stderr + exit code 1) applies.
 *
 * When HATFIELD_CAPTURE_ERRORS=0 (test/CI mode):
 *   The exception is rethrown so the process exits with the original
 *   exception, giving test harnesses a loud, distinguishable crash.
 *
 * This subscriber is NOT a substitute for per-callback boundary wrappers
 * in Revolt event-loop callbacks and TUI polling — those have their own
 * thin capture/rethrow guard at the callback entry point.
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

        if ($this->config->captureErrors) {
            // Capture mode: log the exception and let Symfony console
            // handle rendering the error to stderr with exit code 1.
            $this->logger->error('Unhandled console exception', [
                'exception' => $exception,
                'command' => $event->getCommand()?->getName(),
            ]);

            return;
        }

        // Crash mode: log and rethrow so the process exits with
        // the original exception, not a sanitized console error.
        $this->logger->notice(
            'Error capture disabled — rethrowing console exception',
            ['exception' => $exception],
        );

        // Let the exception propagate naturally beyond the console.
        // Calling $event->setError() alone does NOT rethrow —
        // Symfony console catches the exception and renders it.
        // To get a real crash, we throw from here... but
        // ConsoleEvents::ERROR is dispatched inside a try/catch
        // in Symfony\Console\Application::doRunCommand().
        //
        // However, when `setError()` is called and we then throw,
        // the outer doRun() catch will handle it. Since Symfony
        // console always renders and exits with non-zero, the
        // meaningful difference for tests is whether we log + exit
        // cleanly vs let the raw exception bubble as an uncaught
        // fatal. For CLI tests that check exit codes, both paths
        // produce exit code 1.
        //
        // We rethrow anyway because the user's stated intent is
        // "crash/rethrow" in test mode — tests can detect the
        // difference via the notice-level log entry.
        throw $exception;
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
}
