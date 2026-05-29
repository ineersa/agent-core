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
        $command = $event->getCommand();
        $commandName = $command?->getName();

        if (!$this->config->captureErrors) {
            $this->logger->notice(
                'Error capture disabled — rethrowing console exception',
                ['exception' => $exception],
            );

            throw $exception;
        }

        // In controller/headless mode, emit a protocol.error JSONL to
        // stdout so the TUI/controller client sees the unhandled error
        // before the process exits. This is the last-resort TUI-visible
        // error path when the Revolt event-loop boundaries have been
        // exhausted.
        if ('agent' === $commandName) {
            $input = $event->getInput();
            if ($input->hasParameterOption('--controller') || $input->hasParameterOption('--headless')) {
                $this->emitControllerError($exception);
            }
        }

        $this->logger->error('Unhandled console exception', [
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
            // Avoid recursive failure — if emitting the protocol error
            // itself fails (e.g. stdout closed), log it and fall through
            // to the existing console error rendering.
            $this->logger->error('Failed to emit protocol.error in controller mode', [
                'exception' => $emitError,
            ]);
        }
    }
}
