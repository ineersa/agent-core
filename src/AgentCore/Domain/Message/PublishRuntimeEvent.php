<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Transient runtime event message for the agent.publisher.bus.
 *
 * Unlike command bus messages (StartRun, ExecuteLlmStep),
 * this message carries only scalar/native fields so it can be
 * serialized across process boundaries without dependency on
 * protocol DTOs. It does NOT extend AbstractAgentBusMessage
 * because it has no idempotencyKey, turnNo, stepId, or attempt.
 *
 * The consumer (controller event loop in ASYNC-03) reconstructs
 * a full RuntimeEvent DTO from these fields before forwarding to TUI.
 */
final readonly class PublishRuntimeEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $runId,
        public string $type,
        public int $seq,
        public array $payload = [],
    ) {
    }
}
