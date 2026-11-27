<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Revolt\EventLoop\FiberLocal;

/**
 * Span-based tracer for agent-core operations.
 *
 * Wraps operations in named spans that emit start/finish log records
 * with timing, nesting, and status. These records are the tracing
 * surface: structured logs consumed by log-derived metrics.
 *
 * Default level is DEBUG so routine nesting, worker wrappers, and latency
 * probes stay off the INFO path. Call sites that feed Datadog metrics must
 * pass {@see LogLevel::INFO} explicitly for the canonical `tool.call` and
 * `llm.call` finishes.
 *
 * Parent tracking uses a per-instance Revolt {@see FiberLocal} span stack, so
 * concurrent Fibers keep independent parents. Revolt clears it on reusable
 * callback Fibers after every callback; {@see inSpan()} restores its captured
 * stack before returning, so no span outlives its callback.
 */
final class RunTracer
{
    /** @var FiberLocal<list<string>> */
    private readonly FiberLocal $spanStack;

    private int $sequence = 0;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->spanStack = new FiberLocal(self::emptyStack(...));
    }

    /**
     * Executes an operation inside a span and emits start/finish trace records.
     *
     * @template TResult
     *
     * @param array<string, mixed> $attributes
     * @param callable(): TResult  $operation
     *
     * @return TResult
     */
    public function inSpan(string $name, array $attributes, callable $operation, bool $root = false, string $level = LogLevel::DEBUG): mixed
    {
        $spanId = \sprintf('span-%d', ++$this->sequence);
        $stack = $this->spanStack->get();
        $parentSpanId = $root || [] === $stack ? null : $stack[array_key_last($stack)];

        // A suspended invocation must not become a sibling's tracing parent.
        $this->spanStack->set([...$stack, $spanId]);
        $startedAt = hrtime(true);

        $this->logger->log($level, 'agent_loop.trace.start', [
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId,
            'span_name' => $name,
            ...$attributes,
        ]);

        $status = 'error';

        try {
            $result = $operation();
            $status = 'ok';

            return $result;
        } finally {
            // Restore the captured snapshot rather than popping, so an
            // unbalanced nested span cannot shift this caller's parent.
            if ([] === $stack) {
                $this->spanStack->unset();
            } else {
                $this->spanStack->set($stack);
            }

            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

            $this->logger->log($level, 'agent_loop.trace.finish', [
                'span_id' => $spanId,
                'parent_span_id' => $parentSpanId,
                'span_name' => $name,
                'duration_ms' => round($durationMs, 3),
                'status' => $status,
                ...$attributes,
            ]);
        }
    }

    /** @return list<string> */
    private static function emptyStack(): array
    {
        return [];
    }
}
