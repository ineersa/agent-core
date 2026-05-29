<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Top-level exception boundary for callback/runtime entry points.
 *
 * This is the single place where the HATFIELD_CAPTURE_ERRORS capture policy
 * is enforced. Individual listeners and controllers no longer check
 * RuntimeErrorCaptureConfig directly — they use this boundary instead.
 *
 * Usage in a catch block:
 *
 *   try {
 *       $client->cancel($runId);
 *   } catch (\Throwable $e) {
 *       $this->boundary->catch($e, 'cancel_listener.cancel_command_failed', [
 *           'run_id' => $runId,
 *       ]);
 *       // If we reach here, capture mode is enabled.
 *       // Proceed with user-visible recovery (TUI error block, etc.).
 *   }
 *
 * In capture=0 mode (HATFIELD_CAPTURE_ERRORS=0), catch() never returns —
 * it rethrows the original exception for test/CI harness crash detection.
 *
 * In capture=1 mode (default), catch() dispatches a RuntimeExceptionEvent
 * for centralized subscribers (logging, metrics) and then returns normally
 * so the caller can surface the error to the user.
 */
final readonly class RuntimeExceptionBoundary
{
    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private RuntimeErrorCaptureConfig $captureConfig,
    ) {
    }

    /**
     * Catch a Throwable at a top-level callback boundary.
     *
     * In capture=0 mode, rethrows the exception immediately (never returns).
     * In capture=1 mode, dispatches a RuntimeExceptionEvent for central
     * subscribers and then returns normally.
     *
     * @param array<string, mixed> $context
     *
     * @throws \Throwable Always in capture=0 mode; never in capture=1 mode
     */
    public function catch(\Throwable $e, string $operation, array $context = []): void
    {
        // Capture disabled: crash loud and fast — no subscriber overhead.
        if (!$this->captureConfig->captureErrors) {
            throw $e;
        }

        // Capture enabled: dispatch event for central subscribers.
        $runId = isset($context['run_id']) && \is_string($context['run_id']) ? $context['run_id'] : null;
        $event = new RuntimeExceptionEvent($e, $operation, $runId, $context);
        $this->dispatcher->dispatch($event);

        // Return normally — caller proceeds with user-visible recovery.
    }
}
