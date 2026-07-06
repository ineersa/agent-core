<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Stream;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Psr\Log\LoggerInterface;

/**
 * Writes committed canonical RuntimeEvents to STDOUT as JSONL for the controller pipe.
 *
 * Unlike {@see StdoutRuntimeEventSink}, write failures are logged and swallowed so a
 * dead controller pipe cannot roll back or fail an already-durable EventStore append.
 */
final class CommittedRuntimeEventStdoutSink implements RuntimeEventSinkInterface
{
    /** @var resource|false|null */
    private static $stdout;

    /** @var bool|null */
    private static $isPipe;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function emit(RuntimeEvent $event): void
    {
        if (!$this->isStdoutPipe()) {
            return;
        }

        $handle = $this->stdoutHandle();
        if (false === $handle) {
            return;
        }

        try {
            $encoded = json_encode($event->toArray(), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
            $line = $encoded."\n";
            $written = @fwrite($handle, $line);
            if (false === $written || 0 === $written) {
                $this->logWriteFailure($event, error_get_last()['message'] ?? 'fwrite failed');

                return;
            }

            fflush($handle);
        } catch (\Throwable $e) {
            $this->logWriteFailure($event, $e->getMessage(), $e::class);
        }
    }

    private function isStdoutPipe(): bool
    {
        if (null === self::$isPipe) {
            self::$isPipe = \function_exists('posix_isatty') && !posix_isatty(\STDOUT);
        }

        return (bool) self::$isPipe;
    }

    /**
     * @return resource|false
     */
    private function stdoutHandle(): mixed
    {
        if (null === self::$stdout) {
            $opened = fopen('php://stdout', 'ab');
            self::$stdout = false === $opened ? false : $opened;
        }

        return self::$stdout;
    }

    private function logWriteFailure(RuntimeEvent $event, string $message, ?string $exceptionClass = null): void
    {
        $context = [
            'component' => 'CommittedRuntimeEventStdoutSink',
            'event_type' => 'committed_runtime_event.stdout_write_failed',
            'runtime_event_type' => $event->type,
            'seq' => $event->seq,
            'exception_message' => $message,
        ];
        if ('' !== $event->runId) {
            $context['run_id'] = $event->runId;
        }
        if (null !== $exceptionClass) {
            $context['exception_class'] = $exceptionClass;
        }

        $this->logger->warning('Committed runtime event stdout write failed (durable append already succeeded)', $context);
    }
}
