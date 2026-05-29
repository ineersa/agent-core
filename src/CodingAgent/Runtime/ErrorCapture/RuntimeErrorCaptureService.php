<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ErrorCapture;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Centralized runtime error capture service.
 *
 * Callers catch an infrastructure-level \Throwable and delegate to this
 * service before doing user-visible recovery (emitting a runtime event,
 * setting activity to Failed, appending an error TranscriptBlock, etc.).
 *
 * When capture is enabled (HATFIELD_CAPTURE_ERRORS=1, the default):
 *   - Logs the error at the appropriate level (error for terminal,
 *     warning for recoverable).
 *   - Returns normally so the caller can proceed with recovery.
 *
 * When capture is disabled (HATFIELD_CAPTURE_ERRORS=0):
 *   - Logs a brief notice and rethrows the original exception so that
 *     the process exits with a loud crash. This is the test/CI/SDK mode.
 *
 * Usage pattern inside a catch block:
 *
 *   try {
 *       // ...
 *   } catch (\Throwable $e) {
 *       $this->errorCapture->handle($e, 'some_operation_failed', [
 *           'run_id' => $runId,
 *       ]);
 *       // If we reach here, capture is enabled — emit user-visible error.
 *       $this->emit(new RuntimeEvent(type: 'run.failed', ...));
 *   }
 *
 * The callers never need to check $this->config->captureErrors themselves;
 * the service guarantees that execution reaches code after handle() only
 * when capture is enabled.
 */
final class RuntimeErrorCaptureService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly RuntimeErrorCaptureConfig $config,
    ) {
    }

    /**
     * Handle a caught Throwable with the given context.
     *
     * @param \Throwable $exception The caught throwable
     * @param string     $context   Human-readable operation identifier for logging
     * @param array<string, mixed> $logContext Additional structured context for log entry
     *
     * @throws \Throwable Always rethrows when capture is disabled
     */
    public function handleError(\Throwable $exception, string $context, array $logContext = []): void
    {
        $logContext['capture_context'] = $context;

        if (!$this->config->captureErrors) {
            // Loud crash mode: log at notice level so the test/CI log
            // records the intent, then rethrow so the process fails hard.
            $this->logger?->notice('Error capture disabled — rethrowing exception', $logContext);

            throw $exception;
        }

        // Capture mode: log at error level and return normally.
        // The caller is responsible for converting this into a
        // user-visible runtime event / TUI error block.
        $this->logger?->error('Runtime error captured', [
            ...$logContext,
            'exception' => $exception,
        ]);
    }

    /**
     * Handle a non-terminal degraded operation that is allowed to fall
     * through silently in production but should not be an empty catch
     * block.
     *
     * Logs at warning level. Does NOT rethrow when capture is disabled;
     * this is explicitly for intentional local degradation (image EXIF,
     * log line parsing, optional observer hooks) that must never crash
     * the runtime regardless of capture mode.
     *
     * @param \Throwable $exception The caught throwable
     * @param string     $context   Human-readable operation identifier
     * @param array<string, mixed> $logContext
     */
    public function handleDegradation(\Throwable $exception, string $context, array $logContext = []): void
    {
        $this->logger?->warning('Local degradation handled', [
            ...$logContext,
            'capture_context' => $context,
            'exception' => $exception,
        ]);
    }
}
