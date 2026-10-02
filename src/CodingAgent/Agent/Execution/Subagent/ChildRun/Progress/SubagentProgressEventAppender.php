<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\CommitSubagentProgress;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Submit canonical progress to the owner; controller nonterminal progress stays transient.
 *
 * Validates the typed snapshot and normalizes its wire payload before submission.
 * A true return means transient delivery; false means canonical delivery is queued.
 */
class SubagentProgressEventAppender
{
    public function __construct(
        private MessageBusInterface $commandBus,
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
        string $lifecycleId,
        int $revision,
        ?string $interruptionKind = null,
    ): bool {
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

            return true;
        }

        $this->commandBus->dispatch(new CommitSubagentProgress(
            $parentRunId, $parentTurnNo, $lifecycleId, $parentToolCallId,
            $parentOrderIndex, $revision, $normalized, $interruptionKind,
        ));

        // False means queued, not consumed. Only the owner advances canonical
        // delivery markers after RunCommit has published its updated sequence.
        return false;
    }
}
