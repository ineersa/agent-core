<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;

/**
 * Privacy-safe one-line summaries for disposable event-inspection tails.
 *
 * Shared by history projection maintenance and agent_retrieve so cold init and
 * ordinary committed updates never keep raw event payloads in the projection.
 */
final class EventInspectionSummarizer
{
    public const int DEFAULT_TAIL_LIMIT = 100;

    public function __construct(
        private readonly ?ToolExecutionEndPayloadCodec $toolExecutionEndPayloadCodec = null,
    ) {
    }

    /**
     * @return array{seq: int, turn_no: int, type: string, created_at: string, summary: string}
     */
    public function summarize(RunEvent $event): array
    {
        return [
            'seq' => $event->seq,
            'turn_no' => $event->turnNo,
            'type' => $event->type,
            'created_at' => $event->createdAt->format(\DateTimeInterface::ATOM),
            'summary' => $this->summarizePayload($event),
        ];
    }

    private function summarizePayload(RunEvent $event): string
    {
        $payload = $event->payload;

        return match ($event->type) {
            RunEventTypeEnum::ToolExecutionStart->value => $this->summarizeToolStart($payload),
            RunEventTypeEnum::ToolExecutionEnd->value => $this->summarizeToolEnd($payload),
            RunEventTypeEnum::ToolExecutionUpdate->value => 'tool progress update (payload omitted)',
            RunEventTypeEnum::LlmStepCompleted->value => $this->summarizeLlmCompleted($payload),
            RunEventTypeEnum::LlmStepFailed->value => 'llm step failed (details omitted)',
            RunEventTypeEnum::WaitingHuman->value => 'waiting for human input (unsupported for child runs)',
            RunEventTypeEnum::RunStarted->value => 'run started',
            RunEventTypeEnum::AgentEnd->value => 'agent ended',
            default => 'event (payload omitted)',
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function summarizeToolStart(array $payload): string
    {
        $name = $payload['tool_name'] ?? $payload['toolName'] ?? 'unknown';

        return \is_string($name)
            ? 'tool start: '.$name
            : 'tool start';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function summarizeToolEnd(array $payload): string
    {
        $name = 'unknown';
        if (null !== $this->toolExecutionEndPayloadCodec) {
            $typedResult = $this->toolExecutionEndPayloadCodec->fromEventPayload($payload);
            $name = $typedResult->result['tool_name'] ?? 'unknown';
        } else {
            $name = $payload['tool_name'] ?? $payload['toolName'] ?? 'unknown';
        }
        $exit = $payload['exit_code'] ?? $payload['exitCode'] ?? null;
        $base = \is_string($name) ? 'tool end: '.$name : 'tool end';
        if (\is_int($exit)) {
            return $base.' exit='.$exit;
        }

        return $base.' (output omitted)';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function summarizeLlmCompleted(array $payload): string
    {
        $inner = $payload['payload'] ?? $payload;
        if (!\is_array($inner)) {
            return 'llm step completed';
        }

        $toolCalls = $inner['tool_calls'] ?? $inner['toolCalls'] ?? [];
        $count = \is_array($toolCalls) ? \count($toolCalls) : 0;

        return \sprintf('llm step completed (tool_calls=%d, text omitted)', $count);
    }
}
