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
 * Encoding and writing are delegated to {@see StdoutRuntimeEventSink::write()} so both
 * sinks share one pipe detection, handle, and JsonlCodec encoding.
 */
final class CommittedRuntimeEventStdoutSink implements RuntimeEventSinkInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly StdoutRuntimeEventSink $stdoutSink = new StdoutRuntimeEventSink(),
    ) {
    }

    public function emit(RuntimeEvent $event): void
    {
        try {
            $result = $this->stdoutSink->write($event);
        } catch (\Throwable $e) {
            $this->logWriteFailure($event, $e->getMessage(), $e::class);

            return;
        }

        if (false === $result) {
            $this->logWriteFailure($event, error_get_last()['message'] ?? 'fwrite failed');
        }
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
