<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Top-level exception boundary for callback/runtime entry points.
 *
 * This dispatches Throwable objects caught at callback/runtime boundaries to
 * Symfony subscribers. Individual listeners and controllers no longer check
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
 * RuntimeExceptionPolicySubscriber owns the HATFIELD_CAPTURE_ERRORS decision:
 * capture=0 rethrows during dispatch; capture=1 logs/records diagnostics and
 * returns so the caller can surface the error to the user.
 */
final readonly class RuntimeExceptionBoundary
{
    public function __construct(
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * Catch a Throwable at a top-level callback boundary.
     *
     * Dispatches the exception to central subscribers.
     *
     * In capture=0 mode, RuntimeExceptionPolicySubscriber rethrows during
     * dispatch (never returns). In capture=1 mode, subscribers return normally
     * and the caller proceeds with user-visible recovery.
     *
     * @param array<string, mixed> $context
     *
     * @throws \Throwable When a subscriber, normally RuntimeExceptionPolicySubscriber, rethrows
     */
    public function catch(\Throwable $e, string $operation, array $context = []): void
    {
        $runId = isset($context['run_id']) && \is_string($context['run_id']) ? $context['run_id'] : null;
        $event = new RuntimeExceptionEvent($e, $operation, $runId, $context);
        $this->dispatcher->dispatch($event);
    }
}
