<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

/**
 * Optional tracing provider abstraction for Datadog-compatible span emission.
 *
 * Implementations use the ddtrace PHP extension functions when available
 * and must degrade to no-op when the extension is not loaded.
 *
 * Each returned span ID is opaque — callers must pair each
 * {@see startSpan} with a later {@see closeSpan}.
 */
interface SpanProviderInterface
{
    /**
     * Starts a new span child of the current active span.
     *
     * @param string               $operationName Operation name (e.g. 'persistence.commit')
     * @param array<string, mixed> $tags          Key-value tags
     *
     * @return string|null Opaque span ID, or null if tracing is unavailable
     */
    public function startSpan(string $operationName, array $tags = []): ?string;

    /**
     * Close a span previously returned by {@see startSpan}.
     *
     * **Must be called in LIFO order matching startSpan calls.**
     * Backends like ddtrace use an internal stack and closeSpan always
     * closes the most recently started span, regardless of the passed
     * span ID. Pairing start/close in strict LIFO order ensures spans
     * are properly nested and closed.
     *
     * @param string|null          $spanId Span ID from startSpan, or null for no-op
     * @param array<string, mixed> $tags   Tags to set/update on close
     */
    public function closeSpan(?string $spanId, array $tags = []): void;

    /**
     * Current trace context for log correlation.
     *
     * @return array{trace_id?: string, span_id?: string}
     */
    public function currentContext(): array;
}
