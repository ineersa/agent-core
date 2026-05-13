<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Psr\Log\LoggerInterface;

final class RunTracer
{
    /** @var list<string> */
    private array $spanStack = [];

    private int $sequence = 0;

    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
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
        $parentSpanId = $root ? null : ($this->spanStack[array_key_last($this->spanStack)] ?? null);

        $this->spanStack[] = $spanId;
        $startedAt = hrtime(true);

        $this->logger?->info('agent_loop.trace.start', [
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
            array_pop($this->spanStack);

            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

            $this->logger?->info('agent_loop.trace.finish', [
                'span_id' => $spanId,
                'parent_span_id' => $parentSpanId,
                'span_name' => $name,
                'duration_ms' => round($durationMs, 3),
                'status' => $status,
                ...$attributes,
            ]);
        }
    }
}
