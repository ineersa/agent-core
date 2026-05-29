<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched by RuntimeExceptionBoundary when a top-level callback boundary
 * catches a Throwable in capture mode (HATFIELD_CAPTURE_ERRORS=1).
 *
 * Subscribers log the exception, emit protocol.error JSONL, or perform
 * other cross-cutting diagnostics. The caller (the boundary return path
 * or the callback boundary itself) is responsible for user-visible recovery
 * such as adding TUI error blocks or setting Failed activity state.
 *
 * The operation string is a dot-separated identifier that pinpoints
 * the boundary site, e.g.:
 *   - cancel_listener.cancel_command_failed
 *   - runtime_event_poller.poll_failed
 *   - headless_controller.event_drain_failed
 *   - headless_controller.command_dispatch_failed
 *   - headless_controller.llm_stdout_protocol_error
 */
final class RuntimeExceptionEvent extends Event
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly \Throwable $exception,
        public readonly string $operation,
        public readonly ?string $runId = null,
        public readonly array $context = [],
    ) {
    }
}
