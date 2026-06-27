<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\SpanProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Span-based tracer for agent-core operations.
 *
 * Wraps operations in named spans that emit start/finish log records
 * and, when a {@see SpanProviderInterface} is available, also create
 * real distributed tracing spans via the configured provider (e.g.
 * the ddtrace extension).
 *
 * All existing call sites use {@see inSpan()} which automatically
 * gets real distributed-trace coverage when a provider is wired.
 */
final class RunTracer
{
    /** @var list<string> */
    private array $spanStack = [];

    private int $sequence = 0;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?SpanProviderInterface $spanProvider = null,
    ) {
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
    public function inSpan(string $name, array $attributes, callable $operation, bool $root = false): mixed
    {
        $spanId = \sprintf('span-%d', ++$this->sequence);
        $parentSpanId = $root || [] === $this->spanStack ? null : $this->spanStack[array_key_last($this->spanStack)];

        $this->spanStack[] = $spanId;
        $startedAt = hrtime(true);

        $this->logger->debug('agent_loop.trace.start', [
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId,
            'span_name' => $name,
            ...$attributes,
        ]);

        $ddSpanId = null !== $this->spanProvider
            ? $this->spanProvider->startSpan($name, $this->ddTags($attributes))
            : null;

        $status = 'error';

        try {
            $result = $operation();
            $status = 'ok';

            return $result;
        } finally {
            array_pop($this->spanStack);

            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

            $this->logger->debug('agent_loop.trace.finish', [
                'span_id' => $spanId,
                'parent_span_id' => $parentSpanId,
                'span_name' => $name,
                'duration_ms' => round($durationMs, 3),
                'status' => $status,
                ...$attributes,
            ]);

            if (null !== $ddSpanId) {
                $this->spanProvider->closeSpan($ddSpanId, [
                    'duration_ms' => round($durationMs, 3),
                    'status' => $status,
                    'outcome' => 'ok' === $status ? 'success' : 'error',
                ]);
            }
        }
    }

    /**
     * Convert log-friendly attributes to ddtrace tag format (dot-separated keys).
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    private function ddTags(array $attributes): array
    {
        $tags = [];

        foreach ($attributes as $key => $value) {
            if (\is_scalar($value) || null === $value) {
                $tags[$key] = $value;
            } elseif (\is_array($value)) {
                // Skip complex nested arrays for tags; they are
                // available in the log record.
                continue;
            } elseif (\is_object($value) && method_exists($value, '__toString')) {
                $tags[$key] = (string) $value;
            }
        }

        return $tags;
    }
}
