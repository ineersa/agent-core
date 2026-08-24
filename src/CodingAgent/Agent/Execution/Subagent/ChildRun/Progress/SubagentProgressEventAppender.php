<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Canonical parent subagent_progress append using explicit stored parent tool correlation.
 *
 * Validates the typed snapshot, then normalizes to the canonical snake_case array only
 * when writing the RunEvent payload (persisted/public boundary).
 */
class SubagentProgressEventAppender
{
    public function __construct(
        private CommittedRunEventAppender $committedRunEventAppender,
        private NormalizerInterface $normalizer,
        private ValidatorInterface $validator,
        private RuntimeEventSinkInterface $transientSink,
        private RuntimeEventMapper $runtimeEventMapper,
        private bool $streamCommittedEventsToStdout,
    ) {
    }

    public function append(
        string $parentRunId,
        int $parentTurnNo,
        string $parentToolCallId,
        int $parentOrderIndex,
        string $toolName,
        SubagentProgressSnapshotInterface $progress,
    ): RunEvent {
        $violations = $this->validator->validate($progress);
        if ($violations->count() > 0) {
            throw new ValidationFailedException($progress, $violations);
        }

        /** @var array<string, mixed> $normalized */
        $normalized = $this->normalizer->normalize(
            $progress,
            null,
            [AbstractObjectNormalizer::SKIP_NULL_VALUES => true],
        );

        $event = new RunEvent(
            runId: $parentRunId,
            seq: 0,
            turnNo: $parentTurnNo,
            type: RunEventTypeEnum::ToolExecutionUpdate->value,
            payload: [
                'tool_call_id' => $parentToolCallId,
                'tool_name' => $toolName,
                'delta' => '',
                'subagent_progress' => $normalized,
                'order_index' => $parentOrderIndex,
            ],
        );

        $status = RunStatus::from($progress->status());
        if ($this->streamCommittedEventsToStdout && !$status->isTerminal()) {
            $runtimeEvent = $this->runtimeEventMapper->toRuntimeEvent($event);
            if (null !== $runtimeEvent) {
                $this->transientSink->emit($runtimeEvent);
            }

            return $event;
        }

        // Terminal snapshots remain canonical for replay and artifact recovery.
        return $this->committedRunEventAppender->append($event);
    }
}
