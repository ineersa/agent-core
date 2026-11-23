<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiRunObservationDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiChildExecutionOutcomeEnum;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiRunObservationTypeEnum;

final class TuiRunObservationMapper
{
    public static function map(RuntimeEvent $event): ?TuiRunObservationDTO
    {
        if (0 === $event->seq && \in_array($event->type, [RuntimeEventTypeEnum::ChildExecutionStarted->value, RuntimeEventTypeEnum::ChildExecutionEnded->value, RuntimeEventTypeEnum::ChildTurnObservation->value], true)) {
            $child = $event->payload['child_run_id'] ?? null;
            $parent = $event->payload['parent_run_id'] ?? null;
            $sequence = $event->payload['child_sequence'] ?? null;
            if (!\is_string($child) || '' === $child || $child === $event->runId || !\is_string($parent) || '' === $parent || $child === $parent || !\is_int($sequence) || $sequence <= 0) {
                return null;
            }

            $type = match ($event->type) {
                RuntimeEventTypeEnum::ChildExecutionStarted->value => TuiRunObservationTypeEnum::ChildExecutionStarted,
                RuntimeEventTypeEnum::ChildExecutionEnded->value => TuiRunObservationTypeEnum::ChildExecutionEnded,
                default => match ($event->payload['phase'] ?? null) {
                    'turn' => TuiRunObservationTypeEnum::TurnStarted,
                    'question' => TuiRunObservationTypeEnum::QuestionRequested,
                    'permission' => TuiRunObservationTypeEnum::ApprovalRequested,
                    'resolved' => TuiRunObservationTypeEnum::InputResolved,
                    default => null,
                },
            };
            $request = $event->payload['request_id'] ?? null;
            $outcome = $event->payload['outcome'] ?? null;

            return null === $type ? null : new TuiRunObservationDTO($event->runId, $sequence, $type,
                \is_string($request) && '' !== $request ? $request : null, $child, $parent,
                TuiRunObservationTypeEnum::ChildExecutionEnded === $type && \is_string($outcome) ? TuiChildExecutionOutcomeEnum::tryFrom($outcome) : null);
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
            default => null,
        };
        if (null === $type) {
            return null;
        }
        $request = match ($type) {
            TuiRunObservationTypeEnum::QuestionRequested => $event->payload['question_id'] ?? null,
            TuiRunObservationTypeEnum::ApprovalRequested, TuiRunObservationTypeEnum::InputResolved => $event->payload['request_id'] ?? $event->payload['question_id'] ?? null,
            default => null,
        };

        return new TuiRunObservationDTO($event->runId, $event->seq, $type, \is_string($request) && '' !== $request ? $request : null);
    }
}
