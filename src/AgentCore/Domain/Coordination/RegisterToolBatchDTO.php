<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;

/**
 * Captured scheduling membership for one tool batch.
 *
 * Core preparation decides expected order, initial queue, and in-flight
 * admission once. Durable stores apply this exact decision; recovery never
 * re-runs live scheduling policy against the effect list.
 */
final readonly class RegisterToolBatchDTO
{
    /**
     * @param list<ExecuteToolCall> $effects
     * @param array<string, int>    $expectedOrder
     * @param list<string>          $pendingQueue
     * @param array<string, true>   $inFlight
     */
    public function __construct(
        public string $runId,
        public int $turnNo,
        public string $stepId,
        public array $effects,
        public array $expectedOrder,
        public array $pendingQueue,
        public array $inFlight,
        public int $maxParallelism,
    ) {
    }
}
