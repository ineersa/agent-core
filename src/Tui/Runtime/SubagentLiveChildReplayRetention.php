<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;

/**
 * Keeps only unresolved HITL/tool-question requests for child live-view reentry.
 *
 * Cached child transcript, sequence, activity, and queued messages remain the
 * authoritative presentation state. Full event archives are not retained in
 * {@see SubagentLiveViewState::$childReplayEvents}.
 */
final class SubagentLiveChildReplayRetention
{
    /**
     * @param list<RuntimeEvent> $events
     *
     * @return list<RuntimeEvent>
     */
    public static function pendingHitlRequests(array $events): array
    {
        $pending = [];

        foreach ($events as $event) {
            if (\in_array($event->type, [
                RuntimeEventTypeEnum::ToolExecutionCompleted->value,
                RuntimeEventTypeEnum::ToolExecutionFailed->value,
                RuntimeEventTypeEnum::ToolExecutionCancelled->value,
            ], true)) {
                $toolCallId = (string) ($event->payload['tool_call_id'] ?? '');
                foreach ($pending as $key => $request) {
                    if ('' !== $toolCallId
                        && $request->runId === $event->runId
                        && RuntimeEventTypeEnum::ToolQuestionRequested->value === $request->type
                        && ($request->payload['tool_call_id'] ?? '') === $toolCallId
                    ) {
                        unset($pending[$key]);
                    }
                }
                continue;
            }

            if (RuntimeEventTypeEnum::HumanInputRequested->value === $event->type) {
                $questionId = (string) ($event->payload['question_id'] ?? '');
                if ('' === $questionId) {
                    continue;
                }

                $pending['human:'.$questionId] = $event;
                continue;
            }

            if (RuntimeEventTypeEnum::ToolQuestionRequested->value === $event->type) {
                $requestId = (string) ($event->payload['request_id'] ?? '');
                if ('' === $requestId) {
                    continue;
                }

                $pending['tool:'.$requestId] = $event;
                continue;
            }

            if (RuntimeEventTypeEnum::HumanInputAnswered->value === $event->type
                || RuntimeEventTypeEnum::HumanInputRejected->value === $event->type
            ) {
                $questionId = (string) ($event->payload['question_id'] ?? '');
                if ('' !== $questionId) {
                    unset($pending['human:'.$questionId]);
                }
            }
        }

        return array_values($pending);
    }
}
