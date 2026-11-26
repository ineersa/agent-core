<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiRunObservationDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiRunObservationTypeEnum;

final class TuiRunObservationMapper
{
    /** @param ?string $response final assistant text, attached only to terminal turn observations */
    public static function map(RuntimeEvent $event, ?string $response = null): ?TuiRunObservationDTO
    {
        if (0 === $event->seq && \in_array($event->type, [RuntimeEventTypeEnum::ChildExecutionStarted->value, RuntimeEventTypeEnum::ChildExecutionEnded->value, RuntimeEventTypeEnum::ChildInputObserved->value], true)) {
            $child = $event->payload['child_run_id'] ?? null;
            $sequence = $event->payload['child_sequence'] ?? null;
            if (!\is_string($child) || '' === $child || $child === $event->runId || !\is_int($sequence) || $sequence <= 0) {
                return null;
            }
            $type = match ($event->type) {
                RuntimeEventTypeEnum::ChildExecutionStarted->value => TuiRunObservationTypeEnum::ChildExecutionStarted,
                RuntimeEventTypeEnum::ChildExecutionEnded->value => TuiRunObservationTypeEnum::ChildExecutionEnded,
                default => match ($event->payload['phase'] ?? null) {
                    'permission' => TuiRunObservationTypeEnum::ApprovalRequested,
                    'question' => TuiRunObservationTypeEnum::QuestionRequested,
                    'resolved' => TuiRunObservationTypeEnum::InputResolved,
                    default => null,
                },
            };
            $request = $event->payload['request_id'] ?? null;

            return null === $type ? null : new TuiRunObservationDTO($event->runId, $sequence, $type, \is_string($request) ? $request : null, $child);
        }
        if ($event->seq <= 0) {
            return null;
        }
        $type = match ($event->type) {
            RuntimeEventTypeEnum::TurnStarted->value => TuiRunObservationTypeEnum::TurnStarted,
            RuntimeEventTypeEnum::RunCompleted->value => TuiRunObservationTypeEnum::TurnCompleted,
            RuntimeEventTypeEnum::RunFailed->value, RuntimeEventTypeEnum::TurnFailed->value => TuiRunObservationTypeEnum::TurnFailed,
            RuntimeEventTypeEnum::RunCancelled->value, RuntimeEventTypeEnum::TurnCancelled->value => TuiRunObservationTypeEnum::TurnCancelled,
            RuntimeEventTypeEnum::HumanInputRequested->value => isset($event->payload['approval_context'])
                ? TuiRunObservationTypeEnum::ApprovalRequested : TuiRunObservationTypeEnum::QuestionRequested,
            RuntimeEventTypeEnum::ApprovalRequested->value => TuiRunObservationTypeEnum::ApprovalRequested,
            RuntimeEventTypeEnum::HumanInputAnswered->value, RuntimeEventTypeEnum::ApprovalApproved->value, RuntimeEventTypeEnum::ApprovalRejected->value => TuiRunObservationTypeEnum::InputResolved,
            RuntimeEventTypeEnum::ToolExecutionStarted->value => TuiRunObservationTypeEnum::ToolStarted,
            RuntimeEventTypeEnum::ToolExecutionCompleted->value, RuntimeEventTypeEnum::ToolExecutionFailed->value, RuntimeEventTypeEnum::ToolExecutionCancelled->value, RuntimeEventTypeEnum::ToolCallAcknowledged->value => TuiRunObservationTypeEnum::ToolFinished,
            default => null,
        };
        if (null === $type) {
            return null;
        }
        $request = match ($type) {
            TuiRunObservationTypeEnum::QuestionRequested => $event->payload['question_id'] ?? null,
            TuiRunObservationTypeEnum::ApprovalRequested, TuiRunObservationTypeEnum::InputResolved => $event->payload['request_id'] ?? $event->payload['question_id'] ?? null,
            TuiRunObservationTypeEnum::ToolStarted, TuiRunObservationTypeEnum::ToolFinished => $event->payload['tool_call_id'] ?? null,
            default => null,
        };
        $name = TuiRunObservationTypeEnum::ToolStarted === $type ? ($event->payload['tool_name'] ?? null) : null;

        return new TuiRunObservationDTO(
            $event->runId, $event->seq, $type, \is_string($request) && '' !== $request ? $request : null,
            toolName: \is_string($name) ? $name : null,
            trigger: TuiRunObservationTypeEnum::TurnStarted === $type && 'schedule' === ($event->payload['trigger'] ?? null) ? 'schedule' : null,
            response: \in_array($type, [TuiRunObservationTypeEnum::TurnCompleted, TuiRunObservationTypeEnum::TurnFailed, TuiRunObservationTypeEnum::TurnCancelled], true) ? $response : null,
        );
    }

    /** Visible text of a completed assistant message, or null when it has none (for example tool calls only). */
    public static function assistantText(RuntimeEvent $event): ?string
    {
        $content = $event->payload['content'] ?? null;
        if (\is_array($content)) {
            // Separate text blocks render as separate transcript blocks.
            $text = implode("\n\n", array_map(
                static fn (array $part): string => (string) $part['text'],
                array_filter($content, static fn (mixed $part): bool => \is_array($part) && 'text' === ($part['type'] ?? null) && \is_string($part['text'] ?? null) && '' !== $part['text']),
            ));
        } else {
            $text = $event->payload['text'] ?? null;
        }

        return \is_string($text) && '' !== trim($text) ? $text : null;
    }
}
