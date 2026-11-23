<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiRunObservationDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiRunObservationTypeEnum;

final class TuiRunObservationMapper
{
    public static function map(RuntimeEvent $event): ?TuiRunObservationDTO
    {
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
