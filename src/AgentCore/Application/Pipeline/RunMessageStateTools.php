<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Symfony\AI\Platform\Message\AssistantMessage;

final readonly class RunMessageStateTools
{
    public function __construct(
        private EventFactory $eventFactory = new EventFactory(),
        private AgentMessageNormalizer $messageNormalizer = new AgentMessageNormalizer(),
        private ToolCallExtractor $toolCallExtractor = new ToolCallExtractor(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function event(string $runId, int $seq, int $turnNo, string $type, array $payload = []): RunEvent
    {
        return $this->eventFactory->event($runId, $seq, $turnNo, $type, $payload);
    }

    /**
     * @param list<array{type: string, payload: array<string, mixed>, turn_no?: int}> $eventSpecs
     *
     * @return list<RunEvent>
     */
    public function eventsFromSpecs(string $runId, int $turnNo, int $startSeq, array $eventSpecs): array
    {
        return $this->eventFactory->eventsFromSpecs($runId, $turnNo, $startSeq, $eventSpecs);
    }

    public function isStaleResult(RunState $state, int $turnNo, string $stepId): bool
    {
        if ($state->turnNo !== $turnNo) {
            return true;
        }

        return null !== $state->activeStepId && $state->activeStepId !== $stepId;
    }

    public function incrementStateVersion(RunState $state, int $eventCount): RunState
    {
        return $this->eventFactory->incrementStateVersion($state, $eventCount);
    }

    public function assistantMessage(AssistantMessage $assistantMessage): AgentMessage
    {
        return $this->messageNormalizer->assistantMessage($assistantMessage);
    }

    /**
     * @return array<string, mixed>
     */
    public function assistantMessagePayload(AssistantMessage $assistantMessage): array
    {
        return $this->messageNormalizer->assistantMessagePayload($assistantMessage);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function humanResponseMessage(array $payload): ?AgentMessage
    {
        return $this->messageNormalizer->humanResponseMessage($payload);
    }

    public function toolMessage(ToolCallResult $result): AgentMessage
    {
        return $this->messageNormalizer->toolMessage($result);
    }

    /**
     * @return list<array{id: string, name: string, args: array<string, mixed>, order_index: int, tool_idempotency_key: string|null}>
     */
    public function extractToolCalls(AssistantMessage $assistantMessage): array
    {
        return $this->toolCallExtractor->extractToolCalls($assistantMessage);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function interruptPayloadFromToolResult(ToolCallResult $result): ?array
    {
        return $this->toolCallExtractor->interruptPayloadFromToolResult($result);
    }
}
